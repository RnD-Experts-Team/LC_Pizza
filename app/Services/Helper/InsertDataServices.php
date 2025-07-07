<?php
namespace App\Services\Helper;

use App\Models\BreadBoostModel;//*
use App\Models\CashManagement;//*
use App\Models\ChannelData;//**
use App\Models\DeliveryOrderSummary;//*
use App\Models\DetailOrder;//*
use App\Models\FinalSummary;//*
use App\Models\FinanceData;//*
use App\Models\FinancialView;//*
use App\Models\HourlySales;//
use App\Models\OnlineDiscountProgram;//
use App\Models\OrderLine;//*
use App\Models\SummaryItem;//*
use App\Models\SummarySale;//*
use App\Models\SummaryTransaction;//*
use App\Models\ThirdPartyMarketplaceOrder;//*
use App\Models\Waste;//*

//just inserting functions using models in the db

class InsertDataServices{

    //BreadBoostModel
    public function insertBreadBoostData($BreadBoostArray){
        [$store, $date] = $BreadBoostArray[0];
        [$classic, $classicWithBread, $other, $otherWithBread] = $BreadBoostArray[1];

        $row = [
            'franchise_store'        => $store,
            'business_date'          => $date,
            'classic_order'          => $classic,
            'classic_with_bread'     => $classicWithBread,
            'other_pizza_order'      => $other,
            'other_pizza_with_bread' => $otherWithBread,
        ];

        BreadBoostModel::upsert(
            [ $row ],                              // ← array of one row
            ['franchise_store', 'business_date'],  // ← your unique keys
            [                                      // ← fields to overwrite
                'classic_order',
                'classic_with_bread',
                'other_pizza_order',
                'other_pizza_with_bread',
            ]
        );

    }


    //DeliveryOrderSummary
    public function insertDeliveryOrderSummaryData($summary){
        [$store, $date] = $summary[0];

        // 2) Pull out the twelve values you need
        [
            $ordersCount,
            $productCost,
            $tax,
            $occupationalTax,
            $deliveryCharges,
            $deliveryChargesTaxes,
            $serviceCharges,
            $serviceChargesTaxes,
            $smallOrderCharge,
            $smallOrderChargeTaxes,
            $deliveryLateCharge,
            $tip,
            $tipTax,
            $totalTaxes,
            $orderTotal
        ] = $summary[1];

        // 3) Call updateOrCreate exactly as you would by hand
        return DeliveryOrderSummary::updateOrCreate(
            ['franchise_store' => $store, 'business_date' => $date],
            [
                'orders_count'              => $ordersCount,
                'product_cost'              => $productCost,
                'tax'                       => $tax,
                'occupational_tax'          => $occupationalTax,
                'delivery_charges'          => $deliveryCharges,
                'delivery_charges_taxes'    => $deliveryChargesTaxes,
                'service_charges'           => $serviceCharges,
                'service_charges_taxes'     => $serviceChargesTaxes,
                'small_order_charge'        => $smallOrderCharge,
                'small_order_charge_taxes'  => $smallOrderChargeTaxes,
                'delivery_late_charge'      => $deliveryLateCharge,
                'tip'                       => $tip,
                'tip_tax'                   => $tipTax,
                'total_taxes'               => $totalTaxes,
                'order_total'               => $orderTotal,
            ]
        );
    }

