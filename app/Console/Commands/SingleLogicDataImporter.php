<?php

namespace App\Console\Commands;

use App\Models\CashManagement;//*
use App\Models\DetailOrder;//*
use App\Models\FinancialView;//*
use App\Models\OrderLine;//*
use App\Models\SummaryItem;//*
use App\Models\SummarySale;//*
use App\Models\SummaryTransaction;//*
use App\Models\Waste;//*

use App\Models\AltaInventoryCogs;//*
use App\Models\AltaInventoryIngredientOrder;//*
use App\Models\AltaInventoryIngredientUsage;//*
use App\Models\AltaInventoryWaste;//*

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use App\Services\Helper\Logics\LogicsAndQueriesServices;
use App\Services\Helper\Insert\InsertDataServices;

class SingleLogicDataImporter extends Command
{
    protected $signature = 'Single-logic:data-import
    {--start-date= : Start date in Y-m-d format}
    {--end-date=   : End date in Y-m-d format}
    {--only=*      : Run only these logic blocks (repeatable)}
    {--except=*    : Skip these logic blocks (repeatable)}';

    protected $description = 'Import and process data using specific logic blocks for given date range';

    protected LogicsAndQueriesServices $logic;
    protected InsertDataServices $inserter;

    private const LOGIC_KEYS = [
        'bread-boost',
        'odp',                 // Online Discount Program
        'delivery-summary',
        'third-party',
        'finance-data',
        'final-summary',
        'hourly-sales',
        'hourly-hnr',
        'store-hnr',
        'channel-data',
    ];

    public function __construct(LogicsAndQueriesServices $logic, InsertDataServices $inserter)
    {
        parent::__construct();
        $this->logic = $logic;
        $this->inserter = $inserter;
    }

