<?php

namespace App\Services\Reports;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

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
    private const BREAD_IDS       = [103044,103003,201343,103001,203004,203003,103033,203010];
    private const WINGS_IDS       = [105001];
    private const CRAZY_PUFFS_IDS = [103033, 103044];
    private const COOKIE_IDS      = [101288, 101289];
    private const BEVERAGE_IDS    = [204100, 204200];
    private const SIDES_IDS       = [206117, 103002];

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

        // === NEW: Precompute unit prices ONCE for the whole period (same across buckets) ===
        $unitPriceByItem = $this->precomputeUnitPrices($franchiseStore, $from, $to, $relevantIdStr);

        foreach (self::BUCKETS as $key => $rules) {
            $label = $rules['label'];

            // Accumulators per bucket
            $sumByItem   = [];
            $countByItem = [];
            $nameByItem  = [];

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
                    &$sumByItem, &$countByItem, &$nameByItem,
                    &$pizzaBase, &$countCzb, &$countCok, &$countSau, &$countWin,
                    $relevantIdFlip
                ) {
                    // Pass 1: compute item totals + find pizza orders
                    $pizzaOrders = []; // order_id => true for this chunk only
                    foreach ($rows as $r) {
                        $orderId = (string) ($r->order_id ?? '');
                        $itemId  = (string) ($r->item_id ?? '');
                        $amt     = (float)  ($r->net_amount ?? 0);
                        $name    = (string) ($r->menu_item_name ?? '');

                        // Item breakdown only for relevant IDs (avoid any accidental pollution)
                        if (isset($relevantIdFlip[$itemId])) {
                            $sumByItem[$itemId]   = ($sumByItem[$itemId]   ?? 0.0) + $amt;
                            $countByItem[$itemId] = ($countByItem[$itemId] ?? 0) + 1;

                            // capture a name (doesn't affect price; price is precomputed)
                            if (!isset($nameByItem[$itemId]) && $name !== '') {
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
            $buildRows = function (array $ids) use ($sumByItem, $countByItem, $nameByItem, $unitPriceByItem): array {
                $rows = [];
                foreach ($ids as $intId) {
                    $id = (string) $intId;
                    $rows[] = [
                        'item_id'        => (int) $intId,
                        'menu_item_name' => $nameByItem[$id]  ?? '',
                        'unit_price'     => (float) ($unitPriceByItem[$id] ?? 0.0), // SAME across buckets
                        'total_sales'    => (float) ($sumByItem[$id]   ?? 0.0),
                        'entries_count'  => (int)   ($countByItem[$id] ?? 0),
                    ];
                }
                usort($rows, fn($a,$b) => $b['total_sales'] <=> $a['total_sales']);
                return $rows;
            };

            // === Item breakdown payload ===
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

/**
 * Precompute unit prices for all relevant items once (same across buckets).
 * If a store is provided (and not "all"), use that store.
 * Otherwise, force store = "03795-00001".
 *
 * Rule: first (business_date ASC) row in range where:
 *   placed='Register' AND fulfilled='Register'
 *   AND bundle_name IS NULL/'' AND modification_reason IS NULL/''
 *   AND quantity > 0
 * Fallback: if none found, use latest (business_date DESC) row with quantity > 0 (no extra conditions).
 *
 * @param string|null $store
 * @param string      $from
 * @param string      $to
 * @param string[]    $relevantIdStr
 * @return array<string,float>  [item_id_string => unit_price]
 */
private function precomputeUnitPrices(?string $store, string $from, string $to, array $relevantIdStr): array
{
    $unit = [];

    // Decide which store to use for UNIT PRICE lookup
    $storeForPrice = ($store !== null && $store !== '' && strtolower($store) !== 'all')
        ? $store
        : '03795-00001';

    // ---------- Primary: first qualifying Register/Register row ----------
    $q = DB::table('order_line')
        ->select(['id','item_id','net_amount','quantity','business_date'])
        ->whereBetween('business_date', [$from, $to])
        ->where('franchise_store', $storeForPrice) // <-- always fixed store for price
        ->whereIn('item_id', $relevantIdStr)
        ->where('quantity', '>', 0)
        ->where('order_placed_method', 'Register')
        ->where('order_fulfilled_method', 'Register')
        ->where(function ($q) {
            $q->whereNull('bundle_name')->orWhere('bundle_name', '');
        })
        ->where(function ($q) {
            $q->whereNull('modification_reason')->orWhere('modification_reason', '');
        });

    // "first by date" per item
    $q->orderBy('business_date', 'asc')->orderBy('id', 'asc')
      ->chunk(5000, function ($rows) use (&$unit) {
          foreach ($rows as $r) {
              $id = (string) $r->item_id;
              if (!isset($unit[$id])) {
                  $qty = (float) ($r->quantity ?? 0);
                  if ($qty !== 0.0) {
                      $unit[$id] = (float) $r->net_amount / $qty;
                  }
              }
          }
      });

    // ---------- Fallback: latest qty>0 row in range if still missing ----------
    $missing = array_values(array_diff($relevantIdStr, array_keys($unit)));
    if (!empty($missing)) {
        $fq = DB::table('order_line')
            ->select(['id','item_id','net_amount','quantity','business_date'])
            ->whereBetween('business_date', [$from, $to])
            ->where('franchise_store', $storeForPrice) // <-- same fixed store for price
            ->whereIn('item_id', $missing)
            ->where('quantity', '>', 0);

        // iterate latest-to-oldest and take the first we see per item
        $seen = [];
        $fq->orderBy('business_date', 'desc')->orderBy('id', 'desc')
           ->chunk(5000, function ($rows) use (&$unit, &$seen) {
               foreach ($rows as $r) {
                   $id = (string) $r->item_id;
                   if (isset($seen[$id])) { continue; }
                   $qty = (float) ($r->quantity ?? 0);
                   if ($qty !== 0.0) {
                       $unit[$id] = (float) $r->net_amount / $qty;
                       $seen[$id] = true;
                   }
               }
           });
    }

    return $unit;
}
}
