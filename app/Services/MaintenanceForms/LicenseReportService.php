<?php

namespace App\Services\MaintenanceForms;

use App\Models\Asset;
use App\Models\License;
use App\Models\Location;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * "Báo cáo License" on the Báo cáo sự cố page.
 *
 *  - Licenses that are expired or end within SOON_DAYS.
 *  - Purchase plan: a license is bought per cinema, quantity = number of
 *    rooms of that cinema. Example: "AAM ND" belongs to Nguyễn Du which
 *    has 5 rooms => buy 1 license "AAM" for Nguyễn Du, quantity 5 rooms.
 *
 * Snipe-IT licenses have no location field, so the cinema is found in
 * this order (the source is shown on the report):
 *   1. seats checked out to an asset / user at that cinema,
 *   2. "Licensed To" (license_name) containing the cinema name,
 *   3. the license name containing the cinema name or its code
 *      ("AAM ND" -> ND = Nguyễn Du, same code as on BBSC numbers).
 *
 * End date = the earlier of expiration_date and termination_date.
 * Product keys are never read or shown.
 */
class LicenseReportService
{
    /** Licenses ending within this many days are listed. */
    public const SOON_DAYS = 60;

    /** The card on top counts licenses ending within this many days. */
    public const CARD_DAYS = 30;

    public const SOURCE_SEAT = 'seat';

    public const SOURCE_LICENSED_TO = 'licensed_to';

    public const SOURCE_NAME = 'name';

    public const SOURCE_LABELS = [
        self::SOURCE_SEAT => 'theo seat đã gán',
        self::SOURCE_LICENSED_TO => 'theo ô Licensed To',
        self::SOURCE_NAME => 'theo tên license',
    ];

    public function __construct(private InventoryRepository $inventory) {}

    /**
     * @return array<string, mixed> rows + counters + purchase plan
     */
    public function report(?Carbon $today = null): array
    {
        $today = ($today ?? Carbon::now())->copy()->startOfDay();
        $limit = $today->copy()->addDays(self::SOON_DAYS)->endOfDay();

        $licenses = License::query()
            ->select(['id', 'name', 'license_name', 'license_email', 'notes', 'order_number', 'purchase_order', 'seats', 'expiration_date', 'termination_date', 'manufacturer_id', 'category_id', 'company_id'])
            ->where(function ($q) use ($limit) {
                $q->where(fn ($e) => $e->whereNotNull('expiration_date')->where('expiration_date', '<=', $limit))
                    ->orWhere(fn ($t) => $t->whereNotNull('termination_date')->where('termination_date', '<=', $limit));
            })
            ->with([
                'manufacturer:id,name',
                'category:id,name',
                'licenseseats' => fn ($q) => $q->select(['id', 'license_id', 'assigned_to', 'asset_id']),
                'licenseseats.user:id,first_name,last_name,username,location_id',
                'licenseseats.user.location.parent',
                'licenseseats.asset' => fn ($q) => $q->select(['id', 'name', 'asset_tag', 'location_id', 'rtd_location_id', 'assigned_to', 'assigned_type', 'company_id']),
                'licenseseats.asset.location.parent',
                'licenseseats.asset.defaultLoc',
                'licenseseats.asset.assignedTo' => fn ($morph) => $morph->morphWith([Location::class => ['parent']]),
            ])
            ->limit(500)
            ->get();

        $rows = [];
        foreach ($licenses as $license) {
            $where = [];
            $seatSites = [];
            $used = 0;
            foreach ($license->licenseseats as $seat) {
                if ($seat->asset instanceof Asset) {
                    $used++;
                    [$site, $hall] = MaintenanceFormExporter::siteAndHall($seat->asset);
                    if ($site !== '') {
                        $seatSites[] = $site;
                    }
                    $where[] = trim(implode(' ', array_filter([
                        $site,
                        $hall !== '' ? 'Hall '.$hall : '',
                        '– '.($seat->asset->name ?: $seat->asset->asset_tag),
                    ])));
                } elseif ($seat->assigned_to && $seat->user) {
                    $used++;
                    $site = IncidentReportRepository::siteOfUser($seat->user);
                    if ($site !== '') {
                        $seatSites[] = $site;
                    }
                    $where[] = 'Người dùng: '.trim($seat->user->first_name.' '.$seat->user->last_name).($site !== '' ? ' ('.$site.')' : '');
                }
            }

            $rows[] = self::decorate([
                'id' => $license->id,
                'name' => (string) $license->name,
                'licensed_to' => (string) ($license->license_name ?? ''),
                'mac' => self::findMac([
                    $license->notes, $license->license_email, $license->license_name,
                    $license->order_number, $license->purchase_order,
                ]),
                'manufacturer' => (string) ($license->manufacturer?->name ?? ''),
                'category' => (string) ($license->category?->name ?? ''),
                'seats' => (int) $license->seats,
                'used' => $used,
                'where' => array_values(array_unique($where)),
                'seat_sites' => array_values(array_unique($seatSites)),
                'expiration_date' => $license->expiration_date ? Carbon::parse($license->expiration_date) : null,
                'termination_date' => $license->termination_date ? Carbon::parse($license->termination_date) : null,
            ], $today);
        }

        // Rooms per cinema, from the equipment overview (cached 10 min).
        $siteRooms = [];
        foreach ($this->inventory->cachedOverview()['sites'] ?? [] as $s) {
            $siteRooms[$s['site']] = (int) $s['rooms'];
        }

        return self::summarize(self::planPurchases($rows, $siteRooms));
    }

