<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\BreadBoostModel;
use App\Models\OrderLine;
use Carbon\Carbon;

class BreadBoostImporter extends Command
{
    protected $signature = 'report:bread-boost {--start-date= : Start date (Y-m-d)} {--end-date= : End date (Y-m-d)}';
    protected $description = 'Import bread boost data for all stores and dates';

    public function handle()
    {
        $startDate = $this->option('start-date') ? Carbon::parse($this->option('start-date')) : null;
        $endDate = $this->option('end-date') ? Carbon::parse($this->option('end-date')) : null;

        $query = OrderLine::select('business_date', 'franchise_store')
            ->distinct();

        if ($startDate) {
            $query->where('business_date', '>=', $startDate);
        }
        if ($endDate) {
            $query->where('business_date', '<=', $endDate);
        }

        $dates = $query->get();

        $bar = $this->output->createProgressBar($dates->count());
        $bar->start();

        foreach ($dates as $record) {
            $date = $record->business_date;
            $store = $record->franchise_store;

            $storeOrderLines = OrderLine::where('business_date', $date)
                ->where('franchise_store', $store)
                ->get();

            // Convert to collection for better performance
            $storeOrderLines = collect($storeOrderLines);

            // Classic orders calculation
            $classicOrders = $storeOrderLines
                ->whereIn('menu_item_name', ['Classic Pepperoni', 'Classic Cheese'])
                ->whereIn('order_fulfilled_method', ['Register', 'Drive-Thru'])
                ->whereIn('order_placed_method', ['Phone', 'Register', 'Drive Thru'])
                ->pluck('order_id')
                ->unique();

            $classicOrdersCount = $classicOrders->count();

            $classicWithBreadCount = $storeOrderLines
                ->whereIn('order_id', $classicOrders)
                ->where('menu_item_name', 'Crazy Bread')
                ->pluck('order_id')
                ->unique()
                ->count();

            // Other pizza orders calculation
            $otherPizzaOrder = $storeOrderLines
                ->whereNotIn('item_id', [
                    '-1', '6', '7', '8', '9', '101001', '101002',
                    '101288', '103044', '202901', '101289', '204100', '204200'
                ])
                ->whereIn('order_fulfilled_method', ['Register', 'Drive-Thru'])
                ->whereIn('order_placed_method', ['Phone', 'Register', 'Drive Thru'])
                ->pluck('order_id')
                ->unique();

            $otherPizzaOrderCount = $otherPizzaOrder->count();

            $otherPizzaWithBreadCount = $storeOrderLines
                ->whereIn('order_id', $otherPizzaOrder)
                ->where('menu_item_name', 'Crazy Bread')
                ->pluck('order_id')
                ->unique()
                ->count();

            // Save the data
            BreadBoostModel::updateOrCreate(
                ['franchise_store' => $store, 'date' => $date],
                [
                    'classic_order' => $classicOrdersCount,
                    'classic_with_bread' => $classicWithBreadCount,
                    'other_pizza_order' => $otherPizzaOrderCount,
                    'other_pizza_with_bread' => $otherPizzaWithBreadCount
                ]
            );

            $bar->advance();
        }

        $bar->finish();
        $this->info("\nBread boost data import completed successfully");
    }
}