    public function handle()
    {
        $startDateOpt = $this->option('start-date');
        $endDateOpt   = $this->option('end-date');

        // Add debugging output
        $this->info('Command started with options:');
        $this->info('Start date: ' . ($startDateOpt ?? 'not provided'));
        $this->info('End date: ' . ($endDateOpt ?? 'not provided'));
        $this->info('Only: ' . json_encode($this->option('only')));
        $this->info('Except: ' . json_encode($this->option('except')));

        $startDate = $startDateOpt ? Carbon::parse($startDateOpt)->startOfDay() : null;
        $endDate   = $endDateOpt   ? Carbon::parse($endDateOpt)->startOfDay()   : null;

        // Build the dates list
        $dates = [];
        if ($startDate && $endDate) {
            if ($startDate->gt($endDate)) {
                $this->error('--start-date must be <= --end-date');
                return self::INVALID;
            }
            foreach (CarbonPeriod::create($startDate, $endDate) as $d) {
                $dates[] = $d->format('Y-m-d');
            }
        } elseif ($startDate) {
            $dates[] = $startDate->format('Y-m-d'); // single day
        } else {
            $this->error('Provide at least --start-date (optionally --end-date).');
            return self::INVALID;
        }

        $only   = array_values(array_unique(array_map('strtolower', (array) $this->option('only'))));
        $except = array_values(array_unique(array_map('strtolower', (array) $this->option('except'))));

        $unknown = array_diff([...$only, ...$except], self::LOGIC_KEYS);
        if ($unknown) {
            $this->warn('Unknown logic key(s): '.implode(', ', $unknown));
            $this->line('Valid keys: '.implode(', ', self::LOGIC_KEYS));
        }

        Log::info('Dates to process: ' . json_encode($dates));
        $this->info('Processing ' . count($dates) . ' date(s): ' . implode(', ', $dates));

        foreach ($dates as $date) {
            Log::info('Start logic for: ' . $date);
            $this->info("Processing date: {$date}");

            $data = $this->processModelData($date);

            // Add data count debugging
            $this->info("Data counts for {$date}:");
            $this->info("  Detail Orders: " . count($data['detail_orders'] ?? []));
            $this->info("  Financial View: " . count($data['financial_view'] ?? []));
            $this->info("  Waste Reports: " . count($data['waste_reports'] ?? []));
            $this->info("  Order Lines: " . count($data['order_lines'] ?? []));

            // Map DB payload keys to what DataLoop() expects
            $forLogic = [
                'processDetailOrders'  => $data['detail_orders']   ?? [],
                'processFinancialView' => $data['financial_view']  ?? [],
                'processWaste'         => $data['waste_reports']   ?? [],
                'processOrderLine'     => $data['order_lines']     ?? [],
            ];

            $detailOrder = collect($forLogic['processDetailOrders'] ?? []);
            $financialView = collect($forLogic['processFinancialView'] ?? []);
            $wasteData = collect($forLogic['processWaste'] ?? []);
            $orderLine = collect($forLogic['processOrderLine'] ?? []);

            $allFranchiseStores = collect([
                ...$detailOrder->pluck('franchise_store'),
                ...$financialView->pluck('franchise_store'),
                ...$wasteData->pluck('franchise_store')
            ])->unique();

            $this->info("Found " . $allFranchiseStores->count() . " franchise stores to process");

            // **CRITICAL FIX**: Initialize allChannelRows BEFORE the store loop
            $allChannelRows = [];

            foreach ($allFranchiseStores as $store) {
                $this->info("  Processing store: {$store}");

                $OrderRows = $detailOrder->where('franchise_store', $store);
                $financeRows = $financialView->where('franchise_store', $store);
                $wasteRows = $wasteData->where('franchise_store', $store);
                $storeOrderLines = $orderLine->where('franchise_store', $store);

                //******** *********/
                //******* Bread Boost Summary *********//
                if ($this->shouldRun('bread-boost', $only, $except)) {
                    $this->line("    Running Bread Boost Summary for store {$store}");
                    $breadBoostRow = $this->logic->BreadBoost($storeOrderLines, $store, $date);
                    if (!empty($breadBoostRow)) {
                        $this->inserter->insertBreadBoostData([$breadBoostRow]);
                        $this->info("    Bread Boost data inserted successfully");
                    } else {
                        $this->line("    No Bread Boost data to insert");
                    }
                }

                //******* Online Discount Program *********//
                if ($this->shouldRun('odp', $only, $except)) {
                    $this->line("    Running Online Discount Program for store {$store}");
                    $odpRows = $this->logic->OnlineDiscountProgram($OrderRows, $store, $date);
                    if (!empty($odpRows)) {
                        $this->inserter->insertOnlineDiscountProgramData($odpRows);
                        $this->info("    ODP data inserted successfully (" . count($odpRows) . " rows)");
                    } else {
                        $this->line("    No ODP data to insert");
                    }
                }

                //******* Delivery Order Summary *********//
                if ($this->shouldRun('delivery-summary', $only, $except)) {
                    $this->line("    Running Delivery Order Summary for store {$store}");
                    $deliverySummaryRow = $this->logic->DeliveryOrderSummary($OrderRows, $store, $date);
                    if (!empty($deliverySummaryRow)) {
                        $this->inserter->insertDeliveryOrderSummaryData([$deliverySummaryRow]);
                        $this->info("    Delivery Summary data inserted successfully");
                    } else {
                        $this->line("    No Delivery Summary data to insert");
                    }
                }

                //*******3rd Party Marketplace Orders*********//
                if ($this->shouldRun('third-party', $only, $except)) {
                    $this->line("    Running 3rd Party Marketplace for store {$store}");
                    $thirdPartyRow = $this->logic->ThirdPartyMarketplace($OrderRows, $store, $date);
                    if (!empty($thirdPartyRow)) {
                        $this->inserter->insertThirdPartyMarketplaceOrder([$thirdPartyRow]);
                        $this->info("    3rd Party Marketplace data inserted successfully");
                    } else {
                        $this->line("    No 3rd Party Marketplace data to insert");
                    }
                }

                //******* For finance data table *********//
                if ($this->shouldRun('finance-data', $only, $except)) {
                    $this->line("    Running Finance Data for store {$store}");
                    $financeDataRow = $this->logic->FinanceData($OrderRows, $financeRows, $store, $date);
                    if (!empty($financeDataRow)) {
                        $this->inserter->insertFinanceData([$financeDataRow]);
                        $this->info("    Finance data inserted successfully");
                    } else {
                        $this->line("    No Finance data to insert");
                    }
                }

                //******* final summary *********//
                if ($this->shouldRun('final-summary', $only, $except)) {
                    $this->line("    Running Final Summary for store {$store}");
                    $finalSummaryRow = $this->logic->FinalSummaries($OrderRows, $financeRows, $wasteRows, $store, $date);
                    if (!empty($finalSummaryRow)) {
                        $this->inserter->insertFinalSummary([$finalSummaryRow]);
                        $this->info("    Final Summary data inserted successfully");
                    } else {
                        $this->line("    No Final Summary data to insert");
                    }
                }

                //Hours loop
                $ordersByHour = $this->logic->groupOrdersByHour($OrderRows);

                $hourlySalesRows = [];
                $hourHnrRows     = [];

                if ($this->shouldRun('hourly-sales', $only, $except)) {
                    $this->line("    Processing Hourly Sales for store {$store}");
                    foreach ($ordersByHour as $hour => $hourOrders) {
                        $h = (int) $hour;
                        $hourlySalesRows[] = $this->logic->makeHourlySalesRow($hourOrders, $store, $date, $h);
                    }
                }

                if ($this->shouldRun('hourly-sales', $only, $except)) {
                    if (!empty($hourlySalesRows)) {
                        $this->inserter->insertHourlySales($hourlySalesRows);
                        $this->info("    Hourly Sales data inserted successfully (" . count($hourlySalesRows) . " rows)");
                    } else {
                        $this->line("    No Hourly Sales data to insert");
                    }
                }

                if ($this->shouldRun('hourly-hnr', $only, $except)) {
                    $this->line("    Processing Hourly HNR for store {$store}");
                    foreach ($ordersByHour as $hour => $hourOrders) {
                        $h = (int) $hour;
                        $hourHnrRows[] = $this->logic->makeHourHnrRow($hourOrders, $store, $date, $h);
                    }
                }

                if ($this->shouldRun('hourly-hnr', $only, $except)) {
                    if (!empty($hourHnrRows)) {
                        $this->inserter->insertHourHnrTransactions($hourHnrRows);
                        $this->info("    Hourly HNR data inserted successfully (" . count($hourHnrRows) . " rows)");
                    } else {
                        $this->line("    No Hourly HNR data to insert");
                    }
                }

                // Store hnr transactions
                if ($this->shouldRun('store-hnr', $only, $except)) {
                    $this->line("    Running Store HNR Transactions for store {$store}");
                    $storeHNRRows = $this->logic->StoreHotNReadyTransaction($OrderRows, $storeOrderLines, $store, $date);
                    if (!empty($storeHNRRows)) {
                        $this->inserter->insertStoreHotNReadyTransaction($storeHNRRows);
                        $this->info("    Store HNR data inserted successfully (" . count($storeHNRRows) . " rows)");
                    } else {
                        $this->line("    No Store HNR data to insert");
                    }
                }
                //End of Hours loop

                //******* ChannelData *******
                if ($this->shouldRun('channel-data', $only, $except)) {
                    $this->line("    Processing Channel Data for store {$store}");
                    $channelRows = $this->logic->ChannelData($OrderRows, $store, $date);
                    if (!empty($channelRows)) {
                        array_push($allChannelRows, ...$channelRows);
                        $this->info("    Channel data prepared (" . count($channelRows) . " rows)");
                    } else {
                        $this->line("    No Channel data for this store");
                    }
                }
            }

            // **FIX**: Insert channel data after processing all stores for this date
            if ($this->shouldRun('channel-data', $only, $except)) {
                if (!empty($allChannelRows)) {
                    $this->inserter->insertChannelData($allChannelRows);
                    $this->info("All Channel data inserted successfully (" . count($allChannelRows) . " total rows)");
                } else {
                    $this->line("No Channel data to insert for date {$date}");
                }
            }

            Log::info('Finished logic for: ' . $date);
            $this->info("Completed processing for date: {$date}");
        }

        Log::info('All data updated');
        $this->info('Done.');
        return self::SUCCESS;
    }