    /**
     * Adds end_date / end_kind / days_left / state.
     *
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    /** Licenses are bought per year: next end = old end + 1 year (rolled past today if long expired). */
    public const RENEW_YEARS = 1;

    public static function renewUntil(Carbon $end, Carbon $today): Carbon
    {
        $next = $end->copy()->addYears(self::RENEW_YEARS);
        for ($i = 0; $i < 20 && $next->lt($today); $i++) {
            $next = $next->addYears(self::RENEW_YEARS);
        }

        return $next;
    }

    public static function decorate(array $r, Carbon $today): array
    {
        $exp = $r['expiration_date'] ?? null;
        $term = $r['termination_date'] ?? null;

        $end = $exp;
        $kind = 'Hết hạn';
        if ($term && (! $exp || $term->lt($exp))) {
            $end = $term;
            $kind = 'Chấm dứt';
        }

        $days = $end ? (int) floor(($end->copy()->startOfDay()->getTimestamp() - $today->copy()->startOfDay()->getTimestamp()) / 86400) : null;

        $r['end_date'] = $end;
        $r['end_label'] = $end ? $end->format('d/m/Y') : '';
        $r['end_kind'] = $kind;
        $r['renew_label'] = $end ? self::renewUntil($end, $today)->format('d/m/Y') : '';
        $r['days_left'] = $days;
        $r['state'] = $days === null ? 'none' : ($days < 0 ? 'expired' : ($days <= self::CARD_DAYS ? 'soon' : 'later'));

        return $r;
    }

