<?php

namespace App\Http\Controllers;

use App\Models\HourlySales;
use App\Models\SummaryItem;
use App\Models\FinalSummary;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DSPR_Controller extends Controller
{
    public function index(Request $request,$store, $date)
    {
        // --- guards ---
        if (empty($store) || empty($date)) {
            return response()->noContent();
        }
        if (!preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', $date)) {
            return response()->json(['error' => 'Invalid date format, expected YYYY-MM-DD or YYYY-M-D'], 400);
        }
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            // accept strings or ints; keep it loose
            'items.*' => ['interger'], // or 'integer' if all numeric
        ]);
        // De-duplicate
        $itemIds = array_values(array_unique($validated['items']));
        /***dates Used**/
        $givenDate = Carbon::parse($date);
        $usedDate = CarbonImmutable::parse($givenDate);
        $dayName = $givenDate->dayName;
        //for week
        $weekNumber = $usedDate->isoWeek;
        $weekStartDate = $usedDate->startOfWeek(Carbon::TUESDAY);
        $weekEndDate = $weekStartDate->addDays(6);
        //for lookback
        $lookBackStartDate=$usedDate->subDays(84);
        $lookBackEndDate=$usedDate;



        $startStr = $weekStartDate->toDateString();
        $endStr   = $weekEndDate->toDateString();

        // External deposit/delivery data
        $base = rtrim('https://hook.pneunited.com/api/deposit-delivery-dsqr-weekly', '/');
        $url  = $base.'/'.rawurlencode($store).'/'.rawurlencode($startStr).'/'.rawurlencode($endStr);

        Log::info('Fetching weekly deposit/delivery data', [
            'store'   => $store,
            'start'   => $weekStartDate,
            'end'     => $weekEndDate,
            'url'     => $url,
        ]);
        // Make the GET request
        $response = Http::get($url);

        if ($response->successful()) {
            // Decode the JSON response into a PHP array
            $data = $response->json();

            // Convert the array into a Laravel collection
            $weeklyDepositDeliveryCollection = collect($data['weeklyDepositDelivery']);

             Log::info('Decoded weekly deposit/delivery data', [
                'count' => is_array($data) ? count($data) : null,
                'keys'  => is_array($data) ? array_keys($data) : null,
            ]);
        } else {
            $weeklyDepositDeliveryCollection = collect();
            Log::warning('API call not successful', [
            'status' => $response->status(),
        ]);
        }

        $dailyDepositDeliveryCollection =$weeklyDepositDeliveryCollection->where('HookWorkDaysDate',$date);


        /**get the data from models as collections**/
        //daily
        $dailyFinalSummaryCollection = FinalSummary::
            where('franchise_store', '=',$store )
            ->where('business_date','=',$usedDate)
            ->get();

        // $dailySummaryItemCollection = SummaryItem::
        //     where('franchise_store', '=',$store )
        //     ->where('business_date','=',$usedDate)
        //     ->whereIn('menu_item_name',$Itemslist)
        //     ->get();

        $dailyHourlySalesCollection = HourlySales::
        where('franchise_store', $store )
        ->where('business_date',$usedDate)
        ->get();

        //weekly
        $weeklyFinalSummaryCollection = FinalSummary::
            where('franchise_store', '=',$store )
            ->whereBetween('business_date', [$weekStartDate, $weekEndDate])
            ->get();

        $weeklySummaryItemCollection = SummaryItem::
            where('franchise_store', '=',$store )
            ->whereBetween('business_date', [$weekStartDate, $weekEndDate])
            ->whereIn('item_id', $itemIds)
            ->get();

        // $weeklyHourlySalesCollection = HourlySales::
        // where('franchise_store', '=',$store )
        // ->whereBetween('business_date', [$weekStartDate, $weekEndDate])
        // ->get();

        //lookback
        $lookBackFinalSummaryCollection = FinalSummary::
            where('franchise_store', '=',$store )
            ->whereBetween('business_date', [$lookBackStartDate, $lookBackEndDate])
            ->get();

        $lookBackSummaryItemCollection = SummaryItem::
            where('franchise_store', '=',$store )
            ->whereBetween('business_date', [$lookBackStartDate, $lookBackEndDate])
            ->whereIn('item_id', $itemIds)
            ->get();

        // $lookBackHourlySalesCollection = HourlySales::
        // where('franchise_store', '=',$store )
        // ->whereBetween('business_date', [$lookBackStartDate, $lookBackEndDate])
        // ->get();

        //calling the methods for the data
        $dailyHourlySalesData=$this->DailyHourlySalesReport($dailyHourlySalesCollection);
        $dailyDSQRData=$this->DailyDSQRReport($dailyDepositDeliveryCollection);
        $dailyDSPRData=$this->DailyDSPRReport($dailyFinalSummaryCollection,$dailyDepositDeliveryCollection);

        $WeeklyDSPRData=$this->WeeklyDSPRReport($weeklyFinalSummaryCollection,$weeklyDepositDeliveryCollection);

        $customerService=$this->CustomerService($dayName,$weeklyFinalSummaryCollection,$lookBackFinalSummaryCollection);
        if (is_array($customerService) && isset($customerService[5]['dailyScore'], $customerService[5]['weeklyScore'])) {
    // after you build $dailyDSPRData and $WeeklyDSPRData:
$dailyDSPRData['data']['Customer_count_percent']  = (float) ($customerService[5]['dailyScore']);
$WeeklyDSPRData['data']['Customer_count_percent'] = (float) ($customerService[5]['weeklyScore']);

$dailyDSPRData['data']['Customer_Service'] = (
    (float) $dailyDSPRData['data']['Customer_count_percent'] +
    (float) $dailyDSPRData['data']['Put_into_Portal_Percent'] +
    (float) $dailyDSPRData['data']['In_Portal_on_Time_Percent']
) / 3;

$WeeklyDSPRData['data']['Customer_Service'] = (
    (float) $WeeklyDSPRData['data']['Customer_count_percent'] +
    (float) $WeeklyDSPRData['data']['Put_into_Portal_Percent'] +
    (float) $WeeklyDSPRData['data']['In_Portal_on_Time_Percent']
) / 3;

}

        $upselling =$this->Upselling($dayName,$weeklySummaryItemCollection,$lookBackSummaryItemCollection);
        if (is_array($upselling) && isset($upselling[5]['dailyScore'], $upselling[5]['weeklyScore'])) {
    $dailyDSPRData['data']['Upselling']  = (float) ($upselling[5]['dailyScore']);
    $WeeklyDSPRData['data']['Upselling'] = (float) ($upselling[5]['weeklyScore']);

        }

        return [
            'Filtering Values'=>[
                'date'                  =>$date,
                'store'                 =>$store,
                'items'                 =>$itemIds,,
                'week'                  =>$weekNumber,
                'weekStartDate'         =>$weekStartDate,
                'weekEndDate'           =>$weekEndDate,
                'look back start'       =>$lookBackStartDate,
                'look back end'         =>$lookBackEndDate,
                'depositDeliveryUrl'    =>$url,
                ],
            // 'collections'=>[
            //     'daily'=>[
            //         'dailyDepositDeliveryCollection'     =>$dailyDepositDeliveryCollection,
            //         'dailyFinalSummaryCollection'   =>$dailyFinalSummaryCollection,
            //         'dailySummaryItemCollection'    =>$dailySummaryItemCollection,
            //         'dailyHourlySalesCollection'    =>$dailyHourlySalesCollection,
            //     ],
            //     'weekly'=>[
            //         'weeklyDepositDeliveryCollection'=>$weeklyDepositDeliveryCollection
            //     ],
            //     'lookBack'=>[
            //         'lookBackFinalSummary'=>$lookBackFinalSummaryCollection
            //     ]
            // ],
            'reports'=>[
                'daily'=>[
                    'dailyHourlySales'  =>$dailyHourlySalesData,
                    'dailyDSQRData'     =>$dailyDSQRData,
                    'dailyDSPRData' =>$dailyDSPRData
                ],
                'weekly'=>[
                    'DSPRData' =>$WeeklyDSPRData,
                    // 'customerService'=>$customerService,
                    // 'upselling'=>$upselling
                ]
            ]


        ];
    }

    public function DSQRReport(){

    }

    public function DailyHourlySalesReport($dailyHourlySalesCollection)
{
    // If empty, keep the new shape but mark hours as empty
    if ($dailyHourlySalesCollection->isEmpty()) {
        return [
            'franchise_store' => null,
            'business_date'   => null,
            'hours'           => array_fill_keys(range(0, 23), (object)[]),
        ];
    }

    // Derive store/date from the first record in the collection
    $first = $dailyHourlySalesCollection->first();
    $store = $first->franchise_store ?? null;
    $date  = $first->business_date ?? null;

    // Pre-build the hours map 0..23
    $hours = [];
    for ($h = 0; $h <= 23; $h++) {
        // Filter the in-memory collection for this hour
        $subset = $dailyHourlySalesCollection->where('hour', $h);

        if ($subset->isEmpty()) {
            $hours[$h] = (object)[];  // empty object for missing hour
            continue;
        }

        // Aggregate
        $hours[$h] = [
            'Total_Sales'       => (float) $subset->sum('total_sales'),
            'Phone_Sales'       => (float) $subset->sum('phone_sales'),
            'Call_Center_Agent' => (float) $subset->sum('call_center_sales'),
            'Drive_Thru'        => (float) $subset->sum('drive_thru_sales'),
            'Website'           => (float) $subset->sum('website_sales'),
            'Mobile'            => (float) $subset->sum('mobile_sales'),
            'Order_Count'       => (int)   $subset->sum('order_count'),
        ];
    }

    return [
        'franchise_store' => $store,
        'business_date'   => $date,
        'hours'           => $hours,
    ];
}


    public function DailyDSQRReport($depositDeliveryCollection){

        if ($depositDeliveryCollection->isEmpty()) {
            return "No deposit delivery data available.";
        }

        return[
            'score'=>[
                'DD_Most_Loved_Restaurant'=>(float)($depositDeliveryCollection->value('Hook_MostLovedRestaurant')),
                'DD_Optimization_Score'=>$depositDeliveryCollection->value('Hook_OptimizationScore'),
                'DD_Ratings_Average_Rating'=>(float)($depositDeliveryCollection->value('Hook_RatingsAverageRating')),
                'DD_Cancellations_Sales_Lost'=>(float)($depositDeliveryCollection->value('Hook_CancellationsSalesLost2')),
                'DD_Missing_or_Incorrect_Error_Charges'=>(float)($depositDeliveryCollection->value('Hook_MissingOrIncorrectErrorCharges')),
                'DD_Avoidable_Wait_M-Sec'=>(float)($depositDeliveryCollection->value('Hook_AvoidableWaitMSec2')),
                'DD_Total_Dasher_Wait_M-Sec'=>(float)($depositDeliveryCollection->value('Hook_TotalDasherWaitMSec')),
                'DD_number_1_Top_Missing_or_Incorrect_Item'=>(float)($depositDeliveryCollection->value('Hook_1TopMissingOrIncorrectItem')),
                'DD_Downtime_H-MM'=>(float)($depositDeliveryCollection->value('Hook_DowntimeHMM')),
                'DD_Reviews_Responded'=>(float)($depositDeliveryCollection->value('Hook_ReviewsResponded')),

                'UE_Customer_reviews_overview'=>(float)($depositDeliveryCollection->value('Hook_CustomerReviewsOverview')),
                'UE_Cost_of_Refunds'=>(float)($depositDeliveryCollection->value('Hook_CostOfRefunds')),
                'UE_Unfulfilled_order_rate'=>(float)($depositDeliveryCollection->value('Hook_UnfulfilledOrderRate')),
                'UE_Time_unavailable_during_open_hours_hh-mm'=>(float)($depositDeliveryCollection->value('Hook_TimeUnavailableDuringOpenHoursHhmm')),
                'UE_Top_inaccurate_item'=>(float)($depositDeliveryCollection->value('Hook_TopInaccurateItem')),
                'UE_Reviews_Responded'=>(float)($depositDeliveryCollection->value('Hook_ReviewsResponded_2')),

                'GH_Rating'=>(float)($depositDeliveryCollection->value('Hook_Rating')),
                'GH_Food_was_good'=>(float)($depositDeliveryCollection->value('Hook_FoodWasGood')),
                'GH_Delivery_was_on_time'=>(float)($depositDeliveryCollection->value('Hook_DeliveryWasOnTime')),
                'GH_Order_was_accurate'=>(float)($depositDeliveryCollection->value('Hook_OrderWasAccurate')),
            ],
            'is_on_track'=>[

                'DD_NAOT_Ratings_Average_Rating'=>$depositDeliveryCollection->value('Hook_NAOT_RatingsAverageRating'),
                'DD_NAOT_Cancellations_Sales_Lost'=>$depositDeliveryCollection->value('Hook_NAOT_CancellationsSalesLost'),
                'DD_NAOT_Missing_or_Incorrect_Error_Charges'=>$depositDeliveryCollection->value('Hook_NAOT_MissingOrIncorrectErrorCharges'),
                'DD_NAOT_Avoidable_Wait_M-Sec'=>$depositDeliveryCollection->value('Hook_NAOT_AvoidableWaitMSec'),
                'DD_NAOT_Total_Dasher_Wait_M-Sec'=>$depositDeliveryCollection->value('Hook_NAOT_TotalDasherWaitMSec'),
                'DD_NAOT_Downtime_H-MM'=>$depositDeliveryCollection->value('Hook_NAOT_DowntimeHMM'),

                'UE_NAOT_Customer_reviews_overview'=>$depositDeliveryCollection->value('Hook_NAOT_CustomerReviewsOverview'),
                'UE_NAOT_Cost_of_Refunds'=>$depositDeliveryCollection->value('Hook_NAOT_CostOfRefunds'),
                'UE_NAOT_Unfulfilled_order_rate'=>$depositDeliveryCollection->value('Hook_NAOT_UnfulfilledOrderRate'),
                'UE_NAOT_Time_unavailable_during_open_hours_hh-mm'=>$depositDeliveryCollection->value('Hook_NAOT_TimeUnavailableDuringOpenHoursHhmm'),

                'GH_NAOT_Rating'=>$depositDeliveryCollection->value('Hook_NAOT_Rating'),
                'GH_NAOT_Food_was_good'=>$depositDeliveryCollection->value('Hook_NAOT_FoodWasGood'),
                'GH_NAOT_Delivery_was_on_time'=>$depositDeliveryCollection->value('Hook_NAOT_DeliveryWasOnTime'),
                'GH_NAOT_Order_was_accurate'=>$depositDeliveryCollection->value('Hook_NAOT_OrderWasAccurate'),
            ]
        ];

    }

    public function DailyDSPRReport($dailyFinalSummaryCollection,$depositDeliveryCollection){
         if ($dailyFinalSummaryCollection->isEmpty()) {
            return "No Final Summary data available.";
        }
        if ($depositDeliveryCollection->isEmpty()) {
            return "No deposit delivery data available.";
        }

        $workingHours = $depositDeliveryCollection ->sum('HookEmployeesWorkingHours');
        $deposit =$depositDeliveryCollection ->sum('HookDepositAmount');
        $totalSales =$dailyFinalSummaryCollection ->sum('total_sales');

        $cashSales =$dailyFinalSummaryCollection ->sum('cash_sales');
        $customerCount =$dailyFinalSummaryCollection->sum('customer_count');
        return[
            'helpers'=>[
                'WH' =>$workingHours,
                'deposit' =>$deposit,
                'totalSales' =>$totalSales,
                'cashSales' =>$cashSales,
            ],
            'data'=>[
                'labor'=> $workingHours * 16 /$totalSales,
                'waste_gateway' =>$dailyFinalSummaryCollection->sum('total_waste_cost'),
                'over_short' => $deposit - $cashSales,
                'Refunded_order_Qty'=>$dailyFinalSummaryCollection->sum('refunded_order_qty'),
                'Total_Cash_Sales'=>$cashSales,
                'Total_Sales'=>$totalSales,
                'Waste_Alta'=>$depositDeliveryCollection->sum('HookAltimetricWaste'),
                'Modified_Order_Qty'=>$dailyFinalSummaryCollection->sum('modified_order_qty'),
                'Total_TIPS'=> $dailyFinalSummaryCollection->sum('total_tips') + $depositDeliveryCollection->sum('HookHowMuchTips'),
                'Customer_count'=>$customerCount,
                'DoorDash_Sales'=>$dailyFinalSummaryCollection->sum('doordash_sales'),
                'UberEats_Sales'=>$dailyFinalSummaryCollection->sum('ubereats_sales'),
                'GrubHub_Sales'=>$dailyFinalSummaryCollection->sum('grubhub_sales'),
                'Phone'=>$dailyFinalSummaryCollection->sum('phone_sales'),
                'Call_Center_Agent'=>$dailyFinalSummaryCollection->sum('call_center_sales'),
                'Website'=>$dailyFinalSummaryCollection->sum('website_sales'),
                'Mobile'=>$dailyFinalSummaryCollection->sum('mobile_sales'),
                'Digital_Sales_Percent'=>$dailyFinalSummaryCollection->sum('digital_sales_percent'),
                'Total_Portal_Eligible_Transactions'=>$dailyFinalSummaryCollection->sum('portal_transactions'),
                'Put_into_Portal_Percent'=>$dailyFinalSummaryCollection->sum('portal_used_percent'),
                'In_Portal_on_Time_Percent'=>$dailyFinalSummaryCollection->sum('in_portal_on_time_percent'),
                'Drive_Thru_Sales'=>$dailyFinalSummaryCollection->sum('drive_thru_sales'),
                'Upselling'=>null,
                'Cash_Sales_Vs_Deposite_Difference'=>$deposit - $cashSales,
                'Avrage_ticket'=>$totalSales/$customerCount


            ]
        ];
    }

    public function WeeklyDSPRReport($weeklyFinalSummaryCollection, $weeklyDepositDeliveryCollection)
{
    if ($weeklyFinalSummaryCollection->isEmpty()) {
        return "No Final Summary data available.";
    }
    if ($weeklyDepositDeliveryCollection->isEmpty()) {
        return "No deposit delivery data available.";
    }

    // Group by day name
    $finalSummaryByDay = $weeklyFinalSummaryCollection->groupBy(function ($item) {
        return Carbon::parse($item['business_date'])->dayName; // e.g., 'Thursday'
    });

    $depositDeliveryByDay = $weeklyDepositDeliveryCollection->groupBy(function ($item) {
        return Carbon::parse($item['HookWorkDaysDate'])->dayName;
    });

    // How many days have FinalSummary entries
    $finalSummaryDaysCount = $finalSummaryByDay->count();

    $laborForEachDay = [];

    foreach ($finalSummaryByDay as $day => $fsRecords) {
        // Sum total sales for that day (don’t assume [0])
        $totalSales = (float) $fsRecords->sum('total_sales');

        // Get the deposit/delivery records for this day safely
        $ddRecords = $depositDeliveryByDay->get($day, collect());

        // Sum hours; if day missing → 0
        $employeesWorkingHours = (float) $ddRecords->sum('HookEmployeesWorkingHours');

        if ($totalSales > 0 && $employeesWorkingHours > 0) {
            $laborForEachDay[$day] = ($employeesWorkingHours * 16) / $totalSales;
        }
        // else: either side missing/zero → skip or set null (your choice)
        // $laborForEachDay[$day] = null;
    }

    $sumOfAllLabors = array_sum($laborForEachDay);
    $totalLabors = $finalSummaryDaysCount > 0 ? $sumOfAllLabors / $finalSummaryDaysCount : 0.0;

    $workingHours = (float) $weeklyDepositDeliveryCollection->sum('HookEmployeesWorkingHours');
    $deposit      = (float) $weeklyDepositDeliveryCollection->sum('HookDepositAmount');
    $totalSales   = (float) $weeklyFinalSummaryCollection->sum('total_sales');

    $cashSales    = (float) $weeklyFinalSummaryCollection->sum('cash_sales');
    $customerCount= (float) $weeklyFinalSummaryCollection->sum('customer_count');

    $tipsFinalSummary   = (float) $weeklyFinalSummaryCollection->sum('total_tips');
    $tipsDepositDelivery= (float) $weeklyDepositDeliveryCollection->sum('HookHowMuchTips');

    return [
        'helpers' => [
            'WH'        => $workingHours,
            'deposit'   => $deposit,
            'totalSales'=> $totalSales,
            'cashSales' => $cashSales,
        ],
        'data' => [
            'labor'                          => $totalLabors,
            'waste_gateway'                  => (float) $weeklyFinalSummaryCollection->sum('total_waste_cost'),
            'over_short'                     => $deposit - $cashSales,
            'Refunded_order_Qty'             => (float) $weeklyFinalSummaryCollection->sum('refunded_order_qty'),
            'Total_Cash_Sales'               => $cashSales,
            'Total_Sales'                    => $totalSales,
            'Waste_Alta'                     => (float) $weeklyDepositDeliveryCollection->sum('HookAltimetricWaste'),
            'Modified_Order_Qty'             => (float) $weeklyFinalSummaryCollection->sum('modified_order_qty'),
            'Total_TIPS'                     => $tipsFinalSummary + $tipsDepositDelivery,
            'Customer_count'                 => $customerCount,
            'DoorDash_Sales'                 => (float) $weeklyFinalSummaryCollection->sum('doordash_sales'),
            'UberEats_Sales'                 => (float) $weeklyFinalSummaryCollection->sum('ubereats_sales'),
            'GrubHub_Sales'                  => (float) $weeklyFinalSummaryCollection->sum('grubhub_sales'),
            'Phone'                          => (float) $weeklyFinalSummaryCollection->sum('phone_sales'),
            'Call_Center_Agent'              => (float) $weeklyFinalSummaryCollection->sum('call_center_sales'),
            'Website'                        => (float) $weeklyFinalSummaryCollection->sum('website_sales'),
            'Mobile'                         => (float) $weeklyFinalSummaryCollection->sum('mobile_sales'),
            'Digital_Sales_Percent'          => $finalSummaryDaysCount ? (float) $weeklyFinalSummaryCollection->sum('digital_sales_percent') / $finalSummaryDaysCount : 0.0,
            'Total_Portal_Eligible_Transactions' => (float) $weeklyFinalSummaryCollection->sum('portal_transactions'),
            'Put_into_Portal_Percent'        => $finalSummaryDaysCount ? (float) $weeklyFinalSummaryCollection->sum('portal_used_percent') / $finalSummaryDaysCount : 0.0,
            'In_Portal_on_Time_Percent'      => $finalSummaryDaysCount ? (float) $weeklyFinalSummaryCollection->sum('in_portal_on_time_percent') / $finalSummaryDaysCount : 0.0,
            'Drive_Thru_Sales'               => (float) $weeklyFinalSummaryCollection->sum('drive_thru_sales'),
            'Upselling'                      => null,
            'Cash_Sales_Vs_Deposite_Difference' => $finalSummaryDaysCount ? ($deposit - $cashSales) / $finalSummaryDaysCount : 0.0,
            'Avrage_ticket'                  => $customerCount > 0 ? $totalSales / $customerCount : 0.0,
        ]
    ];
}


    public function CustomerService($dayName,$weeklyFinalSummaryCollection,$lookBackFinalSummaryCollection){
        // check if collections are empty
        if ($weeklyFinalSummaryCollection->isEmpty()) {
            return "No weeklyFinalSummaryCollection data available.";
        }
        if ($lookBackFinalSummaryCollection->isEmpty()) {
            return "No lookBackFinalSummaryCollection data available.";
        }

        //weeklyfinalSummary by day
        $weeklyFinalSummaryDataByDay = $weeklyFinalSummaryCollection->groupBy(function ($item) {
            return Carbon::parse($item['business_date'])->dayName;
        })->map(function ($records) {
            return $records->pluck('customer_count');
        });

        //days count
        $weeklyFinalSummarydaysCount =$weeklyFinalSummaryDataByDay->count();

        //lookBackfinalSummary by day
        $lookBackfinalSummaryDataByDay = $lookBackFinalSummaryCollection->groupBy(function ($item) {
            return Carbon::parse($item['business_date'])->dayName;
        })->map(function ($dayRecords) {
            return (float) $dayRecords->avg('customer_count');
        });

        //days count
        $lookBackfinalSummarydaysCount =$lookBackfinalSummaryDataByDay->count();
        $lookBackdailyCounts = $lookBackFinalSummaryCollection
        ->groupBy(function ($item) {
            return Carbon::parse($item['business_date'])->dayName;
        })
        ->map(function ($dayRecords) {
            return $dayRecords->count(); // occurrences of that weekday
        });


        $weeklyTotal =  $weeklyFinalSummaryDataByDay->map(function ($values) {
            return $values->sum();
        })->sum();
        $lookBackTotal =  $weeklyFinalSummaryDataByDay->map(function ($values) {
            return $values->avg();   // sum inside each weekday
        })->sum();

        $weeklyAvr = $weeklyTotal /$weeklyFinalSummarydaysCount;
        $lookBackAvr = $lookBackfinalSummaryDataByDay->avg();

        //finals
        //for weekly customer service
        $weeklyFinalValue= ($weeklyAvr - $lookBackAvr)/$lookBackAvr;

        //for daily customer service
        $dailyForLookback = $lookBackfinalSummaryDataByDay->get($dayName);
        $dailyForWeekly = $weeklyFinalSummaryDataByDay->get($dayName)[0];
        
        $dailyFinalValue =($dailyForWeekly -$dailyForLookback)/$dailyForLookback;

        //final scores
        $dailyScore = $this->score($dailyFinalValue)/100;
        $weeklyScore = $this->score($weeklyFinalValue)/100;

        return[
                [
            'weeklyFinalSummaryDataByDay'=>$weeklyFinalSummaryDataByDay,
            'weeklyFinalSummarydaysCount'=>$weeklyFinalSummarydaysCount,
                ],  
                [
            'lookBackfinalSummaryDataByDay'=>$lookBackfinalSummaryDataByDay,
            'lookBackfinalSummarydaysCount'=>$lookBackfinalSummarydaysCount,
            'lookBackdailyCounts' =>$lookBackdailyCounts
            
                ],
                [
                    '$weeklyTotal' =>$weeklyTotal,
                    '$lookBackTotal'=>$lookBackTotal
                ],
                [
                   'weeklyAvr' => $weeklyAvr,
                   'lookBackAvr' =>$lookBackAvr
                ],
                [
                    '$dailyFinalValue'=>$dailyFinalValue,
                    '$weeklyFinalValue'=>$weeklyFinalValue,
                ],
                [
                    'dailyScore' =>$dailyScore,
                    'weeklyScore'=>$weeklyScore,
                ]
        ];
    }

    public function Upselling($dayName,$weeklyFinalSummaryCollection,$lookBackFinalSummaryCollection){
        // check if collections are empty
        if ($weeklyFinalSummaryCollection->isEmpty()) {
            return "No weeklyFinalSummaryCollection data available.";
        }
        if ($lookBackFinalSummaryCollection->isEmpty()) {
            return "No lookBackFinalSummaryCollection data available.";
        }

        //weeklyfinalSummary by day
        $weeklyFinalSummaryDataByDay = $weeklyFinalSummaryCollection->groupBy(function ($item) {
            return Carbon::parse($item['business_date'])->dayName;
        })->map(function ($records) {
            return $records->pluck('royalty_obligation');
        });

        //days count
        $weeklyFinalSummarydaysCount =$weeklyFinalSummaryDataByDay->count();

        //lookBackfinalSummary by day
        $lookBackfinalSummaryDataByDay = $lookBackFinalSummaryCollection->groupBy(function ($item) {
            return Carbon::parse($item['business_date'])->dayName;
        })->map(function ($dayRecords) {
            return (float) $dayRecords->avg('royalty_obligation');
        });

        //days count
        
        $lookBackfinalSummarydaysCount =$lookBackfinalSummaryDataByDay->count();
        $lookBackdailyCounts = $lookBackFinalSummaryCollection
        ->groupBy(function ($item) {
            return Carbon::parse($item['business_date'])->dayName;
        })
        ->map(function ($dayRecords) {
            return $dayRecords->count(); // occurrences of that weekday
        });


        $weeklyTotal =  $weeklyFinalSummaryDataByDay->map(function ($values) {
            return $values->sum();
        })->sum();
        $lookBackTotal =  $weeklyFinalSummaryDataByDay->map(function ($values) {
            return $values->avg();   // sum inside each weekday
        })->sum();

        $weeklyAvr = $weeklyTotal /$weeklyFinalSummarydaysCount;
        $lookBackAvr = $lookBackfinalSummaryDataByDay->avg();

        //finals
        //for weekly customer service
        $weeklyFinalValue= ($weeklyAvr - $lookBackAvr)/$lookBackAvr;

        //for daily customer service
        $dailyForLookback = $lookBackfinalSummaryDataByDay->get($dayName);
        $dailyForWeekly = $weeklyFinalSummaryDataByDay->get($dayName)[0];
        
        $dailyFinalValue =($dailyForWeekly -$dailyForLookback)/$dailyForLookback;

        //final scores
        $dailyScore = $this->score($dailyFinalValue)/100;
        $weeklyScore = $this->score($weeklyFinalValue)/100;

        return[
                [
            'weeklyFinalSummaryDataByDay'=>$weeklyFinalSummaryDataByDay,
            'weeklyFinalSummarydaysCount'=>$weeklyFinalSummarydaysCount,
                ],  
                [
            'lookBackfinalSummaryDataByDay'=>$lookBackfinalSummaryDataByDay,
            'lookBackfinalSummarydaysCount'=>$lookBackfinalSummarydaysCount,
            'lookBackdailyCounts' =>$lookBackdailyCounts
            
                ],
                [
                    '$weeklyTotal' =>$weeklyTotal,
                    '$lookBackTotal'=>$lookBackTotal
                ],
                [
                   'weeklyAvr' => $weeklyAvr,
                   'lookBackAvr' =>$lookBackAvr
                ],
                [
                     '$dailyFinalValue'=>$dailyFinalValue,
                    '$weeklyFinalValue'=>$weeklyFinalValue,
                ],
                [
                    'dailyScore' =>$dailyScore,
                    'weeklyScore'=>$weeklyScore,
                   
                ]
        ];
    }

    public function score ($value){
        $score = 0;
        if ($value >= -1.00 && $value <= -0.1001) {
            $score = 75;
        } elseif ($value >= -0.10 && $value <= -0.0401) {
            $score = 80;
        } elseif ($value >= -0.04 && $value <= -0.0001) {
            $score = 85;
        } elseif ($value >= 0.00 && $value <= 0.0399) {
            $score = 90;
        } elseif ($value >= 0.04 && $value <= 0.0699) {
            $score = 95;
        } elseif ($value >= 0.07 && $value <= 1.00) {
            $score = 100;
        }

        return $score;
    }

    /**
     * NEW: catalog endpoint returning one row per item_id with its latest known menu_item_name.
     * Useful for the frontend to render names while we submit IDs in requests.
     */
    public function items($store)
{
    // Subquery with window function to get latest row per item_id for the store
    $sub = DB::table('summary_items')
        ->select([
            'item_id',
            'menu_item_name',
            DB::raw('ROW_NUMBER() OVER (PARTITION BY item_id ORDER BY business_date DESC, id DESC) AS rn'),
        ])
        ->where('franchise_store', '=', $store)
        ->whereNotNull('item_id');

    // Filter rn = 1 (latest), then order for UI
    $rows = DB::query()
        ->fromSub($sub, 't')
        ->where('t.rn', '=', 1)
        ->orderBy('t.menu_item_name')
        ->get(['item_id', 'menu_item_name']);

    return response()->json([
        'store' => $store,
        'count' => $rows->count(),
        'items' => $rows, // each row: { item_id, menu_item_name }
    ]);
}
}
