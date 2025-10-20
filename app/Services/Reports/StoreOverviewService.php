<?php

namespace App\Services\Reports;

use App\Models\ChannelData;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;

/**
 * Memory-safe overview using ONLY scalar aggregate queries (sum()).
 * - No raw SQL (no DB::raw, no selectRaw).
 * - No large collections in memory.
 * - One tiny result per query.
 *
 * Supports:
 *  - Specific store
 *  - "All" or null store => no store filter (aggregate across all stores)
 */
class StoreOverviewService
{
    /**
     * Bucket definitions (strict to your rules).
     * Note: placed uses "Drive Thru", fulfilled uses "Drive-Thru".
     */
    private const BUCKETS = [
        'in_store' => [
            'label'     => 'In Store',
            'placed'    => ['Register','Drive Thru','SoundHoundAgent','Phone','CallCenterAgent'],
            'fulfilled' => ['Register','Drive-Thru'],
        ],
        'lc_pickup' => [
            'label'     => 'LC Pickup',
            'placed'    => ['Website','Mobile'],
            'fulfilled' => ['Register','Drive-Thru'],
        ],
        'lc_delivery' => [
            'label'     => 'LC Delivery',
            'placed'    => ['Website','Mobile','SoundHoundAgent','CallCenterAgent'],
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
    public function overview(?string $franchiseStore, $fromDate, $toDate): array
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
            'bucket_totals'            => $bucketTotals,
            'order_count_all_buckets'  => (float) $sumOrderCounts,
            'avg_ticket_overall'       => (float) $this->safeDivide($sumBucketSales, $sumOrderCounts),
            'bucket_sales_share'       => $this->buildShares($bucketTotals, $sumBucketSales),
        ];

        return [
            'buckets' => $buckets,
            'totals'  => $totals,
        ];
    }

    /**
     * Compute a single bucket using scalar sum() queries.
     * - sales_by_method: one sum() per placed method (category="Sales")
     * - order_count_total: one sum() across all placed methods (category="Order_Count")
     */
    private function computeBucket(
        ?string $franchiseStore,
        string $from,
        string $to,
        array $placed,
        array $fulfilled
    ): array {
        // (1) Sales by method — scalar sum per method
        $salesByMethod = [];
        foreach ($placed as $method) {
            $q = ChannelData::query()
                ->whereBetween('business_date', [$from, $to])
                ->where('category', 'Sales')
                ->where('order_placed_method', $method)
                ->whereIn('order_fulfilled_method', $fulfilled);

            $this->applyStore($q, $franchiseStore);

            $salesByMethod[$method] = (float) $q->sum('amount');
        }

        // (2) Order count total for the bucket
        $qCount = ChannelData::query()
            ->whereBetween('business_date', [$from, $to])
            ->where('category', 'Order_Count')
            ->whereIn('order_placed_method', $placed)
            ->whereIn('order_fulfilled_method', $fulfilled);

        $this->applyStore($qCount, $franchiseStore);

        $orderCountTotal = (float) $qCount->sum('amount');

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
            'sales_by_method'   => $salesByMethod,
            'order_count_total' => (float) $orderCountTotal,
            'total_sales'       => (float) $totalSales,
            'avg_ticket'        => (float) $avgTicket,
            'method_share'      => $methodShare,
        ];
    }

    /** Apply store filter only when a specific store is provided (null/""/"All" => no filter). */
    private function applyStore(Builder $q, ?string $store): void
    {
        if ($store !== null && $store !== '' && strtolower($store) !== 'all') {
            $q->where('franchise_store', $store);
        }
    }

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