    public function processModelData(string $selectedDate)
    {
        // map a key to each Eloquent model class
        $models = [
            'cash_management'                           => CashManagement::class,
            'financial_view'                            => FinancialView::class,
            'summary_items'                             => SummaryItem::class,
            'summary_sales'                             => SummarySale::class,
            'summary_transactions'                      => SummaryTransaction::class,
            'detail_orders'                             => DetailOrder::class,
            'waste_reports'                             => Waste::class,
            'order_lines'                               => OrderLine::class,
            'alta_inventory_cogs'                       => AltaInventoryCogs::class,
            'alta_inventory_ingredient_orders'          => AltaInventoryIngredientOrder::class,
            'alta_inventory_ingredient_usage'           => AltaInventoryIngredientUsage::class,
            'alta_inventory_waste'                      => AltaInventoryWaste::class,
        ];

        $allData = [];

        foreach ($models as $key => $modelClass) {
            try {
                // pull all rows matching the date, then convert to array
                $allData[$key] = $modelClass
                    ::whereDate('business_date', $selectedDate)
                    ->get()
                    ->toArray();

                Log::info("Loaded {$key}: " . count($allData[$key]) . " records for {$selectedDate}");
            } catch (\Exception $e) {
                Log::error("Error loading {$key} for {$selectedDate}: " . $e->getMessage());
                $this->error("Error loading {$key}: " . $e->getMessage());
                $allData[$key] = [];
            }
        }

        return $allData;
    }

    private function shouldRun(string $key, array $only, array $except): bool
    {
        if (!empty($only)) {
            return in_array($key, $only, true);
        }
        return !in_array($key, $except, true);
    }
}
