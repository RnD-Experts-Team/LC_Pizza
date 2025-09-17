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


        // External deposit/delivery data
        $base = rtrim('https://hook.pneunited.com/api/deposit-delivery-dsqr', '/');
        $url  = $base.'/'.rawurlencode($store).'/'.rawurlencode($date);

        // Make the GET request
        $response = Http::get($url);

        if ($response->successful()) {
            // Decode the JSON response into a PHP array
            $data = $response->json();

            // Convert the array into a Laravel collection
            $depositDeliveryCollection = collect($data['weeklyDepositDelivery']);

        } else {
            $depositDeliveryCollection = collect();
        }



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
        where('franchise_store', '=',$store )
        ->where('business_date','=',$usedDate)
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



        return [
            'Filtering Values'=>[
                'date'=>$date,
                'store' =>$store,
                'items'=>$Itemslist,
                'week' =>$weekNumber,
                'weekStartDate' =>$weekStartDate,
                'weekEndDate'=>$weekEndDate,

                'look back start'=>$lookBackStartDate,
                'look back end'=>$lookBackEndDate,
                'depositDeliveryUrl' =>$url,
                ],
            'collections'=>[
                'daily'=>[
                    'depositDeliveryCollection' =>$depositDeliveryCollection,
                    'dailyFinalSummaryCollection'=>$dailyFinalSummaryCollection,
                    'dailySummaryItemCollection'=>$dailySummaryItemCollection,
                    'dailyHourlySalesCollection' =>$dailyHourlySalesCollection
                    ]
            ],


        ];
    }

    /**
     * Parse comma-separated items string to array
     */





}
