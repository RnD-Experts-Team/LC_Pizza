<?php

namespace App\Services\Reports;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ItemsAndWithPizzaFusedService (timeout-safe)
 *
 * Same output + rules as your current version, but:
 *  - Uses Query Builder (no Eloquent model hydration / no casting overhead)
 *  - Two-pass per chunk using a SET of pizza order_ids (no big per-order arrays)
 *  - Adaptive chunk size for "All" store (smaller to reduce peak work)
 *  - Tracks latest business_date per item_id to compute unit price (qty>0)
 *
 * Still 100% "Laravel queries only". No direct SQL (only Builder).
 */
class ItemsAndWithPizzaFusedService
{
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

    // Item groups for breakdown
    private const PIZZA_IDS = [
        101002,101001,101401,201108,201441,101567,101281,101573,101402,201059,201002,101423,201042,102000,201106,
        201048,101473,201064,201157,101474,201004,201138,201003,202900,402208,201100,201001,201129,202206,202100,
        202909,202208,201139,201342,201165,101539,201119,201128,101282,201017,202002,201150,202910,101229,201008,
        202001,201112,101541,202011,201412,101403,201043,101378,101542,201044,201049,201118,201120,201111,201109,
        201140,202212,202003,201426,201058
    ];
    private const BREAD_IDS      = [103044,103003,201343,103001,203004,203003,103033,203010];
    private const WINGS_IDS      = [105001];
    private const CRAZY_PUFFS_IDS= [103033, 103044];
    private const COOKIE_IDS     = [101288, 101289];
    private const BEVERAGE_IDS   = [204100, 204200];
    private const SIDES_IDS      = [206117, 103002];

