<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('finance_data', function (Blueprint $table) {
            $table->id();
            $table->String('franchise_store')->nullable();
            $table->Date('business_date')->nullable();
            $table->decimal('Pizza_Carryout', 15, 2)->nullable();
            $table->decimal('HNR_Carryout', 15, 2)->nullable();
            $table->decimal('Bread_Carryout', 15, 2)->nullable();
            $table->decimal('Wings_Carryout', 15, 2)->nullable();
            $table->decimal('Beverages_Carryout', 15, 2)->nullable();
            $table->decimal('Other_Foods_Carryout', 15, 2)->nullable();
            $table->decimal('Side_Items_Carryout', 15, 2)->nullable();
            $table->decimal('Pizza_Delivery', 15, 2)->nullable();
            $table->decimal('HNR_Delivery', 15, 2)->nullable();
            $table->decimal('Bread_Delivery', 15, 2)->nullable();
            $table->decimal('Wings_Delivery', 15, 2)->nullable();
            $table->decimal('Beverages_Delivery', 15, 2)->nullable();
            $table->decimal('Other_Foods_Delivery', 15, 2)->nullable();
            $table->decimal('Side_Items_Delivery', 15, 2)->nullable();
            $table->decimal('Delivery_Charges', 15, 2)->nullable();
            $table->decimal('TOTAL_Net_Sales', 15, 2)->nullable();
            $table->integer('Customer_Count')->nullable();
            $table->decimal('Gift_Card_Non_Royalty', 15, 2)->nullable();
            $table->decimal('Total_Non_Royalty_Sales', 15, 2)->nullable();
            $table->decimal('Total_Non_Delivery_Tips', 15, 2)->nullable();
            $table->decimal('TOTAL_Sales_TaxQuantity', 15, 2)->nullable();
            $table->integer('DELIVERY_Quantity')->nullable();
            $table->decimal('Delivery_Fee', 15, 2)->nullable();
            $table->decimal('Delivery_Service_Fee', 15, 2)->nullable();
            $table->decimal('Delivery_Small_Order_Fee', 15, 2)->nullable();
            $table->decimal('Delivery_Late_to_Portal_Fee', 15, 2)->nullable();
            $table->decimal('TOTAL_Native_App_Delivery_Fees', 15, 2)->nullable();
            $table->decimal('Delivery_Tips', 15, 2)->nullable();
            $table->integer('DoorDash_Quantity')->nullable();
            $table->decimal('DoorDash_Order_Total', 15, 2)->nullable();
            $table->integer('Grubhub_Quantity')->nullable();
            $table->decimal('Grubhub_Order_Total', 15, 2)->nullable();
            $table->integer('Uber_Eats_Quantity')->nullable();
            $table->decimal('Uber_Eats_Order_Total', 15, 2)->nullable();
            $table->integer('ONLINE_ORDERING_Mobile_Order_Quantity')->nullable();
            $table->integer('ONLINE_ORDERING_Online_Order_Quantity')->nullable();
            $table->integer('ONLINE_ORDERING_Pay_In_Store')->nullable();
            $table->decimal('Agent_Pre_Paid', 15, 2)->nullable();
            $table->decimal('Agent_Pay_InStore', 15, 2)->nullable();
            $table->decimal('AI_Pre_Paid', 15, 2)->nullable();
            $table->decimal('AI_Pay_InStore', 15, 2)->nullable();
            $table->decimal('PrePaid_Cash_Orders', 15, 2)->nullable();
            $table->decimal('PrePaid_Non_Cash_Orders', 15, 2)->nullable();
            $table->decimal('PrePaid_Sales', 15, 2)->nullable();
            $table->decimal('Prepaid_Delivery_Tips', 15, 2)->nullable();
            $table->decimal('Prepaid_InStore_Tips', 15, 2)->nullable();
            $table->decimal('Marketplace_from_Non_Cash_Payments_box', 15, 2)->nullable();
            $table->decimal('AMEX', 15, 2)->nullable();
            $table->decimal('Total_Non_Cash_Payments', 15, 2)->nullable();
            $table->decimal('credit_card_Cash_Payments', 15, 2)->nullable();
            $table->decimal('Debit_Cash_Payments', 15, 2)->nullable();
            $table->decimal('epay_Cash_Payments', 15, 2)->nullable();
            $table->decimal('Non_Cash_Payments', 15, 2)->nullable();
            $table->decimal('Cash_Sales', 15, 2)->nullable();
            $table->decimal('Cash_Drop_Total', 15, 2)->nullable();
            $table->decimal('Over_Short', 15, 2)->nullable();
            $table->decimal('Payouts', 15, 2)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('finance_data');
    }
};