    //DeliveryOrderSummary
    public function insertOnlineDiscountProgramData(array $data)
    {
        // unpack your two-dimensional input…
        [$franchise_store, $business_date, $order_id] = $data[0];
        [$pay_type, $original_subtotal, $modified_subtotal, $promo_code] = $data[1];

        return OnlineDiscountProgram::updateOrCreate(
            // ← associative “lookup” keys
            [
                'franchise_store' => $franchise_store,
                'business_date'   => $business_date,
                'order_id'        => $order_id,
            ],
            // ← associative “values to set”
            [
                'pay_type'            => $pay_type,
                'original_subtotal'   => $original_subtotal,
                'modified_subtotal'   => $modified_subtotal,
                'promo_code'          => $promo_code,
            ]
        );
    }
    //ThirdPartyMarketplaceOrder
    public function insertThirdPartyMarketplaceOrder($ThirdPartyMarketplaceOrderArray){
        [$store, $date] = $ThirdPartyMarketplaceOrderArray[0];

        // 2) Unpack the nine marketplace values
        [
            $doordashProd,
            $doordashTax,
            $doordashTotal,
            $ubereatsProd,
            $ubereatsTax,
            $ubereatsTotal,
            $grubhubProd,
            $grubhubTax,
            $grubhubTotal
        ] = $ThirdPartyMarketplaceOrderArray[1];

        // 3) Call updateOrCreate
        return ThirdPartyMarketplaceOrder::updateOrCreate(
            [
                'franchise_store'  => $store,
                'business_date'    => $date,
            ],
            [
                'doordash_product_costs_Marketplace'  => $doordashProd,
                'doordash_tax_Marketplace'            => $doordashTax,
                'doordash_order_total_Marketplace'   => $doordashTotal,

                'ubereats_product_costs_Marketplace'  => $ubereatsProd,
                'ubereats_tax_Marketplace'            => $ubereatsTax,
                'uberEats_order_total_Marketplace'    => $ubereatsTotal,

                'grubhub_product_costs_Marketplace'   => $grubhubProd,
                'grubhub_tax_Marketplace'             => $grubhubTax,
                'grubhub_order_total_Marketplace'     => $grubhubTotal,
            ]
        );
    }

    //FinanceData
    public function insertFinanceData(array $FinanceDataArray): void
    {
        // 1) Unpack the “lookup” values (store & date)…
        [$store, $date] = $FinanceDataArray[0];

        // 2) Unpack the rest of your metrics…
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
            $Customer_Count,
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
            $Agent_Pay_InStore,
            $AI_Pre_Paid,
            $AI_Pay_InStore,
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
        ] = $FinanceDataArray[1];

