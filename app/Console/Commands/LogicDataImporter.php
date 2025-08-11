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

class LogicDataImporter extends Command
{
    protected $signature   = 'logic:data-import
    {--start-date= : Start date in Y-m-d format}
    {--end-date=   : End date in Y-m-d format}';
    protected LogicsAndQueriesServices $logic;

    public function __construct(LogicsAndQueriesServices $logic)
    {
        parent::__construct();
        $this->logic = $logic;

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
            $this->logic->DataLoop($forLogic, $date);

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
}
