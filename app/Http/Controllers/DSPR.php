<?php

namespace App\Http\Controllers;

use App\Models\SummaryItem;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

class DSPR extends Controller
{
    /**
     * Display the DSPR data for the specified store, date, and items
     *
     * @param string $store
     * @param string $date
     * @param string $items
     * @return JsonResponse
     */
    public function index(string $store, string $date, string $items): JsonResponse
    {
        try {
            // Get both collections
            $currentWeekCollection = $this->getCurrentWeekCollection($date, $store, $items);
            $past84DaysCollection = $this->getPast84DaysCollection($date, $store, $items);

            // Process and calculate averages
            $weeklyAverages = $this->calculateWeeklyAverages($currentWeekCollection);
            $lookbackAverages = $this->calculateLookbackAverages($past84DaysCollection);

            return response()->json([
                'success' => true,
                'data' => [
                    'weekly' => $weeklyAverages,
                    'lookback' => $lookbackAverages
                ],
                'meta' => [
                    'store' => $store,
                    'date' => $date,
                    'items_count' => count($this->parseItemsString($items)),
                    'current_week_records' => $currentWeekCollection->count(),
                    'lookback_records' => $past84DaysCollection->count()
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error processing DSPR data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get current week collection filtered by date, store, and menu items
     *
     * @param string $date
     * @param string $store
     * @param string $items
     * @return Collection
     */
    private function getCurrentWeekCollection(string $date, string $store, string $items): Collection
    {
        $weekDates = $this->getWeekBoundaries($date);
        $itemsArray = $this->parseItemsString($items);

        return SummaryItem::where('franchise_store', $store)
            ->whereBetween('business_date', [$weekDates['start'], $weekDates['end']])
            ->whereIn('menu_item_name', $itemsArray)
            ->get();
    }

    /**
     * Get past 84 days collection filtered by date, store, and menu items
     *
     * @param string $date
     * @param string $store
     * @param string $items
     * @return Collection
     */
    private function getPast84DaysCollection(string $date, string $store, string $items): Collection
    {
        $dateRange = $this->getPast84DaysRange($date);
        $itemsArray = $this->parseItemsString($items);

        return SummaryItem::where('franchise_store', $store)
            ->whereBetween('business_date', [$dateRange['start'], $dateRange['end']])
            ->whereIn('menu_item_name', $itemsArray)
            ->get();
    }

    /**
     * Calculate daily averages for current week collection
     *
     * @param Collection $collection
     * @return array
     */
    private function calculateWeeklyAverages(Collection $collection): array
    {
        // Group by day of week (Tuesday = 2, Monday = 1 in Carbon)
        $groupedByDay = $collection->groupBy(function ($item) {
            return Carbon::parse($item->business_date)->dayOfWeek;
        });

        // Map Carbon day numbers to readable names (Tuesday first)
        $dayMapping = [
            2 => 'Tue',  // Tuesday
            3 => 'Wed',  // Wednesday
            4 => 'Thu',  // Thursday
            5 => 'Fri',  // Friday
            6 => 'Sat',  // Saturday
            0 => 'Sun',  // Sunday
            1 => 'Mon'   // Monday
        ];

        $dailyAverages = [];
        $validDayAverages = [];

        // Calculate average for each day
        foreach ($dayMapping as $dayNumber => $dayName) {
            if ($groupedByDay->has($dayNumber)) {
                $dayItems = $groupedByDay->get($dayNumber);
                $average = $dayItems->avg('royalty_obligation');
                $dailyAverages[$dayName] = round($average, 2);
                $validDayAverages[] = $average;
            } else {
                $dailyAverages[$dayName] = null;
            }
        }

        // Calculate weekly average (only for days with values)
        $weeklyAverage = !empty($validDayAverages)
            ? round(array_sum($validDayAverages) / count($validDayAverages), 2)
            : null;

        $dailyAverages['weeklyAvr'] = $weeklyAverage;

        return $dailyAverages;
    }

    /**
     * Calculate lookback averages for past 84 days collection
     *
     * @param Collection $collection
     * @return array
     */
    private function calculateLookbackAverages(Collection $collection): array
    {
        // Group by day of week
        $groupedByDay = $collection->groupBy(function ($item) {
            return Carbon::parse($item->business_date)->dayOfWeek;
        });

        // Map Carbon day numbers to readable names (Tuesday first)
        $dayMapping = [
            2 => 'Tue',  // Tuesday
            3 => 'Wed',  // Wednesday
            4 => 'Thu',  // Thursday
            5 => 'Fri',  // Friday
            6 => 'Sat',  // Saturday
            0 => 'Sun',  // Sunday
            1 => 'Mon'   // Monday
        ];

        $dailyAverages = [];
        $validDayAverages = [];

        // Calculate average for each day of the week across all weeks in lookback period
        foreach ($dayMapping as $dayNumber => $dayName) {
            if ($groupedByDay->has($dayNumber)) {
                $dayItems = $groupedByDay->get($dayNumber);
                $average = $dayItems->avg('royalty_obligation');
                $dailyAverages[$dayName] = round($average, 2);
                $validDayAverages[] = $average;
            } else {
                $dailyAverages[$dayName] = null;
            }
        }

        // Calculate lookback average (average of all days that have values)
        $lookbackAverage = !empty($validDayAverages)
            ? round(array_sum($validDayAverages) / count($validDayAverages), 2)
            : null;

        $dailyAverages['lookBackAvr'] = $lookbackAverage;

        return $dailyAverages;
    }

    /**
     * Get week start and end dates with Tuesday as week start
     *
     * @param string $date
     * @return array
     */
    private function getWeekBoundaries(string $date): array
    {
        $carbonDate = Carbon::parse($date);

        // Calculate days since Tuesday (Tuesday = 2 in Carbon)
        $daysSinceTuesday = ($carbonDate->dayOfWeek + 5) % 7;

        // Get Tuesday of current week
        $weekStart = $carbonDate->copy()->subDays($daysSinceTuesday);

        // Get Monday of next week (6 days after Tuesday)
        $weekEnd = $weekStart->copy()->addDays(6);

        return [
            'start' => $weekStart->format('Y-m-d'),
            'end' => $weekEnd->format('Y-m-d')
        ];
    }

    /**
     * Get past 84 days date range
     *
     * @param string $date
     * @return array
     */
    private function getPast84DaysRange(string $date): array
    {
        $endDate = Carbon::parse($date);
        $startDate = $endDate->copy()->subDays(84);

        return [
            'start' => $startDate->format('Y-m-d'),
            'end' => $endDate->format('Y-m-d')
        ];
    }

    /**
     * Parse comma-separated items string to array
     *
     * @param string $items
     * @return array
     */
    private function parseItemsString(string $items): array
    {
        return array_filter(
            array_map('trim', explode(',', $items)),
            fn($item) => !empty($item)
        );
    }
}
