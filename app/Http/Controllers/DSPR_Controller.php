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
class DSPR_Controller extends Controller
{
    public function index($store, $date, $items = null)
    {
        // --- guards ---
        if (empty($store) || empty($date)) {
            return response()->noContent();
        }
        if (!preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', $date)) {
            return response()->json(['error' => 'Invalid date format, expected YYYY-MM-DD or YYYY-M-D'], 400);
        }

        /***dates Used**/
        $givenDate = Carbon::parse($date);
        $usedDate = CarbonImmutable::parse($givenDate);
        //for week
        $weekNumber = $usedDate->isoWeek;
        $weekStartDate = $usedDate->startOfWeek(Carbon::TUESDAY);
        $weekEndDate = $weekStartDate->addDays(6);
        //for lookback
        $lookBackStartDate=$usedDate->subDays(84);
        $lookBackEndDate=$usedDate;

        //items parameter
        $decodedItems = $items ? urldecode($items) : null;
        $Itemslist = explode(',', $decodedItems);


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

        $dailySummaryItemCollection = SummaryItem::
            where('franchise_store', '=',$store )
            ->where('business_date','=',$usedDate)
            ->whereIn('menu_item_name',$Itemslist)
            ->get();

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
            ->whereIn('menu_item_name',$Itemslist)
            ->get();

        $weeklyHourlySalesCollection = HourlySales::
        where('franchise_store', '=',$store )
        ->whereBetween('business_date', [$weekStartDate, $weekEndDate])
        ->get();

        //lookback
        $lookBackFinalSummaryCollection = FinalSummary::
            where('franchise_store', '=',$store )
            ->whereBetween('business_date', [$lookBackStartDate, $lookBackEndDate])
            ->get();

        $lookBackSummaryItemCollection = SummaryItem::
            where('franchise_store', '=',$store )
            ->whereBetween('business_date', [$lookBackStartDate, $lookBackEndDate])
            ->whereIn('menu_item_name',$Itemslist)
            ->get();

        $lookBackHourlySalesCollection = HourlySales::
        where('franchise_store', '=',$store )
        ->whereBetween('business_date', [$lookBackStartDate, $lookBackEndDate])
        ->get();

        //calling the methods for the data
        $dailyHourlySalesData=$this->DailyHourlySalesReport($dailyHourlySalesCollection);
        $dailyDSQRData=$this->DailyDSQRReport($dailyDepositDeliveryCollection);
        $dailyDSPRData=$this->DailyDSPRReport($dailyFinalSummaryCollection,$dailyDepositDeliveryCollection);

        $WeeklyDSPRData=$this->WeeklyDSPRReport($weeklyFinalSummaryCollection,$weeklyDepositDeliveryCollection);

        return [
            'Filtering Values'=>[
                'date'                  =>$date,
                'store'                 =>$store,
                'items'                 =>$Itemslist,
                'week'                  =>$weekNumber,
                'weekStartDate'         =>$weekStartDate,
                'weekEndDate'           =>$weekEndDate,
                'look back start'       =>$lookBackStartDate,
                'look back end'         =>$lookBackEndDate,
                'depositDeliveryUrl'    =>$url,
                ],
            'collections'=>[
                'daily'=>[
                    'dailyDepositDeliveryCollection'     =>$dailyDepositDeliveryCollection,
                    'dailyFinalSummaryCollection'   =>$dailyFinalSummaryCollection,
                    'dailySummaryItemCollection'    =>$dailySummaryItemCollection,
                    'dailyHourlySalesCollection'    =>$dailyHourlySalesCollection,
                ],
                'weekly'=>[
                    'weeklyDepositDeliveryCollection'=>$weeklyDepositDeliveryCollection
                ]
            ],
            'reports'=>[
                'daily'=>[
                    'dailyHourlySales'  =>$dailyHourlySalesData,
                    'dailyDSQRData'     =>$dailyDSQRData,
                    'dailyDSPRData' =>$dailyDSPRData
                ],
                'weekly'=>[
                    'DSPRData' =>$WeeklyDSPRData
                ]
            ]


        ];
    }

    public function DSQRReport(){

    }

