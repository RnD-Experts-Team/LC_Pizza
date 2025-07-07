<?php

namespace App\Services\Helper;

use Illuminate\Support\Facades\Log;

use App\Services\Helper\InsertDataServices;

use Carbon\Carbon;

class LogicsAndQueriesServices
{
    protected InsertDataServices $inserter;

    public function __construct(InsertDataServices $inserter)
    {
        $this->inserter = $inserter;

    }

    public function DataLoop(array $data, string $selectedDate){

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

        //channel data array
        $metrics = [
            'Sales' => [
                '-' => ['column' => 'royalty_obligation', 'type' => 'sum'],
            ],
            'Gross_Sales' => [
                '-' => ['column' => 'gross_sales', 'type' => 'sum'],
            ],
            'Order_Count' => [
                '-' => ['column' => 'order_id', 'type' => 'count'], // count distinct order IDs
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

        $summaryRows = [];
        
        Log::info('Started the store loop');
        foreach ($allFranchiseStores as $store) {

            $OrderRows = $detailOrder->where('franchise_store', $store);
            $financeRows = $financialView->where('franchise_store', $store);
            $wasteRows = $wasteData->where('franchise_store', $store);
            $storeOrderLines = $orderLine->where('franchise_store', $store);
            $groupedCombos = $OrderRows->groupBy(function ($row) {
                return $row['order_placed_method'] . '|' . $row['order_fulfilled_method'];
            });


            Log::info('Started the store loop');
             foreach ($groupedCombos as $comboKey => $methodOrders) {
                [$placedMethod, $fulfilledMethod] = explode('|', $comboKey);

                foreach ($metrics as $category => $subcats) {
                    foreach ($subcats as $subcat => $info) {
                        if ($info['type'] === 'sum') {
                            $amount = $methodOrders->sum(function ($row) use ($info) {
                                return floatval($row[$info['column']] ?? 0);
                            });
                        } elseif ($info['type'] === 'count') {
                            $amount = $methodOrders->unique('order_id')->count();
                        }
                        if ($amount != 0) {
                            $summaryRows[] = [
                                'store' => $store,
                                'business_date' => $selectedDate,
                                'category' => $category,
                                'sub_category' => $subcat,
                                'order_placed_method' => $placedMethod,
                                'order_fulfilled_method' => $fulfilledMethod,
                                'amount' => $amount,
                            ];
                        }
                    }
                }
            }
            //******* EndChannelData *******

            //******* Bread Boost Summary *********//
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
                ->whereNotIn('item_id', [
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
                    '204200'
                ])
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
            //******* End Of Bread Boost Summary *********//


            //******* Online Discount Program *********//

            Log::info('Online Discount Program');
            $discountOrders = $OrderRows
                ->where('employee', '')
                ->where('modification_reason', '<>', '');


            foreach ($discountOrders as $discountOrder) {
                $OnlineDiscountProgramArray =[
                    [
                        $store,
                        $selectedDate,
                        $discountOrder['order_id']
                    ],
                    [
                        $discountOrder['payment_methods'],
                        0,
                        $discountOrder['royalty_obligation'],
                        trim(explode(':', string: $discountOrder['modification_reason'])[1] ?? '')
                    ]
                ];
                $this->inserter->insertOnlineDiscountProgramData($OnlineDiscountProgramArray);
            }
            //******* End Of Online Discount Program *********//

            //******* Delivery Order Summary *********//
            Log::info('Delivery Order Summary');
            $Oreders_count = $OrderRows
                ->whereIn('order_placed_method', ['Mobile', 'Website'])
                ->where('order_fulfilled_method', 'Delivery')
                ->Count();

            $RO = $OrderRows
                ->whereIn('order_placed_method', ['Mobile', 'Website'])
                ->where('order_fulfilled_method', 'Delivery')
                ->Sum('royalty_obligation');

            $occupational_tax = $OrderRows
                ->whereIn('order_placed_method', ['Mobile', 'Website'])
                ->where('order_fulfilled_method', 'Delivery')
                ->Sum('occupational_tax');

            $delivery_charges = $OrderRows
                ->whereIn('order_placed_method', ['Mobile', 'Website'])
                ->where('order_fulfilled_method', 'Delivery')
                ->Sum('delivery_fee');

            $delivery_charges_Taxes = $OrderRows
                ->whereIn('order_placed_method', ['Mobile', 'Website'])
                ->where('order_fulfilled_method', 'Delivery')
                ->Sum('delivery_fee_tax');

            $delivery_Service_charges = $OrderRows
                ->whereIn('order_placed_method', ['Mobile', 'Website'])
                ->where('order_fulfilled_method', 'Delivery')
                ->Sum('delivery_service_fee');

            $delivery_Service_charges_Tax = $OrderRows
                ->whereIn('order_placed_method', ['Mobile', 'Website'])
                ->where('order_fulfilled_method', 'Delivery')
                ->Sum('delivery_service_fee_tax');

            $delivery_small_order_charge = $OrderRows
                ->whereIn('order_placed_method', ['Mobile', 'Website'])
                ->where('order_fulfilled_method', 'Delivery')
                ->Sum('delivery_small_order_fee');

            $delivery_small_order_charge_Tax = $OrderRows
                ->whereIn('order_placed_method', ['Mobile', 'Website'])
                ->where('order_fulfilled_method', 'Delivery')
                ->Sum('delivery_small_order_fee_tax');

            $Delivery_Tip_Summary = $OrderRows
                ->whereIn('order_placed_method', ['Mobile', 'Website'])
                ->where('order_fulfilled_method', 'Delivery')
                ->Sum('delivery_tip');

            $Delivery_Tip_Tax_Summary = $OrderRows
                ->whereIn('order_placed_method', ['Mobile', 'Website'])
                ->where('order_fulfilled_method', 'Delivery')
                ->Sum('delivery_tip_tax');

            $total_taxes = $OrderRows
                ->whereIn('order_placed_method', ['Mobile', 'Website'])
                ->where('order_fulfilled_method', 'Delivery')
                ->Sum('sales_tax');
            // sales tax       delivery_service_fee_tax 4.57    -  delivery_fee_tax 0.9     0.48
            $tax = $total_taxes - $delivery_Service_charges_Tax - $delivery_charges_Taxes - $delivery_small_order_charge_Tax;

            $product_cost = $RO - ($delivery_Service_charges + $delivery_charges + $delivery_small_order_charge);

            $order_total = $RO + $total_taxes + $Delivery_Tip_Summary;

            // $tax = $total_taxes - $delivery_Service_charges_Tax;

            //******* End Of Delivery Order Summary *********//

            //*******3rd Party Marketplace Orders*********//
            Log::info('3rd Party Marketplace Orders');
            $doordash_product_costs_Marketplace = $OrderRows
                ->where('order_placed_method', 'DoorDash')
                ->Sum('royalty_obligation');
            $ubereats_product_costs_Marketplace = $OrderRows
                ->where('order_placed_method', 'UberEats')
                ->Sum('royalty_obligation');
            $grubhub_product_costs_Marketplace = $OrderRows
                ->where('order_placed_method', 'Grubhub')
                ->Sum('royalty_obligation');

            $doordash_tax_Marketplace = $OrderRows
                ->where('order_placed_method', 'DoorDash')
                ->Sum('sales_tax');
            $ubereats_tax_Marketplace = $OrderRows
                ->where('order_placed_method', 'UberEats')
                ->Sum('sales_tax');
            $grubhub_tax_Marketplace = $OrderRows
                ->where('order_placed_method', 'Grubhub')
                ->Sum('sales_tax');

            $doordash_order_total_Marketplace = $OrderRows
                ->where('order_placed_method', 'DoorDash')
                ->Sum('gross_sales');
            $uberEats_order_total_Marketplace = $OrderRows
                ->where('order_placed_method', 'UberEats')
                ->Sum('gross_sales');
            $grubhub_order_total_Marketplace = $OrderRows
                ->where('order_placed_method', 'Grubhub')
                ->Sum('gross_sales');

            //******* End Of 3rd Party Marketplace Orders *********//

            //******* For finance data table *********//
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
                ->sum('royalty_obligation');

            $Grubhub_Quantity = $OrderRows
                ->where('order_placed_method', 'Grubhub')
                ->count();
            $Grubhub_Order_Total = $OrderRows
                ->where('order_placed_method', 'Grubhub')
                ->sum('royalty_obligation');

            $Uber_Eats_Quantity = $OrderRows
                ->where('order_placed_method', 'UberEats')
                ->count();
            $Uber_Eats_Order_Total = $OrderRows
                ->where('order_placed_method', 'UberEats')
                ->sum('royalty_obligation');

            $ONLINE_ORDERING_Mobile_Order_Quantity = $OrderRows
                ->where('order_placed_method', 'Mobile')
                ->where('royalty_obligation', '!=', 0)
                ->Count();
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



            $Over_Short = $financeRows
                ->where('sub_account', 'Over-Short-Operating')
                ->sum('amount');

                $Cash_Drop_Total = $Cash_Drop + $Over_Short;

            $Payouts = $financeRows
                ->where('sub_account', 'Payouts')
                ->sum('amount');

            //********  ********//

            // detail_orders (OrderRows)
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
                ->where('order_placed_method', 'SoundHoundAgent')
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

            $inPortalPercentage = $portalTransaction > 0
                ? ($portalOnTime / $portalTransaction)
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



            $BreadBoostArray =[
                [ $store,  $selectedDate],
                [
                    $classicOrdersCount,
                    $classicWithBreadCount,
                    $OtherPizzaOrderCount,
                    $OtherPizzaWithBreadCount,
                ]
            ];
            $this->inserter->insertBreadBoostData($BreadBoostArray);

            $DeliveryOrderSummaryArray=[
                [ $store,  $selectedDate],
                [
                     $Oreders_count,
                     $product_cost,
                     $tax,
                     $occupational_tax,
                     $delivery_charges,
                     $delivery_charges_Taxes,
                     $delivery_Service_charges,
                     $delivery_Service_charges_Tax,
                     $delivery_small_order_charge,
                     $delivery_small_order_charge_Tax,
                     $Delivery_Late_to_Portal_Fee,
                     $Delivery_Tip_Summary,
                     $Delivery_Tip_Tax_Summary,
                     $total_taxes,
                     $order_total
                ]
            ];
            $this->inserter->insertDeliveryOrderSummaryData($DeliveryOrderSummaryArray);


            $ThirdPartyMarketplaceOrderArray=[
                [ $store,  $selectedDate],
                [
                    $doordash_product_costs_Marketplace,
                    $doordash_tax_Marketplace,
                    $doordash_order_total_Marketplace,
                    $ubereats_product_costs_Marketplace,
                    $ubereats_tax_Marketplace,
                    $uberEats_order_total_Marketplace,
                    $grubhub_product_costs_Marketplace,
                    $grubhub_tax_Marketplace,
                    $grubhub_order_total_Marketplace,
                ]
            ];
            $this->inserter->insertThirdPartyMarketplaceOrder($ThirdPartyMarketplaceOrderArray);

            $FinanceDataArray=[
                [ $store,  $selectedDate],
                [
                    $Pizza_Carryout,
                    $HNR_Carryout,
                    $Bread_Carryout,
                    $Wings_Carryout,
                    $Beverages_Carryout,
                    $Other_Foods_Carryout,
                    $Side_Items_Carryout,
                    $Pizza_Delivery,
                    $HNR_Delivery,
                    $Bread_Delivery,
                    $Wings_Delivery,
                    $Beverages_Delivery,
                    $Other_Foods_Delivery,
                    $Side_Items_Delivery,
                    $Delivery_Charges,
                    $TOTAL_Net_Sales,
                    $customerCount,
                    $Gift_Card_Non_Royalty,
                    $Total_Non_Royalty_Sales,
                    $Total_Non_Delivery_Tips,
                    $Sales_Tax_Food_Beverage,
                    $Sales_Tax_Delivery,
                    $TOTAL_Sales_TaxQuantity,
                    $DELIVERY_Quantity,
                    $Delivery_Fee,
                    $Delivery_Service_Fee,
                    $Delivery_Small_Order_Fee,
                    $Delivery_Late_to_Portal_Fee,
                    $TOTAL_Native_App_Delivery_Fees,
                    $Delivery_Tips,
                    $DoorDash_Quantity,
                    $DoorDash_Order_Total,
                    $Grubhub_Quantity,
                    $Grubhub_Order_Total,
                    $Uber_Eats_Quantity,
                    $Uber_Eats_Order_Total,
                    $ONLINE_ORDERING_Mobile_Order_Quantity,
                    $ONLINE_ORDERING_Online_Order_Quantity,
                    $ONLINE_ORDERING_Pay_In_Store,
                    $Agent_Pre_Paid,
                    $Agent_Pay_In_Store,
                    null,
                    null,
                    $PrePaid_Cash_Orders,
                    $PrePaid_Non_Cash_Orders,
                    $PrePaid_Sales,
                    $Prepaid_Delivery_Tips,
                    $Prepaid_InStore_Tips,
                    $Marketplace_from_Non_Cash_Payments_box,
                    $AMEX,
                    $Total_Non_Cash_Payments,
                    $credit_card_Cash_Payments,
                    $Debit_Cash_Payments,
                    $epay_Cash_Payments,
                    $Non_Cash_Payments,
                    $Cash_Sales,
                    $Cash_Drop_Total,
                    $Over_Short,
                    $Payouts,
                ]
            ];
            $this->inserter->insertFinanceData($FinanceDataArray);


            $FinalSummaryArray=[
                [$store, $selectedDate],
                [
                    $totalSales,
                    $modifiedOrderQty,
                    $RefundedOrderQty,
                    $customerCount,
                    $phoneSales,
                    $callCenterAgent,
                    $driveThruSales,
                    $websiteSales,
                    $mobileSales,
                    $doordashSales,
                    $grubHubSales,
                    $uberEatsSales,
                    $deliverySales,
                    round($digitalSales, 2),
                    $portalTransaction,
                    $putIntoPortal,
                    round($portalPercentage, 2),
                    $portalOnTime,
                    round($inPortalPercentage, 2),
                    $deliveryTips,
                    $prePaidDeliveryTips,
                    $inStoreTipAmount,
                    $prePaidInStoreTipAmount,
                    $totalTips,
                    $overShort,
                    $cashSales,
                    $totalWasteCost,
                ]
            ];

            $this->inserter->insertFinalSummary($FinalSummaryArray);



            // Save hourly sales
            $ordersByHour = $OrderRows->groupBy(function ($order) {
                return Carbon::parse($order['promise_date'])->format('H');
            });

            foreach ($ordersByHour as $hour => $hourOrders) {

                $HourlySalesArray=[[
                        $store,
                        $selectedDate,
                        (int) $hour,
                    ],
                    [
                        $hourOrders->sum('royalty_obligation'),
                        $hourOrders->where('order_placed_method', 'Phone')->sum('royalty_obligation'),
                        $hourOrders->where('order_placed_method', 'SoundHoundAgent')->sum('royalty_obligation'),
                        $hourOrders->where('order_placed_method', 'Drive Thru')->sum('royalty_obligation'),
                        $hourOrders->where('order_placed_method', 'Website')->sum('royalty_obligation'),
                        $hourOrders->where('order_placed_method', 'Mobile')->sum('royalty_obligation'),
                        $hourOrders->count(),
                    ]];


                    $this->inserter->insertHourlySales($HourlySalesArray);


            }

        }
        if (!empty($summaryRows)) {
            $this->inserter->insertChannelData($summaryRows);
        }
        Log::info('Ended the data loop.');
    }

}
