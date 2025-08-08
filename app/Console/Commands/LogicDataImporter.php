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

    public function handle(){
        //startDate and endDate var
        $startDate = $this->option('start-date') ? Carbon::parse($this->option('start-date')) : null;
        $endDate = $this->option('end-date') ? Carbon::parse($this->option('end-date')) : null;

        Log::info('got the startDate'.$startDate.'and the endDate'.$endDate);

        //Collect all the dates we need
        $dates = [];

        if ($startDate && $endDate && $startDate->lte($endDate)) {
            // just positional here:
            $period = CarbonPeriod::create($startDate, $endDate);

            foreach ($period as $date) {
                $dates[] = $date->format('Y-m-d');
            }
        }
        Log::info( 'the dates array'. print_r($dates));

        //Start with the date loop
        foreach ($dates as $date) {
             Log::info( 'start the loop for '.$date);

            $Data = $this->processModelData($date);
             Log::info( 'Got All data for '. json_encode($Data));

            $this->logic->DataLoop($Data,$date);
             Log::info( 'finished the Logic loop');
        }
        Log::info( 'All data updated');
    }

    public function processModelData(string $selectedDate)
    {
            // map a “key” to each Eloquent model class
        $models = [
            'cash_management'        => CashManagement::class,
            'financial_view'         => FinancialView::class,
            'summary_items'          => SummaryItem::class,
            'summary_sales'          => SummarySale::class,
            'summary_transactions'   => SummaryTransaction::class,
            'detail_orders'          => DetailOrder::class,
            'waste_reports'          => Waste::class,
            'order_lines'            => OrderLine::class,
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
