<?php

namespace App\Services\Helper\Logics;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use App\Services\Helper\Insert\InsertDataServices;

use Carbon\Carbon;

class LogicsAndQueriesServices
{
    protected InsertDataServices $inserter;

    public function __construct(InsertDataServices $inserter)
    {
        $this->inserter = $inserter;
    }
    protected array $channelDataMetrics = [
        'Sales' => [
            '-' => ['column' => 'royalty_obligation', 'type' => 'sum'],
        ],
        'Gross_Sales' => [
            '-' => ['column' => 'gross_sales', 'type' => 'sum'],
        ],
        'Order_Count' => [
            '-' => ['column' => 'customer_count', 'type' => 'sum'],
        ],
        'Tips' => [
            'DeliveryTip' => ['column' => 'delivery_tip', 'type' => 'sum'],
            'DeliveryTipTax' => ['column' => 'delivery_tip_tax', 'type' => 'sum'],
            'StoreTipAmount' => ['column' => 'store_tip_amount', 'type' => 'sum'],
        ],
        'Tax' => [
            'TaxableAmount' => ['column' => 'taxable_amount', 'type' => 'sum'],
            'NonTaxableAmount' => ['column' => 'non_taxable_amount', 'type' => 'sum'],
            'TaxExemptAmount' => ['column' => 'tax_exempt_amount', 'type' => 'sum'],
            'SalesTax' => ['column' => 'sales_tax', 'type' => 'sum'],
            'OccupationalTax' => ['column' => 'occupational_tax', 'type' => 'sum'],
        ],
        'Fee' => [
            'DeliveryFee' => ['column' => 'delivery_fee', 'type' => 'sum'],
            'DeliveryFeeTax' => ['column' => 'delivery_fee_tax', 'type' => 'sum'],
            'DeliveryServiceFee' => ['column' => 'delivery_service_fee', 'type' => 'sum'],
            'DeliveryServiceFeeTax' => ['column' => 'delivery_service_fee_tax', 'type' => 'sum'],
            'DeliverySmallOrderFee' => ['column' => 'delivery_small_order_fee', 'type' => 'sum'],
            'DeliverySmallOrderFeeTax' => ['column' => 'delivery_small_order_fee_tax', 'type' => 'sum'],
        ],
        'HNR' => [
            'HNROrdersCount' => ['column' => 'hnrOrder', 'type' => 'sum'],
        ],
        'portal' => [
            'PutInPortalOrdersCount' => ['column' => 'portal_used', 'type' => 'sum'],
            'PutInPortalOnTimeOrdersCount' => ['column' => 'put_into_portal_before_promise_time', 'type' => 'sum'],
        ],
    ];
    protected array $breadExcludedItemIds = [
        '-1',
        '6',
        '7',
        '8',
        '9',
        '101001',
        '101002',
        '101288',
        '103044',
        '202901',
        '101289',
        '204100',
        '204200',
    ];
    //main fun
    public function DataLoop(array $data, string $selectedDate)
    {

        Log::info('Started the data loop.');

        $detailOrder = collect($data['processDetailOrders'] ?? []);
        $financialView = collect($data['processFinancialView'] ?? []);
        $wasteData = collect($data['processWaste'] ?? []);
        $orderLine = collect($data['processOrderLine'] ?? []);

        $allFranchiseStores = collect([
            ...$detailOrder->pluck('franchise_store'),
            ...$financialView->pluck('franchise_store'),
            ...$wasteData->pluck('franchise_store')
        ])->unique();


        $allChannelRows = [];

        Log::info('Started the store loop');
        foreach ($allFranchiseStores as $store) {

            $OrderRows = $detailOrder->where('franchise_store', $store);
            $financeRows = $financialView->where('franchise_store', $store);
            $wasteRows = $wasteData->where('franchise_store', $store);
            $storeOrderLines = $orderLine->where('franchise_store', $store);


            //******* Bread Boost Summary *********//

            $breadBoostRow = $this->BreadBoost($storeOrderLines, $store, $selectedDate);
            if (!empty($breadBoostRow)) {
                $this->inserter->insertBreadBoostData([$breadBoostRow]);
            }

            //******* Online Discount Program *********//
            $odpRows = $this->OnlineDiscountProgram($OrderRows, $store, $selectedDate);
            if (!empty($odpRows)) {
                $this->inserter->insertOnlineDiscountProgramData($odpRows);
            }

            //******* Delivery Order Summary *********//
            $deliverySummaryRow = $this->DeliveryOrderSummary($OrderRows, $store, $selectedDate);
            if (!empty($deliverySummaryRow)) {
                $this->inserter->insertDeliveryOrderSummaryData([$deliverySummaryRow]);
            }

            //*******3rd Party Marketplace Orders*********//
            $thirdPartyRow = $this->ThirdPartyMarketplace($OrderRows, $store, $selectedDate);
            if (!empty($thirdPartyRow)) {
                $this->inserter->insertThirdPartyMarketplaceOrder([$thirdPartyRow]);
            }

            //******* For finance data table *********//
            $financeDataRow = $this->FinanceData($OrderRows, $financeRows, $store, $selectedDate);
            if (!empty($financeDataRow)) {
                $this->inserter->insertFinanceData([$financeDataRow]);
            }

            //******* final summary *********//
            $finalSummaryRow = $this->FinalSummaries($OrderRows, $financeRows, $wasteRows, $store, $selectedDate);
            if (!empty($finalSummaryRow)) {
                $this->inserter->insertFinalSummary([$finalSummaryRow]);
            }

            //Hours loop
            $ordersByHour = $this->groupOrdersByHour($OrderRows);

            $hourlySalesRows = [];
            $hourHnrRows     = [];

            foreach ($ordersByHour as $hour => $hourOrders) {
                $h = (int) $hour;

                $hourlySalesRows[] = $this->makeHourlySalesRow($hourOrders, $store, $selectedDate, $h);
                $hourHnrRows[]     = $this->makeHourHnrRow($hourOrders, $store, $selectedDate, $h);
            }

            if (!empty($hourlySalesRows)) {
                $this->inserter->insertHourlySales($hourlySalesRows);
            }
            if (!empty($hourHnrRows)) {
                $this->inserter->insertHourHnrTransactions($hourHnrRows);
            }

            // Store hnr transactions
            $storeHNRRows = $this->StoreHotNReadyTransaction($OrderRows, $storeOrderLines, $store, $selectedDate);
            if (!empty($storeHNRRows)) {
                $this->inserter->insertStoreHotNReadyTransaction($storeHNRRows);
            }
            //End of Hours loop

            //******* ChannelData *******
            $channelRows = $this->ChannelData($OrderRows, $store, $selectedDate);
            if (!empty($channelRows)) {
                array_push($allChannelRows, ...$channelRows);
            }
        }

        if (!empty($allChannelRows)) {
            $this->inserter->insertChannelData($allChannelRows);
        }
        Log::info('Ended the data loop.');
    }

