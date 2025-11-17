<?php

namespace App\Http\Controllers;

use App\Models\HourlySales;
use App\Models\SummaryItem;
use App\Models\FinalSummary;
use App\Models\HourHNRTransaction; // NEW
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

    protected function roundArray($data, int $precision = 2)
    {
        if (is_array($data)) {
            return array_map(fn($v) => $this->roundArray($v, $precision), $data);
        }
        if ($data instanceof \Illuminate\Support\Collection) {
            return $data->map(fn($v) => $this->roundArray($v, $precision));
        }
        if (is_numeric($data)) {
            return round((float)$data, $precision);
        }
        return $data;
    }

    public function index(Request $request, $store, $date)
    {
        // --- guards ---
        if (empty($store) || empty($date)) {
            return response()->noContent();
        }
        if (!preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', $date)) {
            return response()->json(['error' => 'Invalid date format, expected YYYY-MM-DD or YYYY-M-D'], 400);
        }

        // fallback items
        $defaultItemIds = [
            103001, // Crazy Bread
            201128, // EMB Cheese
            201106, // EMB Pepperoni
            105001, // Caesar Wings
            103002, // Crazy Sauce
            103044, // Pepperoni Crazy Puffs®
            103033, // 4 Cheese Crazy Puffs®
            103055, // Bacon & Cheese Crazy Puffs®
        ];

        // validate items only if provided
        $validated = $request->validate([
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*' => ['integer'],
        ]);

        // If items not passed → fallback
        $itemIds = isset($validated['items']) && count($validated['items']) > 0
            ? array_values(array_unique($validated['items']))
            : $defaultItemIds;

        /***dates Used**/
        $givenDate = Carbon::parse($date);
        $usedDate  = CarbonImmutable::parse($givenDate);
        $dayName   = $givenDate->dayName;

        //for week (current week)
        $weekNumber    = $usedDate->isoWeek;
        $weekStartDate = $usedDate->startOfWeek(Carbon::TUESDAY);
        $weekEndDate   = $weekStartDate->addDays(6);

        // PREVIOUS WEEK (same weekday pattern)
        $prevWeekStartDate = $weekStartDate->subWeek();
        $prevWeekEndDate   = $weekEndDate->subWeek();

        //for lookback
        $lookBackStartDate = $usedDate->subDays(84);
        $lookBackEndDate   = $usedDate;

        $startStr = $weekStartDate->toDateString();
        $endStr   = $weekEndDate->toDateString();

        // External deposit/delivery data - CURRENT WEEK
        $base = rtrim('https://hook.pneunited.com/api/deposit-delivery-dsqr-weekly', '/');
        $url  = $base.'/'.rawurlencode($store).'/'.rawurlencode($startStr).'/'.rawurlencode($endStr);

        Log::info('Fetching weekly deposit/delivery data', [
            'store' => $store,
            'start' => $weekStartDate,
            'end'   => $weekEndDate,
            'url'   => $url,
        ]);

        // Make the GET request (current week)
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

        // External deposit/delivery data - PREVIOUS WEEK
        $prevStartStr = $prevWeekStartDate->toDateString();
        $prevEndStr   = $prevWeekEndDate->toDateString();
        $prevUrl      = $base.'/'.rawurlencode($store).'/'.rawurlencode($prevStartStr).'/'.rawurlencode($prevEndStr);

        Log::info('Fetching PREVIOUS weekly deposit/delivery data', [
            'store' => $store,
            'start' => $prevWeekStartDate,
            'end'   => $prevWeekEndDate,
            'url'   => $prevUrl,
        ]);

        $prevResponse = Http::get($prevUrl);

        if ($prevResponse->successful()) {
            $prevData = $prevResponse->json();
            $prevWeeklyDepositDeliveryCollection = collect($prevData['weeklyDepositDelivery']);
        } else {
            $prevWeeklyDepositDeliveryCollection = collect();
            Log::warning('Previous-week API call not successful', [
                'status' => $prevResponse->status(),
            ]);
        }

        // Filter to a single day within current week
        $dailyDepositDeliveryCollection = $weeklyDepositDeliveryCollection->where('HookWorkDaysDate', $date);

        /**get the data from models as collections**/
        //daily
        $dailyFinalSummaryCollection = FinalSummary::
            where('franchise_store', '=', $store)
            ->where('business_date', '=', $usedDate)
            ->get();

        $dailyHourlySalesCollection = HourlySales::
            where('franchise_store', $store)
            ->where('business_date', $usedDate)
            ->get();

        // NEW: daily HNR transactions
        $dailyHnrCollection = HourHNRTransaction::
            where('franchise_store', $store)
            ->where('business_date', $usedDate)
            ->get();

        //weekly (current week)
        $weeklyFinalSummaryCollection = FinalSummary::
            where('franchise_store', '=', $store)
            ->whereBetween('business_date', [$weekStartDate, $weekEndDate])
            ->get();

        $weeklySummaryItemCollection = SummaryItem::
            where('franchise_store', '=', $store)
            ->whereBetween('business_date', [$weekStartDate, $weekEndDate])
            ->whereIn('item_id', $itemIds)
            ->get();

        // PREVIOUS WEEK collections
        $prevWeekFinalSummaryCollection = FinalSummary::
            where('franchise_store', '=', $store)
            ->whereBetween('business_date', [$prevWeekStartDate, $prevWeekEndDate])
            ->get();

        $prevWeekSummaryItemCollection = SummaryItem::
            where('franchise_store', '=', $store)
            ->whereBetween('business_date', [$prevWeekStartDate, $prevWeekEndDate])
            ->whereIn('item_id', $itemIds)
            ->get();

        //lookback
        $lookBackFinalSummaryCollection = FinalSummary::
            where('franchise_store', '=', $store)
            ->whereBetween('business_date', [$lookBackStartDate, $lookBackEndDate])
            ->get();

        $lookBackSummaryItemCollection = SummaryItem::
            where('franchise_store', '=', $store)
            ->whereBetween('business_date', [$lookBackStartDate, $lookBackEndDate])
            ->whereIn('item_id', $itemIds)
            ->get();

        //calling the methods for the data
        $dailyHourlySalesData = $this->DailyHourlySalesReport($dailyHourlySalesCollection, $dailyHnrCollection);
        $dailyDSQRData        = $this->DailyDSQRReport($dailyDepositDeliveryCollection);
        $dailyDSPRData        = $this->DailyDSPRReport($dailyFinalSummaryCollection, $dailyDepositDeliveryCollection, $dailyHnrCollection);

        $WeeklyDSPRData       = $this->WeeklyDSPRReport($weeklyFinalSummaryCollection, $weeklyDepositDeliveryCollection);

        $customerService = $this->CustomerService($dayName, $weeklyFinalSummaryCollection, $lookBackFinalSummaryCollection);

        if (is_array($customerService) && isset($customerService[5]['dailyScore'], $customerService[5]['weeklyScore'])) {
            // after you build $dailyDSPRData and $WeeklyDSPRData:
            $dailyDSPRData['Customer_count_percent']  = round((float) ($customerService[5]['dailyScore']), 2);
            $WeeklyDSPRData['Customer_count_percent'] = round((float) ($customerService[5]['weeklyScore']), 2);

            $dailyDSPRData['Customer_Service'] = round((
                (float) $dailyDSPRData['Customer_count_percent'] +
                (float) $dailyDSPRData['Put_into_Portal_Percent'] +
                (float) $dailyDSPRData['In_Portal_on_Time_Percent']
            ) / 3, 2);

            $WeeklyDSPRData['Customer_Service'] = round((
                (float) $WeeklyDSPRData['Customer_count_percent'] +
                (float) $WeeklyDSPRData['Put_into_Portal_Percent'] +
                (float) $WeeklyDSPRData['In_Portal_on_Time_Percent']
            ) / 3, 2);
        }

        $upselling = $this->Upselling($dayName, $weeklySummaryItemCollection, $lookBackSummaryItemCollection);
        if (is_array($upselling) && isset($upselling[5]['dailyScore'], $upselling[5]['weeklyScore'])) {
            $dailyDSPRData['Upselling']  = round((float) ($upselling[5]['dailyScore']), 2);
            $WeeklyDSPRData['Upselling'] = round((float) ($upselling[5]['weeklyScore']), 2);
        }

        // Group weekly FinalSummary rows by exact date ('Y-m-d') - CURRENT WEEK
        $fsByDate = $weeklyFinalSummaryCollection->groupBy(function ($row) {
            return Carbon::parse($row['business_date'])->toDateString();
        });

        // Group weekly Deposit/Delivery rows by exact date ('Y-m-d') - CURRENT WEEK
        $ddByDate = $weeklyDepositDeliveryCollection->groupBy(function ($row) {
            return Carbon::parse($row['HookWorkDaysDate'])->toDateString();
        });

        // PREVIOUS WEEK groupings
        $prevFsByDate = $prevWeekFinalSummaryCollection->groupBy(function ($row) {
            return Carbon::parse($row['business_date'])->toDateString();
        });

        $prevDdByDate = $prevWeeklyDepositDeliveryCollection->groupBy(function ($row) {
            return Carbon::parse($row['HookWorkDaysDate'])->toDateString();
        });

        /**
         * Build DailyDSPRByDate for current week, and for each date,
         * also attach "PrevWeek" → the same weekday one week earlier.
         * (No HNR added here, to avoid changing weekly structure unless needed.)
         */
        $dailyDSPRRange = [];

        for ($d = $weekStartDate; $d->lte($weekEndDate); $d = $d->addDay()) {
            $key = $d->toDateString(); // e.g. 2025-11-17

            $fs = $fsByDate->get($key, collect()); // per-day FS (current week)
            $dd = $ddByDate->get($key, collect()); // per-day DD (current week)

            // Base DailyDSPR for this date (your existing method; returns array OR string)
            $dayDspr = $this->DailyDSPRReport($fs, $dd); // NOTE: no HNR here → old behavior

            // Only enrich when we got a proper array back
            if (is_array($dayDspr)) {
                // ---------- CURRENT WEEK ENRICHMENT ----------
                $thisDayName = Carbon::parse($key)->dayName;

                // EXACT same methods you already call for the single-day/weekly blocks:
                $csForDay = $this->CustomerService(
                    $thisDayName,
                    $weeklyFinalSummaryCollection,
                    $lookBackFinalSummaryCollection
                );
                $upForDay = $this->Upselling(
                    $thisDayName,
                    $weeklySummaryItemCollection,
                    $lookBackSummaryItemCollection
                );

                // Mirror your existing checks and assignments (dailyScore path = [5]['dailyScore'])
                if (is_array($csForDay) && isset($csForDay[5]['dailyScore'])) {
                    $dayDspr['Customer_count_percent'] = round((float)$csForDay[5]['dailyScore'], 2);
                }

                if (is_array($upForDay) && isset($upForDay[5]['dailyScore'])) {
                    $dayDspr['Upselling'] = round((float)$upForDay[5]['dailyScore'], 2);
                }

                // Customer_Service = avg(Customer_count_percent, Put_into_Portal_Percent, In_Portal_on_Time_Percent)
                if (
                    isset($dayDspr['Customer_count_percent']) &&
                    isset($dayDspr['Put_into_Portal_Percent']) &&
                    isset($dayDspr['In_Portal_on_Time_Percent'])
                ) {
                    $dayDspr['Customer_Service'] = round((
                        (float)$dayDspr['Customer_count_percent'] +
                        (float)$dayDspr['Put_into_Portal_Percent'] +
                        (float)$dayDspr['In_Portal_on_Time_Percent']
                    ) / 3, 2);
                }

                // ---------- PREVIOUS WEEK (same weekday, i.e. 7 days earlier) ----------
                $prevDate    = $d->subWeek();              // 7 days earlier
                $prevKey     = $prevDate->toDateString();  // e.g. 2025-11-10
                $prevFs      = $prevFsByDate->get($prevKey, collect());
                $prevDd      = $prevDdByDate->get($prevKey, collect());
                $prevDayDspr = $this->DailyDSPRReport($prevFs, $prevDd); // also old behavior

                if (is_array($prevDayDspr)) {
                    $prevDayName = $prevDate->dayName;

                    $csPrev = $this->CustomerService(
                        $prevDayName,
                        $prevWeekFinalSummaryCollection,
                        $lookBackFinalSummaryCollection
                    );
                    $upPrev = $this->Upselling(
                        $prevDayName,
                        $prevWeekSummaryItemCollection,
                        $lookBackSummaryItemCollection
                    );

                    if (is_array($csPrev) && isset($csPrev[5]['dailyScore'])) {
                        $prevDayDspr['Customer_count_percent'] = round((float)$csPrev[5]['dailyScore'], 2);
                    }

                    if (is_array($upPrev) && isset($upPrev[5]['dailyScore'])) {
                        $prevDayDspr['Upselling'] = round((float)$upPrev[5]['dailyScore'], 2);
                    }

                    if (
                        isset($prevDayDspr['Customer_count_percent']) &&
                        isset($prevDayDspr['Put_into_Portal_Percent']) &&
                        isset($prevDayDspr['In_Portal_on_Time_Percent'])
                    ) {
                        $prevDayDspr['Customer_Service'] = round((
                            (float)$prevDayDspr['Customer_count_percent'] +
                            (float)$prevDayDspr['Put_into_Portal_Percent'] +
                            (float)$prevDayDspr['In_Portal_on_Time_Percent']
                        ) / 3, 2);
                    }
                }

                // Attach previous week’s same-day data under a key
                $dayDspr['PrevWeek'] = $prevDayDspr; // can be array or "No ... data available." string

                // Save enriched daily record
                $dailyDSPRRange[$key] = $dayDspr;
            } else {
                // If DailyDSPRReport returned a message string, keep it as-is
                $dailyDSPRRange[$key] = $dayDspr;
            }
        }

        $response = [
            'Filtering Values' => [
                'date'               => $date,
                'store'              => $store,
                'items'              => $itemIds,
                'week'               => $weekNumber,
                'weekStartDate'      => $weekStartDate,
                'weekEndDate'        => $weekEndDate,
                'look back start'    => $lookBackStartDate,
                'look back end'      => $lookBackEndDate,
                'depositDeliveryUrl' => $url,
            ],
            'reports' => [
                'daily' => [
                    'dailyHourlySales' => $dailyHourlySalesData,  // includes HNR by hour
                    'dailyDSQRData'    => $dailyDSQRData,
                    'dailyDSPRData'    => $dailyDSPRData,        // includes HNR totals
                ],
                'weekly' => [
                    'DSPRData'        => $WeeklyDSPRData,
                    'DailyDSPRByDate' => $dailyDSPRRange,
                ],
            ],
        ];

        return $this->jsonRoundedResponse($response, 2);
    }

    protected function jsonRoundedResponse(array $payload, int $precision = 2)
    {
        // 1) round all numerics
        $payload = $this->roundArray($payload, $precision);

        // 2) snapshot current INI
        $oldSerialize = ini_get('serialize_precision');
        $oldPrecision = ini_get('precision');

        // 3) tame float output only for this encode
        ini_set('serialize_precision', '-1'); // PHP 7.1+ recommended to avoid float noise
        ini_set('precision', '14');

        // 4) encode (keep .00 when applicable, still numbers — not strings)
        $json = json_encode($payload, JSON_PRESERVE_ZERO_FRACTION);

        // 5) restore INI immediately
        ini_set('serialize_precision', $oldSerialize === false ? '17' : $oldSerialize);
        ini_set('precision', $oldPrecision === false ? '14' : $oldPrecision);

        // 6) return raw JSON response
        return response($json, 200)->header('Content-Type', 'application/json');
    }

    public function DSQRReport()
    {

    }

    /**
     * Extended: if $hnrCollection is provided, we enrich each hour with HNR data.
     */
    public function DailyHourlySalesReport($dailyHourlySalesCollection, $hnrCollection = null)
    {
        // If both are empty, keep the shape but mark hours as empty
        if ($dailyHourlySalesCollection->isEmpty() && (empty($hnrCollection) || $hnrCollection->isEmpty())) {
            return [
                'franchise_store' => null,
                'business_date'   => null,
                'hours'           => array_fill_keys(range(0, 23), (object)[]),
            ];
        }

        // Derive store/date from whichever has data first
        $firstSource = !$dailyHourlySalesCollection->isEmpty()
            ? $dailyHourlySalesCollection->first()
            : ($hnrCollection ? $hnrCollection->first() : null);

        $store = $firstSource->franchise_store ?? null;
        $date  = $firstSource->business_date ?? null;

        // Pre-group HNR by hour if provided
        $hnrByHour = ($hnrCollection instanceof Collection)
            ? $hnrCollection->groupBy('hour')
            : collect();

        // Pre-build the hours map 0..23
        $hours = [];
        for ($h = 0; $h <= 23; $h++) {

            $base = [];

            // --- Existing hourly sales aggregation ---
            $subset = $dailyHourlySalesCollection->where('hour', $h);

            if (!$subset->isEmpty()) {
                $base = [
                    'Total_Sales'       => round((float) $subset->sum('total_sales'), 2),
                    'Phone_Sales'       => round((float) $subset->sum('phone_sales'), 2),
                    'Call_Center_Agent' => round((float) $subset->sum('call_center_sales'), 2),
                    'Drive_Thru'        => round((float) $subset->sum('drive_thru_sales'), 2),
                    'Website'           => round((float) $subset->sum('website_sales'), 2),
                    'Mobile'            => round((float) $subset->sum('mobile_sales'), 2),
                    'Order_Count'       => (int) $subset->sum('order_count'),
                ];
            }

            // --- NEW: HNR per hour ---
            $hnrHourSet = $hnrByHour->get($h, collect());

            if ($hnrHourSet instanceof Collection && !$hnrHourSet->isEmpty()) {
                $totalTransactions    = (float) $hnrHourSet->sum('transactions');
                $totalTransactionsCC  = (float) $hnrHourSet->sum('transactions_with_CC');

                $promiseBrokenWeighted = 0.0;
                $maxBrokenRaw          = 0.0;

                if ($totalTransactions > 0) {
                    $promiseBrokenWeighted = $hnrHourSet->reduce(function ($carry, $row) {
                        $t = (float) $row->transactions;
                        $p = (float) $row->promise_broken_percentage;
                        return $carry + ($t * $p);
                    }, 0.0) / $totalTransactions;

                    $maxBrokenRaw = (float) $hnrHourSet->max('promise_broken_percentage');
                }

                // Handle scale: if max broken <= 1, treat as 0..1; otherwise as 0..100
                if ($maxBrokenRaw <= 1.0) {
                    $promiseMetPercent   = max(0.0, 1.0 - $promiseBrokenWeighted);
                    $promiseMetTx        = $totalTransactions * $promiseMetPercent;
                } else {
                    $promiseMetPercent   = max(0.0, 100.0 - $promiseBrokenWeighted);
                    $promiseMetTx        = $totalTransactions * ($promiseMetPercent / 100.0);
                }

                $base['HNR'] = [
                    'Transactions'               => round($totalTransactions, 2),
                    'Transactions_with_CC'       => round($totalTransactionsCC, 2),
                    'Promise_Broken_Percent'     => round($promiseBrokenWeighted, 2),
                    'Promise_Met_Percent'        => round($promiseMetPercent, 2),
                    'Promise_Met_Transactions'   => round($promiseMetTx, 2),
                ];
            }

            // If we still have no data at all, keep old behavior: empty object
            $hours[$h] = empty($base) ? (object)[] : $base;
        }

        return [
            'franchise_store' => $store,
            'business_date'   => $date,
            'hours'           => $hours,
        ];
    }

    public function DailyDSQRReport($depositDeliveryCollection)
    {
        if ($depositDeliveryCollection->isEmpty()) {
            return "No deposit delivery data available.";
        }

        return[
            'score'=>[
                'DD_Most_Loved_Restaurant'=>round((float)($depositDeliveryCollection->value('Hook_MostLovedRestaurant')),2),
                'DD_Optimization_Score'=>$depositDeliveryCollection->value('Hook_OptimizationScore'),
                'DD_Ratings_Average_Rating'=>round((float)($depositDeliveryCollection->value('Hook_RatingsAverageRating')),2),
                'DD_Cancellations_Sales_Lost'=>round((float)($depositDeliveryCollection->value('Hook_CancellationsSalesLost2')),2),
                'DD_Missing_or_Incorrect_Error_Charges'=>round((float)($depositDeliveryCollection->value('Hook_MissingOrIncorrectErrorCharges')),2),
                'DD_Avoidable_Wait_M-Sec'=>round((float)($depositDeliveryCollection->value('Hook_AvoidableWaitMSec2')),2),
                'DD_Total_Dasher_Wait_M-Sec'=>round((float)($depositDeliveryCollection->value('Hook_TotalDasherWaitMSec')),2),
                'DD_number_1_Top_Missing_or_Incorrect_Item'=>round((float)($depositDeliveryCollection->value('Hook_1TopMissingOrIncorrectItem')),2),
                'DD_Downtime_H-MM'=>round((float)($depositDeliveryCollection->value('Hook_DowntimeHMM')),2),
                'DD_Reviews_Responded'=>round((float)($depositDeliveryCollection->value('Hook_ReviewsResponded')),2),

                'UE_Customer_reviews_overview'=>round((float)($depositDeliveryCollection->value('Hook_CustomerReviewsOverview')),2),
                'UE_Cost_of_Refunds'=>round((float)($depositDeliveryCollection->value('Hook_CostOfRefunds')),2),
                'UE_Unfulfilled_order_rate'=>round((float)($depositDeliveryCollection->value('Hook_UnfulfilledOrderRate')),2),
                'UE_Time_unavailable_during_open_hours_hh-mm'=>round((float)($depositDeliveryCollection->value('Hook_TimeUnavailableDuringOpenHoursHhmm')),2),
                'UE_Top_inaccurate_item'=>round((float)($depositDeliveryCollection->value('Hook_TopInaccurateItem')),2),
                'UE_Reviews_Responded'=>round((float)($depositDeliveryCollection->value('Hook_ReviewsResponded_2')),2),

                'GH_Rating'=>round((float)($depositDeliveryCollection->value('Hook_Rating')),2),
                'GH_Food_was_good'=>round((float)($depositDeliveryCollection->value('Hook_FoodWasGood')),2),
                'GH_Delivery_was_on_time'=>round((float)($depositDeliveryCollection->value('Hook_DeliveryWasOnTime')),2),
                'GH_Order_was_accurate'=>round((float)($depositDeliveryCollection->value('Hook_OrderWasAccurate')),2),
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

    /**
     * Extended: can optionally accept $hnrCollection and add daily HNR totals
     * to the returned DSPR data.
     */
    public function DailyDSPRReport($dailyFinalSummaryCollection, $depositDeliveryCollection, $hnrCollection = null)
    {
        if ($dailyFinalSummaryCollection->isEmpty()) {
            return "No Final Summary data available.";
        }
        if ($depositDeliveryCollection->isEmpty()) {
            return "No deposit delivery data available.";
        }

        $workingHours = $depositDeliveryCollection->sum('HookEmployeesWorkingHours');
        $deposit      = $depositDeliveryCollection->sum('HookDepositAmount');
        $totalSales   = $dailyFinalSummaryCollection->sum('total_sales');

        $cashSales     = $dailyFinalSummaryCollection->sum('cash_sales');
        $customerCount = $dailyFinalSummaryCollection->sum('customer_count');

        $result = [
            'labor'                          => round($workingHours * 16 / $totalSales, 2),
            'waste_gateway'                  => round($dailyFinalSummaryCollection->sum('total_waste_cost'), 2),
            'over_short'                     => round($deposit - $cashSales, 2),
            'Refunded_order_Qty'             => round($dailyFinalSummaryCollection->sum('refunded_order_qty'), 2),
            'Total_Cash_Sales'               => round($cashSales, 2),
            'Total_Sales'                    => round($totalSales, 2),
            'Waste_Alta'                     => round($depositDeliveryCollection->sum('HookAltimetricWaste'), 2),
            'Modified_Order_Qty'             => round($dailyFinalSummaryCollection->sum('modified_order_qty'), 2),
            'Total_TIPS'                     => round($dailyFinalSummaryCollection->sum('total_tips') + $depositDeliveryCollection->sum('HookHowMuchTips'), 2),
            'Customer_count'                 => round($customerCount, 2),
            'DoorDash_Sales'                 => round($dailyFinalSummaryCollection->sum('doordash_sales'), 2),
            'UberEats_Sales'                 => round($dailyFinalSummaryCollection->sum('ubereats_sales'), 2),
            'GrubHub_Sales'                  => round($dailyFinalSummaryCollection->sum('grubhub_sales'), 2),
            'Phone'                          => round($dailyFinalSummaryCollection->sum('phone_sales'), 2),
            'Call_Center_Agent'              => round($dailyFinalSummaryCollection->sum('call_center_sales'), 2),
            'Website'                        => round($dailyFinalSummaryCollection->sum('website_sales'), 2),
            'Mobile'                         => round($dailyFinalSummaryCollection->sum('mobile_sales'), 2),
            'Digital_Sales_Percent'          => round($dailyFinalSummaryCollection->sum('digital_sales_percent'), 2),
            'Total_Portal_Eligible_Transactions' => round($dailyFinalSummaryCollection->sum('portal_transactions'), 2),
            'Put_into_Portal_Percent'        => round($dailyFinalSummaryCollection->sum('portal_used_percent'), 2),
            'In_Portal_on_Time_Percent'      => round($dailyFinalSummaryCollection->sum('in_portal_on_time_percent'), 2),
            'Drive_Thru_Sales'               => round($dailyFinalSummaryCollection->sum('drive_thru_sales'), 2),
            'Upselling'                      => null,
            'Cash_Sales_Vs_Deposite_Difference' => round($deposit - $cashSales, 2),
            'Avrage_ticket'                  => round($totalSales / $customerCount, 2),
        ];

        // NEW: daily HNR totals (if provided)
        if ($hnrCollection instanceof Collection && !$hnrCollection->isEmpty()) {
            $totalTransactions   = (float) $hnrCollection->sum('transactions');
            $totalTransactionsCC = (float) $hnrCollection->sum('transactions_with_CC');

            $promiseBrokenWeighted = 0.0;
            $maxBrokenRaw          = 0.0;

            if ($totalTransactions > 0) {
                $promiseBrokenWeighted = $hnrCollection->reduce(function ($carry, $row) {
                    $t = (float) $row->transactions;
                    $p = (float) $row->promise_broken_percentage;
                    return $carry + ($t * $p);
                }, 0.0) / $totalTransactions;

                $maxBrokenRaw = (float) $hnrCollection->max('promise_broken_percentage');
            }

            // Decide scale (0..1 or 0..100) based on max
            if ($maxBrokenRaw <= 1.0) {
                $promiseMetPercent = max(0.0, 1.0 - $promiseBrokenWeighted);
                $promiseMetTx      = $totalTransactions * $promiseMetPercent;
            } else {
                $promiseMetPercent = max(0.0, 100.0 - $promiseBrokenWeighted);
                $promiseMetTx      = $totalTransactions * ($promiseMetPercent / 100.0);
            }

            $result['HNR_Transactions']             = round($totalTransactions, 2);
            $result['HNR_Transactions_with_CC']     = round($totalTransactionsCC, 2);
            $result['HNR_Promise_Broken_Percent']   = round($promiseBrokenWeighted, 2);
            $result['HNR_Promise_Met_Percent']      = round($promiseMetPercent, 2);
            $result['HNR_Promise_Met_Transactions'] = round($promiseMetTx, 2);
        }

        return $result;
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
        }

        $sumOfAllLabors = array_sum($laborForEachDay);
        $totalLabors    = $finalSummaryDaysCount > 0 ? $sumOfAllLabors / $finalSummaryDaysCount : 0.0;

        $workingHours = (float) $weeklyDepositDeliveryCollection->sum('HookEmployeesWorkingHours');
        $deposit      = (float) $weeklyDepositDeliveryCollection->sum('HookDepositAmount');
        $totalSales   = (float) $weeklyFinalSummaryCollection->sum('total_sales');

        $cashSales     = (float) $weeklyFinalSummaryCollection->sum('cash_sales');
        $customerCount = (float) $weeklyFinalSummaryCollection->sum('customer_count');

        $tipsFinalSummary    = (float) $weeklyFinalSummaryCollection->sum('total_tips');
        $tipsDepositDelivery = (float) $weeklyDepositDeliveryCollection->sum('HookHowMuchTips');

        return [
            'labor'                          => round($totalLabors, 2),
            'waste_gateway'                  => round((float) $weeklyFinalSummaryCollection->sum('total_waste_cost'), 2),
            'over_short'                     => round($deposit - $cashSales, 2),
            'Refunded_order_Qty'             => round((float) $weeklyFinalSummaryCollection->sum('refunded_order_qty'), 2),
            'Total_Cash_Sales'               => round($cashSales, 2),
            'Total_Sales'                    => round($totalSales, 2),
            'Waste_Alta'                     => round((float) $weeklyDepositDeliveryCollection->sum('HookAltimetricWaste'), 2),
            'Modified_Order_Qty'             => round((float) $weeklyFinalSummaryCollection->sum('modified_order_qty'), 2),
            'Total_TIPS'                     => round($tipsFinalSummary + $tipsDepositDelivery, 2),
            'Customer_count'                 => round($customerCount, 2),
            'DoorDash_Sales'                 => round((float) $weeklyFinalSummaryCollection->sum('doordash_sales'), 2),
            'UberEats_Sales'                 => round((float) $weeklyFinalSummaryCollection->sum('ubereats_sales'), 2),
            'GrubHub_Sales'                  => round((float) $weeklyFinalSummaryCollection->sum('grubhub_sales'), 2),
            'Phone'                          => round((float) $weeklyFinalSummaryCollection->sum('phone_sales'), 2),
            'Call_Center_Agent'              => round((float) $weeklyFinalSummaryCollection->sum('call_center_sales'), 2),
            'Website'                        => round((float) $weeklyFinalSummaryCollection->sum('website_sales'), 2),
            'Mobile'                         => round((float) $weeklyFinalSummaryCollection->sum('mobile_sales'), 2),
            'Digital_Sales_Percent'          => round($finalSummaryDaysCount ? (float) $weeklyFinalSummaryCollection->sum('digital_sales_percent') / $finalSummaryDaysCount : 0.0, 2),
            'Total_Portal_Eligible_Transactions' => round((float) $weeklyFinalSummaryCollection->sum('portal_transactions'), 2),
            'Put_into_Portal_Percent'        => round($finalSummaryDaysCount ? (float) $weeklyFinalSummaryCollection->sum('portal_used_percent') / $finalSummaryDaysCount : 0.0, 2),
            'In_Portal_on_Time_Percent'      => round($finalSummaryDaysCount ? (float) $weeklyFinalSummaryCollection->sum('in_portal_on_time_percent') / $finalSummaryDaysCount : 0.0, 2),
            'Drive_Thru_Sales'               => round((float) $weeklyFinalSummaryCollection->sum('drive_thru_sales'), 2),
            'Upselling'                      => null,
            'Cash_Sales_Vs_Deposite_Difference' => round($finalSummaryDaysCount ? ($deposit - $cashSales) / $finalSummaryDaysCount : 0.0, 2),
            'Avrage_ticket'                  => round($customerCount > 0 ? $totalSales / $customerCount : 0.0, 2),
        ];
    }

    public function CustomerService($dayName, $weeklyFinalSummaryCollection, $lookBackFinalSummaryCollection)
    {
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
        $weeklyFinalSummarydaysCount = $weeklyFinalSummaryDataByDay->count();

        //lookBackfinalSummary by day
        $lookBackfinalSummaryDataByDay = $lookBackFinalSummaryCollection->groupBy(function ($item) {
            return Carbon::parse($item['business_date'])->dayName;
        })->map(function ($dayRecords) {
            return (float) $dayRecords->avg('customer_count');
        });

        //days count
        $lookBackfinalSummarydaysCount = $lookBackfinalSummaryDataByDay->count();

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

        // NOTE: this line reuses weeklyFinalSummaryDataByDay for lookBackTotal
        // kept as-is to not break your existing behavior
        $lookBackTotal =  $weeklyFinalSummaryDataByDay->map(function ($values) {
            return $values->avg();
        })->sum();

        $weeklyAvr   = $weeklyTotal / $weeklyFinalSummarydaysCount;
        $lookBackAvr = $lookBackfinalSummaryDataByDay->avg();

        //finals
        //for weekly customer service
        $weeklyFinalValue = ($weeklyAvr - $lookBackAvr) / $lookBackAvr;

        //for daily customer service
        $dailyForLookback = $lookBackfinalSummaryDataByDay->get($dayName);
        $dailyForWeekly   = $weeklyFinalSummaryDataByDay->get($dayName)[0];

        $dailyFinalValue = ($dailyForWeekly - $dailyForLookback) / $dailyForLookback;

        //final scores
        $dailyScore  = $this->score($dailyFinalValue) / 100;
        $weeklyScore = $this->score($weeklyFinalValue) / 100;

        return[
            [
                'weeklyFinalSummaryDataByDay'=>$weeklyFinalSummaryDataByDay,
                'weeklyFinalSummarydaysCount'=>$weeklyFinalSummarydaysCount,
            ],
            [
                'lookBackfinalSummaryDataByDay'=>$lookBackfinalSummaryDataByDay,
                'lookBackfinalSummarydaysCount'=>$lookBackfinalSummarydaysCount,
                'lookBackdailyCounts' =>$lookBackdailyCounts,
            ],
            [
                '$weeklyTotal' =>$weeklyTotal,
                '$lookBackTotal'=>$lookBackTotal,
            ],
            [
                'weeklyAvr' => $weeklyAvr,
                'lookBackAvr' =>$lookBackAvr,
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

    public function Upselling($dayName, $weeklyFinalSummaryCollection, $lookBackFinalSummaryCollection)
    {
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
        $weeklyFinalSummarydaysCount = $weeklyFinalSummaryDataByDay->count();

        //lookBackfinalSummary by day
        $lookBackfinalSummaryDataByDay = $lookBackFinalSummaryCollection->groupBy(function ($item) {
            return Carbon::parse($item['business_date'])->dayName;
        })->map(function ($dayRecords) {
            return (float) $dayRecords->avg('royalty_obligation');
        });

        //days count
        $lookBackfinalSummarydaysCount = $lookBackfinalSummaryDataByDay->count();

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

        // NOTE: this line reuses weeklyFinalSummaryDataByDay for lookBackTotal
        // kept as-is to not change behavior
        $lookBackTotal =  $weeklyFinalSummaryDataByDay->map(function ($values) {
            return $values->avg();
        })->sum();

        $weeklyAvr   = $weeklyTotal / $weeklyFinalSummarydaysCount;
        $lookBackAvr = $lookBackfinalSummaryDataByDay->avg();

        //finals
        //for weekly upselling
        $weeklyFinalValue = ($weeklyAvr - $lookBackAvr) / $lookBackAvr;

        //for daily upselling
        $dailyForLookback = $lookBackfinalSummaryDataByDay->get($dayName);
        $dailyForWeekly   = $weeklyFinalSummaryDataByDay->get($dayName)[0];

        $dailyFinalValue = ($dailyForWeekly - $dailyForLookback) / $dailyForLookback;

        //final scores
        $dailyScore  = $this->score($dailyFinalValue) / 100;
        $weeklyScore = $this->score($weeklyFinalValue) / 100;

        return[
            [
                'weeklyFinalSummaryDataByDay'=>$weeklyFinalSummaryDataByDay,
                'weeklyFinalSummarydaysCount'=>$weeklyFinalSummarydaysCount,
            ],
            [
                'lookBackfinalSummaryDataByDay'=>$lookBackfinalSummaryDataByDay,
                'lookBackfinalSummarydaysCount'=>$lookBackfinalSummarydaysCount,
                'lookBackdailyCounts' =>$lookBackdailyCounts,
            ],
            [
                '$weeklyTotal' =>$weeklyTotal,
                '$lookBackTotal'=>$lookBackTotal,
            ],
            [
                'weeklyAvr' => $weeklyAvr,
                'lookBackAvr' =>$lookBackAvr,
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

    public function score($value)
    {
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
        $rows = DB::table('summary_items as si')
            ->select('si.item_id', 'si.menu_item_name')
            ->where('si.franchise_store', $store)
            ->whereNotNull('si.item_id')

            // Latest business_date per (store, item_id)
            ->whereRaw('si.business_date = (
                SELECT MAX(si2.business_date)
                FROM summary_items as si2
                WHERE si2.franchise_store = si.franchise_store
                  AND si2.item_id = si.item_id
            )')

            // Tie-break when multiple rows share that latest date: pick highest id
            ->whereRaw('si.id = (
                SELECT MAX(si3.id)
                FROM summary_items as si3
                WHERE si3.franchise_store = si.franchise_store
                  AND si3.item_id = si.item_id
                  AND si3.business_date = si.business_date
            )')

            ->orderBy('si.menu_item_name')
            ->get();

        return response()->json([
            'store' => $store,
            'count' => $rows->count(),
            'items' => $rows,
        ]);
    }

}
