<?php

namespace App\Console\Commands;
use App\Models\BreadBoostModel;//*
use App\Models\CashManagement;//*
use App\Models\ChannelData;//**
use App\Models\DeliveryOrderSummary;//*
use App\Models\DetailOrder;//*
use App\Models\FinalSummary;//*
use App\Models\FinanceData;//*
use App\Models\FinancialView;//*
use App\Models\HourlySales;//
use App\Models\OnlineDiscountProgram;//
use App\Models\OrderLine;//*
use App\Models\SummaryItem;//*
use App\Models\SummarySale;//*
use App\Models\SummaryTransaction;//*
use App\Models\ThirdPartyMarketplaceOrder;//*
use App\Models\Waste;//*

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

class LogicDataImporter extends Command
{
    protected $signature = 'Import and update data {--start-date= : Start date in Y-m-d format} {--end-date= : End date in Y-m-d format}';


    public function handle(){

        $startDate = $this->option('start-date') ? Carbon::parse($this->option('start-date')) : null;
        $endDate = $this->option('end-date') ? Carbon::parse($this->option('end-date')) : null;

        //collect all the dates we need
        $dates = [];

        if ($startDate && $endDate && $startDate->lte($endDate)) {
            // just positional here:
            $period = CarbonPeriod::create($startDate, $endDate);

            foreach ($period as $day) {
                $dates[] = $day->format('Y-m-d');
            }
        }

        //start with the date loop
        foreach ($dates as $day) {
        
        }

    }
}
