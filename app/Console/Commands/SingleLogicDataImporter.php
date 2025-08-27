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
    protected $signature   = 'Single-logic:data-import
    {--start-date= : Start date in Y-m-d format}
    {--end-date=   : End date in Y-m-d format}
    {--only=*      : Run only these logic blocks (repeatable)}
    {--except=*    : Skip these logic blocks (repeatable)}';
    protected LogicsAndQueriesServices $logic;

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
protected InsertDataServices $inserter;
    public function __construct(LogicsAndQueriesServices $logic,InsertDataServices $inserter)
    {
        parent::__construct();
        $this->logic = $logic;
        $this->inserter= $inserter;
    }

    public function handle()
    {
        $startDateOpt = $this->option('start-date');
        $endDateOpt   = $this->option('end-date');

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

        foreach ($dates as $date) {
            Log::info('Start logic for: ' . $date);

            $data = $this->processModelData($date);

            // Map DB payload keys to what DataLoop() expects
            $forLogic = [
                'processDetailOrders'  => $data['detail_orders']   ?? [],
                'processFinancialView' => $data['financial_view']  ?? [],
                'processWaste'         => $data['waste_reports']   ?? [],
                'processOrderLine'     => $data['order_lines']     ?? [],
            ];

            // Run the logic builder
            // $this->logic->DataLoop($forLogic, $date);

            $detailOrder = collect($forLogic['processDetailOrders'] ?? []);
            $financialView = collect($forLogicata['processFinancialView'] ?? []);
            $wasteData = collect($forLogic['processWaste'] ?? []);
            $orderLine = collect($forLogic['processOrderLine'] ?? []);

            $allFranchiseStores = collect([
                ...$detailOrder->pluck('franchise_store'),
                ...$financialView->pluck('franchise_store'),
                ...$wasteData->pluck('franchise_store')
            ])->unique();

            foreach ($allFranchiseStores as $store) {

            $OrderRows = $detailOrder->where('franchise_store', $store);
            $financeRows = $financialView->where('franchise_store', $store);
            $wasteRows = $wasteData->where('franchise_store', $store);
            $storeOrderLines = $orderLine->where('franchise_store', $store);

            //*********    **********/
            //******* Bread Boost Summary *********//
            if ($this->shouldRun('bread-boost', $only, $except)) {
                $breadBoostRow = $this->logic->BreadBoost($storeOrderLines, $store, $date);
                if (!empty($breadBoostRow)) {
                    $this->inserter->insertBreadBoostData([$breadBoostRow]);
            }}

            //******* Online Discount Program *********//
            if ($this->shouldRun('odp', $only, $except)) {
                $odpRows = $this->logic->OnlineDiscountProgram($OrderRows, $store, $date);
                if (!empty($odpRows)) {
                    $this->inserter->insertOnlineDiscountProgramData($odpRows);
            }}

            //******* Delivery Order Summary *********//
            if ($this->shouldRun('delivery-summary', $only, $except)) {
                $deliverySummaryRow = $this->logic->DeliveryOrderSummary($OrderRows, $store, $date);
                if (!empty($deliverySummaryRow)) {
                    $this->inserter->insertDeliveryOrderSummaryData([$deliverySummaryRow]);
            }}

            //*******3rd Party Marketplace Orders*********//
            if ($this->shouldRun('third-party', $only, $except)) {
                $thirdPartyRow = $this->logic->ThirdPartyMarketplace($OrderRows, $store, $date);
                if (!empty($thirdPartyRow)) {
                    $this->inserter->insertThirdPartyMarketplaceOrder([$thirdPartyRow]);
            }}

            //******* For finance data table *********//
            if ($this->shouldRun('finance-data', $only, $except)) {
                $financeDataRow = $this->logic->FinanceData($OrderRows, $financeRows, $store, $date);
                if (!empty($financeDataRow)) {
                    $this->inserter->insertFinanceData([$financeDataRow]);
            }}

            //******* final summary *********//
            if ($this->shouldRun('final-summary', $only, $except)) {
                $finalSummaryRow = $this->logic->FinalSummaries($OrderRows, $financeRows, $wasteRows, $store, $date);
                if (!empty($finalSummaryRow)) {
                    $this->inserter->insertFinalSummary([$finalSummaryRow]);
            }}

            //Hours loop
            $ordersByHour = $this->logic->groupOrdersByHour($OrderRows);

            $hourlySalesRows = [];
            $hourHnrRows     = [];

            if ($this->shouldRun('hourly-sales', $only, $except)) {
            foreach ($ordersByHour as $hour => $hourOrders) {
                $h = (int) $hour;
                $hourlySalesRows[] = $this->logic->makeHourlySalesRow($hourOrders, $store, $date, $h);
            }}
                if ($this->shouldRun('hourly-sales', $only, $except)) {
                if (!empty($hourlySalesRows)) {
                    $this->inserter->insertHourlySales($hourlySalesRows);
            }}

            if ($this->shouldRun('hourly-hnr', $only, $except)) {
                foreach ($ordersByHour as $hour => $hourOrders) {
                    $h = (int) $hour;
                    $hourHnrRows[]     = $this->logic->makeHourHnrRow($hourOrders, $store, $date, $h);
            }}

            if ($this->shouldRun('hourly-hnr', $only, $except)) {
                if (!empty($hourHnrRows)) {
                    $this->inserter->insertHourHnrTransactions($hourHnrRows);
            }}

            // Store hnr transactions
            if ($this->shouldRun('store-hnr', $only, $except)) {
            $storeHNRRows = $this->logic->StoreHotNReadyTransaction($OrderRows,$storeOrderLines, $store, $date);
            if (!empty($storeHNRRows)) {
                $this->inserter->insertStoreHotNReadyTransaction($storeHNRRows);
            }}
            //End of Hours loop

            //******* ChannelData *******
            if ($this->shouldRun('channel-data', $only, $except)) {
            $channelRows = $this->logic->ChannelData($OrderRows, $store, $date);
            if (!empty($channelRows)) {
                array_push($allChannelRows, ...$channelRows);
            }}
            }
            if ($this->shouldRun('channel-data', $only, $except)) {
            if (!empty($allChannelRows)) {
            $this->inserter->insertChannelData($allChannelRows);
            }}

            Log::info('Finished logic for: ' . $date);
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
            // pull all rows matching the date, then convert to array
            $allData[$key] = $modelClass
                ::whereDate('business_date', $selectedDate)
                ->get()
                ->toArray();
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