    /**
     * Work out cinema(s), product and quantity (= rooms) for each license.
     *
     * Adds to each row:
     *   'buy'     => list of ['site' => ..., 'rooms' => int|null]
     *   'source'  => seat | licensed_to | name | ''   (how the cinema was found)
     *   'product' => license name without the cinema code / name ("AAM ND" -> "AAM")
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, int>  $siteRooms  cinema name => rooms
     * @return list<array<string, mixed>>
     */
    public static function planPurchases(array $rows, array $siteRooms): array
    {
        $sites = array_keys($siteRooms);

        foreach ($rows as &$r) {
            $found = [];
            $source = '';

            // 1. Seats checked out at a cinema.
            foreach ($r['seat_sites'] ?? [] as $s) {
                $match = self::matchSiteName($s, $sites) ?? $s;
                $found[$match] = true;
            }
            if ($found) {
                $source = self::SOURCE_SEAT;
            }

            // 2. "Licensed To" text.
            if (! $found && ($r['licensed_to'] ?? '') !== '') {
                foreach (self::sitesInText($r['licensed_to'], $sites) as $s) {
                    $found[$s] = true;
                }
                if ($found) {
                    $source = self::SOURCE_LICENSED_TO;
                }
            }

            // 3. License name (cinema name or code such as "ND").
            if (! $found) {
                foreach (self::sitesInText($r['name'], $sites) as $s) {
                    $found[$s] = true;
                }
                if ($found) {
                    $source = self::SOURCE_NAME;
                }
            }

            $r['source'] = $source;
            $r['buy'] = [];
            foreach (array_keys($found) as $s) {
                $r['buy'][] = ['site' => $s, 'rooms' => $siteRooms[$s] ?? null];
            }
            $r['current_product'] = self::productName($r['name'], array_keys($found));
            $r['product'] = self::purchaseProduct($r['current_product']);
        }
        unset($r);

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public static function summarize(array $rows): array
    {
        $rows = array_values(array_filter($rows, fn ($r) => $r['days_left'] !== null && $r['days_left'] <= self::SOON_DAYS));
        usort($rows, fn ($a, $b) => $a['days_left'] <=> $b['days_left'] ?: strnatcasecmp($a['name'], $b['name']));

        // Purchase lines + totals per product.
        $lines = [];
        $products = [];
        foreach ($rows as $r) {
            foreach ($r['buy'] ?? [] as $b) {
                $lines[] = [
                    'license_id' => $r['id'],
                    'license' => $r['name'],
                    'mac' => $r['mac'] ?? '',
                    'product' => $r['product'],
                    'site' => $b['site'],
                    'rooms' => $b['rooms'],
                    'end_label' => $r['end_label'],
                    'renew_label' => $r['renew_label'] ?? '',
                    'days_left' => $r['days_left'],
                    'source' => $r['source'],
                ];
                $p = $r['product'] !== '' ? $r['product'] : $r['name'];
                $products[$p] ??= ['product' => $p, 'licenses' => 0, 'rooms' => 0, 'unknown_rooms' => 0, 'sites' => []];
                $products[$p]['licenses']++;
                $products[$p]['sites'][] = $b['site'];
                if ($b['rooms'] === null) {
                    $products[$p]['unknown_rooms']++;
                } else {
                    $products[$p]['rooms'] += $b['rooms'];
                }
            }
        }
        ksort($products, SORT_NATURAL | SORT_FLAG_CASE);

        return [
            'rows' => $rows,
            'lines' => $lines,
            'products' => array_values($products),
            'unresolved' => array_values(array_filter($rows, fn ($r) => empty($r['buy']))),
            'expired' => count(array_filter($rows, fn ($r) => $r['state'] === 'expired')),
            'soon_card' => count(array_filter($rows, fn ($r) => $r['state'] === 'soon')),
            'soon_list' => count(array_filter($rows, fn ($r) => $r['days_left'] >= 0)),
            'total' => count($rows),
        ];
    }

    /**
     * Excel: "Cần mua" (one line per license × cinema) + "Theo sản phẩm".
     *
     * @param  array<string, mixed>  $report
     */
    public static function exportPurchase(array $report, string $path): string
    {
        $rows = [];
        $styles = [];
        foreach ($report['lines'] as $i => $l) {
            $rows[] = [
                $i + 1,
                $l['product'] !== '' ? $l['product'] : $l['license'],
                $l['site'],
                $l['rooms'] ?? null,
                $l['mac'] ?? '',
                $l['end_label'],
                $l['renew_label'] ?? '',
            ];
            if ($l['days_left'] < 0) {
                $styles[$i] = 'red';
            }
        }
        foreach ($report['unresolved'] as $u) {
            $rows[] = [count($rows) + 1, $u['product'] ?: $u['name'], '(chưa xác định rạp: '.$u['name'].')', null, $u['mac'] ?? '', $u['end_label'], $u['renew_label'] ?? ''];
            $styles[count($rows) - 1] = 'red';
        }

        $w = new SimpleXlsxWriter;
        $totalRooms = array_sum(array_map(fn ($l) => (int) ($l['rooms'] ?? 0), $report['lines']));
        $w->addSheet('Cần mua', ['STT', 'Sản phẩm', 'Rạp', 'Số phòng (SL cần mua)', 'MAC', 'Ngày kết thúc', 'Cần mua đến'], $rows, [
            'title' => 'DANH SÁCH LICENSE CẦN MUA TIẾP',
            'subtitle' => 'License đã hết hạn hoặc hết hạn trong '.self::SOON_DAYS.' ngày  |  Xuất lúc '.date('d/m/Y H:i'),
            'widths' => [6, 24, 26, 14, 20, 14, 14],
            'rowStyles' => $styles,
            'totalRow' => $rows ? ['Tổng', null, count($report['lines']).' license', $totalRooms, null, null, null] : null,
        ]);

        $p = [];
        foreach ($report['products'] as $pr) {
            $p[] = [$pr['product'], $pr['licenses'], $pr['rooms'], implode(', ', $pr['sites']), $pr['unknown_rooms'] ? $pr['unknown_rooms'].' rạp chưa rõ số phòng' : ''];
        }
        $w->addSheet('Theo sản phẩm', ['Sản phẩm', 'Số license (rạp)', 'Tổng số phòng', 'Các rạp', 'Ghi chú'], $p, [
            'title' => 'TỔNG HỢP THEO SẢN PHẨM',
            'widths' => [24, 14, 14, 60, 24],
        ]);

        return $w->save($path);
    }

    // ------------------------------------------------------------------

    /** Exact (accent/case-insensitive) match of a site name against known cinemas. */
    private static function matchSiteName(string $name, array $sites): ?string
    {
        $f = self::fold($name);
        foreach ($sites as $s) {
            if (self::fold($s) === $f) {
                return $s;
            }
        }

        return null;
    }

    /**
     * Words that do not identify a cinema on their own (brand / mall words).
     * "center" and "centre" are treated the same.
     */
    private const GENERIC_WORDS = ['aeon', 'go', 'vincom', 'thiso', 'galaxy', 'cinema', 'cgv', 'mall', 'center', 'centre', 'plaza', 'tttm', 'rap'];

    /**
     * Cinemas mentioned in a text.
     *
     *  1. By words, in any order: every significant word of the cinema name
     *     must appear ("AAM Bến Tre Sense" = Sense Bến Tre, "AAM Phan Huy Ích"
     *     = Thiso Phan Huy Ích, "AAM Hà Nội Centre" = Hà Nội Center).
     *     When several cinemas match, the one with most matching words wins
     *     ("Bến Tre Sense" -> Sense Bến Tre, not Go Bến Tre).
     *  2. Otherwise by cinema code as a separate word ("AAM ND" -> Nguyễn Du).
     *     A code shared by two cinemas is ignored (ambiguous).
     *
     * @param  list<string>  $sites
     * @return list<string>
     */
    public static function sitesInText(string $text, array $sites): array
    {
        $words = self::words($text);
        if (! $words) {
            return [];
        }

        $best = [];
        $bestScore = 0;
        foreach ($sites as $s) {
            $sig = array_values(array_diff(self::words($s), self::GENERIC_WORDS));
            if (! $sig || array_diff($sig, $words)) {
                continue; // a significant word of the cinema is missing
            }
            $score = count($sig);
            if ($score > $bestScore) {
                $best = [$s];
                $bestScore = $score;
            } elseif ($score === $bestScore) {
                $best[] = $s;
            }
        }
        if (count($best) === 1) {
            return $best;
        }
        if (count($best) > 1) {
            return []; // ambiguous
        }

        $codes = [];
        foreach ($sites as $s) {
            $codes[strtolower(MaintenanceFormExporter::siteCode($s))][] = $s;
        }
        $found = [];
        foreach ($words as $word) {
            if (strlen($word) >= 2 && isset($codes[$word]) && count($codes[$word]) === 1) {
                $found[$codes[$word][0]] = true;
            }
        }

        return array_keys($found);
    }

    /** First MAC address found in the given texts ("00:1A:2B:3C:4D:5E", "00-1A-…", "001A2B3C4D5E"). */
    public static function findMac(array $texts): string
    {
        foreach ($texts as $t) {
            $t = (string) $t;
            if ($t === '') {
                continue;
            }
            if (preg_match('/(?<![0-9A-Fa-f])([0-9A-Fa-f]{2}(?:[:\-][0-9A-Fa-f]{2}){5})(?![0-9A-Fa-f])/', $t, $m)) {
                return strtoupper(str_replace('-', ':', $m[1]));
            }
            if (preg_match('/(?<![0-9A-Fa-f])([0-9A-Fa-f]{12})(?![0-9A-Fa-f])/', $t, $m) && preg_match('/[0-9]/', $m[1])) {
                return strtoupper(implode(':', str_split($m[1], 2)));
            }
        }

        return '';
    }

    /** Ascii, lower-case words; "centre" counted as "center". @return list<string> */
    private static function words(string $text): array
    {
        $w = preg_split('/[^a-z0-9]+/', self::fold($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_map(fn ($x) => $x === 'centre' ? 'center' : $x, $w));
    }

    /**
     * Product = license name without the cinema part.
     *   "AAM Nguyễn Du" -> "AAM", "AAM Hà Nội Centre" -> "AAM",
     *   "AAM ND" -> "AAM", "AAM BT Sense" (cinema found by seat) -> "AAM".
     */
    /**
     * What to buy when a license runs out. AAM renews as AAM Screenwriter Plus;
     * GDC TMS is being replaced by AAM from next year. Others keep their own name.
     */
    public const PURCHASE_PRODUCTS = [
        'aam' => 'AAM Screenwriter Plus',
        'gdc' => 'AAM Screenwriter Plus',
    ];

    public static function purchaseProduct(string $current): string
    {
        $first = self::words($current)[0] ?? '';

        return self::PURCHASE_PRODUCTS[$first] ?? $current;
    }

    public static function productName(string $name, array $sites): string
    {
        $drop = self::GENERIC_WORDS;
        foreach ($sites as $s) {
            $drop = array_merge($drop, self::words($s), [strtolower(MaintenanceFormExporter::siteCode($s))]);
        }

        $kept = [];
        foreach (preg_split('/[\s\-–_|,\/]+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $w = self::words($word);
            if ($w && ! array_diff($w, $drop)) {
                continue;
            }
            $kept[] = $word;
        }

        // Cinema found via seat but the name uses its own short form ("AAM BT Sense"):
        // keep only the first word when the rest looks like a place, i.e. when
        // something was removed or the first word is the manufacturer-style tag.
        if ($sites && count($kept) > 1 && count($kept) < count(preg_split('/\s+/u', trim($name)))) {
            $kept = [$kept[0]];
        }

        $out = trim(implode(' ', $kept));

        return $out !== '' ? $out : $name;
    }

    private static function fold(string $v): string
    {
        return Str::lower(Str::ascii(trim($v)));
    }
}