    public function ChannelData(Collection $orderRows, string $store, string $selectedDate): array
    {
        $rows = [];
        $groupedCombos = $orderRows->groupBy(function ($row) {
            return ($row['order_placed_method'] ?? '') . '|' . ($row['order_fulfilled_method'] ?? '');
        });

        foreach ($groupedCombos as $comboKey => $methodOrders) {
            [$placedMethod, $fulfilledMethod] = explode('|', $comboKey);

            foreach ($this->channelDataMetrics as $category => $subcats) {
                foreach ($subcats as $subcat => $info) {
                    if ($info['type'] === 'sum') {
                        $amount = $methodOrders->sum(fn($row) => (float)($row[$info['column']] ?? 0));
                    } else { // count distinct orders
                        $amount = $methodOrders->unique('order_id')->count();
                    }

                    if ($amount != 0) {
                        $rows[] = [
                            'franchise_store'                 => $store,
                            'business_date'         => $selectedDate,
                            'category'              => $category,
                            'sub_category'          => $subcat,
                            'order_placed_method'   => $placedMethod,
                            'order_fulfilled_method' => $fulfilledMethod,
                            'amount'                => $amount,
                        ];
                    }
                }
            }
        }

        return $rows;
    }

    public function BreadBoost(Collection $storeOrderLines, string $store, string $selectedDate): array
    {
        Log::info('Bread Boost Summary');
        $classicOrders = $storeOrderLines
            ->whereIn('menu_item_name', ['Classic Pepperoni', 'Classic Cheese'])
            ->whereIn('order_fulfilled_method', ['Register', 'Drive-Thru'])
            ->whereIn('order_placed_method', ['Phone', 'Register', 'Drive Thru'])
            ->pluck('order_id')
            ->unique();

        $classicOrdersCount = $classicOrders->count();

        $classicWithBreadCount = $storeOrderLines
            ->whereIn('order_id', $classicOrders)
            ->where('menu_item_name', 'Crazy Bread')
            ->pluck('order_id')
            ->unique()
            ->count();

        $OtherPizzaOrder = $storeOrderLines
            ->whereNotIn('item_id', $this->breadExcludedItemIds)
            ->whereIn('order_fulfilled_method', ['Register', 'Drive-Thru'])
            ->whereIn('order_placed_method', ['Phone', 'Register', 'Drive Thru'])
            ->pluck('order_id')
            ->unique();

        $OtherPizzaOrderCount = $OtherPizzaOrder->count();

        $OtherPizzaWithBreadCount = $storeOrderLines
            ->whereIn('order_id', $OtherPizzaOrder)
            ->where('menu_item_name', 'Crazy Bread')
            ->pluck('order_id')
            ->unique()
            ->count();

        return [
            'franchise_store'         => $store,
            'business_date'           => $selectedDate,
            'classic_order'           => $classicOrdersCount,
            'classic_with_bread'      => $classicWithBreadCount,
            'other_pizza_order'       => $OtherPizzaOrderCount,
            'other_pizza_with_bread'  => $OtherPizzaWithBreadCount,
        ];
    }

    public function OnlineDiscountProgram(Collection $orderRows, string $store, string $selectedDate): array
    {
        Log::info('Online Discount Program');
        $rows = [];

        $discountOrders = $orderRows
            ->where('employee', '')
            ->where('modification_reason', '<>', '');

        foreach ($discountOrders as $discountOrder) {
            $rows[] = [
                'franchise_store'    => $store,
                'business_date'      => $selectedDate,
                'order_id'           => $discountOrder['order_id'],
                'pay_type'           => $discountOrder['payment_methods'],
                'original_subtotal'  => 0,
                'modified_subtotal'  => $discountOrder['royalty_obligation'],
                'promo_code'         => trim(explode(':', $discountOrder['modification_reason'])[1] ?? ''),
            ];
        }

        return $rows;
    }

