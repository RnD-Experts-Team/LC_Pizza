<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddCompositeUniqueIndexesToUpsertedTables extends Migration
{
    public function up()
    {
        $this->safeDropUnique('cash_management', 'unique_cash_management');
        Schema::table('cash_management', function (Blueprint $table) {
            $table->unique(['franchise_store', 'business_date', 'create_datetime', 'till', 'check_type'], 'unique_cash_management');
        });

        $tables = [
            ['financial_views', ['franchise_store', 'business_date', 'sub_account', 'area'], 'unique_financial_view'],
            ['summary_items', ['franchise_store', 'business_date', 'menu_item_name', 'item_id'], 'unique_summary_items'],
            ['summary_sales', ['franchise_store', 'business_date'], 'unique_summary_sales'],
            ['summary_transactions', ['franchise_store', 'business_date', 'payment_method', 'sub_payment_method'], 'unique_summary_transactions'],
            ['detail_orders', ['franchise_store', 'business_date', 'order_id'], 'unique_detail_orders'],
            ['order_line', ['franchise_store', 'business_date', 'order_id', 'item_id'], 'unique_order_lines'],
            ['online_discount_program', ['franchise_store', 'date', 'order_id'], 'unique_discount_program'],
            ['delivery_order_summary', ['franchise_store', 'date'], 'unique_delivery_summary'],
            ['third_party_marketplace_orders', ['franchise_store', 'date'], 'unique_marketplace_orders'],
            ['bread_boost', ['franchise_store', 'date'], 'unique_bread_boost'],
            ['finance_data', ['franchise_store', 'business_date'], 'unique_finance_data'],
            ['final_summaries', ['franchise_store', 'business_date'], 'unique_final_summary'],
            ['hourly_sales', ['franchise_store', 'business_date', 'hour'], 'unique_hourly_sales'],
            ['waste', ['business_date', 'franchise_store', 'cv_item_id', 'waste_date_time'], 'unique_waste'],
            ['channel_data', ['store', 'date', 'category', 'sub_category', 'order_placed_method', 'order_fulfilled_method'], 'unique_channel_data'],
        ];

        foreach ($tables as [$tableName, $columns, $indexName]) {
            $this->safeDropUnique($tableName, $indexName);
            Schema::table($tableName, function (Blueprint $table) use ($columns, $indexName) {
                $table->unique($columns, $indexName);
            });
        }

        // // Special index for channel_data
        // Schema::table('channel_data', function (Blueprint $table) {
        //     $table->index(['store', 'date', 'category', 'sub_category'], 'index_channel_data');
        // });
    }

    public function down()
    {
        $indexMap = [
            ['cash_management', 'unique_cash_management'],
            ['financial_views', 'unique_financial_view'],
            ['summary_items', 'unique_summary_items'],
            ['summary_sales', 'unique_summary_sales'],
            ['summary_transactions', 'unique_summary_transactions'],
            ['detail_orders', 'unique_detail_orders'],
            ['order_line', 'unique_order_lines'],
            ['online_discount_program', 'unique_discount_program'],
            ['delivery_order_summary', 'unique_delivery_summary'],
            ['third_party_marketplace_orders', 'unique_marketplace_orders'],
            ['bread_boost', 'unique_bread_boost'],
            ['finance_data', 'unique_finance_data'],
            ['final_summaries', 'unique_final_summary'],
            ['hourly_sales', 'unique_hourly_sales'],
            ['waste', 'unique_waste'],
             ['channel_data', 'unique_channel_data'],

        ];

        foreach ($indexMap as [$table, $index]) {
            $this->safeDropUnique($table, $index);
        }

        // Schema::table('channel_data', function (Blueprint $table) {
        //     $table->dropIndex('index_channel_data');
        // });
    }

    private function safeDropUnique(string $table, string $index): void
    {
        try {
            Schema::table($table, function (Blueprint $table) use ($index) {
                $table->dropUnique($index);
            });
        } catch (\Throwable $e) {

        }
    }
}
