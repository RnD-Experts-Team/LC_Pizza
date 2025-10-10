<?php

namespace App\Services\Reports;

use App\Models\OrderLine;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

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

    private const CHUNK_SIZE = 5000;

    public function compute(?string $franchiseStore, $fromDate, $toDate): array
    {
        $from = $fromDate instanceof Carbon ? $fromDate->toDateString() : Carbon::parse($fromDate)->toDateString();
        $to   = $toDate   instanceof Carbon ? $toDate->toDateString()   : Carbon::parse($toDate)->toDateString();

        $itemRes = ['buckets' => []];
        $soldRes = ['buckets' => []];

        foreach (self::BUCKETS as $key => $rules) {
            $label = $rules['label'];

            // Build the only item_ids we care about (for totals)
            $relevantIds = array_values(array_unique(array_merge(
                self::PIZZA_IDS, self::BREAD_IDS, self::WINGS_IDS, self::CRAZY_PUFFS_IDS,
                self::COOKIE_IDS, self::BEVERAGE_IDS, self::SIDES_IDS
            )));
            $relevantIdStr = array_map('strval', $relevantIds);

            // Accumulators (tiny hash maps)
            $sumByItem   = [];
            $countByItem = [];
            $nameByItem  = [];
            $priceByItem = [];

            $countCzb = 0;
            $countCok = 0;
            $countSau = 0;
            $countWin = 0;
            $pizzaBase = 0;

            // Stream rows: only those that matter for either item totals OR sold-with
            $this->baseQuery($franchiseStore, $from, $to, $rules['placed'], $rules['fulfilled'])
                ->where(function (Builder $q) use ($relevantIdStr) {
                    $q->whereIn('item_id', $relevantIdStr) // for item breakdown
                      ->orWhere('is_pizza', 1)              // for pizza base + with-pizza detection
                      ->orWhere('is_companion_crazy_bread', 1)
                      ->orWhere('is_companion_cookie', 1)
                      ->orWhere('is_companion_sauce', 1)
                      ->orWhere('is_companion_wings', 1);
                })
                ->orderBy('id')
                ->chunkById(self::CHUNK_SIZE, function ($rows) use (
                    &$sumByItem, &$countByItem, &$nameByItem, &$priceByItem,
                    &$pizzaBase, &$countCzb, &$countCok, &$countSau, &$countWin
                ) {
                    // Group within chunk by order to test “has pizza?” once
                    $byOrder = [];
                    foreach ($rows as $r) {
                        $byOrder[(string)$r->order_id][] = $r;
                    }

                    foreach ($byOrder as $lines) {
                        $hasPizza = false;

                        // First pass: item totals + pizza base
                        foreach ($lines as $r) {
                            $itemId = (string) $r->item_id;
                            $qty    = (float) ($r->quantity ?? 0);
                            $amt    = (float) ($r->net_amount ?? 0);
                            $name   = (string) ($r->menu_item_name ?? '');

                            // Item breakdown totals (only when we fetched by relevant IDs; others won’t be here)
                            if ($itemId !== '') {
                                $sumByItem[$itemId]   = ($sumByItem[$itemId]   ?? 0.0) + $amt;
                                $countByItem[$itemId] = ($countByItem[$itemId] ?? 0) + 1;
                                if ($qty !== 0.0) { $priceByItem[$itemId] = $amt / $qty; }
                                if ($name !== '' && !isset($nameByItem[$itemId])) { $nameByItem[$itemId] = $name; }
                            }

                            // Pizza base entry?
                            if ($r->is_pizza) {
                                $pizzaBase++;
                                $hasPizza = true;
                            }
                        }

                        // Second pass: companions only if order had any pizza
                        if ($hasPizza) {
                            foreach ($lines as $r) {
                                if ($r->is_companion_crazy_bread) { $countCzb++; }
                                elseif ($r->is_companion_cookie)  { $countCok++; }
                                elseif ($r->is_companion_sauce)   { $countSau++; }
                                elseif ($r->is_companion_wings)   { $countWin++; }
                            }
                        }
                    }
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

            $itemRes['buckets'][$key] = [
                'label'       => $label,
                'pizza_top10' => array_slice($buildRows(self::PIZZA_IDS), 0, 10),
                'bread_top3'  => array_slice($buildRows(self::BREAD_IDS), 0, 3),
                'wings'       => $buildRows(self::WINGS_IDS),
                'crazy_puffs' => $buildRows(self::CRAZY_PUFFS_IDS),
                'cookie'      => $buildRows(self::COOKIE_IDS),
                'beverage'    => $buildRows(self::BEVERAGE_IDS),
                'sides'       => $buildRows(self::SIDES_IDS),
            ];

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

    private function baseQuery(?string $store, string $from, string $to, ?array $placed, ?array $fulfilled): Builder
    {
        $q = OrderLine::query()
            ->whereBetween('business_date', [$from, $to]);

        $this->applyStore($q, $store);

        if ($placed)    { $q->whereIn('order_placed_method', $placed); }
        if ($fulfilled) { $q->whereIn('order_fulfilled_method', $fulfilled); }

        // Only the columns we need — keeps I/O tiny.
        return $q->select([
            'id','order_id','item_id','menu_item_name','net_amount','quantity',
            'is_pizza','is_companion_crazy_bread','is_companion_cookie','is_companion_sauce','is_companion_wings',
        ]);
    }

        /**
     * Apply store filter only if a specific store was provided.
     * Accepts null, empty string, or "All" to mean "no store filter".
     */
    protected function applyStore(Builder $q, ?string $store): Builder
    {
        if ($store !== null && $store !== '' && strtolower($store) !== 'all') {
            $q->where('franchise_store', $store);
        }
        return $q;
    }
}
