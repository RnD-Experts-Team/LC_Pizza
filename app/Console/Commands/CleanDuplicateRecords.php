<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanDuplicateRecords extends Command
{
    protected $signature = 'cleanup:deduplicate
                            {--from= : Start date (YYYY-MM-DD)}
                            {--to= : End date (YYYY-MM-DD)}';

    protected $description = 'Remove duplicate rows from key tables using a date range, with a progress bar.';

    public function handle()
    {
        DB::disableQueryLog();

        $dateFrom = $this->option('from');
        $dateTo = $this->option('to');

        if (!$dateFrom || !$dateTo) {
            $this->error("❌ Please provide both --from and --to options in YYYY-MM-DD format.");
            return;
        }

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
            $this->info("⏳ Processing: $table");

            $batchSize = 500;
            $totalDeleted = 0;
            $dateField = in_array('business_date', $columns) ? 'business_date' : 'date';

            // Estimate total duplicates
            $totalDuplicates = DB::table($table)
                ->whereBetween($dateField, [$dateFrom, $dateTo])
                ->select(DB::raw("COUNT(*) - COUNT(DISTINCT " . implode(', ', $columns) . ") as duplicates"))
                ->value('duplicates');

            if ($totalDuplicates < 1) {
                $this->info("✔ No duplicates found.");
                continue;
            }

            $bar = $this->output->createProgressBar((int) ceil($totalDuplicates / $batchSize));
            $bar->start();

            do {
                $deleted = DB::delete("
                    DELETE FROM $table
                    WHERE id IN (
                        SELECT id FROM (
                            SELECT id
                            FROM $table
                            WHERE $dateField BETWEEN ? AND ?
                              AND id NOT IN (
                                  SELECT MAX(id)
                                  FROM $table
                                  WHERE $dateField BETWEEN ? AND ?
                                  GROUP BY " . implode(',', $columns) . "
                              )
                            LIMIT $batchSize
                        ) AS subquery
                    );
                ", [$dateFrom, $dateTo, $dateFrom, $dateTo]);

                $totalDeleted += $deleted;
                $bar->advance();
            } while ($deleted > 0);

            $bar->finish();
            $this->line("\n✔ Done: $table (Deleted $totalDeleted rows)");
        }

        $this->info("\n🎯 Completed deduplication from $dateFrom to $dateTo.");
    }
}