    public function DeliveryOrderSummary(Collection $OrderRows, string $store, string $selectedDate): array
    {
        Log::info('Delivery Order Summary');

        $placedOnline = $OrderRows
            ->whereIn('order_placed_method', ['Mobile', 'Website'])
            ->where('order_fulfilled_method', 'Delivery');

        $Oreders_count = $placedOnline
            ->Count();

        $RO = $placedOnline
            ->Sum('royalty_obligation');

        $occupational_tax = $placedOnline
            ->Sum('occupational_tax');

        $delivery_charges = $placedOnline
            ->Sum('delivery_fee');

        $delivery_charges_Taxes = $placedOnline
            ->Sum('delivery_fee_tax');

        $delivery_Service_charges = $placedOnline
            ->Sum('delivery_service_fee');

        $delivery_Service_charges_Tax = $placedOnline
            ->Sum('delivery_service_fee_tax');

        $delivery_small_order_charge = $placedOnline
            ->Sum('delivery_small_order_fee');

        $delivery_small_order_charge_Tax = $placedOnline
            ->Sum('delivery_small_order_fee_tax');

        $Delivery_Tip_Summary = $placedOnline
            ->Sum('delivery_tip');

        $Delivery_Tip_Tax_Summary = $placedOnline
            ->Sum('delivery_tip_tax');

        $total_taxes = $placedOnline
            ->Sum('sales_tax');
        // sales tax       delivery_service_fee_tax 4.57    -  delivery_fee_tax 0.9     0.48
        $tax = $total_taxes - $delivery_Service_charges_Tax - $delivery_charges_Taxes - $delivery_small_order_charge_Tax;

        $product_cost = $RO - ($delivery_Service_charges + $delivery_charges + $delivery_small_order_charge);

        $order_total = $RO + $total_taxes + $Delivery_Tip_Summary;

        $lateCount = $OrderRows
            ->where('delivery_fee', '!=', 0)
            ->whereIn('order_placed_method', ['Mobile', 'Website'])
            ->where('order_fulfilled_method', 'Delivery')
            ->filter(function ($order) {
                $loadedRaw  = trim((string)($order['time_loaded_into_portal'] ?? ''));
                $promiseRaw = trim((string)($order['promise_date'] ?? ''));
                if ($loadedRaw === '' || $promiseRaw === '') return false;

                try {
                    $loaded   = Carbon::createFromFormat('Y-m-d H:i:s', $loadedRaw);
                    $promise5 = Carbon::createFromFormat('Y-m-d H:i:s', $promiseRaw)->addMinutes(5);
                    return $loaded->greaterThan($promise5);
                } catch (\Throwable $e) {
                    return false;
                }
            })
            ->count();

        $delivery_late_charge = round($lateCount * 0.5, 2);

        return [
            'franchise_store' => $store,
            'business_date' => $selectedDate,
            'orders_count' => $Oreders_count,
            'product_cost' => $product_cost,
            'tax' => $tax,
            'occupational_tax' => $occupational_tax,
            'delivery_charges' => $delivery_charges,
            'delivery_charges_taxes' => $delivery_charges_Taxes,
            'service_charges' => $delivery_Service_charges,
            'service_charges_taxes' => $delivery_Service_charges_Tax,
            'small_order_charge' => $delivery_small_order_charge,
            'small_order_charge_taxes' => $delivery_small_order_charge_Tax,
            'delivery_late_charge' => $delivery_late_charge,
            'tip' => $Delivery_Tip_Summary,
            'tip_tax' => $Delivery_Tip_Tax_Summary,
            'total_taxes' => $total_taxes,
            'order_total' => $order_total
        ];
    }
    public function ThirdPartyMarketplace(Collection $OrderRows, string $store, string $selectedDate): array
    {
        Log::info('3rd Party Marketplace Orders');

        $dd  = $OrderRows->where('order_placed_method', 'DoorDash');
        $ue  = $OrderRows->where('order_placed_method', 'UberEats');
        $gh  = $OrderRows->where('order_placed_method', 'Grubhub');

        return [
            'franchise_store'                       => $store,
            'business_date'                         => $selectedDate,
            'doordash_product_costs_Marketplace'    => $dd->Sum('royalty_obligation'),
            'doordash_tax_Marketplace'              => $dd->Sum('sales_tax'),
            'doordash_order_total_Marketplace'      => $dd->Sum('gross_sales'),
            'ubereats_product_costs_Marketplace'    => $ue->Sum('royalty_obligation'),
            'ubereats_tax_Marketplace'              => $ue->Sum('sales_tax'),
            'uberEats_order_total_Marketplace'      => $ue->Sum('gross_sales'),
            'grubhub_product_costs_Marketplace'     => $gh->Sum('royalty_obligation'),
            'grubhub_tax_Marketplace'               => $gh->Sum('sales_tax'),
            'grubhub_order_total_Marketplace'       => $gh->Sum('gross_sales'),

        ];
    }
    public function FinanceData(Collection $OrderRows, Collection $financeRows, string $store, string $selectedDate): array
    {

        Log::info('finance data table');
        $Pizza_Carryout = $financeRows
            ->where('sub_account', 'Pizza - Carryout')
            ->sum('amount');

        $HNR_Carryout = $financeRows
            ->where('sub_account', 'HNR - Carryout')
            ->sum('amount');

        $Bread_Carryout = $financeRows
            ->where('sub_account', 'Bread - Carryout')
            ->sum('amount');

        $Wings_Carryout = $financeRows
            ->where('sub_account', 'Wings - Carryout')
            ->sum('amount');

        $Beverages_Carryout = $financeRows
            ->where('sub_account', 'Beverages - Carryout')
            ->sum('amount');

        $Other_Foods_Carryout = $financeRows
            ->where('sub_account', 'Other Foods - Carryout')
            ->sum('amount');

        $Side_Items_Carryout = $financeRows
            ->where('sub_account', 'Side Items - Carryout')
            ->sum('amount');

        $Side_Items_Carryout = $financeRows
            ->where('sub_account', 'Side Items - Carryout')
            ->sum('amount');

        $Pizza_Delivery = $financeRows
            ->where('sub_account', 'Pizza - Delivery')
            ->sum('amount');

        $HNR_Delivery = $financeRows
            ->where('sub_account', 'HNR - Delivery')
            ->sum('amount');

        $Bread_Delivery = $financeRows
            ->where('sub_account', 'Bread - Delivery')
            ->sum('amount');

        $Wings_Delivery = $financeRows
            ->where('sub_account', 'Wings - Delivery')
            ->sum('amount');

        $Beverages_Delivery = $financeRows
            ->where('sub_account', 'Beverages - Delivery')
            ->sum('amount');

        $Other_Foods_Delivery = $financeRows
            ->where('sub_account', 'Other Foods - Delivery')
            ->sum('amount');

        $Side_Items_Delivery = $financeRows
            ->where('sub_account', 'Side Items - Delivery')
            ->sum('amount');

        $Delivery_Charges = $financeRows
            ->where('sub_account', 'Delivery-Fees')
            ->sum('amount');

        $TOTAL_Net_Sales = $Pizza_Carryout + $HNR_Carryout + $Bread_Carryout + $Wings_Carryout + $Beverages_Carryout + $Other_Foods_Carryout + $Side_Items_Carryout + $Pizza_Delivery + $HNR_Delivery + $Bread_Delivery + $Wings_Delivery + $Beverages_Delivery + $Other_Foods_Delivery + $Side_Items_Delivery + $Delivery_Charges;

        //customer count calculated below

        $Gift_Card_Non_Royalty = $financeRows
            ->where('sub_account', 'Gift Card')
            ->sum('amount');
        $Total_Non_Royalty_Sales = $financeRows
            ->where('sub_account', 'Non-Royalty')
            ->sum('amount');

        $Total_Non_Delivery_Tips = $financeRows
            ->where('area', 'Store Tips')
            ->sum('amount');

        $Sales_Tax_Delivery = $OrderRows
            ->where('order_fulfilled_method', 'Delivery')
            ->whereIn('order_placed_method', ['Mobile', 'Website', 'SoundHoundAgent'])
            ->sum('sales_tax');


        $TOTAL_Sales_TaxQuantity = $financeRows
            ->where('sub_account', 'Sales-Tax')
            ->sum('amount');

        $Sales_Tax_Food_Beverage = $TOTAL_Sales_TaxQuantity - $Sales_Tax_Delivery;


        $DELIVERY_Quantity = $OrderRows
            ->where('delivery_fee', '<>', 0)
            ->where('royalty_obligation', '!=', 0)
            ->count();

        $Delivery_Fee = $OrderRows->sum('delivery_fee');
        $Delivery_Service_Fee = $OrderRows->sum('delivery_service_fee');
        $Delivery_Small_Order_Fee = $OrderRows->sum('delivery_small_order_fee');
        $TOTAL_Native_App_Delivery_Fees = $financeRows
            ->where('sub_account', 'Delivery-Fees')
            ->sum('amount');

        $Delivery_Late_to_Portal_Fee_Count = $OrderRows
            ->where('delivery_fee', '!=', 0)
            ->whereIn('order_placed_method', ['Mobile', 'Website'])
            ->where('order_fulfilled_method', 'Delivery')
            ->filter(function ($order) {
                $loadedRaw = trim((string) $order['time_loaded_into_portal'] ?? '');
                $promiseRaw = trim((string) $order['promise_date'] ?? '');

                if (empty($loadedRaw) || empty($promiseRaw)) {
                    return false;
                }

                try {
                    $loadedTime = Carbon::createFromFormat('Y-m-d H:i:s', $loadedRaw);
                    $promiseTimePlus5 = Carbon::createFromFormat('Y-m-d H:i:s', $promiseRaw)->addMinutes(5);

                    return $loadedTime->greaterThan($promiseTimePlus5);
                } catch (\Exception $e) {
                    Log::warning('Late portal fee date parse failed', [
                        'loaded' => $loadedRaw,
                        'promise' => $promiseRaw,
                        'error' => $e->getMessage()
                    ]);
                    return false;
                }
            })
            ->count();

        $Delivery_Late_to_Portal_Fee = round($Delivery_Late_to_Portal_Fee_Count * 0.5, 2);

        $Delivery_Tips = $financeRows
            ->whereIn('sub_account', ['Delivery-Tips', 'Prepaid-Delivery-Tips'])
            ->sum('amount');

        $DoorDash_Quantity = $OrderRows
            ->where('order_placed_method', 'DoorDash')
            ->count();
        $DoorDash_Order_Total = $OrderRows
            ->where('order_placed_method', 'DoorDash')
            ->sum('gross_sales');

        $Grubhub_Quantity = $OrderRows
            ->where('order_placed_method', 'Grubhub')
            ->count();
        $Grubhub_Order_Total = $OrderRows
            ->where('order_placed_method', 'Grubhub')
            ->sum('gross_sales');

        $Uber_Eats_Quantity = $OrderRows
            ->where('order_placed_method', 'UberEats')
            ->count();
        $Uber_Eats_Order_Total = $OrderRows
            ->where('order_placed_method', 'UberEats')
            ->sum('gross_sales');

        $ONLINE_ORDERING_Mobile_Order_Quantity = $OrderRows
            ->where('order_placed_method', 'Mobile')
            ->where('payment_methods', 'EPay')
            ->reject(fn($row) => $row['royalty_obligation'] == 0 && $row['refunded'] === 'Yes')
            ->count();

        $ONLINE_ORDERING_Online_Order_Quantity = $OrderRows
            ->where('order_placed_method', 'Website')
            ->where('royalty_obligation', '!=', 0)
            ->Count();

        $ONLINE_ORDERING_Pay_In_Store = $OrderRows
            ->whereIn('order_placed_method', ['Mobile', 'Website'])
            ->whereIn('order_fulfilled_method', ['Register', 'Drive-Thru'])
            ->where('royalty_obligation', '!=', 0)
            ->Count();

        $Agent_Pre_Paid = $OrderRows
            ->where('order_placed_method', 'SoundHoundAgent')
            ->where('order_fulfilled_method', 'Delivery')
            ->where('royalty_obligation', '!=', 0)
            ->Count();

        $Agent_Pay_In_Store = $OrderRows
            ->where('order_placed_method', 'SoundHoundAgent')
            ->whereIn('order_fulfilled_method', ['Register', 'Drive-Thru'])
            ->where('royalty_obligation', '!=', 0)
            ->Count();

        $PrePaid_Cash_Orders = $financeRows
            ->where('sub_account', 'PrePaidCash-Orders')
            ->sum('amount');

        $PrePaid_Non_Cash_Orders = $financeRows
            ->where('sub_account', 'PrePaidNonCash-Orders')
            ->sum('amount');

        $PrePaid_Sales = $financeRows
            ->where('sub_account', 'PrePaid-Sales')
            ->sum('amount');

        $Prepaid_Delivery_Tips = $financeRows
            ->where('sub_account', 'Prepaid-Delivery-Tips')
            ->sum('amount');

        $Prepaid_InStore_Tips = $financeRows
            ->where('sub_account', 'Prepaid-InStoreTipAmount')
            ->sum('amount');

        $Marketplace_from_Non_Cash_Payments_box = $financeRows
            ->whereIn('sub_account', ['Marketplace - DoorDash', 'Marketplace - UberEats', 'Marketplace - Grubhub'])
            ->sum('amount');

        $AMEX = $financeRows
            ->whereIn('sub_account', ['Credit Card - AMEX', 'EPay - AMEX'])
            ->sum('amount');

        //Total_Non_Cash_Payments
        $credit_card_Cash_Payments = $financeRows
            ->whereIn('sub_account', ['Credit Card - Discover', 'Credit Card - AMEX', 'Credit Card - Visa/MC'])
            ->sum('amount');

        $Debit_Cash_Payments = $financeRows
            ->where('sub_account', 'Debit')
            ->sum('amount');

        $epay_Cash_Payments = $financeRows
            ->whereIn('sub_account', ['EPay - Visa/MC', 'EPay - AMEX', 'EPay - Discover'])
            ->sum('amount');

        $Total_Non_Cash_Payments = $financeRows
            ->where('sub_account', 'Non-Cash-Payments')
            ->sum('amount');
        //
        $Non_Cash_Payments = $Total_Non_Cash_Payments -
            $AMEX -
            $Marketplace_from_Non_Cash_Payments_box -
            $Gift_Card_Non_Royalty;


        //finance sheet

        $Cash_Sales = $financeRows
            ->where('sub_account', 'Cash-Check-Deposit')
            ->sum('amount');

        $Cash_Drop = $financeRows
            ->where('sub_account', 'Cash Drop Total')
            ->sum('amount');

        $Tip_Drop_Total = $financeRows
            ->where('sub_account', 'Tip Drop Total')
            ->sum('amount');

        $tOTAL_Cash_Sales = $financeRows
            ->where('sub_account', 'Total Cash Sales')
            ->sum('amount');

        $Over_Short_New = $tOTAL_Cash_Sales - $Cash_Drop;

        $Over_Short = $financeRows
            ->where('sub_account', 'Over-Short-Operating')
            ->sum('amount');

        $Cash_Drop_Total = $Cash_Drop + $Over_Short;

        $Payouts = $financeRows
            ->where('sub_account', 'Payouts')
            ->sum('amount');

        $Customer_Count = $OrderRows->sum('customer_count');

        return [
            'franchise_store' => $store,
            'business_date' => $selectedDate,
            'Pizza_Carryout' => $Pizza_Carryout,
            'HNR_Carryout' => $HNR_Carryout,
            'Bread_Carryout' => $Bread_Carryout,
            'Wings_Carryout' => $Wings_Carryout,
            'Beverages_Carryout' => $Beverages_Carryout,
            'Other_Foods_Carryout' => $Other_Foods_Carryout,
            'Side_Items_Carryout' => $Side_Items_Carryout,
            'Pizza_Delivery' => $Pizza_Delivery,
            'HNR_Delivery' => $HNR_Delivery,
            'Bread_Delivery' => $Bread_Delivery,
            'Wings_Delivery' => $Wings_Delivery,
            'Beverages_Delivery' => $Beverages_Delivery,
            'Other_Foods_Delivery' => $Other_Foods_Delivery,
            'Side_Items_Delivery' => $Side_Items_Delivery,
            'Delivery_Charges' => $Delivery_Charges,
            'TOTAL_Net_Sales' => $TOTAL_Net_Sales,
            'Customer_Count' => $Customer_Count,
            'Gift_Card_Non_Royalty' => $Gift_Card_Non_Royalty,
            'Total_Non_Royalty_Sales' => $Total_Non_Royalty_Sales,
            'Total_Non_Delivery_Tips' => $Total_Non_Delivery_Tips,
            'Sales_Tax_Food_Beverage' => $Sales_Tax_Food_Beverage,
            'Sales_Tax_Delivery' => $Sales_Tax_Delivery,
            'TOTAL_Sales_TaxQuantity' => $TOTAL_Sales_TaxQuantity,
            'DELIVERY_Quantity' => $DELIVERY_Quantity,
            'Delivery_Fee' => $Delivery_Fee,
            'Delivery_Service_Fee' => $Delivery_Service_Fee,
            'Delivery_Small_Order_Fee' => $Delivery_Small_Order_Fee,
            'Delivery_Late_to_Portal_Fee' => $Delivery_Late_to_Portal_Fee,
            'TOTAL_Native_App_Delivery_Fees' => $TOTAL_Native_App_Delivery_Fees,
            'Delivery_Tips' => $Delivery_Tips,
            'DoorDash_Quantity' => $DoorDash_Quantity,
            'DoorDash_Order_Total' => $DoorDash_Order_Total,
            'Grubhub_Quantity' => $Grubhub_Quantity,
            'Grubhub_Order_Total' => $Grubhub_Order_Total,
            'Uber_Eats_Quantity' => $Uber_Eats_Quantity,
            'Uber_Eats_Order_Total' => $Uber_Eats_Order_Total,
            'ONLINE_ORDERING_Mobile_Order_Quantity' => $ONLINE_ORDERING_Mobile_Order_Quantity,
            'ONLINE_ORDERING_Online_Order_Quantity' => $ONLINE_ORDERING_Online_Order_Quantity,
            'ONLINE_ORDERING_Pay_In_Store' => $ONLINE_ORDERING_Pay_In_Store,
            'Agent_Pre_Paid' => $Agent_Pre_Paid,
            'Agent_Pay_InStore' => $Agent_Pay_In_Store,
            'AI_Pre_Paid' => null,
            'AI_Pay_InStore' => null,
            'PrePaid_Cash_Orders' => $PrePaid_Cash_Orders,
            'PrePaid_Non_Cash_Orders' => $PrePaid_Non_Cash_Orders,
            'PrePaid_Sales' => $PrePaid_Sales,
            'Prepaid_Delivery_Tips' => $Prepaid_Delivery_Tips,
            'Prepaid_InStore_Tips' => $Prepaid_InStore_Tips,
            'Marketplace_from_Non_Cash_Payments_box' => $Marketplace_from_Non_Cash_Payments_box,
            'AMEX' => $AMEX,
            'Total_Non_Cash_Payments' => $Total_Non_Cash_Payments,
            'credit_card_Cash_Payments' => $credit_card_Cash_Payments,
            'Debit_Cash_Payments' => $Debit_Cash_Payments,
            'epay_Cash_Payments' => $epay_Cash_Payments,
            'Non_Cash_Payments' => $Non_Cash_Payments,
            'Cash_Sales' => $Cash_Sales,
            'Cash_Drop_Total' => $Cash_Drop_Total,
            'Over_Short' => $Over_Short_New,
            'Payouts' => $Payouts,
        ];
    }
    public function FinalSummaries(Collection $OrderRows, Collection $financeRows, Collection $wasteRows, string $store, string $selectedDate): array
    {
        $totalSales = $OrderRows->sum('royalty_obligation');

        $modifiedOrderQty = $OrderRows->filter(function ($row) {
            return !empty(trim($row['override_approval_employee']));
        })->count();

        $RefundedOrderQty = $OrderRows
            ->where('refunded', "Yes")
            ->count();

        $customerCount = $OrderRows->sum('customer_count');

        $phoneSales = $OrderRows
            ->where('order_placed_method', 'Phone')
            ->sum('royalty_obligation');

        $callCenterAgent = $OrderRows
            ->whereIn('order_placed_method', ['SoundHoundAgent', 'CallCenterAgent'])
            ->sum('royalty_obligation');

        $driveThruSales = $OrderRows
            ->where('order_placed_method', 'Drive Thru')
            ->sum('royalty_obligation');

        $websiteSales = $OrderRows
            ->where('order_placed_method', 'Website')
            ->sum('royalty_obligation');

        $mobileSales = $OrderRows
            ->where('order_placed_method', 'Mobile')
            ->sum('royalty_obligation');

        $doordashSales = $OrderRows
            ->where('order_placed_method', 'DoorDash')
            ->sum('royalty_obligation');

        $grubHubSales = $OrderRows
            ->where('order_placed_method', 'Grubhub')
            ->sum('royalty_obligation');

        $uberEatsSales = $OrderRows
            ->where('order_placed_method', 'UberEats')
            ->sum('royalty_obligation');

        $deliverySales = $doordashSales + $grubHubSales + $uberEatsSales + $mobileSales + $websiteSales;

        $digitalSales = $totalSales > 0
            ? ($deliverySales / $totalSales)
            : 0;

        $portalTransaction = $OrderRows
            ->where('portal_eligible', 'Yes')
            ->count();

        $putIntoPortal = $OrderRows
            ->where('portal_used', 'Yes')
            ->count();

        $portalPercentage = $portalTransaction > 0
            ? ($putIntoPortal / $portalTransaction)
            : 0;

        $portalOnTime = $OrderRows
            ->where('put_into_portal_before_promise_time', 'Yes')
            ->count();

        $inPortalPercentage = $putIntoPortal > 0
            ? ($portalOnTime / $putIntoPortal)
            : 0;
        // detail_orders (OrderRows) end

        $deliveryTips = $financeRows
            ->where('sub_account', 'Delivery-Tips')
            ->sum('amount');

        $prePaidDeliveryTips = $financeRows
            ->where('sub_account', 'Prepaid-Delivery-Tips')
            ->sum('amount');

        $inStoreTipAmount = $financeRows
            ->where('sub_account', 'InStoreTipAmount')
            ->sum('amount');

        $prePaidInStoreTipAmount = $financeRows
            ->where('sub_account', 'Prepaid-InStoreTipAmount')
            ->sum('amount');

        $totalTips = $deliveryTips + $prePaidDeliveryTips + $inStoreTipAmount + $prePaidInStoreTipAmount;

        $overShort = $financeRows
            ->where('sub_account', 'Over-Short')
            ->sum('amount');

        //final sum
        $cashSales = $financeRows
            ->where('sub_account', 'Total Cash Sales')
            ->sum('amount');

        $totalWasteCost = $wasteRows->sum(function ($row) {
            return $row['item_cost'] * $row['quantity'];
        });

        return [
            'franchise_store'             => $store,
            'business_date'               => $selectedDate,
            'total_sales'                 => $totalSales,
            'modified_order_qty'          => $modifiedOrderQty,
            'refunded_order_qty'          => $RefundedOrderQty,
            'customer_count'              => $customerCount,
            'phone_sales'                 => $phoneSales,
            'call_center_sales'           => $callCenterAgent,
            'drive_thru_sales'            => $driveThruSales,
            'website_sales'               => $websiteSales,
            'mobile_sales'                => $mobileSales,
            'doordash_sales'              => $doordashSales,
            'grubhub_sales'               => $grubHubSales,
            'ubereats_sales'              => $uberEatsSales,
            'delivery_sales'              => $deliverySales,
            'digital_sales_percent'       => round($digitalSales, 2),
            'portal_transactions'         => $portalTransaction,
            'put_into_portal'             => $putIntoPortal,
            'portal_used_percent'         => round($portalPercentage, 2),
            'put_in_portal_on_time'       => $portalOnTime,
            'in_portal_on_time_percent'   => round($inPortalPercentage, 2),
            'delivery_tips'               => $deliveryTips,
            'prepaid_delivery_tips'       => $prePaidDeliveryTips,
            'in_store_tip_amount'         => $inStoreTipAmount,
            'prepaid_instore_tip_amount'  => $prePaidInStoreTipAmount,
            'total_tips'                  => $totalTips,
            'over_short'                  => $overShort,
            'cash_sales'                  => $cashSales,
            'total_waste_cost'            => $totalWasteCost,
        ];
    }


