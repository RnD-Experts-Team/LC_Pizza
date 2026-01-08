<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\DB;

class StoreItemsMatrixService
{
    private const BUCKETS = [
        'in_store' => [
            'label'     => 'In Store',
            'placed'    => ['Register', 'Drive Thru', 'SoundHoundAgent', 'Phone', 'CallCenterAgent'],
            'fulfilled' => ['Register', 'Drive-Thru'],
        ],
        'lc_pickup' => [
            'label'     => 'LC Pickup',
            'placed'    => ['Website', 'Mobile'],
            'fulfilled' => ['Register', 'Drive-Thru', 'In Store Only'],
        ],
        'lc_delivery' => [
            'label'     => 'LC Delivery',
            'placed'    => ['Website', 'Mobile', 'CallCenterAgent', 'SoundHoundAgent'],
            'fulfilled' => ['Delivery'],
        ],
        'third_party' => [
            'label'     => '3rd Party',
            'placed'    => ['UberEats', 'Grubhub', 'DoorDash'],
            'fulfilled' => ['Delivery'],
        ],
    ];

    public function compute(
        string $from,
        string $to,
        ?array $stores = null,   // null => all stores
        ?array $items = null,    // null => all items
        bool $withoutBundle = false
    ): array {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        // Accumulators
        $storeBucketItem = []; // [store][bucket][itemId] => ['units'=>..,'sales'=>..,'name'=>..,'account'=>..]
        $storesSeen = [];
        $itemsSeen = [];       // union of item_ids that appeared

        // Optional: overall totals across all stores per bucket
        $totalsByBucket = [];  // [bucket][itemId] => ...
        $bucketLabels = array_map(fn($b) => $b['label'], self::BUCKETS);

        $placedToBucket = $this->buildPlacedToBucketIndex();
        $fulfilledToBucket = $this->buildFulfilledToBucketIndex();

        $q = DB::table('order_line')
            ->select([
                'id',
                'franchise_store',
                'order_placed_method',
                'order_fulfilled_method',
                'item_id',
                'menu_item_name',
                'menu_item_account',
                'net_amount',
                'quantity',
            ])
            ->whereBetween('business_date', [$from, $to])
            ->whereNotNull('item_id')
            ->orderBy('id');

        if ($stores && count($stores)) {
            $q->whereIn('franchise_store', $stores);
        }

        if ($items && count($items)) {
            $q->whereIn('item_id', $items);
        }

        if ($withoutBundle) {
            $q->where(function ($qq) {
                $qq->whereNull('bundle_name')->orWhere('bundle_name', '');
            })->where(function ($qq) {
                $qq->whereNull('modification_reason')->orWhere('modification_reason', '');
            });
        }

        // Tune chunk size: all-stores can be heavier
        $chunkSize = ($stores === null) ? 2000 : 5000;

        $q->chunkById($chunkSize, function ($rows) use (
            &$storeBucketItem,
            &$storesSeen,
            &$itemsSeen,
            &$totalsByBucket,
            $placedToBucket,
            $fulfilledToBucket
        ) {
            foreach ($rows as $r) {
                $store = (string)($r->franchise_store ?? '');
                if ($store === '') continue;

                $itemId = (int)($r->item_id ?? 0);
                if ($itemId <= 0) continue;

                $qty  = (int)($r->quantity ?? 0);
                $amt  = (float)($r->net_amount ?? 0);
                $name = (string)($r->menu_item_name ?? '');
                $acct = (string)($r->menu_item_account ?? '');

                $bucket = $this->resolveBucket(
                    (string)($r->order_placed_method ?? ''),
                    (string)($r->order_fulfilled_method ?? ''),
                    $placedToBucket,
                    $fulfilledToBucket
                );

                // if it doesn’t match any bucket, ignore (or you can store under 'unmatched')
                if ($bucket === null) {
                    continue;
                }

                $storesSeen[$store] = true;
                $itemsSeen[(string)$itemId] = true;

                // Store+Bucket
                $this->accumulate($storeBucketItem, $store, $bucket, $itemId, $qty, $amt, $name, $acct);

                // Store+"all" bucket
                $this->accumulate($storeBucketItem, $store, 'all', $itemId, $qty, $amt, $name, $acct);

                // Optional: overall totals across all stores
                $this->accumulate($totalsByBucket, '_all_stores_', $bucket, $itemId, $qty, $amt, $name, $acct);
                $this->accumulate($totalsByBucket, '_all_stores_', 'all', $itemId, $qty, $amt, $name, $acct);
            }
        }, 'id', 'id');

        // Shape output: stores -> buckets -> sorted item rows
        $storesOut = [];
        $storeCodes = array_keys($storesSeen);
        sort($storeCodes);

        $bucketMeta = self::BUCKETS;
        $bucketMeta['all'] = ['label' => 'All Buckets', 'placed' => null, 'fulfilled' => null];

        foreach ($storeCodes as $store) {
            $bucketsOut = [];

            foreach ($bucketMeta as $bucketKey => $meta) {
                $itemMap = $storeBucketItem[$store][$bucketKey] ?? [];
                $rows = $this->mapToRows($itemMap);

                // sort by units desc, then sales desc
                usort($rows, fn($a, $b) => ($b['units_sold'] <=> $a['units_sold']) ?: ($b['total_sales'] <=> $a['total_sales']));

                $bucketsOut[$bucketKey] = [
                    'label' => $meta['label'],
                    'items' => $rows,
                ];
            }

            $storesOut[] = [
                'store'   => $store,
                'buckets' => $bucketsOut,
            ];
        }

        // overall totals across all stores (optional but useful)
        $overallBucketsOut = [];
        foreach ($bucketMeta as $bucketKey => $meta) {
            $itemMap = $totalsByBucket['_all_stores_'][$bucketKey] ?? [];
            $rows = $this->mapToRows($itemMap);
            usort($rows, fn($a, $b) => ($b['units_sold'] <=> $a['units_sold']) ?: ($b['total_sales'] <=> $a['total_sales']));

            $overallBucketsOut[$bucketKey] = [
                'label' => $meta['label'],
                'items' => $rows,
            ];
        }

        $union = array_map('intval', array_keys($itemsSeen));
        sort($union);

        return [
            'bucket_labels' => array_merge($bucketLabels, ['all' => 'All Buckets']),
            'items_union'   => $union,
            'stores'        => $storesOut,
            'overall'       => [
                'buckets' => $overallBucketsOut,
            ],
        ];
    }

