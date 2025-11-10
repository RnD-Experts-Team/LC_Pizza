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

    /** Keep Sides “as they are” */
    private const SIDES_IDS = [206117, 103002];

    /** Cookies unchanged (your sold-with-pizza still counts cookies via generated flag) */
    private const COOKIE_IDS = [101288, 101289];

    /** Caesar dips explicit list (new group) */
    private const CAESAR_DIP_IDS = [
        206117, // Caesar Dip
        206103, // Caesar Dip - Buttery Garlic
        206104, // Caesar Dip - Ranch
        206108, // Caesar Dip - Cheezy Jalapeno
        206101, // Caesar Dip - Buffalo Ranch
    ];

    /**
     * Helper: distinct item_ids for a menu_item_account list
     */
    private function getIdsByAccount(?string $store, string $from, string $to, array $accounts): array
    {
        $q = DB::table('order_line')
            ->distinct()
            ->whereBetween('business_date', [$from, $to])
            ->whereIn('menu_item_account', $accounts)
            ->whereNotNull('item_id');

        if ($store !== null && $store !== '' && strtolower($store) !== 'all') {
            $q->where('franchise_store', $store);
        }

        return $q->pluck('item_id')->map(fn($v) => (int)$v)->unique()->values()->all();
    }

    /**
     * Helper: distinct item_ids for a LIKE pattern on menu_item_name
     */
    private function getIdsByNameLike(?string $store, string $from, string $to, string $needle): array
    {
        $q = DB::table('order_line')
            ->distinct()
            ->whereBetween('business_date', [$from, $to])
            ->where('menu_item_name', 'LIKE', '%'.$needle.'%')
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

        // ===== Build category ID sets from DB (per your new rules) =====
        $PIZZA_IDS       = $this->getIdsByAccount($franchiseStore, $from, $to, ['HNR','Pizza']);
        $BREAD_IDS       = $this->getIdsByAccount($franchiseStore, $from, $to, ['Bread']);
        $WINGS_IDS       = $this->getIdsByAccount($franchiseStore, $from, $to, ['Wings']);
        $BEVERAGE_IDS    = $this->getIdsByAccount($franchiseStore, $from, $to, ['Beverages']);
        $PUFFS_FROM_NAME = $this->getIdsByNameLike($franchiseStore, $from, $to, 'Puffs');
        $CRAZY_PUFFS_IDS = !empty($PUFFS_FROM_NAME) ? $PUFFS_FROM_NAME : [103057, 103044, 103033]; // fallback
        $COOKIE_IDS      = self::COOKIE_IDS;
        $SIDES_IDS       = self::SIDES_IDS;
        $CAESAR_DIP_IDS  = self::CAESAR_DIP_IDS;

        // Everything we care about (used for filtering + unit price precompute)
        $relevantIds = array_values(array_unique(array_merge(
            $PIZZA_IDS, $BREAD_IDS, $WINGS_IDS, $CRAZY_PUFFS_IDS, $COOKIE_IDS, $BEVERAGE_IDS, $SIDES_IDS, $CAESAR_DIP_IDS
        )));
        $relevantIdStr  = array_map('strval', $relevantIds);
        $relevantIdFlip = array_fill_keys($relevantIdStr, true);

        // === the rest of your original method stays the same, except:
        // - replace references to self::XYZ_IDS with the local variables above
        // - add an extra output block for 'caesar_dips'
        // - keep sides unchanged

        // === We’ll accumulate a global “seen anywhere” set while we stream buckets (no extra pass).
        $seenAnywhere = [];

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
                $id = (string)$intId;
                $count = (int)($countByItem[$id] ?? 0);
                if ($filterAppeared && $count === 0) { continue; }

                $rows[] = [
                    'item_id'        => (int)$intId,
                    'menu_item_name' => $nameByItem[$id]  ?? '',
                    'unit_price'     => (float)($unitPriceByItem[$id] ?? 0.0),
                    'total_sales'    => (float)($sumByItem[$id]   ?? 0.0),
                    'entries_count'  => $count,
                ];
            }
            usort($rows, static function ($a, $b) {
                $cmp = $b['entries_count'] <=> $a['entries_count'];
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
            $countByItem = [];
            $nameByItem  = [];

            $countCzb = 0; $countCok = 0; $countSau = 0; $countWin = 0; $pizzaBase = 0;

            $this->baseQB($franchiseStore, $from, $to, $rules['placed'], $rules['fulfilled'])
                ->where(function ($q) use ($relevantIdStr) {
                    $q->whereIn('item_id', $relevantIdStr)
                      ->orWhere('is_pizza', 1)
                      ->orWhere('is_companion_crazy_bread', 1)
                      ->orWhere('is_companion_cookie', 1)
                      ->orWhere('is_companion_sauce', 1)
                      ->orWhere('is_companion_wings', 1);
                })
                ->orderBy('id')
                ->chunkById($isAllStore ? 2000 : 5000, function ($rows) use (
                    &$sumByItem, &$countByItem, &$nameByItem,
                    &$pizzaBase, &$countCzb, &$countCok, &$countSau, &$countWin,
                    $relevantIdFlip, &$seenAnywhere
                ) {
                    $pizzaOrders = [];

                    foreach ($rows as $r) {
                        $orderId = (string)($r->order_id ?? '');
                        $itemIdS = (string)($r->item_id ?? '');
                        $amt     = (float) ($r->net_amount ?? 0);
                        $name    = (string)($r->menu_item_name ?? '');

                        if (isset($relevantIdFlip[$itemIdS])) {
                            $sumByItem[$itemIdS]   = ($sumByItem[$itemIdS]   ?? 0.0) + $amt;
                            $countByItem[$itemIdS] = ($countByItem[$itemIdS] ?? 0) + 1;
                            if (!isset($nameByItem[$itemIdS]) && $name !== '') {
                                $nameByItem[$itemIdS] = $name;
                            }
                            $seenAnywhere[$itemIdS] = true;
                        }

                        if ($r->is_pizza) {
                            $pizzaBase++;
                            if ($orderId !== '') { $pizzaOrders[$orderId] = true; }
                        }
                    }

                    if (!empty($pizzaOrders)) {
                        foreach ($rows as $r) {
                            $oid = (string)($r->order_id ?? '');
                            if ($oid === '' || !isset($pizzaOrders[$oid])) { continue; }
                            if     ($r->is_companion_crazy_bread) { $countCzb++; }
                            elseif ($r->is_companion_cookie)      { $countCok++; }
                            elseif ($r->is_companion_sauce)       { $countSau++; }
                            elseif ($r->is_companion_wings)       { $countWin++; }
                        }
                    }
                }, 'id', 'id');

            // Build groups with new ID sets
            $pizzaRows = $buildRows($PIZZA_IDS,       $sumByItem, $countByItem, $nameByItem, $unitPriceByItem, true);
            $breadRows = $buildRows($BREAD_IDS,       $sumByItem, $countByItem, $nameByItem, $unitPriceByItem, true);
            $wingRows  = $buildRows($WINGS_IDS,       $sumByItem, $countByItem, $nameByItem, $unitPriceByItem, true);
            $puffRows  = $buildRows($CRAZY_PUFFS_IDS, $sumByItem, $countByItem, $nameByItem, $unitPriceByItem, true);
            $cookRows  = $buildRows($COOKIE_IDS,      $sumByItem, $countByItem, $nameByItem, $unitPriceByItem, true);
            $bevRows   = $buildRows($BEVERAGE_IDS,    $sumByItem, $countByItem, $nameByItem, $unitPriceByItem, true);
            $sideRows  = $buildRows($SIDES_IDS,       $sumByItem, $countByItem, $nameByItem, $unitPriceByItem, true);
            $dipRows   = $buildRows($CAESAR_DIP_IDS,  $sumByItem, $countByItem, $nameByItem, $unitPriceByItem, true);

            $overallRows = $buildRows($relevantIds, $sumByItem, $countByItem, $nameByItem, $unitPriceByItem, true);
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
                'caesar_dips'   => $dipRows,   // << NEW GROUP
                'top15_overall' => $top15,
                'all_items_seen'=> [],         // filled later
            ];

            // sold-with-pizza stays the same
            $den = $pizzaBase ?: 1;
            $soldRes['buckets'][$key] = [
                'label'       => $label,
                'counts'      => [
                    'crazy_bread' => (int)$countCzb,
                    'cookies'     => (int)$countCok,
                    'sauce'       => (int)$countSau,
                    'wings'       => (int)$countWin,
                    'pizza_base'  => (int)$pizzaBase,
                ],
                'percentages' => [
                    'crazy_bread' => $pizzaBase ? $countCzb / $den : 0.0,
                    'cookies'     => $pizzaBase ? $countCok / $den : 0.0,
                    'sauce'       => $pizzaBase ? $countSau / $den : 0.0,
                    'wings'       => $pizzaBase ? $countWin / $den : 0.0,
                ],
            ];

            $_bucketRaw[$key] = [$sumByItem, $countByItem, $nameByItem, $unitPriceByItem];
        }

        // Union + per-bucket zero-filled list (unchanged)
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
                    'entries_count'  => (int)  ($countBy[$id] ?? 0),
                ];
            }

            \usort($rows, static function ($a, $b) {
                $cmp = $b['entries_count'] <=> $a['entries_count'];
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
     * Precompute unit prices for all relevant items once FOR A GIVEN BUCKET.
     *
     * If a store is provided (and not "all"), use that store.
     * Otherwise, force store = "03795-00001".
     *
     * Rule for the bucket:
     *   - placed in $placedForPrice (if not null)
     *   - fulfilled in $fulfilledForPrice (if not null)
     *   - bundle_name IS NULL/'' AND modification_reason IS NULL/''
     *   - quantity > 0
     *
     * Primary: first (business_date ASC, then id ASC)
     * Fallback: latest (business_date DESC, then id DESC) with same filters
     *
     * @param string|null  $store
     * @param string       $from
     * @param string       $to
     * @param string[]     $relevantIdStr
     * @param string[]|null $placedForPrice
     * @param string[]|null $fulfilledForPrice
     * @return array<string,float>  [item_id_string => unit_price]
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

        // ---------- Primary: first qualifying row per item ----------
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

        // ---------- Fallback: latest row with SAME filters if still missing ----------
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
