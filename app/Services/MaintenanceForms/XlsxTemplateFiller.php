<?php

namespace App\Services\MaintenanceForms;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use ZipArchive;

/**
 * Fills cells of an existing .xlsx template in place, keeping every
 * style, merge, image and print setting of the original file.
 *
 * Works directly on the OpenXML parts with ZipArchive + DOM so it needs
 * no extra composer dependency (only ext-zip, which Snipe-IT requires).
 */
class XlsxTemplateFiller
{
    private const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private ZipArchive $zip;

    /** @var array<string, string> sheet name => part path (xl/worksheets/sheetN.xml) */
    private array $sheetParts = [];

    /** @var array<string, DOMDocument> part path => loaded document */
    private array $docs = [];

    /** @var list<string>|null */
    private ?array $sharedStrings = null;

    public function __construct(string $templatePath, private string $outputPath)
    {
        if (! is_file($templatePath)) {
            throw new RuntimeException("Template not found: {$templatePath}");
        }
        if (! copy($templatePath, $outputPath)) {
            throw new RuntimeException("Cannot copy template to {$outputPath}");
        }

        $this->zip = new ZipArchive;
        if ($this->zip->open($outputPath) !== true) {
            throw new RuntimeException("Cannot open {$outputPath} as xlsx");
        }

        $this->loadSheetMap();
    }

    /** Write a text value into a cell (keeps the cell's existing style). */
    public function setCell(string $sheet, string $ref, ?string $value): self
    {
        $cell = $this->findOrCreateCell($sheet, $ref);
        $doc = $cell->ownerDocument;

        while ($cell->firstChild) {
            $cell->removeChild($cell->firstChild);
        }

        if ($value === null || $value === '') {
            $cell->removeAttribute('t');

            return $this;
        }

        $cell->setAttribute('t', 'inlineStr');
        $is = $doc->createElementNS(self::NS_MAIN, 'is');
        $t = $doc->createElementNS(self::NS_MAIN, 't');
        $t->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
        $t->appendChild($doc->createTextNode($this->cleanText($value)));
        $is->appendChild($t);
        $cell->appendChild($is);

        return $this;
    }

    /** Read the current text of a cell (shared or inline string). */
    public function getCell(string $sheet, string $ref): string
    {
        $cell = $this->findCell($sheet, $ref);
        if (! $cell) {
            return '';
        }

        $xp = $this->xpath($cell->ownerDocument);
        $type = $cell->getAttribute('t');

        if ($type === 's') {
            $idx = (int) $xp->evaluate('string(m:v)', $cell);

            return $this->sharedStrings()[$idx] ?? '';
        }
        if ($type === 'inlineStr') {
            return $xp->evaluate('string(m:is)', $cell);
        }

        return $xp->evaluate('string(m:v)', $cell);
    }

    /**
     * Turn "☐ Label" into "☑ Label" in the given cell, keeping the
     * per-run fonts of the template (☐ is MS Gothic, label is Times).
     */
    public function tick(string $sheet, string $ref): self
    {
        $runs = $this->getRuns($sheet, $ref);
        if ($runs === null) {
            $text = $this->getCell($sheet, $ref);
            if (str_contains($text, '☐')) {
                $this->setCell($sheet, $ref, preg_replace('/☐/u', '☑', $text, 1));
            }

            return $this;
        }

        foreach ($runs as $i => $text) {
            if (str_contains($text, '☐')) {
                $runs[$i] = preg_replace('/☐/u', '☑', $text, 1);

                return $this->setRuns($sheet, $ref, $runs);
            }
        }

        return $this;
    }

    /**
     * Texts of the rich-text runs of a cell, or null when the cell is
     * plain text.
     *
     * @return list<string>|null
     */
    public function getRuns(string $sheet, string $ref): ?array
    {
        $si = $this->richSource($sheet, $ref);
        if (! $si) {
            return null;
        }

        $xp = $this->xpath($si->ownerDocument);
        $texts = [];
        foreach ($xp->query('m:r', $si) as $r) {
            $texts[] = $xp->evaluate('string(m:t)', $r);
        }

        return $texts;
    }

