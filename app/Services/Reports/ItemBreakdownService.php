<?php

namespace App\Services\Reports;

use App\Models\OrderLine;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * ItemBreakdownService
 *
 * Memory-safe, Eloquent-only (no raw SQL) item analytics:
 * - Top 10 Pizza (by total net_amount)
 * - Top 3 Bread (by total net_amount)
 * - Wings, Crazy Puffs, Cookie, Beverage, Sides (per item)
 *
 * Always filtered by franchise_store + business_date range.
 * Bucket-aware (In Store, LC Pickup, LC Delivery, 3rd Party) + All Buckets.
 *
 * Implementation notes:
 * - Uses ONLY scalar aggregates (sum(), count()) and a single first() per item to fetch name/price.
 * - Never pulls large collections to PHP.
 * - Sorting for "top" lists is done on tiny in-memory arrays of ~IDs with scalar totals.
 */
class ItemBreakdownService
{
    /** @var array<string, array{label:string, placed:string[]|null, fulfilled:string[]|null}> */
    private const BUCKETS = [
        'in_store' => [
            'label'     => 'In Store',
            'placed'    => ['Register','Drive Thru','SoundHoundAgent','Phone'],
            'fulfilled' => ['Register','Drive-Thru'],
        ],
        'lc_pickup' => [
            'label'     => 'LC Pickup',
            'placed'    => ['Website','Mobile'],
            'fulfilled' => ['Register','Drive-Thru'],
        ],
        'lc_delivery' => [
            'label'     => 'LC Delivery',
            'placed'    => ['Website','Mobile'],
            'fulfilled' => ['Delivery'],
        ],
        'third_party' => [
            'label'     => '3rd Party',
            'placed'    => ['UberEats','Grubhub','DoorDash'],
            'fulfilled' => ['Delivery'],
        ],
        // All buckets combined (no placed/fulfilled filter)
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

    /**
     * Public API: compute all item groups for each bucket plus "all".
     *
     * @return array{
     *   buckets: array<string, array{
     *     label: string,
     *     pizza_top10: array<int, array{item_id:int,menu_item_name:string,unit_price:float,total_sales:float,entries_count:int}>,
     *     bread_top3: array<int, array{item_id:int,menu_item_name:string,unit_price:float,total_sales:float,entries_count:int}>,
     *     wings: array<int, array{item_id:int,menu_item_name:string,unit_price:float,total_sales:float,entries_count:int}>,
     *     crazy_puffs: array<int, array{item_id:int,menu_item_name:string,unit_price:float,total_sales:float,entries_count:int}>,
     *     cookie: array<int, array{item_id:int,menu_item_name:string,unit_price:float,total_sales:float,entries_count:int}>,
     *     beverage: array<int, array{item_id:int,menu_item_name:string,unit_price:float,total_sales:float,entries_count:int}>,
     *     sides: array<int, array{item_id:int,menu_item_name:string,unit_price:float,total_sales:float,entries_count:int}>
     *   }>
     * }
     */
    public function breakdown(string $franchiseStore, $fromDate, $toDate): array
    {
        $from = $fromDate instanceof Carbon ? $fromDate->toDateString() : Carbon::parse($fromDate)->toDateString();
        $to   = $toDate   instanceof Carbon ? $toDate->toDateString()   : Carbon::parse($toDate)->toDateString();

        $out = ['buckets' => []];

        foreach (self::BUCKETS as $key => $rules) {
            $base = $this->baseQuery($franchiseStore, $from, $to, $rules['placed'], $rules['fulfilled']);

            // PIZZA: compute totals per id (scalar sum per id), then sort desc & pick top 10
            $pizzaTotals = $this->totalsByIds($base, self::PIZZA_IDS);
            arsort($pizzaTotals); // high to low by total_sales
            $topPizzaIds = array_slice(array_keys($pizzaTotals), 0, 10);

            // BREAD: same, pick top 3
            $breadTotals = $this->totalsByIds($base, self::BREAD_IDS);
            arsort($breadTotals);
            $topBreadIds = array_slice(array_keys($breadTotals), 0, 3);

            $out['buckets'][$key] = [
                'label'        => $rules['label'],
                'pizza_top10'  => $this->materializeItems($base, $topPizzaIds),
                'bread_top3'   => $this->materializeItems($base, $topBreadIds),
                'wings'        => $this->materializeItems($base, self::WINGS_IDS),
                'crazy_puffs'  => $this->materializeItems($base, self::CRAZY_PUFFS_IDS),
                'cookie'       => $this->materializeItems($base, self::COOKIE_IDS),
                'beverage'     => $this->materializeItems($base, self::BEVERAGE_IDS),
                'sides'        => $this->materializeItems($base, self::SIDES_IDS),
            ];
        }

        return $out;
    }

    /**
     * Build a base query with store/date and optional bucket method filters.
     * We only add filters; no selections or raw expressions.
     */
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

    /**
     * Compute scalar totals for a set of item_ids using sum('net_amount').
     * @param  Builder $base  (already contains store/date/bucket filters)
     * @param  int[]   $ids
     * @return array<int,float>   [item_id => total_sales]
     */
    private function totalsByIds(Builder $base, array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            // clone base to keep it clean per iteration
            $sum = (clone $base)
                ->where('item_id', (string)$id)
                ->sum('net_amount');

            $out[$id] = (float) $sum;
        }
        return $out;
    }

    /**
     * For each item_id, materialize the full record:
     * - total_sales (sum)
     * - entries_count (count)
     * - menu_item_name + unit_price (from first row with quantity > 0; fallback to any row)
     *
     * Uses only scalar operations; no raw SQL.
     *
     * @param  Builder $base
     * @param  int[]   $ids
     * @return array<int, array{item_id:int,menu_item_name:string,unit_price:float,total_sales:float,entries_count:int}>
     */
    private function materializeItems(Builder $base, array $ids): array
    {
        $rows = [];
        foreach ($ids as $id) {
            $idStr = (string) $id;

            // total sales
            $total = (float) (clone $base)
                ->where('item_id', $idStr)
                ->sum('net_amount');

            // count of entries
            $count = (int) (clone $base)
                ->where('item_id', $idStr)
                ->count();

            // fetch one row to derive name & unit price
            $row = (clone $base)
                ->where('item_id', $idStr)
                ->where('quantity', '>', 0)
                ->orderBy('business_date', 'desc')  // deterministic latest price
                ->first(['menu_item_name', 'net_amount', 'quantity']);

            if (!$row) {
                // fallback to any row if all quantities are zero/null
                $row = (clone $base)
                    ->where('item_id', $idStr)
                    ->orderBy('business_date', 'desc')
                    ->first(['menu_item_name', 'net_amount', 'quantity']);
            }

            $name  = $row?->menu_item_name ?? '';
            $price = ($row && (float)$row->quantity !== 0.0)
                ? (float) $row->net_amount / (float) $row->quantity
                : 0.0;

            $rows[] = [
                'item_id'        => (int) $id,
                'menu_item_name' => (string) $name,
                'unit_price'     => (float) $price,
                'total_sales'     => (float) $total,
                'entries_count'   => (int) $count,
            ];
        }

        // For deterministic ordering, sort by total_sales desc when we didn't pre-limit
        usort($rows, function ($a, $b) {
            if ($a['total_sales'] === $b['total_sales']) return 0;
            return ($a['total_sales'] < $b['total_sales']) ? 1 : -1;
        });

        return $rows;
    }
}
