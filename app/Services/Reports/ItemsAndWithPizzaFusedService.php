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

    private const BEV_20OZ_ID = 204100;
    private const BEV_2L_ID   = 204200;
    private const ICB_ID      = 203003; // Italian Cheese Bread
    /**
     * Helper: distinct item_ids where a boolean flag column = 1
     */
    private function getIdsByFlag(
    ?string $store,
    string $from,
    string $to,
    string $flagCol,
    bool $withoutBundle = false   // <--- NEW
): array {
    $q = DB::table('order_line')
        ->distinct()
        ->whereBetween('business_date', [$from, $to])
        ->where($flagCol, 1)
        ->whereNotNull('item_id');

    if ($store !== null && $store !== '' && strtolower($store) !== 'all') {
        $q->where('franchise_store', $store);
    }

    if ($withoutBundle) {
        $q->where(function ($qq) {
            $qq->whereNull('bundle_name')->orWhere('bundle_name', '');
        })->where(function ($qq) {
            $qq->whereNull('modification_reason')->orWhere('modification_reason', '');
        });
    }

    return $q->pluck('item_id')->map(fn($v) => (int)$v)->unique()->values()->all();
}

    // ===== Public API =====
public function compute(?string $franchiseStore, $fromDate, $toDate, bool $withoutBundle = false): array
    {
        if (function_exists('set_time_limit')) { @set_time_limit(0); }

        $from = $fromDate instanceof Carbon ? $fromDate->toDateString() : Carbon::parse($fromDate)->toDateString();
        $to   = $toDate   instanceof Carbon ? $toDate->toDateString()   : Carbon::parse($toDate)->toDateString();

        $itemRes = ['buckets' => []];
        $soldRes = ['buckets' => []];

        $isAllStore = ($franchiseStore === null || $franchiseStore === '' || strtolower($franchiseStore) === 'all');
        $chunkSize  = $isAllStore ? 2000 : 5000;

// ===== Build category ID sets DIRECTLY from generated flags =====
$PIZZA_IDS    = $this->getIdsByFlag($franchiseStore, $from, $to, 'is_pizza',      $withoutBundle);
$BREAD_IDS    = $this->getIdsByFlag($franchiseStore, $from, $to, 'is_bread',      $withoutBundle);
$WINGS_IDS    = $this->getIdsByFlag($franchiseStore, $from, $to, 'is_wings',      $withoutBundle);
$BEVERAGE_IDS = $this->getIdsByFlag($franchiseStore, $from, $to, 'is_beverages',  $withoutBundle);
$PUFFS_IDS    = $this->getIdsByFlag($franchiseStore, $from, $to, 'is_crazy_puffs',$withoutBundle);
$DIP_IDS      = $this->getIdsByFlag($franchiseStore, $from, $to, 'is_caesar_dip', $withoutBundle);

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

        // ===== rows builder using UNITS (quantities) =====
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
                    'units_sold'     => $units,
                ];
            }
            usort($rows, static function ($a, $b) {
                $cmp = $b['units_sold'] <=> $a['units_sold'];
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
            $countByItem = []; // stores UNITS (sum of quantities)
            $nameByItem  = [];

            // ====== SOLD-WITH (UNITS) ======
            $unitsBread  = 0; $unitsCookie = 0; $unitsSauce = 0; $unitsWings = 0; $unitsBev = 0; $unitsPuffs = 0;
            $unitsBev20oz = 0; $unitsBev2L = 0; $unitsICB = 0;
            $pizzaUnitsBase = 0; 

$this->baseQB($franchiseStore, $from, $to, $rules['placed'], $rules['fulfilled'], $withoutBundle)
                ->where(function ($q) use ($relevantIdStr) {
                    $q->whereIn('item_id', $relevantIdStr)
                      ->orWhere('is_pizza', 1);
                })
                ->orderBy('id')
                ->chunkById($chunkSize, function ($rows) use (
                    &$sumByItem, &$countByItem, &$nameByItem,
                    &$pizzaUnitsBase, &$unitsBread, &$unitsCookie, &$unitsSauce, &$unitsWings, &$unitsBev, &$unitsPuffs, &$unitsBev20oz, &$unitsBev2L, &$unitsICB,
                    $relevantIdFlip, &$seenAnywhere, $COOKIE_IDS
                ) {
                    $pizzaOrders = [];

                    foreach ($rows as $r) {
                        $orderId = (string)($r->order_id ?? '');
                        $itemIdS = (string)($r->item_id ?? '');
                        $amt     = (float) ($r->net_amount ?? 0);
                        $name    = (string)($r->menu_item_name ?? '');
                        $qty     = (int)   ($r->quantity ?? 0);

                        if (isset($relevantIdFlip[$itemIdS])) {
                            $sumByItem[$itemIdS]   = ($sumByItem[$itemIdS]   ?? 0.0) + $amt;
                            $countByItem[$itemIdS] = ($countByItem[$itemIdS] ?? 0)   + $qty;
                            if (!isset($nameByItem[$itemIdS]) && $name !== '') {
                                $nameByItem[$itemIdS] = $name;
                            }
                            $seenAnywhere[$itemIdS] = true;
                        }

                        // pizza base by UNITS
                        if ($r->is_pizza) {
                            $pizzaUnitsBase += $qty;
                            if ($orderId !== '') { $pizzaOrders[$orderId] = true; }
                        }
                    }

                    // sold-with-pizza using generated flags; sum UNITS
                    if (!empty($pizzaOrders)) {
                        foreach ($rows as $r) {
                            $oid = (string)($r->order_id ?? '');
                            if ($oid === '' || !isset($pizzaOrders[$oid])) { continue; }

                            $itemId = (int)($r->item_id ?? 0);
                            $qty    = (int)($r->quantity ?? 0);
                            if ($qty <= 0) { continue; }

                            if ($itemId === self::BEV_20OZ_ID) { $unitsBev20oz += $qty; }
                            if ($itemId === self::BEV_2L_ID)   { $unitsBev2L   += $qty; }
                            if ($itemId === self::ICB_ID)      { $unitsICB     += $qty; }

                            if ($r->is_bread)                         { $unitsBread  += $qty; continue; }
                            if (in_array($itemId, $COOKIE_IDS, true)) { $unitsCookie += $qty; continue; }
                            if ($r->is_caesar_dip)                    { $unitsSauce  += $qty; continue; }
                            if ($r->is_wings)                         { $unitsWings  += $qty; continue; }
                            if ($r->is_beverages)                     { $unitsBev    += $qty; continue; }
                            if ($r->is_crazy_puffs)                   { $unitsPuffs  += $qty; continue; }
                        }
                    }
                }, 'id', 'id');

            // Build groups from flags
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

            // ===== SOLD-WITH OUTPUT (UNITS ONLY) =====
            $den = $pizzaUnitsBase ?: 1;
            $soldRes['buckets'][$key] = [
                'label'  => $label,
                'units'  => [
                    'crazy_bread' => (int)$unitsBread,
                    'cookies'     => (int)$unitsCookie,
                    'sauce'       => (int)$unitsSauce,
                    'wings'       => (int)$unitsWings,
                    'beverages'   => (int)$unitsBev,
                    'crazy_puffs' => (int)$unitsPuffs,
                    'bev_20oz'             => (int)$unitsBev20oz,
                    'bev_2l'               => (int)$unitsBev2L,
                    'italian_cheese_bread' => (int)$unitsICB,
                    'pizza_base'  => (int)$pizzaUnitsBase,
                ],
                'percentages' => [
                    'crazy_bread' => $pizzaUnitsBase ? $unitsBread / $den : 0.0,
                    'cookies'     => $pizzaUnitsBase ? $unitsCookie / $den : 0.0,
                    'sauce'       => $pizzaUnitsBase ? $unitsSauce / $den : 0.0,
                    'wings'       => $pizzaUnitsBase ? $unitsWings / $den : 0.0,
                    'beverages'   => $pizzaUnitsBase ? $unitsBev   / $den : 0.0,
                    'crazy_puffs' => $pizzaUnitsBase ? $unitsPuffs / $den : 0.0,
                    'bev_20oz'             => $pizzaUnitsBase ? $unitsBev20oz / $den : 0.0,
                    'bev_2l'               => $pizzaUnitsBase ? $unitsBev2L   / $den : 0.0,
                    'italian_cheese_bread' => $pizzaUnitsBase ? $unitsICB     / $den : 0.0,
                ],
            ];

            $_bucketRaw[$key] = [$sumByItem, $countByItem, $nameByItem, $unitPriceByItem];
        }

        // ===== Union + per-bucket zero-filled list uses units_sold and sorts by it
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
                    'units_sold'     => (int)  ($countBy[$id] ?? 0),
                ];
            }

            \usort($rows, static function ($a, $b) {
                $cmp = $b['units_sold'] <=> $a['units_sold'];
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
    private function baseQB(
    ?string $store,
    string $from,
    string $to,
    ?array $placed,
    ?array $fulfilled,
    bool $withoutBundle = false  // <--- NEW
) {
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

    if ($withoutBundle) {
        $q->where(function ($qq) {
            $qq->whereNull('bundle_name')->orWhere('bundle_name', '');
        })->where(function ($qq) {
            $qq->whereNull('modification_reason')->orWhere('modification_reason', '');
        });
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

/**
 * Detailed sold-with-pizza (UNITS only), per store.
 *
 * Standalone rules:
 * - DOES NOT use getIdsByFlag or compute() ID sets.
 * - NO without-bundle filtering at all.
 * - Default bucket = in_store unless overridden.
 * - If $franchiseStore is null/""/"all": returns each store separately.
 * - If specific store: returns only that store payload.
 *
 * FIX:
 * - Do NOT use baseQB() for GROUP BY queries because it selects '*'/id/order_id.
 * - Build fresh builders for aggregates to satisfy only_full_group_by.
 */
public function soldWithPizzaDetailsStandalone(
    ?string $franchiseStore,
    $fromDate,
    $toDate,
    string $bucketKey = 'in_store'
): array {
    if (function_exists('set_time_limit')) { @set_time_limit(0); }

    $from = $fromDate instanceof Carbon
        ? $fromDate->toDateString()
        : Carbon::parse($fromDate)->toDateString();

    $to = $toDate instanceof Carbon
        ? $toDate->toDateString()
        : Carbon::parse($toDate)->toDateString();

    // -------------------------
    // Local buckets (standalone)
    // -------------------------
    $BUCKETS_LOCAL = [
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

    $bucketKey = strtolower($bucketKey);
    $rules = $BUCKETS_LOCAL[$bucketKey] ?? $BUCKETS_LOCAL['in_store'];

    // -------------------------
    // Explicit IDs ONLY
    // -------------------------
    $COOKIE_IDS  = [101288, 101289];
    $CRAZY_SAUCE = 206117;
    $BEV_2L_IDS  = [204200, 204234];

    // -------------------------
    // Helper to apply filters to a naked builder
    // (no select list inside)
    // -------------------------
    $applyFilters = function ($q) use ($franchiseStore, $from, $to, $rules) {
        $q->whereBetween('business_date', [$from, $to]);

        if ($franchiseStore !== null && $franchiseStore !== '' && strtolower($franchiseStore) !== 'all') {
            $q->where('franchise_store', $franchiseStore);
        }
        if (!empty($rules['placed'])) {
            $q->whereIn('order_placed_method', $rules['placed']);
        }
        if (!empty($rules['fulfilled'])) {
            $q->whereIn('order_fulfilled_method', $rules['fulfilled']);
        }

        return $q;
    };

    // -------------------------
    // 1) Pizza orders subquery
    // -------------------------
    $pizzaOrdersSub = $applyFilters(DB::table('order_line'))
        ->where('is_pizza', 1)
        ->select('order_id')
        ->distinct();

    // -------------------------
    // 2) Sold-with aggregation (UNITS only)
    // Fresh builder -> NO select *
    // -------------------------
    $soldWithRows = $applyFilters(DB::table('order_line'))
        ->whereIn('order_id', $pizzaOrdersSub)
        ->where(function ($q) use ($COOKIE_IDS, $CRAZY_SAUCE, $BEV_2L_IDS) {
            $q
              ->where('is_bread', 1)
              ->orWhereIn('item_id', $COOKIE_IDS)
              ->orWhere('item_id', $CRAZY_SAUCE)
              ->orWhere('is_wings', 1)
              ->orWhereIn('item_id', $BEV_2L_IDS);
        })
        ->selectRaw('
            franchise_store,
            item_id,
            COALESCE(menu_item_name, "") as menu_item_name,
            SUM(quantity) as units_sold,
            MAX(is_bread) as is_bread,
            MAX(is_wings) as is_wings
        ')
        ->groupBy('franchise_store', 'item_id', 'menu_item_name')
        ->orderBy('franchise_store')
        ->orderByDesc('units_sold')
        ->get();

    // -------------------------
    // 3) Pizza base units per store
    // Fresh builder -> NO select *
    // -------------------------
    $pizzaBaseByStore = $applyFilters(DB::table('order_line'))
        ->where('is_pizza', 1)
        ->selectRaw('franchise_store, SUM(quantity) as pizza_units')
        ->groupBy('franchise_store')
        ->pluck('pizza_units', 'franchise_store');

    // -------------------------
    // 4) Shape output
    // -------------------------
    $byStore = [];

    foreach ($soldWithRows as $r) {
        $st = (string) $r->franchise_store;

        if (!isset($byStore[$st])) {
            $byStore[$st] = [
                'store'      => $st,
                'bucket'     => $bucketKey,
                'from'       => $from,
                'to'         => $to,
                'pizza_base' => (int)($pizzaBaseByStore[$st] ?? 0),
                'sold_with'  => [
                    'crazy_bread' => [],
                    'cookies'     => [],
                    'crazy_sauce' => [],
                    'wings'       => [],
                    'bev_2l'      => [],
                ],
            ];
        }

        $itemId = (int) $r->item_id;
        $units  = (int) $r->units_sold;
        $name   = (string) $r->menu_item_name;

        if ($itemId === $CRAZY_SAUCE) {
            $byStore[$st]['sold_with']['crazy_sauce'][] = [
                'item_id' => $itemId, 'name' => $name, 'units' => $units
            ];
            continue;
        }

        if (in_array($itemId, $COOKIE_IDS, true)) {
            $byStore[$st]['sold_with']['cookies'][] = [
                'item_id' => $itemId, 'name' => $name, 'units' => $units
            ];
            continue;
        }

        if (in_array($itemId, $BEV_2L_IDS, true)) {
            $byStore[$st]['sold_with']['bev_2l'][] = [
                'item_id' => $itemId, 'name' => $name, 'units' => $units
            ];
            continue;
        }

        if ((int)$r->is_bread === 1) {
            $byStore[$st]['sold_with']['crazy_bread'][] = [
                'item_id' => $itemId, 'name' => $name, 'units' => $units
            ];
            continue;
        }

        if ((int)$r->is_wings === 1) {
            $byStore[$st]['sold_with']['wings'][] = [
                'item_id' => $itemId, 'name' => $name, 'units' => $units
            ];
            continue;
        }
    }

    // specific store => single payload
    $isSpecificStore =
        ($franchiseStore !== null && $franchiseStore !== '' && strtolower($franchiseStore) !== 'all');

    if ($isSpecificStore) {
        $st = (string) $franchiseStore;

        return $byStore[$st] ?? [
            'store'      => $st,
            'bucket'     => $bucketKey,
            'from'       => $from,
            'to'         => $to,
            'pizza_base' => (int)($pizzaBaseByStore[$st] ?? 0),
            'sold_with'  => [
                'crazy_bread' => [],
                'cookies'     => [],
                'crazy_sauce' => [],
                'wings'       => [],
                'bev_2l'      => [],
            ],
        ];
    }

    return array_values($byStore);
}

}