    /**
     * Replace the text of each rich-text run while keeping its font
     * (size, bold, family). $texts[i] = null keeps run i unchanged.
     * Falls back to setCell() for plain cells.
     *
     * @param  list<string|null>  $texts
     * @param  array<int, float>  $sizes  optional font size override per run index
     */
    public function setRuns(string $sheet, string $ref, array $texts, array $sizes = []): self
    {
        $si = $this->richSource($sheet, $ref);
        if (! $si) {
            return $this->setCell($sheet, $ref, implode('', array_map('strval', $texts)));
        }

        $cell = $this->findOrCreateCell($sheet, $ref);
        $doc = $cell->ownerDocument;
        $runs = iterator_to_array($this->xpath($si->ownerDocument)->query('m:r', $si));

        $is = $doc->createElementNS(self::NS_MAIN, 'is');
        foreach ($runs as $i => $run) {
            /** @var DOMElement $copy */
            $copy = $doc->importNode($run, true);
            if (array_key_exists($i, $texts) && $texts[$i] !== null) {
                foreach (iterator_to_array($copy->getElementsByTagNameNS(self::NS_MAIN, 't')) as $t) {
                    $copy->removeChild($t);
                }
                $t = $doc->createElementNS(self::NS_MAIN, 't');
                $t->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
                $t->appendChild($doc->createTextNode($this->cleanText($texts[$i])));
                $copy->appendChild($t);
            }
            if (isset($sizes[$i])) {
                $this->setRunSize($copy, $sizes[$i]);
            }
            $is->appendChild($copy);
        }

        while ($cell->firstChild) {
            $cell->removeChild($cell->firstChild);
        }
        $cell->setAttribute('t', 'inlineStr');
        $cell->appendChild($is);

        return $this;
    }

    /** Set <rPr><sz val=".."/></rPr> on a run, creating rPr if needed. */
    private function setRunSize(DOMElement $run, float $size): void
    {
        $doc = $run->ownerDocument;
        $rPr = $run->getElementsByTagNameNS(self::NS_MAIN, 'rPr')->item(0);
        if (! $rPr) {
            $rPr = $doc->createElementNS(self::NS_MAIN, 'rPr');
            $run->insertBefore($rPr, $run->firstChild);
        }
        $sz = $rPr->getElementsByTagNameNS(self::NS_MAIN, 'sz')->item(0);
        if (! $sz) {
            $sz = $doc->createElementNS(self::NS_MAIN, 'sz');
            // sz must follow b/i/strike/... and precede color/rFont.
            $after = null;
            foreach ($rPr->childNodes as $child) {
                if ($child instanceof DOMElement && in_array($child->localName, ['color', 'rFont', 'family', 'charset', 'scheme', 'u', 'vertAlign'], true)) {
                    $after = $child;
                    break;
                }
            }
            $rPr->insertBefore($sz, $after);
        }
        $sz->setAttribute('val', (string) $size);
    }

    /** The <si>/<is> node holding rich-text runs for a cell, if any. */
    private function richSource(string $sheet, string $ref): ?DOMElement
    {
        $cell = $this->findCell($sheet, $ref);
        if (! $cell) {
            return null;
        }

        $xp = $this->xpath($cell->ownerDocument);
        $type = $cell->getAttribute('t');
        $node = null;

        if ($type === 's') {
            $idx = (int) $xp->evaluate('string(m:v)', $cell);
            $this->sharedStrings();
            $sst = $this->doc('xl/sharedStrings.xml');
            $node = $this->xpath($sst)->query('/m:sst/m:si')->item($idx);
        } elseif ($type === 'inlineStr') {
            $node = $xp->query('m:is', $cell)->item(0);
        }

        if ($node instanceof DOMElement && $this->xpath($node->ownerDocument)->query('m:r', $node)->length > 0) {
            return $node;
        }

        return null;
    }

    /** Set a fixed row height in points. */
    public function setRowHeight(string $sheet, int $rowNumber, float $height): self
    {
        $row = $this->findOrCreateRow($sheet, $rowNumber);
        $row->setAttribute('ht', (string) $height);
        $row->setAttribute('customHeight', '1');

        return $this;
    }

    /** Give $ref the same style index as $sourceRef. */
    public function copyStyle(string $sheet, string $sourceRef, string $ref): self
    {
        $source = $this->findCell($sheet, $sourceRef);
        if ($source && $source->hasAttribute('s')) {
            $this->findOrCreateCell($sheet, $ref)->setAttribute('s', $source->getAttribute('s'));
        }

        return $this;
    }