    private function accumulate(
        array &$root,
        string $store,
        string $bucket,
        int $itemId,
        int $qty,
        float $amt,
        string $name,
        string $acct
    ): void {
        $id = (string)$itemId;

        if (!isset($root[$store])) $root[$store] = [];
        if (!isset($root[$store][$bucket])) $root[$store][$bucket] = [];
        if (!isset($root[$store][$bucket][$id])) {
            $root[$store][$bucket][$id] = [
                'item_id' => $itemId,
                'menu_item_name' => $name,
                'menu_item_account' => $acct,
                'units_sold' => 0,
                'total_sales' => 0.0,
            ];
        }

        $root[$store][$bucket][$id]['units_sold']  += max(0, $qty);
        $root[$store][$bucket][$id]['total_sales'] += $amt;

        // fill missing meta if first rows lacked it
        if ($root[$store][$bucket][$id]['menu_item_name'] === '' && $name !== '') {
            $root[$store][$bucket][$id]['menu_item_name'] = $name;
        }
        if ($root[$store][$bucket][$id]['menu_item_account'] === '' && $acct !== '') {
            $root[$store][$bucket][$id]['menu_item_account'] = $acct;
        }
    }

    private function mapToRows(array $itemMap): array
    {
        // already has desired keys; just return values
        return array_values($itemMap);
    }

    /**
     * Fast bucket resolution without raw SQL:
     * - uses tiny indices so it’s cheap per row
     */
    private function resolveBucket(
        string $placed,
        string $fulfilled,
        array $placedToBucket,
        array $fulfilledToBucket
    ): ?string {
        if ($placed === '' || $fulfilled === '') return null;

        // candidate buckets by placed method
        $candidates = $placedToBucket[$placed] ?? null;
        if (!$candidates) return null;

        // bucket is valid only if fulfilled method is allowed for that bucket
        foreach ($candidates as $bucketKey) {
            if (isset($fulfilledToBucket[$bucketKey][$fulfilled])) {
                return $bucketKey;
            }
        }
        return null;
    }

    private function buildPlacedToBucketIndex(): array
    {
        $idx = [];
        foreach (self::BUCKETS as $key => $rules) {
            foreach ($rules['placed'] as $m) {
                $idx[$m] ??= [];
                $idx[$m][] = $key;
            }
        }
        return $idx;
    }

    private function buildFulfilledToBucketIndex(): array
    {
        $idx = [];
        foreach (self::BUCKETS as $key => $rules) {
            $idx[$key] = [];
            foreach ($rules['fulfilled'] as $m) {
                $idx[$key][$m] = true;
            }
        }
        return $idx;
    }
}
