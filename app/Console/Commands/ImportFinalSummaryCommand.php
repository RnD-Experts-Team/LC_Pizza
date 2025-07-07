<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use App\Models\CashManagement;
use App\Models\DetailOrder;
use App\Models\FinancialView;
use App\Models\SummaryItem;
use App\Models\SummarySale;
use App\Models\SummaryTransaction;
use App\Models\FinalSummary;  // Make sure your FinalSummary model exists
use Illuminate\Support\Facades\Log;

class ImportFinalSummaryCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * Run via: php artisan import:final-summary
     *
     * @var string
     */
    protected $signature = 'import:final-summary';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Build final summary aggregates from various models and update the FinalSummary table';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Collect unique combinations of franchise_store and business_date from all models.
        $combinations = collect();

        foreach ([
            CashManagement::class,
            DetailOrder::class,
            FinancialView::class,
            SummaryItem::class,
            SummarySale::class,
            SummaryTransaction::class,
        ] as $model) {
            $combinations = $combinations->merge(
                $model::query()->select('franchise_store', 'business_date')->distinct()->get()
            );
        }


        // Make the combinations unique by a composite key.
        $combinations = $combinations->unique(function ($item) {
            return $item->franchise_store . '_' . $item->business_date;
        });



        // Iterate through each (franchise_store, business_date) combination.
        foreach ($combinations as $combo) {
            $store = $combo->franchise_store;
            $date  = $combo->business_date;

            // Retrieve data for this specific combination.
            $orderRows   = DetailOrder::where('franchise_store', $store)
                                ->where('business_date', $date)
                                ->get();

            $financeRows = FinancialView::where('franchise_store', $store)
                                ->where('business_date', $date)
                                ->get();

            // Optional: Get waste rows from SummaryItem where the account indicates Waste.
            $wasteRows = SummaryItem::where('franchise_store', $store)
                                ->where('business_date', $date)
                                ->where('menu_item_account', 'Waste')
                                ->get();

            // --------------------------
            // Build Aggregates (using logic similar to the in-memory function)
            // --------------------------
            // detail_orders (OrderRows) calculations
            $totalSales = $orderRows->sum('royalty_obligation');

            $modifiedOrderQty = $orderRows->filter(function ($row) {
                return !empty(trim($row->override_approval_employee));
            })->count();

            $refundedOrderQty = $orderRows->where('refunded', "Yes")->count();
            $customerCount    = $orderRows->sum('customer_count');

            $phoneSales       = $orderRows->where('order_placed_method', 'Phone')->sum('royalty_obligation');
            $callCenterSales  = $orderRows->where('order_placed_method', 'SoundHoundAgent')->sum('royalty_obligation');
            $driveThruSales   = $orderRows->where('order_placed_method', 'Drive Thru')->sum('royalty_obligation');
            $websiteSales     = $orderRows->where('order_placed_method', 'Website')->sum('royalty_obligation');
            $mobileSales      = $orderRows->where('order_placed_method', 'Mobile')->sum('royalty_obligation');

            $doordashSales    = $orderRows->where('order_placed_method', 'DoorDash')->sum('royalty_obligation');
            $grubHubSales     = $orderRows->where('order_placed_method', 'Grubhub')->sum('royalty_obligation');
            $uberEatsSales    = $orderRows->where('order_placed_method', 'UberEats')->sum('royalty_obligation');

            $deliverySales    = $doordashSales + $grubHubSales + $uberEatsSales + $mobileSales + $websiteSales;
            $digitalSales     = $totalSales > 0 ? ($deliverySales / $totalSales)  : 0;

            $portalTransactions   = $orderRows->where('portal_eligible', 'Yes')->count();
            $putIntoPortal        = $orderRows->where('portal_used', 'Yes')->count();
            $portalUsedPercent    = $portalTransactions > 0 ? ($putIntoPortal / $portalTransactions)  : 0;
            $portalOnTime         = $orderRows->where('put_into_portal_before_promise_time', 'Yes')->count();
            $inPortalOnTimePercent = $portalTransactions > 0 ? ($portalOnTime / $portalTransactions) : 0;

            // financial view (FinanceRows) calculations
            $deliveryTips          = $financeRows->where('sub_account', 'Delivery-Tips')->sum('amount');
            $prepaidDeliveryTips   = $financeRows->where('sub_account', 'Prepaid-Delivery-Tips')->sum('amount');
            $inStoreTipAmount      = $financeRows->where('sub_account', 'InStoreTipAmount')->sum('amount');
            $prepaidInstoreTipAmount = $financeRows->where('sub_account', 'Prepaid-InStoreTipAmount')->sum('amount');

            $totalTips = $deliveryTips + $prepaidDeliveryTips + $inStoreTipAmount + $prepaidInstoreTipAmount;
            $overShort = $financeRows->where('sub_account', 'Over-Short')->sum('amount');
            $cashSales = $financeRows->where('sub_account', 'Total Cash Sales')->sum('amount');

            // Waste calculations: For now, if no waste data, it will be zero.
            // Here we assume that each SummaryItem row that qualifies has properties: item_cost and item_quantity.
            $totalWasteCost = $wasteRows->sum(function ($row) {
                return isset($row->item_cost) ? $row->item_cost * $row->item_quantity : 0;
            });

            // --------------------------
            // Save or update the FinalSummary record
            // --------------------------
            FinalSummary::updateOrCreate(
                [
                    'franchise_store' => $store,
                    'business_date'   => $date,

                ],
                [
                    'total_sales'            => $totalSales,
                    'modified_order_qty'     => $modifiedOrderQty,
                    'refunded_order_qty'     => $refundedOrderQty,
                    'customer_count'         => $customerCount,
                    'phone_sales'            => $phoneSales,
                    'call_center_sales'      => $callCenterSales,
                    'drive_thru_sales'       => $driveThruSales,
                    'website_sales'          => $websiteSales,
                    'mobile_sales'           => $mobileSales,
                    'doordash_sales'         => $doordashSales,
                    'grubhub_sales'          => $grubHubSales,
                    'ubereats_sales'         => $uberEatsSales,
                    'delivery_sales'         => $deliverySales,
                    'digital_sales_percent'  => round($digitalSales, 2),
                    'portal_transactions'    => $portalTransactions,
                    'put_into_portal'        => $putIntoPortal,
                    'portal_used_percent'    => round($portalUsedPercent, 2),
                    'put_in_portal_on_time'  => $portalOnTime,
                    'in_portal_on_time_percent' => round($inPortalOnTimePercent, 2),
                    'delivery_tips'          => $deliveryTips,
                    'prepaid_delivery_tips'  => $prepaidDeliveryTips,
                    'in_store_tip_amount'    => $inStoreTipAmount,
                    'prepaid_instore_tip_amount' => $prepaidInstoreTipAmount,
                    'total_tips'             => $totalTips,
                    'over_short'             => $overShort,
                    'cash_sales'             => $cashSales,
                    'total_waste_cost'       => $totalWasteCost,
                ]
            );

            $this->info("Final summary updated for store: {$store} on date: {$date}");
        }

        $this->info("Final summary import completed.");
        return 0;
    }
}