        // 3) Build a single associative row
        $row = [
            'franchise_store'                       => $store,
            'business_date'                         => $date,
            'Pizza_Carryout'                        => $Pizza_Carryout,
            'HNR_Carryout'                          => $HNR_Carryout,
            'Bread_Carryout'                        => $Bread_Carryout,
            'Wings_Carryout'                        => $Wings_Carryout,
            'Beverages_Carryout'                    => $Beverages_Carryout,
            'Other_Foods_Carryout'                  => $Other_Foods_Carryout,
            'Side_Items_Carryout'                   => $Side_Items_Carryout,
            'Pizza_Delivery'                        => $Pizza_Delivery,
            'HNR_Delivery'                          => $HNR_Delivery,
            'Bread_Delivery'                        => $Bread_Delivery,
            'Wings_Delivery'                        => $Wings_Delivery,
            'Beverages_Delivery'                    => $Beverages_Delivery,
            'Other_Foods_Delivery'                  => $Other_Foods_Delivery,
            'Side_Items_Delivery'                   => $Side_Items_Delivery,
            'Delivery_Charges'                      => $Delivery_Charges,
            'TOTAL_Net_Sales'                       => $TOTAL_Net_Sales,
            'Customer_Count'                        => $Customer_Count,
            'Gift_Card_Non_Royalty'                 => $Gift_Card_Non_Royalty,
            'Total_Non_Royalty_Sales'               => $Total_Non_Royalty_Sales,
            'Total_Non_Delivery_Tips'               => $Total_Non_Delivery_Tips,
            'Sales_Tax_Food_Beverage'               => $Sales_Tax_Food_Beverage,
            'Sales_Tax_Delivery'                    => $Sales_Tax_Delivery,
            'TOTAL_Sales_TaxQuantity'               => $TOTAL_Sales_TaxQuantity,
            'DELIVERY_Quantity'                     => $DELIVERY_Quantity,
            'Delivery_Fee'                          => $Delivery_Fee,
            'Delivery_Service_Fee'                  => $Delivery_Service_Fee,
            'Delivery_Small_Order_Fee'              => $Delivery_Small_Order_Fee,
            'Delivery_Late_to_Portal_Fee'           => $Delivery_Late_to_Portal_Fee,
            'TOTAL_Native_App_Delivery_Fees'        => $TOTAL_Native_App_Delivery_Fees,
            'Delivery_Tips'                         => $Delivery_Tips,
            'DoorDash_Quantity'                     => $DoorDash_Quantity,
            'DoorDash_Order_Total'                  => $DoorDash_Order_Total,
            'Grubhub_Quantity'                      => $Grubhub_Quantity,
            'Grubhub_Order_Total'                   => $Grubhub_Order_Total,
            'Uber_Eats_Quantity'                    => $Uber_Eats_Quantity,
            'Uber_Eats_Order_Total'                 => $Uber_Eats_Order_Total,
            'ONLINE_ORDERING_Mobile_Order_Quantity' => $ONLINE_ORDERING_Mobile_Order_Quantity,
            'ONLINE_ORDERING_Online_Order_Quantity' => $ONLINE_ORDERING_Online_Order_Quantity,
            'ONLINE_ORDERING_Pay_In_Store'          => $ONLINE_ORDERING_Pay_In_Store,
            'Agent_Pre_Paid'                        => $Agent_Pre_Paid,
            'Agent_Pay_InStore'                     => $Agent_Pay_InStore,
            'AI_Pre_Paid'                           => $AI_Pre_Paid,
            'AI_Pay_InStore'                        => $AI_Pay_InStore,
            'PrePaid_Cash_Orders'                   => $PrePaid_Cash_Orders,
            'PrePaid_Non_Cash_Orders'               => $PrePaid_Non_Cash_Orders,
            'PrePaid_Sales'                         => $PrePaid_Sales,
            'Prepaid_Delivery_Tips'                 => $Prepaid_Delivery_Tips,
            'Prepaid_InStore_Tips'                  => $Prepaid_InStore_Tips,
            'Marketplace_from_Non_Cash_Payments_box' => $Marketplace_from_Non_Cash_Payments_box,
            'AMEX'                                  => $AMEX,
            'Total_Non_Cash_Payments'               => $Total_Non_Cash_Payments,
            'credit_card_Cash_Payments'             => $credit_card_Cash_Payments,
            'Debit_Cash_Payments'                   => $Debit_Cash_Payments,
            'epay_Cash_Payments'                    => $epay_Cash_Payments,
            'Non_Cash_Payments'                     => $Non_Cash_Payments,
            'Cash_Sales'                            => $Cash_Sales,
            'Cash_Drop_Total'                       => $Cash_Drop_Total,
            'Over_Short'                            => $Over_Short,
            'Payouts'                               => $Payouts,
        ];

        // 4) Define keys to match on, and which to update
        $uniqueBy  = ['franchise_store', 'business_date'];
        $updateCols = array_diff(array_keys($row), $uniqueBy);