    /**
     * Print the sheet on a single A4 page (only if the template has no
     * page setup of its own).
     */
    public function fitToOnePage(string $sheet, string $orientation = 'portrait'): self
    {
        $doc = $this->sheetDoc($sheet);
        $xp = $this->xpath($doc);
        $root = $doc->documentElement;

        if ($xp->query('/m:worksheet/m:pageSetup')->length > 0) {
            return $this;
        }

        // <sheetPr><pageSetUpPr fitToPage="1"/></sheetPr> must be the first child.
        $sheetPr = $xp->query('/m:worksheet/m:sheetPr')->item(0);
        if (! $sheetPr) {
            $sheetPr = $doc->createElementNS(self::NS_MAIN, 'sheetPr');
            $root->insertBefore($sheetPr, $root->firstChild);
        }
        $pageSetUpPr = $xp->query('m:pageSetUpPr', $sheetPr)->item(0);
        if (! $pageSetUpPr) {
            $pageSetUpPr = $doc->createElementNS(self::NS_MAIN, 'pageSetUpPr');
            // pageSetUpPr comes after tabColor/outlinePr if present.
            $sheetPr->appendChild($pageSetUpPr);
        }
        $pageSetUpPr->setAttribute('fitToPage', '1');

        $pageSetup = $doc->createElementNS(self::NS_MAIN, 'pageSetup');
        $pageSetup->setAttribute('paperSize', '9');
        $pageSetup->setAttribute('orientation', $orientation);
        $pageSetup->setAttribute('fitToWidth', '1');
        $pageSetup->setAttribute('fitToHeight', '1');

        // pageSetup goes right after pageMargins (or printOptions).
        $anchor = $xp->query('/m:worksheet/m:pageMargins')->item(0)
            ?? $xp->query('/m:worksheet/m:printOptions')->item(0);
        if ($anchor) {
            $root->insertBefore($pageSetup, $anchor->nextSibling);
        } else {
            $root->appendChild($pageSetup);
        }

        return $this;
    }

    /** Keep only $sheet visible and make it the active tab. */
    public function showOnlySheet(string $sheet): self
    {
        if (! isset($this->sheetParts[$sheet])) {
            throw new RuntimeException("Sheet not found: {$sheet}");
        }

        $wb = $this->doc('xl/workbook.xml');
        $xp = $this->xpath($wb);
        $activeIndex = 0;

        foreach ($xp->query('/m:workbook/m:sheets/m:sheet') as $i => $node) {
            /** @var DOMElement $node */
            if ($node->getAttribute('name') === $sheet) {
                $node->removeAttribute('state');
                $activeIndex = $i;
            } else {
                $node->setAttribute('state', 'hidden');
            }
        }

        foreach ($xp->query('/m:workbook/m:bookViews/m:workbookView') as $view) {
            /** @var DOMElement $view */
            $view->setAttribute('activeTab', (string) $activeIndex);
            $view->setAttribute('firstSheet', (string) $activeIndex);
        }

        foreach ($this->sheetParts as $name => $part) {
            $doc = $this->doc($part);
            foreach ($this->xpath($doc)->query('//m:sheetViews/m:sheetView') as $sv) {
                /** @var DOMElement $sv */
                if ($name === $sheet) {
                    $sv->setAttribute('tabSelected', '1');
                } else {
                    $sv->removeAttribute('tabSelected');
                }
            }
        }

        return $this;
    }

    /** Write all modified parts back and close the archive. */
    public function save(): string
    {
        foreach ($this->docs as $part => $doc) {
            $this->zip->addFromString($part, $doc->saveXML());
        }
        $this->zip->close();

        return $this->outputPath;
    }

    // ------------------------------------------------------------------

    private function loadSheetMap(): void
    {
        $rels = $this->doc('xl/_rels/workbook.xml.rels');
        $targets = [];
        foreach ($rels->documentElement->childNodes as $rel) {
            if ($rel instanceof DOMElement) {
                $target = ltrim($rel->getAttribute('Target'), '/');
                $targets[$rel->getAttribute('Id')] = str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
            }
        }

        $wb = $this->doc('xl/workbook.xml');
        foreach ($this->xpath($wb)->query('/m:workbook/m:sheets/m:sheet') as $node) {
            /** @var DOMElement $node */
            $rid = $node->getAttributeNS(self::NS_REL, 'id');
            $this->sheetParts[$node->getAttribute('name')] = $targets[$rid];
        }
    }

