<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\DetailOrder;
use App\Models\ChannelData;
use Carbon\Carbon;

class ChannelDataImporter extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'channel-data:import';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import ChannelData summaries from DetailOrder for all dates';

    /**
     * Metrics mapping: [ Category => [ SubCategory => [ column, type ] ] ]
     *
     * @var array
     */
    protected $metrics = [
        'Sales' => [
            '-' => ['column' => 'royalty_obligation', 'type' => 'sum'],
        ],
        'Gross_Sales' => [
            '-' => ['column' => 'gross_sales', 'type' => 'sum'],
        ],
        'Order_Count' => [
            '-' => ['column' => 'order_id', 'type' => 'count'],
        ],
        'Tips' => [
            'DeliveryTip'         => ['column' => 'delivery_tip', 'type' => 'sum'],
            'DeliveryTipTax'      => ['column' => 'delivery_tip_tax', 'type' => 'sum'],
            'StoreTipAmount'      => ['column' => 'store_tip_amount', 'type' => 'sum'],
        ],
        'Tax' => [
            'TaxableAmount'       => ['column' => 'taxable_amount', 'type' => 'sum'],
            'NonTaxableAmount'    => ['column' => 'non_taxable_amount', 'type' => 'sum'],
            'TaxExemptAmount'     => ['column' => 'tax_exempt_amount', 'type' => 'sum'],
            'SalesTax'            => ['column' => 'sales_tax', 'type' => 'sum'],
            'OccupationalTax'     => ['column' => 'occupational_tax', 'type' => 'sum'],
        ],
        'Fee' => [
            'DeliveryFee'               => ['column' => 'delivery_fee', 'type' => 'sum'],
            'DeliveryFeeTax'            => ['column' => 'delivery_fee_tax', 'type' => 'sum'],
            'DeliveryServiceFee'        => ['column' => 'delivery_service_fee', 'type' => 'sum'],
            'DeliveryServiceFeeTax'     => ['column' => 'delivery_service_fee_tax', 'type' => 'sum'],
            'DeliverySmallOrderFee'     => ['column' => 'delivery_small_order_fee', 'type' => 'sum'],
            'DeliverySmallOrderFeeTax'  => ['column' => 'delivery_small_order_fee_tax', 'type' => 'sum'],
        ],
        'HNR' => [
            'HNROrdersCount' => ['column' => 'hnrOrder', 'type' => 'sum'],
        ],
        'portal' => [
            'PutInPortalOrdersCount'    => ['column' => 'portal_used', 'type' => 'sum'],
            'PutInPortalOnTimeOrdersCount' => ['column' => 'put_into_portal_before_promise_time', 'type' => 'sum'],
        ],
    ];

    public function handle()
    {
        $this->info('Starting ChannelData import…');

        // 1) Get every distinct date
        $dates = DetailOrder::query()
            ->select('business_date')
            ->distinct()
            ->pluck('business_date');

        foreach ($dates as $date) {
            $this->info("Processing date: {$date}");

            // Optional: clear out any existing summaries for this date
            ChannelData::where('date', $date)->delete();

        // 2) Load all orders for that date
            $detailOrders = DetailOrder::where('business_date', $date)->get();

        // 3) Get all stores for that date
            $stores = $detailOrders->pluck('franchise_store')->unique();

            $summaryRows = [];

            foreach ($stores as $store) {
                $OrderRows = $detailOrders->where('franchise_store', $store);

                // Group by actual method combos
                $grouped = $OrderRows->groupBy(fn($r) =>
                    $r->order_placed_method.'|'.$r->order_fulfilled_method
                );

                foreach ($grouped as $comboKey => $methodOrders) {
                    [$placedMethod, $fulfilledMethod] = explode('|', $comboKey);

                    // Compute each metric
                    foreach ($this->metrics as $category => $subcats) {
                        foreach ($subcats as $subcat => $info) {
                            if ($info['type'] === 'sum') {
                                $amount = $methodOrders->sum(fn($r) => floatval($r->{$info['column']} ?? 0));
                            } else {
                                $amount = $methodOrders->unique('order_id')->count();
                            }

                            if ($amount != 0) {
                                $summaryRows[] = [
                                    'store'                  => $store,
                                    'date'                   => $date,
                                    'category'               => $category,
                                    'sub_category'           => $subcat,
                                    'order_placed_method'    => $placedMethod,
                                    'order_fulfilled_method' => $fulfilledMethod,
                                    'amount'                 => $amount,
                                ];
                            }
                        }
                    }
                }
            }

            // 4) Bulk insert in chunks
            if (! empty($summaryRows)) {
                foreach (array_chunk($summaryRows, 1000) as $batch) {
                    ChannelData::insert($batch);
                }
            }

            $this->info("Finished date: {$date} — inserted ".count($summaryRows)." rows.");
        }

        $this->info('ChannelData import complete.');
    }
}
