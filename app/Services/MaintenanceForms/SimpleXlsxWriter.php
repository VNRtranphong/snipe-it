<?php

namespace App\Services\MaintenanceForms;

use RuntimeException;
use ZipArchive;

/**
 * Minimal .xlsx writer (no composer dependency) for tabular reports:
 * several sheets, a title line, a bold header row, borders, wrapped
 * text, column widths, frozen header, autofilter and a few row styles.
 *
 * Usage:
 *   $w = new SimpleXlsxWriter();
 *   $w->addSheet('Sự cố', $headers, $rows, ['widths' => [...], 'title' => '...', 'rowStyles' => [...]]);
 *   $w->save('/tmp/x.xlsx');
 *
 * Cell values: int/float => number, string => text, null => empty.
 */
class SimpleXlsxWriter
{
    // Style indexes in styles.xml (cellXfs order below).
    public const STYLE_DEFAULT = 0;

    public const STYLE_TITLE = 1;

    public const STYLE_HEADER = 2;

    public const STYLE_CELL = 3;

    public const STYLE_CELL_RED = 4;

    public const STYLE_TOTAL = 5;

    public const STYLE_NUMBER = 6;

    public const STYLE_NUMBER_RED = 7;

    public const STYLE_SUBTITLE = 8;

    public const STYLE_NUMBER_TOTAL = 9;

    public const STYLE_INT = 10;

    public const STYLE_INT_RED = 11;

    public const STYLE_INT_TOTAL = 12;

    /** float style => matching integer style */
    private const INT_STYLE = [
        self::STYLE_NUMBER => self::STYLE_INT,
        self::STYLE_NUMBER_RED => self::STYLE_INT_RED,
        self::STYLE_NUMBER_TOTAL => self::STYLE_INT_TOTAL,
    ];

    /** @var list<array{name: string, xml: string}> */
    private array $sheets = [];

    /**
     * @param  list<string>  $headers
     * @param  list<list<string|int|float|null>>  $rows
     * @param  array{widths?: list<float>, title?: string, subtitle?: string, rowStyles?: array<int, string>, totalRow?: list<string|int|float|null>}  $opts
     *                                                                                                                                                      rowStyles: row index => 'red'
     */
    public function addSheet(string $name, array $headers, array $rows, array $opts = []): self
    {
        $cols = count($headers);
        $xmlRows = [];
        $r = 1;

        if (! empty($opts['title'])) {
            $xmlRows[] = $this->row($r++, [$opts['title']], self::STYLE_TITLE, 24);
        }
        if (! empty($opts['subtitle'])) {
            $xmlRows[] = $this->row($r++, [$opts['subtitle']], self::STYLE_SUBTITLE);
        }
        if ($r > 1) {
            $r++; // blank spacer row
        }

        $headerRow = $r;
        $xmlRows[] = $this->row($r++, $headers, self::STYLE_HEADER, 30);

        foreach ($rows as $i => $values) {
            $red = ($opts['rowStyles'][$i] ?? '') === 'red';
            $xmlRows[] = $this->row($r++, $values, $red ? self::STYLE_CELL_RED : self::STYLE_CELL, null, $red ? self::STYLE_NUMBER_RED : self::STYLE_NUMBER);
        }

        if (! empty($opts['totalRow'])) {
            $xmlRows[] = $this->row($r++, $opts['totalRow'], self::STYLE_TOTAL, null, self::STYLE_NUMBER_TOTAL);
        }

        $lastCol = self::col($cols);
        $lastDataRow = max($headerRow, $headerRow + count($rows));

        $colsXml = '';
        foreach ($opts['widths'] ?? [] as $i => $w) {
            $n = $i + 1;
            $colsXml .= '<col min="'.$n.'" max="'.$n.'" width="'.$w.'" customWidth="1"/>';
        }

        $merge = '';
        $mergeRefs = [];
        if (! empty($opts['title'])) {
            $mergeRefs[] = 'A1:'.$lastCol.'1';
        }
        if (! empty($opts['subtitle'])) {
            $sr = empty($opts['title']) ? 1 : 2;
            $mergeRefs[] = 'A'.$sr.':'.$lastCol.$sr;
        }
        if ($mergeRefs) {
            $merge = '<mergeCells count="'.count($mergeRefs).'">';
            foreach ($mergeRefs as $ref) {
                $merge .= '<mergeCell ref="'.$ref.'"/>';
            }
            $merge .= '</mergeCells>';
        }

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
            .'<sheetViews><sheetView workbookViewId="0"'.(count($this->sheets) === 0 ? ' tabSelected="1"' : '').'>'
            .'<pane ySplit="'.$headerRow.'" topLeftCell="A'.($headerRow + 1).'" activePane="bottomLeft" state="frozen"/>'
            .'</sheetView></sheetViews>'
            .'<sheetFormatPr defaultRowHeight="15"/>'
            .($colsXml ? '<cols>'.$colsXml.'</cols>' : '')
            .'<sheetData>'.implode('', $xmlRows).'</sheetData>'
            .($rows ? '<autoFilter ref="A'.$headerRow.':'.$lastCol.$lastDataRow.'"/>' : '')
            .$merge
            .'<pageMargins left="0.3" right="0.3" top="0.5" bottom="0.5" header="0.3" footer="0.3"/>'
            .'<pageSetup paperSize="9" orientation="landscape" fitToWidth="1" fitToHeight="0"/>'
            .'</worksheet>';

        $this->sheets[] = ['name' => self::sheetName($name), 'xml' => $xml, 'filterRef' => $rows ? 'A'.$headerRow.':'.$lastCol.$lastDataRow : null];

        return $this;
    }