        // 5) Call upsert with an array of rows
        FinanceData::upsert(
            [ $row ],
            $uniqueBy,
            $updateCols
        );
    }


    //FinalSummary
    public function insertFinalSummary($FinalSummary){
        [$store, $selectedDate]=$FinalSummary[0];
        [
            $total_sales,
            $modified_order_qty,
            $refunded_order_qty,
            $customer_count,
            $phone_sales,
            $call_center_sales,
            $drive_thru_sales,
            $website_sales,
            $mobile_sales,
            $doordash_sales,
            $grubhub_sales,
            $ubereats_sales,
            $delivery_sales,
            $digital_sales_percent,
            $portal_transactions,
            $put_into_portal,
            $portal_used_percent,
            $put_in_portal_on_time,
            $in_portal_on_time_percent,
            $delivery_tips,
            $prepaid_delivery_tips,
            $in_store_tip_amount,
            $prepaid_instore_tip_amount,
            $total_tips,
            $over_short,
            $cash_sales,
            $total_waste_cost,
        ]=$FinalSummary[1];

        $row = [
        'franchise_store'=>$store,
        'business_date'=>$selectedDate,
        'total_sales'=>$total_sales,
        'modified_order_qty'=>$modified_order_qty,
        'refunded_order_qty'=>$refunded_order_qty,
        'customer_count'=>$customer_count,

        'phone_sales'=>$phone_sales,
        'call_center_sales'=>$call_center_sales,
        'drive_thru_sales'=>$drive_thru_sales,
        'website_sales'=>$website_sales,
        'mobile_sales'=>$mobile_sales,

        'doordash_sales'=>$doordash_sales,
        'grubhub_sales'=>$grubhub_sales,
        'ubereats_sales'=>$ubereats_sales,
        'delivery_sales'=>$delivery_sales,
        'digital_sales_percent'=>$digital_sales_percent,

        'portal_transactions'=>$portal_transactions,
        'put_into_portal'=>$put_into_portal,
        'portal_used_percent'=>$portal_used_percent,
        'put_in_portal_on_time'=>$put_in_portal_on_time,
        'in_portal_on_time_percent'=>$in_portal_on_time_percent,

        'delivery_tips'=>$delivery_tips,
        'prepaid_delivery_tips'=>$prepaid_delivery_tips,
        'in_store_tip_amount'=>$in_store_tip_amount,
        'prepaid_instore_tip_amount'=>$prepaid_instore_tip_amount,
        'total_tips'=>$total_tips,

        'over_short'=>$over_short,
        'cash_sales'=>$cash_sales,


        'total_waste_cost'=>$total_waste_cost,
    ];

    $uniqueBy    = ['franchise_store', 'business_date'];
    $updateCols  = array_keys($row);
    // but remove your unique columns from updateCols:
    $updateCols  = array_diff($updateCols, $uniqueBy);

        FinalSummary::upsert(
        [ $row ],      // ← note the *outer* array here
        $uniqueBy,     // ← a simple list of column names
        $updateCols
        );
    }

    //CashManagement
    public function insertCashManagement(array $CashManagement, int $chunkSize = 500){

        if (empty($CashManagement)) {
            return;
        }

        $uniqueBy   = [
            'franchise_store',
            'business_date',
            'create_datetime',
            'till',
            'check_type',
        ];
        $updateCols = [
            'verified_datetime',
            'system_totals',
            'verified',
            'variance',
            'created_by',
            'verified_by',
        ];

        foreach (array_chunk($CashManagement, $chunkSize) as $batch) {
            CashManagement::upsert($batch, $uniqueBy, $updateCols);
        }
        // FinalSummary::upsert( ['franchise_store' => $CashManagement[0], 'business_date' => $CashManagement[1]],
        // [
        // 'create_datetime' => $CashManagement[2],
        // 'verified_datetime' => $CashManagement[3],
        // 'till' => $CashManagement[4],
        // 'check_type' => $CashManagement[5],
        // 'system_totals' => $CashManagement[6],
        // 'verified' => $CashManagement[7],
        // 'variance' => $CashManagement[8],
        // 'created_by' => $CashManagement[9],
        // 'verified_by' => $CashManagement[10],
        // ]
        // );
    }

    //Waste
    public function insertWaste(array $Waste, int $chunkSize = 500){

        if (empty($Waste)) {
            return;
        }

        $uniqueBy   = ['business_date', 'franchise_store', 'cv_item_id', 'waste_date_time'];
        $updateCols = ['menu_item_name', 'expired', 'produce_date_time', 'waste_reason', 'cv_order_id', 'waste_type', 'item_cost', 'quantity'];

        foreach (array_chunk($Waste, $chunkSize) as $batch) {
            Waste::upsert($batch, $uniqueBy, $updateCols);
        }

        // Waste::upsert( ['franchise_store' => $Waste[0], 'business_date' => $Waste[1]],
        // [
        //     'cv_item_id' => $Waste[2],
        //     'menu_item_name' => $Waste[3],
        //     'expired' => $Waste[4],
        //     'waste_date_time' => $Waste[5],
        //     'produce_date_time'=> $Waste[6],
        //     'waste_reason'=> $Waste[7],
        //     'cv_order_id'=> $Waste[8],
        //     'waste_type'=> $Waste[9],
        //     'item_cost'=> $Waste[10],
        //     'quantity'=> $Waste[11],
        // ]
        // );
    }

    //FinancialView
    public function insertFinancialView($FinancialView){

        foreach (array_chunk($FinancialView, 500) as $batch) {
            FinancialView::upsert(
                $batch,
                ['franchise_store', 'business_date', 'sub_account', 'area'],
                ['amount']
            );
        }
    }

    //SummaryItems
    public function insertSummaryItems($SummaryItems){
        // SummaryItem::upsert( ['franchise_store' => $SummaryItems[0], 'business_date' => $SummaryItems[1]],
        // [
        //     'menu_item_name' => $SummaryItems[2],
        //     'menu_item_account' => $SummaryItems[3],
        //     'item_id'=>$SummaryItems[4],
        //     'item_quantity'=>$SummaryItems[5],
        //     'royalty_obligation'=>$SummaryItems[6],
        //     'taxable_amount'=>$SummaryItems[7],
        //     'non_taxable_amount'=>$SummaryItems[8],
        //     'tax_exempt_amount'=>$SummaryItems[9],
        //     'non_royalty_amount'=>$SummaryItems[10],
        //     'tax_included_amount'=>$SummaryItems[11],
        // ]
        // );
        foreach (array_chunk($SummaryItems, 500) as $batch) {
            SummaryItem::upsert(
                $batch,
                ['franchise_store', 'business_date', 'menu_item_name', 'item_id'],
                [
                    'menu_item_account',
                    'item_quantity',
                    'royalty_obligation',
                    'taxable_amount',
                    'non_taxable_amount',
                    'tax_exempt_amount',
                    'non_royalty_amount',
                    'tax_included_amount'
                ]
            );
        }

    }

    //SummarySale
    public function insertSummarySale($SummarySale){

        foreach (array_chunk($SummarySale, 500) as $batch) {
            SummarySale::upsert(
                $batch,
                ['franchise_store', 'business_date'],
                [
                    'royalty_obligation',
                    'customer_count',
                    'taxable_amount',
                    'non_taxable_amount',
                    'tax_exempt_amount',
                    'non_royalty_amount',
                    'refund_amount',
                    'sales_tax',
                    'gross_sales',
                    'occupational_tax',
                    'delivery_tip',
                    'delivery_fee',
                    'delivery_service_fee',
                    'delivery_small_order_fee',
                    'modified_order_amount',
                    'store_tip_amount',
                    'prepaid_cash_orders',
                    'prepaid_non_cash_orders',
                    'prepaid_sales',
                    'prepaid_delivery_tip',
                    'prepaid_in_store_tip_amount',
                    'over_short',
                    'previous_day_refunds',
                    'saf',
                    'manager_notes'
                ]
            );
        }


        // SummarySale::upsert( ['franchise_store' => $SummarySale[0], 'business_date' => $SummarySale[1]],
        // [
        //     'royalty_obligation' => $SummarySale[2],
        //     'customer_count' => $SummarySale[3],
        //     'taxable_amount' => $SummarySale[4],
        //     'non_taxable_amount' => $SummarySale[5],
        //     'tax_exempt_amount' => $SummarySale[6],
        //     'non_royalty_amount' => $SummarySale[7],
        //     'refund_amount' => $SummarySale[8],
        //     'sales_tax' => $SummarySale[9],
        //     'gross_sales' => $SummarySale[10],
        //     'occupational_tax' => $SummarySale[11],
        //     'delivery_tip' => $SummarySale[12],
        //     'delivery_fee' => $SummarySale[13],
        //     'delivery_service_fee' => $SummarySale[14],
        //     'delivery_small_order_fee' => $SummarySale[15],
        //     'modified_order_amount' => $SummarySale[16],
        //     'store_tip_amount' => $SummarySale[17],
        //     'prepaid_cash_orders' => $SummarySale[18],
        //     'prepaid_non_cash_orders' => $SummarySale[19],
        //     'prepaid_sales' => $SummarySale[20],
        //     'prepaid_delivery_tip' => $SummarySale[21],
        //     'prepaid_in_store_tip_amount' => $SummarySale[22],
        //     'over_short' => $SummarySale[23],
        //     'previous_day_refunds' => $SummarySale[24],
        //     'saf' => $SummarySale[25],
        //     'manager_notes' => $SummarySale[26] ,
        // ]
        // );
    }

    //SummaryTransactions
    public function insertSummaryTransactions($SummaryTransaction){
        // SummaryTransaction::upsert( ['franchise_store' => $SummaryTransaction[0], 'business_date' => $SummaryTransaction[1]],
        // [
        //     'payment_method' => $SummaryTransaction[2],
        //     'sub_payment_method'=> $SummaryTransaction[3],
        //     'total_amount'=> $SummaryTransaction[4],
        //     'saf_qty'=> $SummaryTransaction[5],
        //     'saf_total'=> $SummaryTransaction[6],
        // ]
        // );
        foreach (array_chunk($SummaryTransaction, 500) as $batch) {
            SummaryTransaction::upsert(
                $batch,
                ['franchise_store', 'business_date', 'payment_method', 'sub_payment_method'],
                [
                    'total_amount',
                    'saf_qty',
                    'saf_total'
                ]
            );
        }

    }

    //DetailOrders
    public function insertDetailOrders($DetailOrder){
        // DetailOrder::upsert( values: ['franchise_store' => $DetailOrder[0], 'business_date' => $DetailOrder[1]],
        // uniqueBy: [
        //     'date_time_placed' => $DetailOrder[2],
        //     'date_time_fulfilled' => $DetailOrder[3],
        //     'royalty_obligation' => $DetailOrder[4],
        //     'quantity' => $DetailOrder[5],
        //     'customer_count' => $DetailOrder[6],
        //     'order_id' => $DetailOrder[7],
        //     'taxable_amount' => $DetailOrder[8],
        //     'non_taxable_amount' => $DetailOrder[9] ,
        //     'tax_exempt_amount' => $DetailOrder[10],
        //     'non_royalty_amount' => $DetailOrder[11],
        //     'sales_tax' => $DetailOrder[12],
        //     'employee' => $DetailOrder[13],
        //     'gross_sales' => $DetailOrder[14],
        //     'occupational_tax' => $DetailOrder[15],
        //     'override_approval_employee' => $DetailOrder[16],
        //     'order_placed_method' => $DetailOrder[17],
        //     'delivery_tip' => $DetailOrder[18],
        //     'delivery_tip_tax' => $DetailOrder[19],
        //     'order_fulfilled_method' => $DetailOrder[20],
        //     'delivery_fee' => $DetailOrder[21],
        //     'modified_order_amount' => $DetailOrder[22],
        //     'delivery_fee_tax' => $DetailOrder[23],
        //     'modification_reason' => $DetailOrder[24],
        //     'payment_methods' => $DetailOrder[25],
        //     'delivery_service_fee' => $DetailOrder[26],
        //     'delivery_service_fee_tax' => $DetailOrder[27],
        //     'refunded' => $DetailOrder[28],
        //     'delivery_small_order_fee' => $DetailOrder[29],
        //     'delivery_small_order_fee_tax' => $DetailOrder[30],
        //     'transaction_type' => $DetailOrder[31],
        //     'store_tip_amount' => $DetailOrder[32],
        //     'promise_date' => $DetailOrder[33],
        //     'tax_exemption_id' => $DetailOrder[34],
        //     'tax_exemption_entity_name' => $DetailOrder[35],
        //     'user_id' => $DetailOrder[36],
        //     'hnrOrder' => $DetailOrder[37],
        //     'broken_promise' => $DetailOrder[38],
        //     'portal_eligible' => $DetailOrder[39],
        //     'portal_used' => $DetailOrder[40],
        //     'put_into_portal_before_promise_time' => $DetailOrder[41],
        //     'portal_compartments_used' => $DetailOrder[42],
        //     'time_loaded_into_portal' => $DetailOrder[43],
        // ]
        // );

        foreach (array_chunk($DetailOrder, 500) as $batch) {
            DetailOrder::upsert(
                $batch,
                ['franchise_store', 'business_date', 'order_id'],
                [
                    'date_time_placed',
                    'date_time_fulfilled',
                    'royalty_obligation',
                    'quantity',
                    'customer_count',
                    'taxable_amount',
                    'non_taxable_amount',
                    'tax_exempt_amount',
                    'non_royalty_amount',
                    'sales_tax',
                    'employee',
                    'gross_sales',
                    'occupational_tax',
                    'override_approval_employee',
                    'order_placed_method',
                    'delivery_tip',
                    'delivery_tip_tax',
                    'order_fulfilled_method',
                    'delivery_fee',
                    'modified_order_amount',
                    'delivery_fee_tax',
                    'modification_reason',
                    'payment_methods',
                    'delivery_service_fee',
                    'delivery_service_fee_tax',
                    'refunded',
                    'delivery_small_order_fee',
                    'delivery_small_order_fee_tax',
                    'transaction_type',
                    'store_tip_amount',
                    'promise_date',
                    'tax_exemption_id',
                    'tax_exemption_entity_name',
                    'user_id',
                    'hnrOrder',
                    'broken_promise',
                    'portal_eligible',
                    'portal_used',
                    'put_into_portal_before_promise_time',
                    'portal_compartments_used',
                    'time_loaded_into_portal'
                ]
            );
        }
    }

    //OrderLine
    public function insertOrderLine($OrderLine){
        foreach (array_chunk($OrderLine, 500) as $batch) {
            OrderLine::upsert(
                $batch,
                ['franchise_store', 'business_date', 'order_id', 'item_id'],
                [
                    'date_time_placed',
                    'date_time_fulfilled',
                    'net_amount',
                    'quantity',
                    'royalty_item',
                    'taxable_item',
                    'menu_item_name',
                    'menu_item_account',
                    'bundle_name',
                    'employee',
                    'override_approval_employee',
                    'order_placed_method',
                    'order_fulfilled_method',
                    'modified_order_amount',
                    'modification_reason',
                    'payment_methods',
                    'refunded',
                    'tax_included_amount'
                ]
            );
        }

    }

    /**
     * Insert or update channel data in batches.
     *
     * @param  array  $rows       List of rows; each row is an associative array:
     *                            [
     *                              'store'                  => ...,
     *                              'business_date'          => ...,
     *                              'category'               => ...,
     *                              'sub_category'           => ...,
     *                              'order_placed_method'    => ...,
     *                              'order_fulfilled_method' => ...,
     *                              'amount'                 => ...,
     *                            ]
     * @param  int    $chunkSize  How many rows per batch (default 1000)
     * @return void
     */
    public function insertChannelData(array $rows, int $chunkSize = 1000): void
    {
        if (empty($rows)) {
            return;
        }

        $uniqueBy   = [
            'store',
            'business_date',
            'category',
            'sub_category',
            'order_placed_method',
            'order_fulfilled_method',
        ];
        $updateCols = ['amount'];

        foreach (array_chunk($rows, $chunkSize) as $batch) {
            ChannelData::upsert($batch, $uniqueBy, $updateCols);
        }
    }

    //hourlySales
    public function insertHourlySales($HourlySales){
        [$franchise_store, $business_date, $hour] = $HourlySales[0];
        [
            $total_sales,
            $phone_sales,
            $call_center_sales,
            $drive_thru_sales,
            $website_sales,
            $mobile_sales,
            $order_count,
        ] = $HourlySales[1];

        HourlySales::upsert(
        [
                    'franchise_store' => $franchise_store,
                    'business_date' => $business_date,
                    'hour' => $hour,
                ],
        [
                    'total_sales' => $total_sales,
                    'phone_sales' => $phone_sales,
                    'call_center_sales' => $call_center_sales,
                    'drive_thru_sales' => $drive_thru_sales,
                    'website_sales' => $website_sales,
                    'mobile_sales' => $mobile_sales,
                    'order_count' => $order_count,
                ]
        );
    }
}
