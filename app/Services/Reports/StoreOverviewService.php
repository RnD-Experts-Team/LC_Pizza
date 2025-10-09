<?php

namespace App\Services\Reports;

use App\Models\ChannelData;
use Carbon\Carbon;
use Illuminate\Support\Arr;

/**
 * Memory-safe overview using ONLY scalar aggregate queries (sum()).
 * - No raw SQL (no DB::raw, no selectRaw).
 * - No large collections in memory.
 * - One tiny result per query.
 *
 * Inputs (always): franchise_store + business_date range.
 *
 * Buckets (strict rules from your message):
 *  In Store:
 *    placed:    ["Register","Drive Thru","SoundHoundAgent","Phone"]
 *    fulfilled: ["Register","Drive-Thru"]
 *
 *  LC Pickup:
 *    placed:    ["Website","Mobile"]
 *    fulfilled: ["Register","Drive-Thru"]
 *
 *  LC Delivery:
 *    placed:    ["Website","Mobile"]
 *    fulfilled: ["Delivery"]
 *
 *  3rd Party:
 *    placed:    ["UberEats","Grubhub","DoorDash"]
 *    fulfilled: ["Delivery"]
 *
 * For each bucket we return:
 *   (1) sales_by_method: sum(amount) where category = "Sales" per placed method (scalar sum per method)
 *   (2) order_count_total: sum(amount) where category = "Order_Count" across all placed methods for the bucket
 *   (3) total_sales: sum of (1)
 *   (4) avg_ticket: (3)/(2) guarded
 *   (5) method_share: sales_by_method[method]/(3) guarded
 *   (6) customers_share: (2)/sum(all buckets' order_count_total) — filled after all buckets computed
 *
 * Totals:
 *   - bucket_totals: (3) per bucket
 *   - order_count_all_buckets: sum of (2)
 *   - avg_ticket_overall: sum(bucket (3)) / order_count_all_buckets
 *   - bucket_sales_share: bucket (3) / sum(all bucket (3))
 */
class StoreOverviewService
{
    /**
     * Bucket definitions (strict to your rules).
     * Note: We intentionally keep "Drive Thru" (space) for placed,
     * and "Drive-Thru" (hyphen) for fulfilled to match your data precisely.
     */
    private const BUCKETS = [
        'in_store' => [
            'label'     => 'In Store',
            'placed'    => ['Register','Drive Thru','SoundHoundAgent','Phone'],
            'fulfilled' => ['Register','Drive-Thru'],
        ],
        'lc_pickup' => [
            'label'     => 'LC Pickup',
            'placed'    => ['Website','Mobile'],
            'fulfilled' => ['Register','Drive-Thru'],
        ],
        'lc_delivery' => [
            'label'     => 'LC Delivery',
            'placed'    => ['Website','Mobile'],
            'fulfilled' => ['Delivery'],
        ],
        'third_party' => [
            'label'     => '3rd Party',
            'placed'    => ['UberEats','Grubhub','DoorDash'],
            'fulfilled' => ['Delivery'],
        ],
    ];

    /**
     * Public API.
     */
    public function overview(string $franchiseStore, $fromDate, $toDate): array
    {
        $from = $fromDate instanceof Carbon ? $fromDate->toDateString() : Carbon::parse($fromDate)->toDateString();
        $to   = $toDate   instanceof Carbon ? $toDate->toDateString()   : Carbon::parse($toDate)->toDateString();

        $buckets = [];
        foreach (self::BUCKETS as $key => $rules) {
            $buckets[$key] = $this->computeBucket(
                $franchiseStore,
                $from,
                $to,
                $rules['placed'],
                $rules['fulfilled']
            ) + ['label' => $rules['label']];
        }

        // Cross-bucket totals (small scalars)
        $sumOrderCounts = array_sum(Arr::pluck($buckets, 'order_count_total'));
        $sumBucketSales = array_sum(Arr::pluck($buckets, 'total_sales'));

        // (6) customers_share for each bucket
        foreach ($buckets as $key => $data) {
            $buckets[$key]['customers_share'] = $this->safeDivide($data['order_count_total'], $sumOrderCounts);
        }

        // Totals section
        $bucketTotals = [];
        foreach ($buckets as $key => $data) {
            $bucketTotals[$key] = $data['total_sales'];
        }

        $totals = [
            'bucket_totals'            => $bucketTotals,                                     // (1)
            'order_count_all_buckets'  => (float) $sumOrderCounts,                          // (2)
            'avg_ticket_overall'       => (float) $this->safeDivide($sumBucketSales, $sumOrderCounts), // (3)
            'bucket_sales_share'       => $this->buildShares($bucketTotals, $sumBucketSales)          // (4)
        ];

        return [
            'buckets' => $buckets,
            'totals'  => $totals,
        ];
    }

    /**
     * Compute a single bucket using only scalar aggregate queries.
     * - sales_by_method: one sum() per placed method (category="Sales")
     * - order_count_total: one sum() across all placed methods (category="Order_Count")
     */
    private function computeBucket(
        string $franchiseStore,
        string $from,
        string $to,
        array $placed,
        array $fulfilled
    ): array {
        // (1) Sales by method — tiny scalar per method, no memory growth
        $salesByMethod = [];
        foreach ($placed as $method) {
            $salesByMethod[$method] = (float) ChannelData::query()
                ->where('franchise_store', $franchiseStore)
                ->whereBetween('business_date', [$from, $to])
                ->where('category', 'Sales')
                ->where('order_placed_method', $method)
                ->whereIn('order_fulfilled_method', $fulfilled)
                ->sum('amount');
        }

        // (2) Order count total for the bucket
        $orderCountTotal = (float) ChannelData::query()
            ->where('franchise_store', $franchiseStore)
            ->whereBetween('business_date', [$from, $to])
            ->where('category', 'Order_Count')
            ->whereIn('order_placed_method', $placed)
            ->whereIn('order_fulfilled_method', $fulfilled)
            ->sum('amount');

        // (3) Total sales for this bucket
        $totalSales = array_sum($salesByMethod);

        // (4) Avg ticket
        $avgTicket = $this->safeDivide($totalSales, $orderCountTotal);

        // (5) Method share per placed method
        $methodShare = [];
        foreach ($salesByMethod as $method => $sum) {
            $methodShare[$method] = $this->safeDivide($sum, $totalSales);
        }

        return [
            'sales_by_method'   => $salesByMethod,            // (1)
            'order_count_total' => (float) $orderCountTotal,  // (2)
            'total_sales'       => (float) $totalSales,       // (3)
            'avg_ticket'        => (float) $avgTicket,        // (4)
            'method_share'      => $methodShare,              // (5)
            // (6) customers_share filled by caller
        ];
    }

    /**
     * Build share map safely.
     * @param array<string,float> $totals
     * @return array<string,float>
     */
    private function buildShares(array $totals, float $grandTotal): array
    {
        $shares = [];
        foreach ($totals as $k => $v) {
            $shares[$k] = $this->safeDivide((float) $v, (float) $grandTotal);
        }
        return $shares;
    }

    private function safeDivide(float $num, float $den): float
    {
        return $den != 0.0 ? $num / $den : 0.0;
    }
}
