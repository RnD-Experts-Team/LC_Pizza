<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\DetailOrder;
use App\Models\HourlySales;

class ImportHourlySales extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:hourly-sales {--store= : Specific franchise store to process} {--date= : Specific date to process (Y-m-d format)} {--all : Process all dates}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import hourly sales data from existing database records';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $store = $this->option('store');
        $specificDate = $this->option('date');
        $processAllDates = $this->option('all');

        // Build the query to get unique business dates
        $query = DetailOrder::select('business_date')
            ->distinct();

        // If a specific store is provided, filter by it
        if ($store) {
            $query->where('franchise_store', $store);
            $this->info("Processing only store: {$store}");
        }

        // If a specific date is provided, use only that date
        if ($specificDate) {
            $query->where('business_date', $specificDate);
            $this->info("Processing only date: {$specificDate}");
        } elseif (!$processAllDates) {
            // Default to yesterday if no date specified and not processing all
            $yesterday = Carbon::yesterday()->format('Y-m-d');
            $query->where('business_date', $yesterday);
            $this->info("No date specified, defaulting to yesterday: {$yesterday}");
        } else {
            $this->info("Processing all available dates");
        }

        // Get all unique business dates
        $businessDates = $query->pluck('business_date')->toArray();

        if (empty($businessDates)) {
            $this->error("No business dates found with the specified criteria");
            return Command::FAILURE;
        }

        $this->info("Found " . count($businessDates) . " business dates to process");
        $totalProcessed = 0;

        // Process each business date
        foreach ($businessDates as $date) {
            $this->info("Processing date: {$date}");

            // Get franchise stores for this date
            $franchiseStoresQuery = DetailOrder::select('franchise_store')
                ->where('business_date', $date)
                ->distinct();

            if ($store) {
                $franchiseStoresQuery->where('franchise_store', $store);
            }

            $franchiseStores = $franchiseStoresQuery->pluck('franchise_store')->toArray();

            foreach ($franchiseStores as $franchiseStore) {
                $this->info("  Processing store: {$franchiseStore}");

                // Get all orders for this store and date
                $storeOrders = DetailOrder::where('business_date', $date)
                    ->where('franchise_store', $franchiseStore)
                    ->get();

                // Group orders by hour
                $ordersByHour = $storeOrders->groupBy(function ($order) {
                    return Carbon::parse($order->date_time_placed)->format('H');
                });

                $hourlyRecordsProcessed = 0;
                foreach ($ordersByHour as $hour => $hourOrders) {
                    // Create or update hourly sales record
                    HourlySales::updateOrCreate(
                        [
                            'franchise_store' => $franchiseStore,
                            'business_date'   => $date,
                            'hour'            => (int) $hour,
                        ],
                        [
                            'total_sales'        => $hourOrders->sum('royalty_obligation'),
                            'phone_sales'        => $hourOrders->where('order_placed_method', 'Phone')->sum('royalty_obligation'),
                            'call_center_sales'  => $hourOrders->where('order_placed_method', 'SoundHoundAgent')->sum('royalty_obligation'),
                            'drive_thru_sales'   => $hourOrders->where('order_placed_method', 'Drive Thru')->sum('royalty_obligation'),
                            'website_sales'      => $hourOrders->where('order_placed_method', 'Website')->sum('royalty_obligation'),
                            'mobile_sales'       => $hourOrders->where('order_placed_method', 'Mobile')->sum('royalty_obligation'),
                            'order_count'        => $hourOrders->count(),
                        ]
                    );

                    $hourlyRecordsProcessed++;
                }

                $this->info("    Processed {$hourlyRecordsProcessed} hourly records for store {$franchiseStore}");
                $totalProcessed += $hourlyRecordsProcessed;
            }
        }

        $this->info("Hourly sales import completed successfully. Processed {$totalProcessed} hourly records in total.");
        Log::info("Hourly sales import completed successfully", [
            'total_records_processed' => $totalProcessed,
            'dates_processed' => count($businessDates)
        ]);

        return Command::SUCCESS;
    }
}
