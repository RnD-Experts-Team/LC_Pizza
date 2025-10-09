<?php

namespace App\Services\Reports;

use App\Models\OrderLine;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;              // Eloquent Builder (outer)
use Illuminate\Database\Query\Builder as QueryBuilder; // Query Builder (exists subquery)

/**
 * SoldWithPizzaService
 *
 * For each bucket (In Store, LC Pickup, LC Delivery, 3rd Party) + All:
 *  1) Crazy Bread sold with pizza (count)
 *  2) Cookies sold with pizza (count)
 *  3) Crazy Sauce sold with pizza (count)
 *  4) Caesar Wings sold with pizza (count)
 *  5) Pizza base count (entries that are pizza by name or by item_id list)
 *  And percentages for items (1..4) relative to (5).
 *
 * All queries are strictly Eloquent/Builder (no raw SQL).
 */
class SoldWithPizzaService
{
    /** Buckets & rules */
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

    /** Pizza-by-name test */
    private const PIZZA_NAMES = ['Classic Pepperoni', 'Classic Cheese'];

    /**
     * Pizza-or set for “with pizza” checks (compare as strings).
     * Given list: -1,6,7,8,9,101001,101002,101288,103044,202901,101289,204100,204200
     */
    private const PIZZA_OR_SET = [
        '-1','6','7','8','9','101001','101002','101288','103044','202901','101289','204100','204200'
    ];

    /** Base item names */
    private const ITEM_CRAZY_BREAD = ['Crazy Bread'];
    private const ITEM_COOKIES     = ['Cookie Dough Brownie M&M','Cookie Dough Brownie - Twix'];
    private const ITEM_SAUCE       = ['Crazy Sauce'];
    private const ITEM_WINGS       = ['Caesar Wings'];

    /**
     * Public API.
     */
    public function metrics(string $franchiseStore, $fromDate, $toDate): array
    {
        $from = $fromDate instanceof Carbon ? $fromDate->toDateString() : Carbon::parse($fromDate)->toDateString();
        $to   = $toDate   instanceof Carbon ? $toDate->toDateString()   : Carbon::parse($toDate)->toDateString();

        $out = ['buckets' => []];

        foreach (self::BUCKETS as $key => $rules) {
            $crazyBread = $this->countSoldWithPizza($franchiseStore, $from, $to, $rules['placed'], $rules['fulfilled'], self::ITEM_CRAZY_BREAD);
            $cookies    = $this->countSoldWithPizza($franchiseStore, $from, $to, $rules['placed'], $rules['fulfilled'], self::ITEM_COOKIES);
            $sauce      = $this->countSoldWithPizza($franchiseStore, $from, $to, $rules['placed'], $rules['fulfilled'], self::ITEM_SAUCE);
            $wings      = $this->countSoldWithPizza($franchiseStore, $from, $to, $rules['placed'], $rules['fulfilled'], self::ITEM_WINGS);

            $pizzaBase  = $this->countPizzaBaseEntries($franchiseStore, $from, $to, $rules['placed'], $rules['fulfilled']);

            $percentages = [
                'crazy_bread' => $this->safeDivide($crazyBread, $pizzaBase),
                'cookies'     => $this->safeDivide($cookies, $pizzaBase),
                'sauce'       => $this->safeDivide($sauce, $pizzaBase),
                'wings'       => $this->safeDivide($wings, $pizzaBase),
            ];

            $out['buckets'][$key] = [
                'label' => $rules['label'],
                'counts' => [
                    'crazy_bread' => $crazyBread,
                    'cookies'     => $cookies,
                    'sauce'       => $sauce,
                    'wings'       => $wings,
                    'pizza_base'  => $pizzaBase,
                ],
                'percentages' => $percentages,
            ];
        }

        return $out;
    }

    /**
     * Count entries for the given item names sold WITH pizza (exists pizza row in same order).
     * Uses a whereExists subquery; the closure receives a Query\Builder.
     */
    private function countSoldWithPizza(
        string $franchiseStore,
        string $from,
        string $to,
        ?array $placed,
        ?array $fulfilled,
        array $targetItemNames
    ): int {
        $base = $this->baseQuery($franchiseStore, $from, $to, $placed, $fulfilled)
            ->whereIn('menu_item_name', $targetItemNames);

        // IMPORTANT: Closure receives Illuminate\Database\Query\Builder (not Eloquent\Builder)
        $base->whereExists(function (QueryBuilder $q) use ($franchiseStore, $from, $to, $placed, $fulfilled) {
            $q->from('order_line as ol2')
              ->select('ol2.id')
              ->whereColumn('ol2.order_id', 'order_line.order_id')
              ->where('ol2.franchise_store', $franchiseStore)
              ->whereBetween('ol2.business_date', [$from, $to]);

            if ($placed !== null && count($placed)) {
                $q->whereIn('ol2.order_placed_method', $placed);
            }
            if ($fulfilled !== null && count($fulfilled)) {
                $q->whereIn('ol2.order_fulfilled_method', $fulfilled);
            }

            // Pizza condition: by name OR by item_id in the set
            $q->where(function (QueryBuilder $b) {
                $b->whereIn('ol2.menu_item_name', self::PIZZA_NAMES)
                  ->orWhereIn('ol2.item_id', self::PIZZA_OR_SET);
            });
        });

        return (int) $base->count();
    }

    /**
     * Count entries that are themselves pizza entries (by name or item_id set).
     */
    private function countPizzaBaseEntries(
        string $franchiseStore,
        string $from,
        string $to,
        ?array $placed,
        ?array $fulfilled
    ): int {
        $q = $this->baseQuery($franchiseStore, $from, $to, $placed, $fulfilled);

        $q->where(function (Builder $b) {
            $b->whereIn('menu_item_name', self::PIZZA_NAMES)
              ->orWhereIn('item_id', self::PIZZA_OR_SET);
        });

        return (int) $q->count();
    }

    /**
     * Shared base Eloquent query: store + date + optional bucket filters.
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

    private function safeDivide(int|float $num, int|float $den): float
    {
        return ($den != 0) ? ((float) $num / (float) $den) : 0.0;
    }
}
