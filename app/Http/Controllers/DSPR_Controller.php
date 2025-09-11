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

class DSPR_Controller extends Controller
{
    public function index($store, $date)
    {
        // --- guards ---
        if (empty($store) || empty($date)) {
            return response()->noContent();
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return response()->json(['error' => 'Invalid date format, expected YYYY-MM-DD'], 400);
        }

        // --- compute custom week [Tue..Mon] ---
        try {
            $day   = Carbon::parse($date);
            $start = $day->copy()->startOfWeek(CarbonInterface::TUESDAY)->startOfDay(); // Tuesday 00:00
            $end   = $start->copy()->addDays(6)->endOfDay();                             // Monday 23:59:59
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Invalid date value'], 400);
        }

        $startValue = $start->toDateString(); // week start (Tue)
        $endValue   = $end->toDateString();   // week end   (Mon)

        $weekDates = [];
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $weekDates[] = $d->toDateString();
        }
        // HourlySales: ONLY $date
        $dailyHourlySales = HourlySales::where('franchise_store', $store)
            ->where('business_date', $date)
            ->get();


        $weeklyHourlySales = $dailyHourlySales;


        $weeklySummaryItems = SummaryItem::where('franchise_store', $store)
            ->whereBetween('business_date', [$startValue, $endValue])
            ->get();

        $weeklyFinalSummaries = FinalSummary::where('franchise_store', $store)
            ->whereBetween('business_date', [$startValue, $endValue])
            ->get();

        $lookbackStart      = $day->copy()->subDays(84)->startOfDay(); // 84 days before $date
        $lookbackStartValue = $lookbackStart->toDateString();

        // Calculate the end of the previous week (before current week starts)
        $currentWeekStart = $start->copy(); // Tuesday of current week
        $lookbackEnd = $currentWeekStart->copy()->subDay()->endOfDay(); // Monday before current week
        $lookbackEndValue = $lookbackEnd->toDateString();

        $lookbackSummaryItems = SummaryItem::where('franchise_store', $store)
            ->whereBetween('business_date', [$lookbackStartValue, $lookbackEndValue])
            ->get();

        $lookbackFinalSummaries = FinalSummary::where('franchise_store', $store)
            ->whereBetween('business_date', [$lookbackStartValue, $lookbackEndValue])
            ->get();

        // External deposit/delivery

        $base = rtrim('https://hook.pneunited.com/api/deposit-delivery-dsqr', '/');
        $url  = $base.'/'.rawurlencode($store).'/'.rawurlencode($date);

        $resp = Http::timeout(15)->acceptJson()->get($url);
        $weeklyDepositDelivery = collect($resp->successful() ? $resp->json('weeklyDepositDelivery') : []);
        $dailyDepositDelivery  = $weeklyDepositDelivery->where('HookWorkDaysDate', $date)->values();


        // Daily slices (for KPIs)

        $dailySummaryItems    = $weeklySummaryItems->where('business_date', $date)->values();
        $dailyFinalSummaries  = $weeklyFinalSummaries->where('business_date', $date)->values();


        // Daily DSPR logic (KPIs)

        $dailyDSPR = $this->dailyDspr($dailyFinalSummaries, $dailyDepositDelivery);


        // Weekly DSPR logic (KPIs)

        $weeklyDSPR = $this->weeklyDspr($weekDates,$weeklyFinalSummaries, $weeklyDepositDelivery);


        // Weekly DSQR logics

        $dailyDSQR = $this->dailyDsqr(dailyDepositDelivery: $dailyDepositDelivery);

        $dailyHourlySalesReport = $this->dailyHourlySales($dailyHourlySales);