    public function StoreHotNReadyTransaction(Collection $OrderRows, Collection $orderLine, string $store, string $selectedDate)
    {

        $hnrOrders = $OrderRows
            ->where('hnrOrder', 'Yes');

        $hnrOrderIds = $hnrOrders->pluck('order_id')->unique();

        $hnrOrderLines = $orderLine
            ->where('franchise_store', $store)
            ->whereIn('order_id', $hnrOrderIds);

        // Group by item_id and item_name
        $groupedItems = $hnrOrderLines->groupBy(function ($item) {
            return $item['item_id'] . '|' . $item['menu_item_name'];
        });
        $rows = [];
        foreach ($groupedItems as $itemKey => $lines) {
            [$itemId, $itemName] = explode('|', $itemKey);

            $transactions_with_CC = 0;
            // Count all item-level occurrences
            if ($itemId == '105001') {
                $transactions = $lines->pluck('order_id')->unique()->count();
            } else {
                $transactions = $lines->count();
                $transactions_with_CC = $lines->where('bundle_name', 'Crazy Combo')->count();
            }
            // Filter lines where the parent order has broken_promise == 'No'
            $promiseMetTransactions = $lines->filter(function ($line) use ($hnrOrders) {
                $order = $hnrOrders->firstWhere('order_id', $line['order_id']);
                return $order && strtolower($order['broken_promise']) === 'no';
            })->count();

            $promiseMetTransactionsCC = $lines
                ->where('bundle_name', 'Crazy Combo')
                ->filter(function ($line) use ($hnrOrders) {
                    $order = $hnrOrders->firstWhere('order_id', $line['order_id']);
                    return $order && strtolower($order['broken_promise']) === 'no';
                })->count();


            $percentage = $transactions > 0
                ? round(($promiseMetTransactions / $transactions) * 100, 2)
                : 0;

            $percentageCC = $transactions_with_CC > 0
                ? round(($promiseMetTransactions / $transactions_with_CC) * 100, 2)
                : 0;

            $rows[] = [
                'franchise_store' => $store,
                'business_date' => $selectedDate,
                'item_id' => $itemId,
                'item_name' => $itemName,
                'transactions' => $transactions,
                'promise_met_transactions' => $promiseMetTransactions,
                'promise_met_percentage' => $percentage,

                'transactions_with_CC' => $transactions_with_CC,
                'promise_met_transactions_cc' => $promiseMetTransactionsCC,
                'promise_met_percentage_cc' => $percentageCC,
            ];
        }
        return $rows;
    }
    public function groupOrdersByHour(Collection $orders): Collection
    {
        return $orders->groupBy(function ($order) {
            $raw = $order['promise_date'] ?? null;
            if (!$raw) return '00';
            try {
                return Carbon::parse($raw)->format('H');
            } catch (\Throwable $e) {
                return '00';
            }
        });
    }
    public function makeHourlySalesRow(Collection $hourOrders, string $store, string $selectedDate, int $hour): array
    {
        return [
            'franchise_store'   => $store,
            'business_date'     => $selectedDate,
            'hour'              => $hour,
            'total_sales'       => $hourOrders->sum('royalty_obligation'),
            'phone_sales'       => $hourOrders->where('order_placed_method', 'Phone')->sum('royalty_obligation'),
            'call_center_sales' => $hourOrders->whereIn('order_placed_method', ['SoundHoundAgent', 'CallCenterAgent'])->sum('royalty_obligation'),
            'drive_thru_sales'  => $hourOrders->where('order_placed_method', 'Drive Thru')->sum('royalty_obligation'),
            'website_sales'     => $hourOrders
                ->where('order_placed_method', 'Website')
                ->whereIn('order_fulfilled_method', ['Register', 'Drive-Thru'])
                ->sum('royalty_obligation'),
            'mobile_sales'      => $hourOrders
                ->where('order_placed_method', 'Mobile')
                ->whereIn('order_fulfilled_method', ['Register', 'Drive-Thru'])
                ->sum('royalty_obligation'),

            'website_sales_delivery'   => $hourOrders
                ->where('order_placed_method', 'Website')
                ->where('order_fulfilled_method', 'Delivery')
                ->sum('royalty_obligation'),
            'mobile_sales_delivery'    => $hourOrders
                ->where('order_placed_method', 'Mobile')
                ->where('order_fulfilled_method', 'Delivery')
                ->sum('royalty_obligation'),
            'doordash_sales'           => $hourOrders
                ->where('order_placed_method', 'DoorDash')
                ->where('order_fulfilled_method', 'Delivery')
                ->sum('royalty_obligation'),
            'ubereats_sales'           => $hourOrders
                ->where('order_placed_method', 'UberEats')
                ->where('order_fulfilled_method', 'Delivery')
                ->sum('royalty_obligation'),
            'grubhub_sales'            => $hourOrders
                ->where('order_placed_method', 'Grubhub')
                ->where('order_fulfilled_method', 'Delivery')
                ->sum('royalty_obligation'),
            'order_count'       => $hourOrders->count(),
        ];
    }

    public function makeHourHnrRow(Collection $hourOrders, string $store, string $selectedDate, int $hour): array
    {
        $transactions = $hourOrders->where('hnrOrder', 'Yes')->count();

        $promiseBroken = $hourOrders
            ->where('hnrOrder', 'Yes')
            ->where('broken_promise', 'Yes')
            ->count();

        $percentage = $transactions > 0
            ? round(($promiseBroken / $transactions) * 100, 2)
            : 0;

        return [
            'franchise_store'             => $store,
            'business_date'               => $selectedDate,
            'hour'                        => $hour,
            'transactions'                => $transactions,
            'promise_broken_transactions' => $promiseBroken,
            'promise_broken_percentage'   => $percentage,
        ];
    }
}
