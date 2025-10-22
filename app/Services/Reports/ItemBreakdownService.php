<?php

namespace App\Services\Reports;

use App\Models\OrderLine;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class ItemBreakdownService
{
    /** @var array<string, array{label:string, placed:string[]|null, fulfilled:string[]|null}> */
    private const BUCKETS = [
        'in_store' => [
            'label'     => 'In Store',
            'placed'    => ['Register','Drive Thru','SoundHoundAgent','Phone','CallCenterAgent'],
            'fulfilled' => ['Register','Drive-Thru'],
        ],
        'lc_pickup' => [
            'label'     => 'LC Pickup',
            'placed'    => ['Website','Mobile'],
            'fulfilled' => ['Register','Drive-Thru','In Store Only'],
        ],
        'lc_delivery' => [
            'label'     => 'LC Delivery',
            'placed'    => ['Website','Mobile','CallCenterAgent','SoundHoundAgent'],
            'fulfilled' => ['Delivery'],
        ],
        'third_party' => [
            'label'     => '3rd Party',
            'placed'    => ['UberEats','Grubhub','DoorDash'],
            'fulfilled' => ['Delivery'],
        ],
        'all' => [
            'label'     => 'All Buckets',
            'placed'    => null,
            'fulfilled' => null,
        ],
    ];

    /** Pizza item ids (given) */
    private const PIZZA_IDS = [
        101002,101001,101401,201108,201441,101567,101281,101573,101402,201059,201002,101423,201042,102000,201106,
        201048,101473,201064,201157,101474,201004,201138,201003,202900,402208,201100,201001,201129,202206,202100,
        202909,202208,201139,201342,201165,101539,201119,201128,101282,201017,202002,201150,202910,101229,201008,
        202001,201112,101541,202011,201412,101403,201043,101378,101542,201044,201049,201118,201120,201111,201109,
        201140,202212,202003,201426,201058
    ];

    /** Bread item ids (given) */
    private const BREAD_IDS = [
        103044,103003,201343,103001,203004,203003,103033,203010
    ];

    /** Single/Grouped items */
    private const WINGS_IDS        = [105001];
    private const CRAZY_PUFFS_IDS  = [103033, 103044];
    private const COOKIE_IDS       = [101288, 101289];
    private const BEVERAGE_IDS     = [204100, 204200];
    private const SIDES_IDS        = [206117, 103002];

    public function breakdown(string $franchiseStore, $fromDate, $toDate): array
    {
        $from = $fromDate instanceof Carbon ? $fromDate->toDateString() : Carbon::parse($fromDate)->toDateString();
        $to   = $toDate   instanceof Carbon ? $toDate->toDateString()   : Carbon::parse($toDate)->toDateString();

        $out = ['buckets' => []];

        foreach (self::BUCKETS as $key => $rules) {
            $base = $this->baseQuery($franchiseStore, $from, $to, $rules['placed'], $rules['fulfilled']);

            // PIZZA: totals & top 10
            $pizzaTotals = $this->totalsByIds($base, self::PIZZA_IDS);
            arsort($pizzaTotals);
            $topPizzaIds = array_slice(array_keys($pizzaTotals), 0, 10);

            // BREAD: totals & top 3
            $breadTotals = $this->totalsByIds($base, self::BREAD_IDS);
            arsort($breadTotals);
            $topBreadIds = array_slice(array_keys($breadTotals), 0, 3);

            $out['buckets'][$key] = [
                'label'        => $rules['label'],
                // NOTE: pass $franchiseStore/$from/$to so unit_price can be sourced from Register/Register rows
                'pizza_top10'  => $this->materializeItems($base, $topPizzaIds, $franchiseStore, $from, $to),
                'bread_top3'   => $this->materializeItems($base, $topBreadIds, $franchiseStore, $from, $to),
                'wings'        => $this->materializeItems($base, self::WINGS_IDS, $franchiseStore, $from, $to),
                'crazy_puffs'  => $this->materializeItems($base, self::CRAZY_PUFFS_IDS, $franchiseStore, $from, $to),
                'cookie'       => $this->materializeItems($base, self::COOKIE_IDS, $franchiseStore, $from, $to),
                'beverage'     => $this->materializeItems($base, self::BEVERAGE_IDS, $franchiseStore, $from, $to),
                'sides'        => $this->materializeItems($base, self::SIDES_IDS, $franchiseStore, $from, $to),
            ];
        }

        return $out;
    }

    private function baseQuery(
        string $franchiseStore,
        string $from,
        string $to,
        ?array $placed,
        ?array $fulfilled
    ): Builder {
        $q = OrderLine::query()
            ->where('franchise_store', $franchiseStore)
            ->whereBetween('business_date', [$from, $to]);

        if ($placed !== null && count($placed)) {
            $q->whereIn('order_placed_method', $placed);
        }
        if ($fulfilled !== null && count($fulfilled)) {
            $q->whereIn('order_fulfilled_method', $fulfilled);
        }

        return $q;
    }

    private function totalsByIds(Builder $base, array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $sum = (clone $base)
                ->where('item_id', (string)$id)
                ->sum('net_amount');

            $out[$id] = (float) $sum;
        }
        return $out;
    }

    /**
     * Materialize rows for the provided item IDs.
     * - total_sales: sum over the (possibly bucket-filtered) base
     * - entries_count: count over the (possibly bucket-filtered) base
     * - unit_price: FIRST row in date range with Register/Register and empty/null bundle & mod reason
     *
     * If no qualifying unit-price row exists, fallback to the latest row (bucket-filtered) as before.
     *
     * @param  Builder $base
     * @param  int[]   $ids
     * @param  string  $franchiseStore
     * @param  string  $from
     * @param  string  $to
     * @return array<int, array{item_id:int,menu_item_name:string,unit_price:float,total_sales:float,entries_count:int}>
     */
    private function materializeItems(
        Builder $base,
        array $ids,
        string $franchiseStore,
        string $from,
        string $to
    ): array {
        $rows = [];
        foreach ($ids as $id) {
            $idStr = (string) $id;

            // total sales (bucket-aware)
            $total = (float) (clone $base)
                ->where('item_id', $idStr)
                ->sum('net_amount');

            // count of entries (bucket-aware)
            $count = (int) (clone $base)
                ->where('item_id', $idStr)
                ->count();

            // -------- NEW: derive unit price from FIRST qualifying Register/Register row in date range --------
            $priceRow = OrderLine::query()
                ->where('franchise_store', $franchiseStore)
                ->whereBetween('business_date', [$from, $to])
                ->where('item_id', $idStr)
                ->where('quantity', '>', 0)
                ->where('order_placed_method', 'Register')
                ->where('order_fulfilled_method', 'Register')
                ->where(function ($q) {
                    $q->whereNull('bundle_name')->orWhere('bundle_name', '');
                })
                ->where(function ($q) {
                    $q->whereNull('modification_reason')->orWhere('modification_reason', '');
                })
                ->orderBy('business_date', 'asc') // FIRST in the range
                ->first(['menu_item_name', 'net_amount', 'quantity']);

            $name  = $priceRow?->menu_item_name ?? '';

            $price = ($priceRow && (float)$priceRow->quantity !== 0.0)
                ? (float) $priceRow->net_amount / (float) $priceRow->quantity
                : null;

            // Fallback if no qualifying price row: use latest bucket-filtered row (original behavior)
            if ($price === null) {
                $fallbackRow = (clone $base)
                    ->where('item_id', $idStr)
                    ->where('quantity', '>', 0)
                    ->orderBy('business_date', 'desc')
                    ->first(['menu_item_name', 'net_amount', 'quantity']);

                if (!$fallbackRow) {
                    $fallbackRow = (clone $base)
                        ->where('item_id', $idStr)
                        ->orderBy('business_date', 'desc')
                        ->first(['menu_item_name', 'net_amount', 'quantity']);
                }

                $name = $name !== '' ? $name : ($fallbackRow?->menu_item_name ?? '');
                $price = ($fallbackRow && (float)$fallbackRow->quantity !== 0.0)
                    ? (float) $fallbackRow->net_amount / (float) $fallbackRow->quantity
                    : 0.0;
            }

            $rows[] = [
                'item_id'        => (int) $id,
                'menu_item_name' => (string) $name,
                'unit_price'     => (float) $price,
                'total_sales'    => (float) $total,
                'entries_count'  => (int) $count,
            ];
        }

        // Deterministic ordering by total_sales desc
        usort($rows, function ($a, $b) {
            if ($a['total_sales'] === $b['total_sales']) return 0;
            return ($a['total_sales'] < $b['total_sales']) ? 1 : -1;
        });

        return $rows;
    }
}