        //customer service
        $customerServiceReport= $this->customerService($lookbackFinalSummaries, $weeklyFinalSummaries);
        //return the data
        return response()->json([
            'store'       => $store,
            'anchor_date' => $date,
            'week_start'  => $startValue,
            'week_end'    => $endValue,
            '84 back value' =>$lookbackStartValue,
            'lookbackFinalSummaries' =>$lookbackFinalSummaries,
            'dailyDSQR'   => $dailyDSQR,
            'dailyDSPR'       => $dailyDSPR,
            'weeklyDSPR'      => $weeklyDSPR,
            'dailyHourlySalesReport' =>$dailyHourlySalesReport,
            'customerServiceReport' =>$customerServiceReport
        ]);

    }

    public function dailyDspr($dailyFinalSummaries, $dailyDepositDelivery){
        // ============================
        // Daily DSPR logic (KPIs)
        // ============================
        $sum = function ($collection, string $key) {
            return $collection->sum(function ($row) use ($key) {
                $v = is_array($row) ? ($row[$key] ?? 0) : ($row->{$key} ?? 0);
                return (float) $v;
            });
        };

        // External JSON field map
        $DD = [
            'hours'   => 'HookEmployeesWorkingHours',
            'deposit' => 'HookDepositAmount',
            'tips'    => 'HookHowMuchTips',
            'waste'   => 'HookAltimetricWaste',
        ];

        // Helpers
        $daily_WH        = $sum($dailyDepositDelivery, $DD['hours']);
        $daily_deposit   = $sum($dailyDepositDelivery, $DD['deposit']);
        $daily_tips_ext  = $sum($dailyDepositDelivery, $DD['tips']);

        $daily_totalSales = $sum($dailyFinalSummaries, 'total_sales');
        $daily_cashSales  = $sum($dailyFinalSummaries, 'cash_sales');

        $daily_labor = $daily_totalSales > 0 ? (float)(($daily_WH * 16) / $daily_totalSales) : null;

        $daily_wasteGateway  = $sum($dailyFinalSummaries, 'total_waste_cost');
        $daily_overShort     = $daily_deposit - $daily_cashSales;
        $daily_refundedOrderQty = $sum($dailyFinalSummaries, 'refunded_order_qty');
        $daily_totalTips     = $sum($dailyFinalSummaries, 'total_tips') + $daily_tips_ext;
        $daily_customerCount = $sum($dailyFinalSummaries, 'customer_count');

        $daily_doorDashSales = $sum($dailyFinalSummaries, 'doordash_sales');
        $daily_uberEatsSales = $sum($dailyFinalSummaries, 'ubereats_sales');
        $daily_grubHubSales  = $sum($dailyFinalSummaries, 'grubhub_sales');
        $daily_phone         = $sum($dailyFinalSummaries, 'phone_sales');
        $daily_callCenter    = $sum($dailyFinalSummaries, 'call_center_sales');
        $daily_website       = $sum($dailyFinalSummaries, 'website_sales');
        $daily_mobile        = $sum($dailyFinalSummaries, 'mobile_sales');

        // consider averaging these percent fields if multiple rows/day
        $daily_digitalSalesPercentSum   = $sum($dailyFinalSummaries, 'digital_sales_percent');
        $daily_portalTransactions       = $sum($dailyFinalSummaries, 'portal_transactions');
        $daily_portalUsedPercentSum     = $sum($dailyFinalSummaries, 'portal_used_percent');
        $daily_inPortalOnTimePercentSum = $sum($dailyFinalSummaries, 'in_portal_on_time_percent');

        $daily_driveThruSales = $sum($dailyFinalSummaries, 'drive_thru_sales');
        $daily_wasteAlta      = $sum($dailyDepositDelivery, $DD['waste']);
        $daily_modifiedOrderQty = $sum($dailyFinalSummaries, 'modified_order_qty');

        $daily_cashVsDepositDiff = $daily_deposit - $daily_cashSales;
        $daily_averageTicket     = $daily_customerCount > 0 ? $daily_totalSales / $daily_customerCount : null;

        $daily_upselling = null;

        $daily_DSPR = [
            'labor'                          => $daily_labor,
            'waste_gateway'                  => $daily_wasteGateway,
            'over_short'                     => $daily_overShort,
            'refunded_order_qty'             => $daily_refundedOrderQty,
            'total_cash_sales'               => $daily_cashSales,
            'total_sales'                    => $daily_totalSales,
            'waste_alta'                     => $daily_wasteAlta,
            'modified_order_qty'             => $daily_modifiedOrderQty,
            'total_tips'                     => $daily_totalTips,
            'customer_count'                 => $daily_customerCount,
            'doordash_sales'                 => $daily_doorDashSales,
            'ubereats_sales'                 => $daily_uberEatsSales,
            'grubhub_sales'                  => $daily_grubHubSales,
            'phone_sales'                    => $daily_phone,
            'call_center_sales'              => $daily_callCenter,
            'website_sales'                  => $daily_website,
            'mobile_sales'                   => $daily_mobile,
            'digital_sales_percent_sum'      => $daily_digitalSalesPercentSum,
            'portal_transactions'            => $daily_portalTransactions,
            'portal_used_percent_sum'        => $daily_portalUsedPercentSum,
            'in_portal_on_time_percent_sum'  => $daily_inPortalOnTimePercentSum,
            'drive_thru_sales'               => $daily_driveThruSales,
            'cash_vs_deposit_diff'           => $daily_cashVsDepositDiff,
            'average_ticket'                 => $daily_averageTicket,
            'upselling'                      => $daily_upselling,
        ];

        return $daily_DSPR;
    }

    public function weeklyDspr($weekDates, $weeklyFinalSummaries, $weeklyDepositDelivery)
    {


        // external JSON field map
        $DD = [
            'hours'   => 'HookEmployeesWorkingHours',
            'deposit' => 'HookDepositAmount',
            'tips'    => 'HookHowMuchTips',
            'waste'   => 'HookAltimetricWaste',
        ];

        // helpers (weekly aggregates)
        $weekly_WH         = (float) $weeklyDepositDelivery->sum($DD['hours']);
        $weekly_deposit    = (float) $weeklyDepositDelivery->sum($DD['deposit']);
        $weekly_tips_ext   = (float) $weeklyDepositDelivery->sum($DD['tips']);

        $weekly_totalSales = (float) $weeklyFinalSummaries->sum('total_sales');
        $weekly_cashSales  = (float) $weeklyFinalSummaries->sum('cash_sales');

        $weekly_labor =$weekly_totalSales > 0 ? $weekly_WH*16 / $weekly_totalSales : null;

        $weekly_wasteGateway            = (float) $weeklyFinalSummaries->sum('total_waste_cost');

        $weekly_overShort               = $weekly_deposit - $weekly_cashSales;

        $weekly_refundedOrderQty        = (int)   $weeklyFinalSummaries->sum('refunded_order_qty');
        $weekly_customerCount           = (int)   $weeklyFinalSummaries->sum('customer_count');
        $weekly_totalTips               = (float) $weeklyFinalSummaries->sum('total_tips') + $weekly_tips_ext;

        $weekly_doorDashSales           = (float) $weeklyFinalSummaries->sum('doordash_sales');
        $weekly_uberEatsSales           = (float) $weeklyFinalSummaries->sum('ubereats_sales');
        $weekly_grubHubSales            = (float) $weeklyFinalSummaries->sum('grubhub_sales');
        $weekly_phone                   = (float) $weeklyFinalSummaries->sum('phone_sales');
        $weekly_callCenter              = (float) $weeklyFinalSummaries->sum('call_center_sales');
        $weekly_website                 = (float) $weeklyFinalSummaries->sum('website_sales');
        $weekly_mobile                  = (float) $weeklyFinalSummaries->sum('mobile_sales');


        $weekly_digitalSalesPercentSum  = (float) $weeklyFinalSummaries->avg('digital_sales_percent');
        $weekly_portalTransactions      = (int)   $weeklyFinalSummaries->sum('portal_transactions');
        $weekly_portalUsedPercentSum    = (float) $weeklyFinalSummaries->avg('portal_used_percent');
        $weekly_inPortalOnTimePercentSum= (float) $weeklyFinalSummaries->avg('in_portal_on_time_percent');

        $weekly_driveThruSales          = (float) $weeklyFinalSummaries->sum('drive_thru_sales');
        $weekly_wasteAlta               = (float) $weeklyDepositDelivery->sum($DD['waste']);
        $weekly_modifiedOrderQty        = (int)   $weeklyFinalSummaries->sum('modified_order_qty');

        $weekly_cashVsDepositDiff       = $weekly_deposit - $weekly_cashSales;
        $weekly_averageTicket           = $weekly_customerCount > 0 ? $weekly_totalSales / $weekly_customerCount : null;

        $weekly_upselling = null;


        return [
            'labor'                          => $weekly_labor,
            'waste_gateway'                  => $weekly_wasteGateway,
            'over_short'                     => $weekly_overShort,
            'refunded_order_qty'             => $weekly_refundedOrderQty,
            'total_cash_sales'               => $weekly_cashSales,
            'total_sales'                    => $weekly_totalSales,
            'waste_alta'                     => $weekly_wasteAlta,
            'modified_order_qty'             => $weekly_modifiedOrderQty,
            'total_tips'                     => $weekly_totalTips,
            'customer_count'                 => $weekly_customerCount,
            'doordash_sales'                 => $weekly_doorDashSales,
            'ubereats_sales'                 => $weekly_uberEatsSales,
            'grubhub_sales'                  => $weekly_grubHubSales,
            'phone_sales'                    => $weekly_phone,
            'call_center_sales'              => $weekly_callCenter,
            'website_sales'                  => $weekly_website,
            'mobile_sales'                   => $weekly_mobile,
            'digital_sales_percent_sum'      => $weekly_digitalSalesPercentSum,
            'portal_transactions'            => $weekly_portalTransactions,
            'portal_used_percent_sum'        => $weekly_portalUsedPercentSum,
            'in_portal_on_time_percent_sum'  => $weekly_inPortalOnTimePercentSum,
            'drive_thru_sales'               => $weekly_driveThruSales,
            'cash_vs_deposit_diff'           => $weekly_cashVsDepositDiff,
            'average_ticket'                 => $weekly_averageTicket,
            'upselling'                      => $weekly_upselling,
        ];
    }
    public function dailyDsqr ($dailyDepositDelivery){


        $DD_mostLovedRestaurant= $dailyDepositDelivery->pluck('Hook_MostLovedRestaurant')->first();
        $DD_optimizationScore = $dailyDepositDelivery->pluck('Hook_OptimizationScore')->first();
        $DD_ratingsAverageRating = $dailyDepositDelivery->pluck('Hook_RatingsAverageRating')->first();
        $DD_cancellationsSalesLost= $dailyDepositDelivery->pluck('Hook_CancellationsSalesLost2')->first();
        $DD_missingOrIncorrectErrorCharges= $dailyDepositDelivery->pluck('Hook_MissingOrIncorrectErrorCharges')->first();
        $DD_avoidableWaitMSec= $dailyDepositDelivery->pluck('Hook_AvoidableWaitMSec2')->first();
        $DD_totalDasherWaitMSec= $dailyDepositDelivery->pluck('Hook_TotalDasherWaitMSec')->first();
        $DD_topOneMissingOrIncorrectItem= $dailyDepositDelivery->pluck('Hook_1TopMissingOrIncorrectItem')->first();
        $DD_downtimeHMM= $dailyDepositDelivery->pluck('Hook_DowntimeHMM')->first();
        $DD_reviewsResponded= $dailyDepositDelivery->pluck('Hook_ReviewsResponded')->first();


        $NAOT_DD_ratingsAverageRating = $dailyDepositDelivery->pluck('Hook_NAOT_RatingsAverageRating')->first();
        $NAOT_DD_cancellationsSalesLost= $dailyDepositDelivery->pluck('Hook_NAOT_CancellationsSalesLost')->first();
        $NAOT_DD_missingOrIncorrectErrorCharges= $dailyDepositDelivery->pluck('Hook_NAOT_MissingOrIncorrectErrorCharges')->first();
        $NAOT_DD_avoidableWaitMSec= $dailyDepositDelivery->pluck('Hook_NAOT_AvoidableWaitMSec')->first();
        $NAOT_DD_totalDasherWaitMSec= $dailyDepositDelivery->pluck('Hook_NAOT_TotalDasherWaitMSec')->first();
        $NAOT_DD_downtimeHMM= $dailyDepositDelivery->pluck('Hook_NAOT_DowntimeHMM')->first();


        $UE_customerReviewsOverview= $dailyDepositDelivery->pluck('Hook_CustomerReviewsOverview')->first();
        $UE_costOfRefunds= $dailyDepositDelivery->pluck('Hook_CostOfRefunds')->first();
        $UE_unfulfilledOrderRate= $dailyDepositDelivery->pluck('Hook_UnfulfilledOrderRate')->first();
        $UE_timeUnavailableDuringOpenHourshhmm= $dailyDepositDelivery->pluck('Hook_TimeUnavailableDuringOpenHoursHhmm')->first();
        $UE_topInaccurateItem= $dailyDepositDelivery->pluck('Hook_TopInaccurateItem')->first();
        $UE_reviewsResponded= $dailyDepositDelivery->pluck('Hook_ReviewsResponded_2')->first();

        $NAOT_UE_customerReviewsOverview= $dailyDepositDelivery->pluck('Hook_NAOT_CustomerReviewsOverview')->first();
        $NAOT_UE_costOfRefunds= $dailyDepositDelivery->pluck('Hook_NAOT_CostOfRefunds')->first();
        $NAOT_UE_unfulfilledOrderRate= $dailyDepositDelivery->pluck('Hook_NAOT_UnfulfilledOrderRate')->first();
        $NAOT_UE_timeUnavailableDuringOpenHourshhmm= $dailyDepositDelivery->pluck('Hook_NAOT_TimeUnavailableDuringOpenHoursHhmm')->first();


        $GH_rating= $dailyDepositDelivery->pluck('Hook_Rating')->first();
        $GH_roodWasGood= $dailyDepositDelivery->pluck('Hook_FoodWasGood')->first();
        $GH_deliveryWasOnTime= $dailyDepositDelivery->pluck('Hook_DeliveryWasOnTime')->first();
        $GH_orderWasAccurate= $dailyDepositDelivery->pluck('Hook_OrderWasAccurate')->first();

        $NAOT_GH_rating= $dailyDepositDelivery->pluck('Hook_NAOT_Rating')->first();
        $NAOT_GH_roodWasGood= $dailyDepositDelivery->pluck('Hook_NAOT_FoodWasGood')->first();
        $NAOT_GH_deliveryWasOnTime= $dailyDepositDelivery->pluck('Hook_NAOT_DeliveryWasOnTime')->first();
        $NAOT_GH_orderWasAccurate= $dailyDepositDelivery->pluck('Hook_NAOT_OrderWasAccurate')->first();

        return compact(
            'DD_mostLovedRestaurant',
            'DD_optimizationScore',
            'DD_ratingsAverageRating',
            'DD_cancellationsSalesLost',
            'DD_missingOrIncorrectErrorCharges',
            'DD_avoidableWaitMSec',
            'DD_totalDasherWaitMSec',
            'DD_topOneMissingOrIncorrectItem',
            'DD_downtimeHMM',
            'DD_reviewsResponded',

            'NAOT_DD_ratingsAverageRating',
            'NAOT_DD_cancellationsSalesLost',
            'NAOT_DD_missingOrIncorrectErrorCharges',
            'NAOT_DD_avoidableWaitMSec',
            'NAOT_DD_totalDasherWaitMSec',
            'NAOT_DD_downtimeHMM',

            'UE_customerReviewsOverview',
            'UE_costOfRefunds',
            'UE_unfulfilledOrderRate',
            'UE_timeUnavailableDuringOpenHourshhmm',
            'UE_topInaccurateItem',
            'UE_reviewsResponded',

            'NAOT_UE_customerReviewsOverview',
            'NAOT_UE_costOfRefunds',
            'NAOT_UE_unfulfilledOrderRate',
            'NAOT_UE_timeUnavailableDuringOpenHourshhmm',

            'GH_rating',
            'GH_roodWasGood',            // kept as-is
            'GH_deliveryWasOnTime',
            'GH_orderWasAccurate',

            'NAOT_GH_rating',
            'NAOT_GH_roodWasGood',       // kept as-is
            'NAOT_GH_deliveryWasOnTime',
            'NAOT_GH_orderWasAccurate'
        );
    }

    public function dailyHourlySales($dailyHourlySales): array
    {
        return $dailyHourlySales
            ->sortBy('hour')
            ->map(function ($row) {
                return [
                    'franchise_store'         => $row->franchise_store,
                    'business_date'           => $row->business_date,
                    'hour'                    => (int) $row->hour,
                    'total_sales'             => (float) $row->total_sales,
                    'phone_sales'             => (float) $row->phone_sales,
                    'call_center_sales'       => (float) $row->call_center_sales,
                    'drive_thru_sales'        => (float) $row->drive_thru_sales,
                    'website_sales'           => (float) $row->website_sales,
                    'mobile_sales'            => (float) $row->mobile_sales,
                    'website_sales_delivery'  => (float) $row->website_sales_delivery,
                    'mobile_sales_delivery'   => (float) $row->mobile_sales_delivery,
                    'doordash_sales'          => (float) $row->doordash_sales,
                    'ubereats_sales'          => (float) $row->ubereats_sales,
                    'grubhub_sales'           => (float) $row->grubhub_sales,
                    'order_count'             => (int) $row->order_count,
                ];
            })
            ->values()
            ->all();
    }

   public function customerService($lookbackFinalSummaries, $weeklyFinalSummaries)
{
    // Normalize to collections
    $toCollection = fn($x) => $x instanceof Collection ? $x : collect($x);

    $lookback = $toCollection($lookbackFinalSummaries);
    $weekly   = $toCollection($weeklyFinalSummaries);

    // Weekday labels in your desired order (Tue..Mon)
    $labels = ['Tue','Wed','Thu','Fri','Sat','Sun','Mon'];

    // Safe accessors (works for arrays or Eloquent models)
    $getDate = fn($r) => is_array($r) ? ($r['business_date'] ?? null) : ($r->business_date ?? null);
    $getCust = fn($r) => (float) (is_array($r) ? ($r['customer_count'] ?? 0) : ($r->customer_count ?? 0));

    // Map a record to a weekday label
    $labelOf = function ($r) use ($getDate) {
        $dow = Carbon::parse($getDate($r))->dayOfWeek; // 0=Sun .. 6=Sat
        return ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'][$dow];
    };

    // --- current week: just take the value (sum in case there are multiple rows for a day) ---
    $weeklyByDay = collect($labels)->mapWithKeys(function ($lab) use ($weekly, $labelOf, $getCust) {
        $val = $weekly->filter(fn($r) => $labelOf($r) === $lab)->sum($getCust);
        return [$lab => $val === 0.0 ? null : $val];
    });

    $weeklyAvg = $weeklyByDay->filter(fn($v) => $v !== null)->avg();

    // --- lookback: average customer_count across ALL 84 days for each weekday ---
    $lookbackByDay = collect($labels)->mapWithKeys(function ($lab) use ($lookback, $labelOf, $getCust) {
        $subset = $lookback->filter(fn($r) => $labelOf($r) === $lab);

        if ($subset->count() === 0) {
            return [$lab => null];
        }

        $avg = $subset->avg($getCust);
        return [$lab => $avg];
    });

    $lookbackAvg = $lookbackByDay->filter(fn($v) => $v !== null)->avg();

    return [
        'lookback' => [
            'by_day'         => $lookbackByDay,   // Tue..Mon averages over 84 days
            'weekly_average' => $lookbackAvg,     // average of available days
        ],
        'current_week' => [
            'by_day'         => $weeklyByDay,     // Tue..Mon values for the week
            'weekly_average' => $weeklyAvg,       // average of available days
        ],
    ];
}


}