    private function doc(string $part): DOMDocument
    {
        if (! isset($this->docs[$part])) {
            $xml = $this->zip->getFromName($part);
            if ($xml === false) {
                throw new RuntimeException("Missing part {$part} in template");
            }
            $doc = new DOMDocument;
            $doc->preserveWhiteSpace = true;
            $doc->loadXML($xml);
            $this->docs[$part] = $doc;
        }

        return $this->docs[$part];
    }

    private function xpath(DOMDocument $doc): DOMXPath
    {
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('m', self::NS_MAIN);

        return $xp;
    }

    /** @return list<string> */
    private function sharedStrings(): array
    {
        if ($this->sharedStrings === null) {
            $this->sharedStrings = [];
            if ($this->zip->locateName('xl/sharedStrings.xml') !== false) {
                $doc = $this->doc('xl/sharedStrings.xml');
                $xp = $this->xpath($doc);
                foreach ($xp->query('/m:sst/m:si') as $si) {
                    // Concatenate plain + rich-text runs, skip phonetic runs.
                    $text = '';
                    foreach ($xp->query('m:t | m:r/m:t', $si) as $t) {
                        $text .= $t->textContent;
                    }
                    $this->sharedStrings[] = $text;
                }
            }
        }

        return $this->sharedStrings;
    }

    private function sheetDoc(string $sheet): DOMDocument
    {
        if (! isset($this->sheetParts[$sheet])) {
            throw new RuntimeException("Sheet not found: {$sheet}");
        }

        return $this->doc($this->sheetParts[$sheet]);
    }

    private function findCell(string $sheet, string $ref): ?DOMElement
    {
        $doc = $this->sheetDoc($sheet);
        $node = $this->xpath($doc)->query('//m:sheetData/m:row/m:c[@r="'.$ref.'"]')->item(0);

        return $node instanceof DOMElement ? $node : null;
    }

    private function findOrCreateRow(string $sheet, int $rowNumber): DOMElement
    {
        $doc = $this->sheetDoc($sheet);
        $xp = $this->xpath($doc);
        $sheetData = $xp->query('//m:sheetData')->item(0);

        $before = null;
        foreach ($xp->query('m:row', $sheetData) as $row) {
            /** @var DOMElement $row */
            $r = (int) $row->getAttribute('r');
            if ($r === $rowNumber) {
                return $row;
            }
            if ($r > $rowNumber) {
                $before = $row;
                break;
            }
        }

        $row = $doc->createElementNS(self::NS_MAIN, 'row');
        $row->setAttribute('r', (string) $rowNumber);
        $sheetData->insertBefore($row, $before);

        return $row;
    }

    private function findOrCreateCell(string $sheet, string $ref): DOMElement
    {
        if ($cell = $this->findCell($sheet, $ref)) {
            return $cell;
        }

        if (! preg_match('/^([A-Z]+)(\d+)$/', $ref, $m)) {
            throw new RuntimeException("Bad cell reference {$ref}");
        }
        $col = self::columnIndex($m[1]);
        $row = $this->findOrCreateRow($sheet, (int) $m[2]);
        $doc = $row->ownerDocument;

        $before = null;
        foreach ($row->childNodes as $c) {
            if ($c instanceof DOMElement && preg_match('/^([A-Z]+)/', $c->getAttribute('r'), $cm)
                && self::columnIndex($cm[1]) > $col) {
                $before = $c;
                break;
            }
        }

        $cell = $doc->createElementNS(self::NS_MAIN, 'c');
        $cell->setAttribute('r', $ref);
        // Inherit the row style if the row has one, so borders stay intact.
        if ($row->hasAttribute('s')) {
            $cell->setAttribute('s', $row->getAttribute('s'));
        }
        $row->insertBefore($cell, $before);

        return $cell;
    }

    private static function columnIndex(string $letters): int
    {
        $n = 0;
        foreach (str_split($letters) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return $n;
    }

    /** Strip characters that are illegal in XML 1.0 and normalise newlines. */
    private function cleanText(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);

        return preg_replace('/[^\x{9}\x{A}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value) ?? '';
    }
}
