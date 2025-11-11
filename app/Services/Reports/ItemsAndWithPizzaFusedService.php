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

    /** Keep Sides “as they are” (unchanged) */
    private const SIDES_IDS = [206117, 103002];

    /** Cookies explicit IDs (unchanged) */
    private const COOKIE_IDS = [101288, 101289];

    /** Fallback dip IDs if needed (rare) */
    private const CAESAR_DIP_IDS_FALLBACK = [
        206117, // Caesar Dip
        206103, // Caesar Dip - Buttery Garlic
        206104, // Caesar Dip - Ranch
        206108, // Caesar Dip - Cheezy Jalapeno
        206101, // Caesar Dip - Buffalo Ranch
    ];

    /**
     * Helper: distinct item_ids where a boolean flag column = 1
     */
    private function getIdsByFlag(?string $store, string $from, string $to, string $flagCol): array
    {
        $q = DB::table('order_line')
            ->distinct()
            ->whereBetween('business_date', [$from, $to])
            ->where($flagCol, 1)
            ->whereNotNull('item_id');

        if ($store !== null && $store !== '' && strtolower($store) !== 'all') {
            $q->where('franchise_store', $store);
        }

        return $q->pluck('item_id')->map(fn($v) => (int)$v)->unique()->values()->all();
    }

    // ===== Public API =====
    public function compute(?string $franchiseStore, $fromDate, $toDate): array
    {
        if (function_exists('set_time_limit')) { @set_time_limit(0); }

        $from = $fromDate instanceof Carbon ? $fromDate->toDateString() : Carbon::parse($fromDate)->toDateString();
        $to   = $toDate   instanceof Carbon ? $toDate->toDateString()   : Carbon::parse($toDate)->toDateString();

        $itemRes = ['buckets' => []];
        $soldRes = ['buckets' => []];

        $isAllStore = ($franchiseStore === null || $franchiseStore === '' || strtolower($franchiseStore) === 'all');
        $chunkSize  = $isAllStore ? 2000 : 5000;

        // ===== Build category ID sets DIRECTLY from generated flags =====
        $PIZZA_IDS      = $this->getIdsByFlag($franchiseStore, $from, $to, 'is_pizza');
        $BREAD_IDS      = $this->getIdsByFlag($franchiseStore, $from, $to, 'is_bread');
        $WINGS_IDS      = $this->getIdsByFlag($franchiseStore, $from, $to, 'is_wings');
        $BEVERAGE_IDS   = $this->getIdsByFlag($franchiseStore, $from, $to, 'is_beverages');
        $PUFFS_IDS      = $this->getIdsByFlag($franchiseStore, $from, $to, 'is_crazy_puffs');
        $DIP_IDS        = $this->getIdsByFlag($franchiseStore, $from, $to, 'is_caesar_dip');
        if (empty($DIP_IDS)) {
            // optional safety fallback if no dips appear in the window
            $DIP_IDS = self::CAESAR_DIP_IDS_FALLBACK;
        }

        $COOKIE_IDS     = self::COOKIE_IDS;
        $SIDES_IDS      = self::SIDES_IDS;

        // Everything we care about (filtering + unit prices)
        $relevantIds = array_values(array_unique(array_merge(
            $PIZZA_IDS, $BREAD_IDS, $WINGS_IDS, $PUFFS_IDS, $COOKIE_IDS, $BEVERAGE_IDS, $SIDES_IDS, $DIP_IDS
        )));
        $relevantIdStr  = array_map('strval', $relevantIds);
        $relevantIdFlip = array_fill_keys($relevantIdStr, true);

        // collect union across buckets
        $seenAnywhere = [];

        // ===== EDIT #2: renamed entries_count -> units_sold and sort by it
        $buildRows = static function (
            array $ids,
            array $sumByItem,
            array $countByItem,
            array $nameByItem,
            array $unitPriceByItem,
            bool $filterAppeared = true
        ): array {
            $rows = [];
            foreach ($ids as $intId) {
                $id    = (string)$intId;
                $units = (int)($countByItem[$id] ?? 0);
                if ($filterAppeared && $units === 0) { continue; }

                $rows[] = [
                    'item_id'        => (int)$intId,
                    'menu_item_name' => $nameByItem[$id]  ?? '',
                    'unit_price'     => (float)($unitPriceByItem[$id] ?? 0.0),
                    'total_sales'    => (float)($sumByItem[$id]   ?? 0.0),
                    'units_sold'     => $units, // renamed
                ];
            }
            usort($rows, static function ($a, $b) {
                $cmp = $b['units_sold'] <=> $a['units_sold']; // sort by units
                return $cmp !== 0 ? $cmp : ($b['total_sales'] <=> $a['total_sales']);
            });
            return $rows;
        };

        $priceFiltersByBucket = [
            'in_store'     => [['Register'],         ['Register']],
            'lc_pickup'    => [['Website','Mobile'], ['Register']],
            'lc_delivery'  => [['Website','Mobile'], ['Delivery']],
            'third_party'  => [['DoorDash'],         ['Delivery']],
            'all'          => [['Register'],         ['Register']],
        ];

        $_bucketRaw = [];

        foreach (self::BUCKETS as $key => $rules) {
            $label = $rules['label'];

            [$placedForPrice, $fulfilledForPrice] = $priceFiltersByBucket[$key] ?? [null, null];
            $unitPriceByItem = $this->precomputeUnitPrices(
                $franchiseStore,
                $from,
                $to,
                $relevantIdStr,
                $placedForPrice,
                $fulfilledForPrice
            );

            $sumByItem   = [];
            $countByItem = []; // now stores UNITS (sum of quantities)
            $nameByItem  = [];

            // sold-with-pizza counters (using flags)
            $countBread = 0; $countCookie = 0; $countSauce = 0; $countWings = 0; $countBev = 0; $countPuffs = 0;
            $pizzaBase = 0;

            $this->baseQB($franchiseStore, $from, $to, $rules['placed'], $rules['fulfilled'])
                ->where(function ($q) use ($relevantIdStr) {
                    $q->whereIn('item_id', $relevantIdStr)
                      ->orWhere('is_pizza', 1);
                })
                ->orderBy('id')
                ->chunkById($chunkSize, function ($rows) use (
                    &$sumByItem, &$countByItem, &$nameByItem,
                    &$pizzaBase, &$countBread, &$countCookie, &$countSauce, &$countWings, &$countBev, &$countPuffs,
                    $relevantIdFlip, &$seenAnywhere, $COOKIE_IDS
                ) {
                    $pizzaOrders = [];

                    foreach ($rows as $r) {
                        $orderId = (string)($r->order_id ?? '');
                        $itemIdS = (string)($r->item_id ?? '');
                        $amt     = (float) ($r->net_amount ?? 0);
                        $name    = (string)($r->menu_item_name ?? '');
                        $qty     = (int)   ($r->quantity ?? 0); // ===== EDIT #1: grab quantity

                        if (isset($relevantIdFlip[$itemIdS])) {
                            $sumByItem[$itemIdS]   = ($sumByItem[$itemIdS]   ?? 0.0) + $amt;
                            $countByItem[$itemIdS] = ($countByItem[$itemIdS] ?? 0)   + $qty; // ===== EDIT #1: sum quantities
                            if (!isset($nameByItem[$itemIdS]) && $name !== '') {
                                $nameByItem[$itemIdS] = $name;
                            }
                            $seenAnywhere[$itemIdS] = true;
                        }

                        // sold-with-pizza section remains as you had it (you said it's good)
                        if ($r->is_pizza) {
                            $pizzaBase++; // keep as row-level base if that's what you want
                            if ($orderId !== '') { $pizzaOrders[$orderId] = true; }
                        }
                    }

                    // sold-with-pizza using the generated boolean columns (unchanged)
                    if (!empty($pizzaOrders)) {
                        foreach ($rows as $r) {
                            $oid = (string)($r->order_id ?? '');
                            if ($oid === '' || !isset($pizzaOrders[$oid])) { continue; }

                            $itemId = (int)($r->item_id ?? 0);

                            if ($r->is_bread)        { $countBread++;  continue; }
                            if (in_array($itemId, $COOKIE_IDS, true)) { $countCookie++; continue; }
                            if ($r->is_caesar_dip)   { $countSauce++;  continue; }
                            if ($r->is_wings)        { $countWings++;  continue; }
                            if ($r->is_beverages)    { $countBev++;    continue; }
                            if ($r->is_crazy_puffs)  { $countPuffs++;  continue; }
                        }
                    }
                }, 'id', 'id');

            // Build groups using the ID sets sourced from flags
            $pizzaRows = $buildRows($PIZZA_IDS,    $sumByItem, $countByItem, $nameByItem, $unitPriceByItem, true);
            $breadRows = $buildRows($BREAD_IDS,    $sumByItem, $countByItem, $nameByItem, $unitPriceByItem, true);
            $wingRows  = $buildRows($WINGS_IDS,    $sumByItem, $countByItem, $nameByItem, $unitPriceByItem, true);
            $puffRows  = $buildRows($PUFFS_IDS,    $sumByItem, $countByItem, $nameByItem, $unitPriceByItem, true);
            $cookRows  = $buildRows($COOKIE_IDS,   $sumByItem, $countByItem, $nameByItem, $unitPriceByItem, true);
            $bevRows   = $buildRows($BEVERAGE_IDS, $sumByItem, $countByItem, $nameByItem, $unitPriceByItem, true);
            $sideRows  = $buildRows($SIDES_IDS,    $sumByItem, $countByItem, $nameByItem, $unitPriceByItem, true);
            $dipRows   = $buildRows($DIP_IDS,      $sumByItem, $countByItem, $nameByItem, $unitPriceByItem, true);

            $overallRows = $buildRows(array_values(array_unique($relevantIds)), $sumByItem, $countByItem, $nameByItem, $unitPriceByItem, true);
            $top15 = \array_slice($overallRows, 0, 15);

            $itemRes['buckets'][$key] = [
                'label'         => $label,
                'pizza_top10'   => \array_slice($pizzaRows, 0, 10),
                'bread_top3'    => \array_slice($breadRows, 0, 3),
                'wings'         => $wingRows,
                'crazy_puffs'   => $puffRows,
                'cookies'       => $cookRows,
                'beverages'     => $bevRows,
                'sides'         => $sideRows,
                'caesar_dips'   => $dipRows,
                'top15_overall' => $top15,
                'all_items_seen'=> [],
            ];

            // sold-with-pizza output (unchanged)
            $den = $pizzaBase ?: 1;
            $soldRes['buckets'][$key] = [
                'label'       => $label,
                'counts'      => [
                    'crazy_bread' => (int)$countBread,
                    'cookies'     => (int)$countCookie,
                    'sauce'       => (int)$countSauce,
                    'wings'       => (int)$countWings,
                    'beverages'   => (int)$countBev,
                    'crazy_puffs' => (int)$countPuffs,
                    'pizza_base'  => (int)$pizzaBase,
                ],
                'percentages' => [
                    'crazy_bread' => $pizzaBase ? $countBread / $den : 0.0,
                    'cookies'     => $pizzaBase ? $countCookie / $den : 0.0,
                    'sauce'       => $pizzaBase ? $countSauce / $den : 0.0,
                    'wings'       => $pizzaBase ? $countWings / $den : 0.0,
                    'beverages'   => $pizzaBase ? $countBev   / $den : 0.0,
                    'crazy_puffs' => $pizzaBase ? $countPuffs / $den : 0.0,
                ],
            ];

            $_bucketRaw[$key] = [$sumByItem, $countByItem, $nameByItem, $unitPriceByItem];
        }

        // ===== EDIT #3: Union + per-bucket zero-filled list uses units_sold and sorts by it
        $unionIds = \array_keys($seenAnywhere);
        \sort($unionIds, \SORT_STRING);
        $allItemsUnion = \array_map('intval', $unionIds);

        foreach (self::BUCKETS as $key => $_) {
            [$sumBy, $countBy, $nameBy, $unitPriceByItem] = $_bucketRaw[$key];

            $rows = [];
            foreach ($unionIds as $id) {
                $rows[] = [
                    'item_id'        => (int)$id,
                    'menu_item_name' => $nameBy[$id] ?? '',
                    'unit_price'     => (float)($unitPriceByItem[$id] ?? 0.0),
                    'total_sales'    => (float)($sumBy[$id]   ?? 0.0),
                    'units_sold'     => (int)  ($countBy[$id] ?? 0), // renamed
                ];
            }

            \usort($rows, static function ($a, $b) {
                $cmp = $b['units_sold'] <=> $a['units_sold']; // sort by units
                return $cmp !== 0 ? $cmp : ($b['total_sales'] <=> $a['total_sales']);
            });

            $itemRes['buckets'][$key]['all_items_seen'] = $rows;
        }

        return [
            'item_breakdown'   => $itemRes,
            'sold_with_pizza'  => $soldRes,
            'all_items_union'  => $allItemsUnion,
        ];
    }

    // ====== Query Builder base (no Eloquent hydration) ======
    private function baseQB(?string $store, string $from, string $to, ?array $placed, ?array $fulfilled)
    {
        // select only columns we truly use, including the generated flags
        $q = DB::table('order_line')
            ->select([
                'id','order_id','item_id','menu_item_name',
                'net_amount','quantity','business_date',
                'is_pizza',
                'is_bread','is_wings','is_beverages','is_crazy_puffs','is_caesar_dip',
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
     * Precompute unit prices for all relevant items once FOR A GIVEN BUCKET.
     */
    private function precomputeUnitPrices(
        ?string $store,
        string $from,
        string $to,
        array $relevantIdStr,
        ?array $placedForPrice,
        ?array $fulfilledForPrice
    ): array {
        $unit = [];

        // Decide which store to use for UNIT PRICE lookup
        $storeForPrice = ($store !== null && $store !== '' && strtolower($store) !== 'all')
            ? $store
            : '03795-00001';

        // Primary: first qualifying row per item
        $q = DB::table('order_line')
            ->select(['id','item_id','net_amount','quantity','business_date'])
            ->whereBetween('business_date', [$from, $to])
            ->where('franchise_store', $storeForPrice)
            ->whereIn('item_id', $relevantIdStr)
            ->where('quantity', '>', 0)
            ->when($placedForPrice, fn ($qq) => $qq->whereIn('order_placed_method', $placedForPrice))
            ->when($fulfilledForPrice, fn ($qq) => $qq->whereIn('order_fulfilled_method', $fulfilledForPrice))
            ->where(function ($qq) {
                $qq->whereNull('bundle_name')->orWhere('bundle_name', '');
            })
            ->where(function ($qq) {
                $qq->whereNull('modification_reason')->orWhere('modification_reason', '');
            });

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

        // Fallback: latest row with same filters if still missing
        $missing = array_values(array_diff($relevantIdStr, array_keys($unit)));
        if (!empty($missing)) {
            $fq = DB::table('order_line')
                ->select(['id','item_id','net_amount','quantity','business_date'])
                ->whereBetween('business_date', [$from, $to])
                ->where('franchise_store', $storeForPrice)
                ->whereIn('item_id', $missing)
                ->where('quantity', '>', 0)
                ->when($placedForPrice, fn ($qq) => $qq->whereIn('order_placed_method', $placedForPrice))
                ->when($fulfilledForPrice, fn ($qq) => $qq->whereIn('order_fulfilled_method', $fulfilledForPrice))
                ->where(function ($qq) {
                    $qq->whereNull('bundle_name')->orWhere('bundle_name', '');
                })
                ->where(function ($qq) {
                    $qq->whereNull('modification_reason')->orWhere('modification_reason', '');
                });

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
