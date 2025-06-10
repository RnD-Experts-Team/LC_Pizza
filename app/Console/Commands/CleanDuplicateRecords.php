<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanDuplicateRecords extends Command
{
    protected $signature = 'cleanup:deduplicate';
    protected $description = 'Remove duplicate rows from critical tables using safe chunked logic.';

    public function handle()
    {
        $tables = [
            'detail_orders' => ['franchise_store', 'business_date', 'order_id'],
            'cash_management' => ['franchise_store','business_date','create_datetime', 'till', 'check_type'],
            'financial_views' => ['franchise_store', 'business_date', 'sub_account', 'area'],
            'summary_items' => ['franchise_store', 'business_date', 'menu_item_name', 'item_id'],
            'summary_sales' => ['franchise_store', 'business_date'],
            'summary_transactions' => ['franchise_store', 'business_date', 'payment_method', 'sub_payment_method'],
            'order_line' => ['franchise_store', 'business_date', 'order_id', 'item_id'],
            'online_discount_program' => ['franchise_store', 'date', 'order_id'],
            'delivery_order_summary' => ['franchise_store', 'date'],
            'third_party_marketplace_orders' => ['franchise_store', 'date'],
            'bread_boost' => ['franchise_store', 'date'],
            'finance_data' => ['franchise_store', 'business_date'],
            'final_summaries' => ['franchise_store', 'business_date'],
            'hourly_sales' => ['franchise_store', 'business_date', 'hour'],
            'waste' => ['business_date', 'franchise_store', 'cv_item_id', 'waste_date_time'],
            'channel_data' => ['store', 'date', 'category', 'sub_category', 'order_placed_method', 'order_fulfilled_method'],
        ];

        foreach ($tables as $table => $columns) {
            $this->info("⏳ Cleaning: $table");

            $deleted = 0;
            $seen = [];

            DB::table($table)->orderBy('id')->chunk(500, function ($rows) use ($columns, $table, &$seen, &$deleted) {
                foreach ($rows as $row) {
                    $compositeKey = implode('|', array_map(fn($col) => $row->$col, $columns));

                    if (isset($seen[$compositeKey])) {
                        DB::table($table)->where('id', $row->id)->delete();
                        $deleted++;
                    } else {
                        $seen[$compositeKey] = true;
                    }
                }
            });

            $this->info("✔ Done: $table (Deleted $deleted rows)");
        }

        $this->info("🎯 All deduplication operations completed.");
    }
}