    public function save(string $path): string
    {
        if (! $this->sheets) {
            throw new RuntimeException('Workbook has no sheets');
        }

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Cannot write {$path}");
        }

        $n = count($this->sheets);
        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        for ($i = 1; $i <= $n; $i++) {
            $ct .= '<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $ct .= '</Types>';
        $zip->addFromString('[Content_Types].xml', $ct);

        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');

        $sheetsXml = '';
        $rels = '';
        $defined = '';
        foreach ($this->sheets as $i => $s) {
            $id = $i + 1;
            $sheetsXml .= '<sheet name="'.self::esc($s['name']).'" sheetId="'.$id.'" r:id="rId'.$id.'"/>';
            $rels .= '<Relationship Id="rId'.$id.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$id.'.xml"/>';
            $zip->addFromString('xl/worksheets/sheet'.$id.'.xml', $s['xml']);
            if ($s['filterRef']) {
                [$a, $b] = explode(':', $s['filterRef']);
                $defined .= '<definedName name="_xlnm._FilterDatabase" localSheetId="'.$i.'" hidden="1">'
                    .self::esc("'".str_replace("'", "''", $s['name'])."'!".self::abs($a).':'.self::abs($b))
                    .'</definedName>';
            }
        }
        $rels .= '<Relationship Id="rId'.($n + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<bookViews><workbookView activeTab="0"/></bookViews>'
            .'<sheets>'.$sheetsXml.'</sheets>'
            .($defined ? '<definedNames>'.$defined.'</definedNames>' : '')
            .'</workbook>');

        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>');

        $zip->addFromString('xl/styles.xml', self::styles());
        $zip->close();

        return $path;
    }

    // ------------------------------------------------------------------

    /**
     * @param  list<string|int|float|null>  $values
     */
    private function row(int $r, array $values, int $style, ?float $height = null, ?int $numberStyle = null): string
    {
        $cells = '';
        foreach (array_values($values) as $i => $v) {
            $ref = self::col($i + 1).$r;
            if ($v === null || $v === '') {
                $cells .= '<c r="'.$ref.'" s="'.$style.'"/>';
            } elseif (is_int($v) || is_float($v)) {
                $ns = $numberStyle ?? $style;
                if (is_int($v)) {
                    $ns = self::INT_STYLE[$ns] ?? $ns;
                }
                $cells .= '<c r="'.$ref.'" s="'.$ns.'"><v>'.$v.'</v></c>';
            } else {
                $cells .= '<c r="'.$ref.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'.self::esc((string) $v).'</t></is></c>';
            }
        }

        return '<row r="'.$r.'"'.($height ? ' ht="'.$height.'" customHeight="1"' : '').'>'.$cells.'</row>';
    }

