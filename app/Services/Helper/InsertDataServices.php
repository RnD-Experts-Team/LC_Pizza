<?php
namespace App\Services\Helper;

use Illuminate\Database\Eloquent\Model;

//just inserting functions using models in the db

class InsertDataServices{

    /**
     * @param  class-string<Model>  $model
     * @param  array<int,array>     $rows
     * @param  string[]             $uniqueBy
     * @param  string[]             $updateCols
     */
    // upsert rows function (uses the model name, rows[witch have the new data], uniqueBy array, updateCols)
    protected function upsertRows(string $table, array $rows, array $uniqueBy, array $updateCols): void
    {
        if (empty($rows)) {
            return;
        }
        $table::upsert($rows, $uniqueBy, $updateCols);
    }

    //insertHelper
    protected function insertHelper(array $data, string $tableName){
        $cfg  = $this->tables[$tableName];

        $this->upsertRows(
            $cfg['model'],
            $data ,
            $cfg['unique'],
            $cfg['updateCols']
        );
    }
    //loopInsertHelper
    protected function loopInsertHelper(array $data, string $tableName, int $chunkSize)
    {
        if (empty($data)) {
            return;
        }

        $cfg = $this->tables[$tableName];
        $model     = $cfg['model'];
        $uniqueBy  = $cfg['unique'];
        $updateCols= $cfg['updateCols'];

        foreach (array_chunk($data, $chunkSize) as $batch) {
            $this->upsertRows($model, $batch, $uniqueBy, $updateCols);
        }
    }
    // array with all the models
    protected array $tables = [
        'bread_boost' => [
            'model'      => \App\Models\BreadBoostModel::class,
            'unique'     => ['franchise_store','business_date'],
            'updateCols' => ['classic_order','classic_with_bread','other_pizza_order','other_pizza_with_bread'],
        ],
        'delivery_order_summary' => [
            'model'      => \App\Models\DeliveryOrderSummary::class,
            'unique'     => ['franchise_store','business_date'],
            'updateCols' => [
                'orders_count','product_cost','tax','occupational_tax',
                'delivery_charges','delivery_charges_taxes','service_charges',
                'service_charges_taxes','small_order_charge','small_order_charge_taxes',
                'delivery_late_charge','tip','tip_tax','total_taxes','order_total',
            ],
        ],
        'online_discount_program' => [
            'model'      => \App\Models\OnlineDiscountProgram::class,
            'unique'     => ['franchise_store','business_date','order_id'],
            'updateCols' => ['pay_type','original_subtotal','modified_subtotal','promo_code'],
        ],
        'third_party_marketplace_order' => [
            'model'      => \App\Models\ThirdPartyMarketplaceOrder::class,
            'unique'     => ['franchise_store','business_date'],
            'updateCols' => [
                'doordash_product_costs_Marketplace','doordash_tax_Marketplace','doordash_order_total_Marketplace',
                'ubereats_product_costs_Marketplace','ubereats_tax_Marketplace','uberEats_order_total_Marketplace',
                'grubhub_product_costs_Marketplace','grubhub_tax_Marketplace','grubhub_order_total_Marketplace',
            ],
        ],
        'finance_data' => [
            'model'      => \App\Models\FinanceData::class,
            'unique'     => ['franchise_store','business_date'],
            'updateCols' => [
                'Pizza_Carryout',
                'HNR_Carryout',
                'Bread_Carryout',
                'Wings_Carryout',
                'Beverages_Carryout',
                'Other_Foods_Carryout',
                'Side_Items_Carryout',
                'Pizza_Delivery',
                'HNR_Delivery',
                'Bread_Delivery',
                'Wings_Delivery',
                'Beverages_Delivery',
                'Other_Foods_Delivery',
                'Side_Items_Delivery',
                'Delivery_Charges',
                'TOTAL_Net_Sales',
                'Customer_Count',
                'Gift_Card_Non_Royalty',
                'Total_Non_Royalty_Sales',
                'Total_Non_Delivery_Tips',
                'Sales_Tax_Food_Beverage',
                'Sales_Tax_Delivery',
                'TOTAL_Sales_TaxQuantity',
                'DELIVERY_Quantity',
                'Delivery_Fee',
                'Delivery_Service_Fee',
                'Delivery_Small_Order_Fee',
                'Delivery_Late_to_Portal_Fee',
                'TOTAL_Native_App_Delivery_Fees',
                'Delivery_Tips',
                'DoorDash_Quantity',
                'DoorDash_Order_Total',
                'Grubhub_Quantity',
                'Grubhub_Order_Total',
                'Uber_Eats_Quantity',
                'Uber_Eats_Order_Total',
                'ONLINE_ORDERING_Mobile_Order_Quantity',
                'ONLINE_ORDERING_Online_Order_Quantity',
                'ONLINE_ORDERING_Pay_In_Store',
                'Agent_Pre_Paid',
                'Agent_Pay_InStore',
                'AI_Pre_Paid',
                'AI_Pay_InStore',
                'PrePaid_Cash_Orders',
                'PrePaid_Non_Cash_Orders',
                'PrePaid_Sales',
                'Prepaid_Delivery_Tips',
                'Prepaid_InStore_Tips',
                'Marketplace_from_Non_Cash_Payments_box',
                'AMEX',
                'Total_Non_Cash_Payments',
                'credit_card_Cash_Payments',
                'Debit_Cash_Payments',
                'epay_Cash_Payments',
                'Non_Cash_Payments',
                'Cash_Sales',
                'Cash_Drop_Total',
                'Over_Short',
                'Payouts',
            ],
        ],
        'final_summary' => [
            'model'      => \App\Models\FinalSummary::class,
            'unique'     => ['franchise_store','business_date'],
            'updateCols' => [
                'total_sales',
                'modified_order_qty',
                'refunded_order_qty',
                'customer_count',
                'phone_sales',
                'call_center_sales',
                'drive_thru_sales',
                'website_sales',
                'mobile_sales',
                'doordash_sales',
                'grubhub_sales',
                'ubereats_sales',
                'delivery_sales',
                'digital_sales_percent',
                'portal_transactions',
                'put_into_portal',
                'portal_used_percent',
                'put_in_portal_on_time',
                'in_portal_on_time_percent',
                'delivery_tips',
                'prepaid_delivery_tips',
                'in_store_tip_amount',
                'prepaid_instore_tip_amount',
                'total_tips',
                'over_short',
                'cash_sales',
                'total_waste_cost',
            ]
        ],
        'cash_management' => [
            'model'      => \App\Models\CashManagement::class,
            'unique'     => ['franchise_store', 'business_date', 'create_datetime', 'till', 'check_type',],
            'updateCols' => [
                'verified_datetime',
                'system_totals',
                'verified',
                'variance',
                'created_by',
                'verified_by',
            ]
        ],
        'waste' => [
            'model'      => \App\Models\Waste::class,
            'unique'     => ['business_date', 'franchise_store', 'cv_item_id', 'waste_date_time'],
            'updateCols' => ['menu_item_name', 'expired', 'produce_date_time', 'waste_reason', 'cv_order_id', 'waste_type', 'item_cost', 'quantity']
        ],
        'financial_view' => [
            'model'      => \App\Models\FinancialView::class,
            'unique'     => ['franchise_store', 'business_date', 'sub_account', 'area'],
            'updateCols' => ['amount']
        ],
        'summary_items' => [
            'model'      => \App\Models\SummaryItem::class,
            'unique'     => ['franchise_store', 'business_date', 'menu_item_name', 'item_id'],
            'updateCols' => ['menu_item_account',
                    'item_quantity',
                    'royalty_obligation',
                    'taxable_amount',
                    'non_taxable_amount',
                    'tax_exempt_amount',
                    'non_royalty_amount',
                    'tax_included_amount']
        ],
        'summary_sale' => [
            'model'      => \App\Models\SummarySale::class,
            'unique'     => ['franchise_store', 'business_date'],
            'updateCols' => [
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
        ],
        'summary_transactions' => [
            'model'      => \App\Models\SummaryTransaction::class,
            'unique'     => ['franchise_store', 'business_date', 'payment_method', 'sub_payment_method'],
            'updateCols' => [
                'total_amount',
                'saf_qty',
                'saf_total'
            ]
        ],
        'detail_orders' => [
            'model'      => \App\Models\DetailOrder::class,
            'unique'     => ['franchise_store', 'business_date', 'order_id'],
            'updateCols' => [
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
        ],
        'order_line' => [
            'model'      => \App\Models\OrderLine::class,
            'unique'     => ['franchise_store', 'business_date', 'order_id', 'item_id'],
            'updateCols' => [
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
        ],
        'channel_data' => [
            'model'      => \App\Models\ChannelData::class,
            'unique'     => ['store','business_date','category','sub_category','order_placed_method','order_fulfilled_method'],
            'updateCols' => ['amount']
        ],
        'hourly_sales' => [
            'model'      => \App\Models\HourlySales::class,
            'unique'     => ['franchise_store','business_date','hour'],
            'updateCols' => [
                'total_sales',
                'phone_sales',
                'call_center_sales',
                'drive_thru_sales',
                'website_sales',
                'mobile_sales',
                'order_count'
            ]
        ],
    ];

    //BreadBoostModel
    public function insertBreadBoostData(array $data): void{

        $this->insertHelper($data,'bread_boost');
    }
    //DeliveryOrderSummary
    public function insertDeliveryOrderSummaryData(array $data){

        $this->insertHelper($data,'delivery_order_summary');
    }
    //DeliveryOrderSummary
    public function insertOnlineDiscountProgramData(array $data){

        $this->insertHelper($data,'online_discount_program');
    }
    //ThirdPartyMarketplaceOrder
    public function insertThirdPartyMarketplaceOrder(array $data){

       $this->insertHelper($data,'third_party_marketplace_order');
    }
    //FinanceData
    public function insertFinanceData(array $data): void{

        $this->insertHelper($data,'finance_data');
    }
    //FinalSummary
    public function insertFinalSummary($data){

        $this->insertHelper($data,'final_summary');
    }
    //hourlySales
    public function insertHourlySales($data){

        $this->insertHelper($data,'hourly_sales');
     }

    //CashManagement
    public function insertCashManagement(array $data, int $chunkSize = 500){

        $this->loopInsertHelper($data,'cash_management',$chunkSize);
    }
    //Waste
    public function insertWaste(array $data, int $chunkSize = 500){

        $this->loopInsertHelper($data,'waste',$chunkSize);
    }
    //FinancialView
    public function insertFinancialView(array $data, int $chunkSize = 500){

        $this->loopInsertHelper($data,'financial_view',$chunkSize);
    }
    //SummaryItems
    public function insertSummaryItems(array $data, int $chunkSize = 500){
        $this->loopInsertHelper($data,'summary_items',$chunkSize);
    }
    //SummarySale
    public function insertSummarySale(array $data, int $chunkSize = 500){

        $this->loopInsertHelper($data,'summary_sale',$chunkSize);
    }
    //SummaryTransactions
    public function insertSummaryTransactions(array $data, int $chunkSize = 500){

        $this->loopInsertHelper($data,'summary_transactions',$chunkSize);
    }
    //DetailOrders
    public function insertDetailOrders(array $data, int $chunkSize = 500){

        $this->loopInsertHelper($data,'detail_orders',$chunkSize);
    }
    //OrderLine
    public function insertOrderLine(array $data, int $chunkSize = 500){

        $this->loopInsertHelper($data,'order_line',$chunkSize);
    }

    /**
     * @param  int    $chunkSize  How many rows per batch (default 1000)
     * @return void
     */
    public function insertChannelData(array $data, int $chunkSize = 1000): void{

        $this->loopInsertHelper($data,'channel_data',$chunkSize);
    }





}