    // ===== Public API =====
    public function compute(?string $franchiseStore, $fromDate, $toDate): array
    {
        // Optional: prevent web PHP 60s hard timeout for large ALL-store ranges (no-op on CLI).
        // @phpstan-ignore-next-line
        if (function_exists('set_time_limit')) { @set_time_limit(0); }

        $from = $fromDate instanceof Carbon ? $fromDate->toDateString() : Carbon::parse($fromDate)->toDateString();
        $to   = $toDate   instanceof Carbon ? $toDate->toDateString()   : Carbon::parse($toDate)->toDateString();

        $itemRes = ['buckets' => []];
        $soldRes = ['buckets' => []];

        $isAllStore = ($franchiseStore === null || $franchiseStore === '' || strtolower($franchiseStore) === 'all');
        $chunkSize  = $isAllStore ? 2000 : 5000; // smaller chunk for All to avoid CPU spikes

        // Pre-build relevant ID set (string compare in DB)
        $relevantIds = array_values(array_unique(array_merge(
            self::PIZZA_IDS,
            self::BREAD_IDS,
            self::WINGS_IDS,
            self::CRAZY_PUFFS_IDS,
            self::COOKIE_IDS,
            self::BEVERAGE_IDS,
            self::SIDES_IDS
        )));
        $relevantIdStr  = array_map('strval', $relevantIds);
        $relevantIdFlip = array_fill_keys($relevantIdStr, true); // tiny in-memory set

        foreach (self::BUCKETS as $key => $rules) {
            $label = $rules['label'];

            // Accumulators per bucket
            $sumByItem   = [];
            $countByItem = [];
            $nameByItem  = [];
            $priceByItem = [];
            $latestDate  = []; // item_id => Y-m-d string for most recent qty>0 row used for price

            $countCzb = 0; $countCok = 0; $countSau = 0; $countWin = 0; $pizzaBase = 0;

            // Stream rows: only columns used; only rows needed
            $this->baseQB($franchiseStore, $from, $to, $rules['placed'], $rules['fulfilled'])
                ->where(function ($q) use ($relevantIdStr) {
                    $q->whereIn('item_id', $relevantIdStr)   // item breakdown rows
                      ->orWhere('is_pizza', 1)               // pizza base rows
                      ->orWhere('is_companion_crazy_bread', 1)
                      ->orWhere('is_companion_cookie', 1)
                      ->orWhere('is_companion_sauce', 1)
                      ->orWhere('is_companion_wings', 1);
                })
                ->orderBy('id')
                ->chunkById($chunkSize, function ($rows) use (
                    &$sumByItem, &$countByItem, &$nameByItem, &$priceByItem, &$latestDate,
                    &$pizzaBase, &$countCzb, &$countCok, &$countSau, &$countWin,
                    $relevantIdFlip
                ) {
                    // Pass 1: compute item totals + find pizza orders
                    $pizzaOrders = []; // order_id => true for this chunk only
                    foreach ($rows as $r) {
                        $orderId = (string) ($r->order_id ?? '');
                        $itemId  = (string) ($r->item_id ?? '');
                        $qty     = (float)  ($r->quantity ?? 0);
                        $amt     = (float)  ($r->net_amount ?? 0);
                        $name    = (string) ($r->menu_item_name ?? '');
                        $bDate   = (string) ($r->business_date ?? '');

                        // Item breakdown only for relevant IDs (avoid any accidental pollution)
                        if (isset($relevantIdFlip[$itemId])) {
                            $sumByItem[$itemId]   = ($sumByItem[$itemId]   ?? 0.0) + $amt;
                            $countByItem[$itemId] = ($countByItem[$itemId] ?? 0) + 1;

                            if ($qty !== 0.0) {
                                // keep the latest business_date's unit price
                                if (!isset($latestDate[$itemId]) || $bDate > $latestDate[$itemId]) {
                                    $latestDate[$itemId] = $bDate;
                                    $priceByItem[$itemId] = $amt / $qty;
                                    if ($name !== '') { $nameByItem[$itemId] = $name; }
                                }
                            } elseif (!isset($nameByItem[$itemId]) && $name !== '') {
                                // capture a name even if qty=0 rows dominate
                                $nameByItem[$itemId] = $name;
                            }
                        }

                        if ($r->is_pizza) {
                            $pizzaBase++;
                            if ($orderId !== '') { $pizzaOrders[$orderId] = true; }
                        }
                    }

                    // Pass 2: count companions only if order had pizza (hash set check)
                    if (!empty($pizzaOrders)) {
                        foreach ($rows as $r) {
                            $orderId = (string) ($r->order_id ?? '');
                            if ($orderId === '' || !isset($pizzaOrders[$orderId])) {
                                continue;
                            }
                            if ($r->is_companion_crazy_bread) { $countCzb++; }
                            elseif ($r->is_companion_cookie)  { $countCok++; }
                            elseif ($r->is_companion_sauce)   { $countSau++; }
                            elseif ($r->is_companion_wings)   { $countWin++; }
                        }
                    }
                    // allow $pizzaOrders to be GC'ed at end of chunk
                }, 'id', 'id');

            // Build breakdown rows (sorted desc by total_sales)
            $buildRows = function (array $ids) use ($sumByItem, $countByItem, $nameByItem, $priceByItem): array {
                $rows = [];
                foreach ($ids as $intId) {
                    $id = (string) $intId;
                    $rows[] = [
                        'item_id'        => (int) $intId,
                        'menu_item_name' => $nameByItem[$id]  ?? '',
                        'unit_price'     => (float) ($priceByItem[$id] ?? 0.0),
                        'total_sales'    => (float) ($sumByItem[$id]   ?? 0.0),
                        'entries_count'  => (int)   ($countByItem[$id] ?? 0),
                    ];
                }
                usort($rows, fn($a,$b) => $b['total_sales'] <=> $a['total_sales']);
                return $rows;
            };

            // === Item breakdown payload (with all the sections you want) ===
            $itemRes['buckets'][$key] = [
                'label'        => $label,
                'pizza_top10'  => array_slice($buildRows(self::PIZZA_IDS), 0, 10),
                'bread_top3'   => array_slice($buildRows(self::BREAD_IDS), 0, 3),
                'wings'        => $buildRows(self::WINGS_IDS),
                'crazy_puffs'  => $buildRows(self::CRAZY_PUFFS_IDS),
                'cookies'      => $buildRows(self::COOKIE_IDS),
                'beverages'    => $buildRows(self::BEVERAGE_IDS),
                'sides'        => $buildRows(self::SIDES_IDS),
            ];

            // === Sold-with-pizza payload (unchanged) ===
            $den = $pizzaBase ?: 1;
            $soldRes['buckets'][$key] = [
                'label'       => $label,
                'counts'      => [
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
        }

        return [
            'item_breakdown'  => $itemRes,
            'sold_with_pizza' => $soldRes,
        ];
    }

    // ====== Query Builder base (no Eloquent hydration) ======
    private function baseQB(?string $store, string $from, string $to, ?array $placed, ?array $fulfilled)
    {
        $q = DB::table('order_line')
            ->select([
                'id','order_id','item_id','menu_item_name','net_amount','quantity',
                'business_date',
                'is_pizza','is_companion_crazy_bread','is_companion_cookie','is_companion_sauce','is_companion_wings',
            ])
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