    public function DailyHourlySalesReport($dailyHourlySalesCollection){
        // for if the collection is empty
        if ($dailyHourlySalesCollection->isEmpty()) {
            return "No sales data available.";
        }

        $rows=[];

        foreach($dailyHourlySalesCollection as $record){
            $hour = $record->hour;
            $store = $record->franchise_store;
            $date = $record->business_date;

            $data = $record ->where('hour',$hour)-> where('business_date',$date)-> where('franchise_store',$store);
               $rows[] =[
                'franchise_store'=>$store,
                'business_date'=>$date,
                'Hour'=>$hour,
                'Total_Sales'=>(float)($data ->sum('total_sales')),
                'Total_Sales2'=>(float)($data ->sum('total_sales')),
                'Phone_Sales'=>(float)($data ->sum('phone_sales')),
                'Call_Center_Agent'=>(float)($data ->sum('call_center_sales')),
                'Drive_Thru'=>(float)($data  ->sum('drive_thru_sales')),
                'Website'=>(float)($data ->sum('website_sales')),
                'Mobile'=>(float)($data ->sum('mobile_sales')),
                'Order_Count'=>(float)($data ->sum('order_count'))
                ,];

        }


        return $rows;

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

    public function WeeklyDSPRReport($weeklyFinalSummaryCollection,$weeklyDepositDeliveryCollection){
        if ($weeklyFinalSummaryCollection->isEmpty()) {
            return "No Final Summary data available.";
        }
        if ($weeklyDepositDeliveryCollection->isEmpty()) {
            return "No deposit delivery data available.";
        }

        $workingHours = $weeklyDepositDeliveryCollection ->sum('HookEmployeesWorkingHours');
        $deposit =$weeklyDepositDeliveryCollection ->sum('HookDepositAmount');
        $totalSales =$weeklyFinalSummaryCollection ->sum('total_sales');

        $cashSales =$weeklyFinalSummaryCollection ->sum('cash_sales');
        $customerCount =$weeklyFinalSummaryCollection->sum('customer_count');
        return[
            'helpers'=>[
                'WH' =>$workingHours,
                'deposit' =>$deposit,
                'totalSales' =>$totalSales,
                'cashSales' =>$cashSales,
            ],
            'data'=>[
                'labor'=> $workingHours * 16 /$totalSales,
                'waste_gateway' =>$weeklyFinalSummaryCollection->sum('total_waste_cost'),
                'over_short' => $deposit - $cashSales,
                'Refunded_order_Qty'=>$weeklyFinalSummaryCollection->sum('refunded_order_qty'),
                'Total_Cash_Sales'=>$cashSales,
                'Total_Sales'=>$totalSales,
                'Waste_Alta'=>$weeklyDepositDeliveryCollection->sum('HookAltimetricWaste'),
                'Modified_Order_Qty'=>$weeklyFinalSummaryCollection->sum('modified_order_qty'),
                'Total_TIPS'=> $weeklyFinalSummaryCollection->sum('total_tips') + $weeklyDepositDeliveryCollection->sum('HookHowMuchTips'),
                'Customer_count'=>$customerCount,
                'DoorDash_Sales'=>$weeklyFinalSummaryCollection->sum('doordash_sales'),
                'UberEats_Sales'=>$weeklyFinalSummaryCollection->sum('ubereats_sales'),
                'GrubHub_Sales'=>$weeklyFinalSummaryCollection->sum('grubhub_sales'),
                'Phone'=>$weeklyFinalSummaryCollection->sum('phone_sales'),
                'Call_Center_Agent'=>$weeklyFinalSummaryCollection->sum('call_center_sales'),
                'Website'=>$weeklyFinalSummaryCollection->sum('website_sales'),
                'Mobile'=>$weeklyFinalSummaryCollection->sum('mobile_sales'),
                'Digital_Sales_Percent'=>$weeklyFinalSummaryCollection->sum('digital_sales_percent'),
                'Total_Portal_Eligible_Transactions'=>$weeklyFinalSummaryCollection->sum('portal_transactions'),
                'Put_into_Portal_Percent'=>$weeklyFinalSummaryCollection->sum('portal_used_percent'),
                'In_Portal_on_Time_Percent'=>$weeklyFinalSummaryCollection->sum('in_portal_on_time_percent'),
                'Drive_Thru_Sales'=>$weeklyFinalSummaryCollection->sum('drive_thru_sales'),
                'Upselling'=>null,
                'Cash_Sales_Vs_Deposite_Difference'=>$deposit - $cashSales,
                'Avrage_ticket'=>$totalSales/$customerCount


            ]
        ];
    }
}
