<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FinanceData extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'finance_data';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'franchise_store',
        'business_date',

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
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'Pizza_Carryout' => 'decimal:2',
        'HNR_Carryout' => 'decimal:2',
        'Bread_Carryout' => 'decimal:2',
        'Wings_Carryout' => 'decimal:2',
        'Beverages_Carryout' => 'decimal:2',
        'Other_Foods_Carryout' => 'decimal:2',
        'Side_Items_Carryout' => 'decimal:2',
        'Pizza_Delivery' => 'decimal:2',
        'HNR_Delivery' => 'decimal:2',
        'Bread_Delivery' => 'decimal:2',
        'Wings_Delivery' => 'decimal:2',
        'Beverages_Delivery' => 'decimal:2',
        'Other_Foods_Delivery' => 'decimal:2',
        'Side_Items_Delivery' => 'decimal:2',
        'Delivery_Charges' => 'decimal:2',
        'TOTAL_Net_Sales' => 'decimal:2',
        'Customer_Count' => 'integer',
        'Gift_Card_Non_Royalty' => 'decimal:2',
        'Total_Non_Royalty_Sales' => 'decimal:2',
        'Total_Non_Delivery_Tips' => 'decimal:2',
        'TOTAL_Sales_TaxQuantity' => 'decimal:2',
        'DELIVERY_Quantity' => 'integer',
        'Delivery_Fee' => 'decimal:2',
        'Delivery_Service_Fee' => 'decimal:2',
        'Delivery_Small_Order_Fee' => 'decimal:2',
        'Delivery_Late_to_Portal_Fee' => 'decimal:2',
        'TOTAL_Native_App_Delivery_Fees' => 'decimal:2',
        'Delivery_Tips' => 'decimal:2',
        'DoorDash_Quantity' => 'integer',
        'DoorDash_Order_Total' => 'decimal:2',
        'Grubhub_Quantity' => 'integer',
        'Grubhub_Order_Total' => 'decimal:2',
        'Uber_Eats_Quantity' => 'integer',
        'Uber_Eats_Order_Total' => 'decimal:2',
        'ONLINE_ORDERING_Mobile_Order_Quantity' => 'integer',
        'ONLINE_ORDERING_Online_Order_Quantity' => 'integer',
        'ONLINE_ORDERING_Pay_In_Store' => 'integer',
        'Agent_Pre_Paid' => 'decimal:2',
        'Agent_Pay_InStore' => 'decimal:2',
        'AI_Pre_Paid' => 'decimal:2',
        'AI_Pay_InStore' => 'decimal:2',
        'PrePaid_Cash_Orders' => 'decimal:2',
        'PrePaid_Non_Cash_Orders' => 'decimal:2',
        'PrePaid_Sales' => 'decimal:2',
        'Prepaid_Delivery_Tips' => 'decimal:2',
        'Prepaid_InStore_Tips' => 'decimal:2',
        'Marketplace_from_Non_Cash_Payments_box' => 'decimal:2',
        'AMEX' => 'decimal:2',
        'Total_Non_Cash_Payments' => 'decimal:2',
        'credit_card_Cash_Payments' => 'decimal:2',
        'Debit_Cash_Payments' => 'decimal:2',
        'epay_Cash_Payments' => 'decimal:2',
        'Non_Cash_Payments' => 'decimal:2',
        'Cash_Sales' => 'decimal:2',
        'Cash_Drop_Total' => 'decimal:2',
        'Over_Short' => 'decimal:2',
        'Payouts' => 'decimal:2',
    ];
}
