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
    // @phpstan-ignore-next-line
    if (function_exists('set_time_limit')) { @set_time_limit(0); }

    $from = $fromDate instanceof Carbon ? $fromDate->toDateString() : Carbon::parse($fromDate)->toDateString();
    $to   = $toDate   instanceof Carbon ? $toDate->toDateString()   : Carbon::parse($toDate)->toDateString();

    $itemRes = ['buckets' => []];
    $soldRes = ['buckets' => []];

    $isAllStore = ($franchiseStore === null || $franchiseStore === '' || strtolower($franchiseStore) === 'all');
    $chunkSize  = $isAllStore ? 2000 : 5000;

    // Relevant IDs, string set for fast checks
    $relevantIds = array_values(array_unique(array_merge(
        self::PIZZA_IDS, self::BREAD_IDS, self::WINGS_IDS, self::CRAZY_PUFFS_IDS,
        self::COOKIE_IDS, self::BEVERAGE_IDS, self::SIDES_IDS
    )));
    $relevantIdStr  = array_map('strval', $relevantIds);
    $relevantIdFlip = array_fill_keys($relevantIdStr, true);

    // Precompute unit prices ONCE (same map reused in all buckets)
    $unitPriceByItem = $this->precomputeUnitPrices($franchiseStore, $from, $to, $relevantIdStr);

    // Small helpers local to this method
    $buildRows = function (array $ids, array $sumByItem, array $countByItem, array $nameByItem) use ($unitPriceByItem): array {
        $rows = [];
        foreach ($ids as $intId) {
            $id = (string) $intId;
            $rows[] = [
                'item_id'        => (int) $intId,
                'menu_item_name' => $nameByItem[$id]  ?? '',
                'unit_price'     => (float) ($unitPriceByItem[$id] ?? 0.0),
                'total_sales'    => (float) ($sumByItem[$id]   ?? 0.0),
                'entries_count'  => (int)   ($countByItem[$id] ?? 0),
            ];
        }
        usort($rows, fn($a,$b) => $b['total_sales'] <=> $a['total_sales']);
        return $rows;
    };

    $rowsToMap = function (array $rows): array {
        $m = [];
        foreach ($rows as $r) { $m[(string)$r['item_id']] = $r; }
        return $m;
    };

    $sumGroup = function (array $bucketMaps, array $ids) use ($unitPriceByItem): array {
        $sum = []; $cnt = []; $name = [];
        foreach (['in_store','lc_pickup','lc_delivery','third_party'] as $k) {
            if (!isset($bucketMaps[$k])) continue;
            $map = $bucketMaps[$k];
            foreach ($ids as $intId) {
                $rid = (string)$intId;
                if (!isset($map[$rid])) continue;
                $row = $map[$rid];
                $sum[$rid]  = ($sum[$rid] ?? 0.0) + (float)$row['total_sales'];
                $cnt[$rid]  = ($cnt[$rid] ?? 0)   + (int)$row['entries_count'];
                if (($name[$rid] ?? '') === '' && ($row['menu_item_name'] ?? '') !== '') {
                    $name[$rid] = $row['menu_item_name'];
                }
            }
        }
        $out = [];
        foreach ($ids as $intId) {
            $rid = (string)$intId;
            $out[] = [
                'item_id'        => (int)$intId,
                'menu_item_name' => $name[$rid] ?? '',
                'unit_price'     => (float)($unitPriceByItem[$rid] ?? 0.0),
                'total_sales'    => (float)($sum[$rid] ?? 0.0),
                'entries_count'  => (int)  ($cnt[$rid] ?? 0),
            ];
        }
        usort($out, fn($a,$b) => $b['total_sales'] <=> $a['total_sales']);
        return $out;
    };

    // We’ll store FULL, unsliced row lists per bucket so we can sum them for "all".
    $fullListsPerBucket = [];  // [bucketKey => [groupName => fullRows]]
    $mapsPerBucket      = [];  // [bucketKey => [item_id => row]]  (merged across groups)

    foreach (self::BUCKETS as $key => $rules) {
        if ($key === 'all') {
            // Skip building "all" here; we will synthesize it by summing the 4 real buckets below.
            continue;
        }

        $label = $rules['label'];

        // Accumulators for this bucket
        $sumByItem   = [];  // [item_id_string => float]
        $countByItem = [];  // [item_id_string => int]
        $nameByItem  = [];  // [item_id_string => string]

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
            ->chunkById($chunkSize, function ($rows) use (
                &$sumByItem, &$countByItem, &$nameByItem,
                &$pizzaBase, &$countCzb, &$countCok, &$countSau, &$countWin,
                $relevantIdFlip
            ) {
                $pizzaOrders = []; // per-chunk
                foreach ($rows as $r) {
                    $orderId = (string) ($r->order_id ?? '');
                    $itemId  = (string) ($r->item_id ?? '');
                    $amt     = (float)  ($r->net_amount ?? 0);
                    $name    = (string) ($r->menu_item_name ?? '');

                    if (isset($relevantIdFlip[$itemId])) {
                        $sumByItem[$itemId]   = ($sumByItem[$itemId]   ?? 0.0) + $amt;
                        $countByItem[$itemId] = ($countByItem[$itemId] ?? 0) + 1;
                        if (!isset($nameByItem[$itemId]) && $name !== '') {
                            $nameByItem[$itemId] = $name;
                        }
                    }

                    if ($r->is_pizza) {
                        $pizzaBase++;
                        if ($orderId !== '') { $pizzaOrders[$orderId] = true; }
                    }
                }

                if (!empty($pizzaOrders)) {
                    foreach ($rows as $r) {
                        $orderId = (string) ($r->order_id ?? '');
                        if ($orderId === '' || !isset($pizzaOrders[$orderId])) { continue; }
                        if ($r->is_companion_crazy_bread) { $countCzb++; }
                        elseif ($r->is_companion_cookie)  { $countCok++; }
                        elseif ($r->is_companion_sauce)   { $countSau++; }
                        elseif ($r->is_companion_wings)   { $countWin++; }
                    }
                }
            }, 'id', 'id');

        // ---------- FULL, unsliced lists for this bucket ----------
        $fullPizza     = $buildRows(self::PIZZA_IDS,     $sumByItem, $countByItem, $nameByItem);
        $fullBread     = $buildRows(self::BREAD_IDS,     $sumByItem, $countByItem, $nameByItem);
        $fullWings     = $buildRows(self::WINGS_IDS,     $sumByItem, $countByItem, $nameByItem);
        $fullCrazy     = $buildRows(self::CRAZY_PUFFS_IDS,$sumByItem, $countByItem, $nameByItem);
        $fullCookies   = $buildRows(self::COOKIE_IDS,    $sumByItem, $countByItem, $nameByItem);
        $fullBeverages = $buildRows(self::BEVERAGE_IDS,  $sumByItem, $countByItem, $nameByItem);
        $fullSides     = $buildRows(self::SIDES_IDS,     $sumByItem, $countByItem, $nameByItem);

        $fullListsPerBucket[$key] = [
            'pizza'     => $fullPizza,
            'bread'     => $fullBread,
            'wings'     => $fullWings,
            'crazy'     => $fullCrazy,
            'cookies'   => $fullCookies,
            'beverages' => $fullBeverages,
            'sides'     => $fullSides,
        ];

        // Single merged map per bucket (by item_id) to make summing trivial
        $mapsPerBucket[$key] = array_replace(
            $rowsToMap($fullPizza),
            $rowsToMap($fullBread),
            $rowsToMap($fullWings),
            $rowsToMap($fullCrazy),
            $rowsToMap($fullCookies),
            $rowsToMap($fullBeverages),
            $rowsToMap($fullSides),
        );

        // ---------- Emit sliced payload for this bucket (unchanged shape) ----------
        $itemRes['buckets'][$key] = [
            'label'        => $label,
            'pizza_top10'  => array_slice($fullPizza, 0, 10),
            'bread_top3'   => array_slice($fullBread, 0, 3),
            'wings'        => $fullWings,
            'crazy_puffs'  => $fullCrazy,
            'cookies'      => $fullCookies,
            'beverages'    => $fullBeverages,
            'sides'        => $fullSides,
        ];

        // Sold-with (unchanged)(note: chunk-local pizza detection can miss cross-chunk pairs; see note below)
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

    // ===== Synthesize "ALL" by summing the 4 bucket FULL lists, then slice =====
    // This makes "All" == sum of the four buckets FOR EVERY item_id.
    $labelAll = self::BUCKETS['all']['label'] ?? 'All Buckets';

    $allPizza     = $sumGroup($mapsPerBucket, self::PIZZA_IDS);
    $allBread     = $sumGroup($mapsPerBucket, self::BREAD_IDS);
    $allWings     = $sumGroup($mapsPerBucket, self::WINGS_IDS);
    $allCrazy     = $sumGroup($mapsPerBucket, self::CRAZY_PUFFS_IDS);
    $allCookies   = $sumGroup($mapsPerBucket, self::COOKIE_IDS);
    $allBeverages = $sumGroup($mapsPerBucket, self::BEVERAGE_IDS);
    $allSides     = $sumGroup($mapsPerBucket, self::SIDES_IDS);

    $itemRes['buckets']['all'] = [
        'label'        => $labelAll,
        'pizza_top10'  => array_slice($allPizza, 0, 10),
        'bread_top3'   => array_slice($allBread, 0, 3),
        'wings'        => $allWings,
        'crazy_puffs'  => $allCrazy,
        'cookies'      => $allCookies,
        'beverages'    => $allBeverages,
        'sides'        => $allSides,
    ];

    // Sold-with for "all" (sum counts; recompute % from summed pizza_base)
    $sumCounts = ['crazy_bread'=>0,'cookies'=>0,'sauce'=>0,'wings'=>0,'pizza_base'=>0];
    foreach (['in_store','lc_pickup','lc_delivery','third_party'] as $k) {
        if (!isset($soldRes['buckets'][$k])) continue;
        foreach ($sumCounts as $m => $_) {
            $sumCounts[$m] += (int) ($soldRes['buckets'][$k]['counts'][$m] ?? 0);
        }
    }
    $denAll = $sumCounts['pizza_base'] ?: 1;
    $soldRes['buckets']['all'] = [
        'label'       => $labelAll,
        'counts'      => $sumCounts,
        'percentages' => [
            'crazy_bread' => $sumCounts['pizza_base'] ? $sumCounts['crazy_bread'] / $denAll : 0.0,
            'cookies'     => $sumCounts['pizza_base'] ? $sumCounts['cookies']     / $denAll : 0.0,
            'sauce'       => $sumCounts['pizza_base'] ? $sumCounts['sauce']       / $denAll : 0.0,
            'wings'       => $sumCounts['pizza_base'] ? $sumCounts['wings']       / $denAll : 0.0,
        ],
    ];

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
