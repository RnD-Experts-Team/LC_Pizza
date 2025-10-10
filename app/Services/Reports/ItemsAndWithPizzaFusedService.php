<?php

namespace App\Services\Reports;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder as Q;
use Illuminate\Support\Facades\DB;

/**
 * Ultra-optimized fused service:
 * - Query Builder only (no Eloquent hydration / no model casting).
 * - Uses generated flags + indexes for fast EXISTS counts.
 * - Item breakdown via single GROUP BY per bucket on relevant item_ids.
 * - One extra per-item lookup to get latest non-zero-qty unit price/name.
 * - Supports null/"All" store (no store filter).
 */
class ItemsAndWithPizzaFusedService
{
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
        'all' => [
            'label'     => 'All Buckets',
            'placed'    => null,
            'fulfilled' => null,
        ],
    ];

    // Item groups for breakdown
    private const PIZZA_IDS = [
        101002,101001,101401,201108,201441,101567,101281,101573,101402,201059,201002,101423,201042,102000,201106,
        201048,101473,201064,201157,101474,201004,201138,201003,202900,402208,201100,201001,201129,202206,202100,
        202909,202208,201139,201342,201165,101539,201119,201128,101282,201017,202002,201150,202910,101229,201008,
        202001,201112,101541,202011,201412,101403,201043,101378,101542,201044,201049,201118,201120,201111,201109,
        201140,202212,202003,201426,201058
    ];
    private const BREAD_IDS = [103044,103003,201343,103001,203004,203003,103033,203010];
    private const WINGS_IDS       = [105001];
    private const CRAZY_PUFFS_IDS = [103033, 103044];
    private const COOKIE_IDS      = [101288, 101289];
    private const BEVERAGE_IDS    = [204100, 204200];
    private const SIDES_IDS       = [206117, 103002];

    public function compute(?string $franchiseStore, $fromDate, $toDate): array
    {
        $from = $fromDate instanceof Carbon ? $fromDate->toDateString() : Carbon::parse($fromDate)->toDateString();
        $to   = $toDate   instanceof Carbon ? $toDate->toDateString()   : Carbon::parse($toDate)->toDateString();

        $itemRes = ['buckets' => []];
        $soldRes = ['buckets' => []];

        foreach (self::BUCKETS as $key => $rules) {
            $label = $rules['label'];

            // ---- SOLD-WITH-PIZZA: fast EXISTS counts using flags ----
            $pizzaBase = $this->countPizzaBase($franchiseStore, $from, $to, $rules['placed'], $rules['fulfilled']);

            $countCzb = $this->countCompanionWithPizza($franchiseStore, $from, $to, $rules['placed'], $rules['fulfilled'], 'is_companion_crazy_bread');
            $countCok = $this->countCompanionWithPizza($franchiseStore, $from, $to, $rules['placed'], $rules['fulfilled'], 'is_companion_cookie');
            $countSau = $this->countCompanionWithPizza($franchiseStore, $from, $to, $rules['placed'], $rules['fulfilled'], 'is_companion_sauce');
            $countWin = $this->countCompanionWithPizza($franchiseStore, $from, $to, $rules['placed'], $rules['fulfilled'], 'is_companion_wings');

            $den = $pizzaBase ?: 1;
            $soldRes['buckets'][$key] = [
                'label'  => $label,
                'counts' => [
                    'crazy_bread' => (int) $countCzb,
                    'cookies'     => (int) $countCok,
                    'sauce'       => (int) $countSau,
                    'wings'       => (int) $countWin,
                    'pizza_base'  => (int) $pizzaBase,
                ],
                'percentages' => [
                    'crazy_bread' => $pizzaBase ? $countCzb / $den : 0.0,
                    'cookies'     => $pizzaBase ? $countCok / $den : 0.0,
                    'sauce'       => $pizzaBase ? $countSau / $den : 0.0,
                    'wings'       => $pizzaBase ? $countWin / $den : 0.0,
                ],
            ];

            // ---- ITEM BREAKDOWN: single GROUP BY on relevant item_ids ----
            $groups = [
                'pizza_top10' => self::PIZZA_IDS,
                'bread_top3'  => self::BREAD_IDS,
                'wings'       => self::WINGS_IDS,
                'crazy_puffs' => self::CRAZY_PUFFS_IDS,
                'cookie'      => self::COOKIE_IDS,
                'beverage'    => self::BEVERAGE_IDS,
                'sides'       => self::SIDES_IDS,
            ];

            $bucketItems = [];
            foreach ($groups as $groupKey => $ids) {
                $stats = $this->groupTotals($franchiseStore, $from, $to, $rules['placed'], $rules['fulfilled'], $ids);

                // hydrate unit price + name once per present item_id
                $rows = [];
                foreach ($stats as $itemId => $agg) {
                    [$name, $unitPrice] = $this->latestNameAndPrice($franchiseStore, $from, $to, $rules['placed'], $rules['fulfilled'], (string)$itemId);

                    $rows[] = [
                        'item_id'        => (int) $itemId,
                        'menu_item_name' => $name,
                        'unit_price'     => $unitPrice,
                        'total_sales'    => (float) $agg['total_sales'],
                        'entries_count'  => (int)   $agg['entries_count'],
                    ];
                }

                // sort desc by total_sales
                usort($rows, fn($a,$b) => $b['total_sales'] <=> $a['total_sales']);

                // top N limits
                if ($groupKey === 'pizza_top10') { $rows = array_slice($rows, 0, 10); }
                if ($groupKey === 'bread_top3')  { $rows = array_slice($rows, 0, 3); }

                $bucketItems[$groupKey] = $rows;
            }

            $itemRes['buckets'][$key] = array_merge(['label' => $label], $bucketItems);
        }

        return [
            'item_breakdown'  => $itemRes,
            'sold_with_pizza' => $soldRes,
        ];
    }

    // =========================
    // SOLD-WITH helpers
    // =========================

    private function countPizzaBase(?string $store, string $from, string $to, ?array $placed, ?array $fulfilled): int
    {
        $q = $this->base($store, $from, $to, $placed, $fulfilled)
            ->where('is_pizza', 1);

        return (int) $q->count();
    }

    private function countCompanionWithPizza(?string $store, string $from, string $to, ?array $placed, ?array $fulfilled, string $flagCol): int
    {
        $q = $this->base($store, $from, $to, $placed, $fulfilled)
            ->where($flagCol, 1)
            ->whereExists(function (Q $sub) use ($store, $from, $to, $placed, $fulfilled) {
                $sub->from('order_line as ol2')
                    ->select(DB::raw('1'))
                    ->whereColumn('ol2.order_id', 'order_line.order_id')
                    ->whereBetween('ol2.business_date', [$from, $to])
                    ->where('ol2.is_pizza', 1);

                if ($store !== null && $store !== '' && strtolower($store) !== 'all') {
                    $sub->where('ol2.franchise_store', $store);
                }
                if ($placed) {
                    $sub->whereIn('ol2.order_placed_method', $placed);
                }
                if ($fulfilled) {
                    $sub->whereIn('ol2.order_fulfilled_method', $fulfilled);
                }
            });

        return (int) $q->count();
    }

    // =========================
    // ITEM BREAKDOWN helpers
    // =========================

    /**
     * Returns [item_id => ['total_sales'=>float,'entries_count'=>int]] for a set of IDs.
     */
    private function groupTotals(?string $store, string $from, string $to, ?array $placed, ?array $fulfilled, array $ids): array
    {
        if (empty($ids)) return [];

        $q = $this->base($store, $from, $to, $placed, $fulfilled)
            ->whereIn('item_id', array_map('strval', $ids))
            ->groupBy('item_id')
            ->select([
                'item_id',
                DB::raw('SUM(net_amount) as total_sales'),
                DB::raw('COUNT(*) as entries_count'),
            ]);

        $rows = $q->get();
        $out = [];
        foreach ($rows as $r) {
            $out[$r->item_id] = [
                'total_sales'   => (float) $r->total_sales,
                'entries_count' => (int) $r->entries_count,
            ];
        }
        return $out;
    }

    /**
     * Fetch one row for an item_id (latest by business_date with quantity>0) to derive name and unit price.
     */
    private function latestNameAndPrice(?string $store, string $from, string $to, ?array $placed, ?array $fulfilled, string $itemId): array
    {
        $q = $this->base($store, $from, $to, $placed, $fulfilled)
            ->where('item_id', $itemId)
            ->where('quantity', '>', 0)
            ->orderBy('business_date', 'desc')
            ->limit(1)
            ->select(['menu_item_name','net_amount','quantity']);

        $row = $q->first();

        if (!$row) {
            // fallback to any row if none with qty>0 in range
            $row = $this->base($store, $from, $to, $placed, $fulfilled)
                ->where('item_id', $itemId)
                ->orderBy('business_date', 'desc')
                ->limit(1)
                ->select(['menu_item_name','net_amount','quantity'])
                ->first();
        }

        $name  = $row->menu_item_name ?? '';
        $qty   = (float) ($row->quantity ?? 0);
        $amt   = (float) ($row->net_amount ?? 0);
        $price = $qty != 0.0 ? ($amt / $qty) : 0.0;

        return [(string)$name, (float)$price];
    }

    // =========================
    // BASE query (Query Builder, no Eloquent)
    // =========================

    private function base(?string $store, string $from, string $to, ?array $placed, ?array $fulfilled): Q
    {
        $q = DB::table('order_line')
            ->whereBetween('business_date', [$from, $to]);

        if ($store !== null && $store !== '' && strtolower($store) !== 'all') {
            $q->where('franchise_store', $store);
        }
        if ($placed) {
            $q->whereIn('order_placed_method', $placed);
        }
        if ($fulfilled) {
            $q->whereIn('order_fulfilled_method', $fulfilled);
        }

        return $q;
    }
}