    public static function col(int $n): string
    {
        $s = '';
        while ($n > 0) {
            $m = ($n - 1) % 26;
            $s = chr(65 + $m).$s;
            $n = intdiv($n - 1, 26);
        }

        return $s;
    }

    private static function abs(string $ref): string
    {
        return preg_replace('/^([A-Z]+)(\d+)$/', '\$$1\$$2', $ref);
    }

    private static function esc(string $v): string
    {
        $v = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $v) ?? '';

        return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** Excel sheet names: max 31 chars, no []:*?/\ */
    private static function sheetName(string $name): string
    {
        $name = trim(preg_replace('/[\[\]:*?\/\\\\]/', ' ', $name));

        return mb_substr($name !== '' ? $name : 'Sheet', 0, 31);
    }

    private static function styles(): string
    {
        // fonts: 0 normal, 1 bold, 2 title (bold 14), 3 italic grey, 4 red, 5 bold white
        // fills: 0 none, 1 gray125 (required), 2 header blue, 3 light red, 4 light grey
        // borders: 0 none, 1 thin all
        // numFmt 164: "#,##0.0" for hours
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.0"/></numFmts>'
            .'<fonts count="6">'
            .'<font><sz val="11"/><name val="Times New Roman"/><family val="1"/></font>'
            .'<font><b/><sz val="11"/><name val="Times New Roman"/><family val="1"/></font>'
            .'<font><b/><sz val="15"/><name val="Times New Roman"/><family val="1"/></font>'
            .'<font><i/><sz val="11"/><color rgb="FF595959"/><name val="Times New Roman"/><family val="1"/></font>'
            .'<font><sz val="11"/><color rgb="FF9C0006"/><name val="Times New Roman"/><family val="1"/></font>'
            .'<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Times New Roman"/><family val="1"/></font>'
            .'</fonts>'
            .'<fills count="5">'
            .'<fill><patternFill patternType="none"/></fill>'
            .'<fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FF1F4E78"/><bgColor indexed="64"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFFFE1E1"/><bgColor indexed="64"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFEDEDED"/><bgColor indexed="64"/></patternFill></fill>'
            .'</fills>'
            .'<borders count="2">'
            .'<border><left/><right/><top/><bottom/><diagonal/></border>'
            .'<border><left style="thin"><color rgb="FF808080"/></left><right style="thin"><color rgb="FF808080"/></right>'
            .'<top style="thin"><color rgb="FF808080"/></top><bottom style="thin"><color rgb="FF808080"/></bottom><diagonal/></border>'
            .'</borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="13">'
            // 0 default
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            // 1 title
            .'<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            // 2 header
            .'<xf numFmtId="0" fontId="5" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            // 3 cell
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
            // 4 cell red (overdue)
            .'<xf numFmtId="0" fontId="4" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
            // 5 total row
            .'<xf numFmtId="0" fontId="1" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>'
            // 6 number
            .'<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="top"/></xf>'
            // 7 number red
            .'<xf numFmtId="164" fontId="4" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="top"/></xf>'
            // 8 subtitle
            .'<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
            // 9 number total
            .'<xf numFmtId="164" fontId="1" fillId="4" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
            // 10 int
            .'<xf numFmtId="3" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="top"/></xf>'
            // 11 int red
            .'<xf numFmtId="3" fontId="4" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="top"/></xf>'
            // 12 int total
            .'<xf numFmtId="3" fontId="1" fillId="4" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            .'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }
}
