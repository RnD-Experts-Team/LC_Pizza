<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('order_line')) {
            return;
        }

        $driver = DB::getDriverName();
        if ($driver !== 'mysql') {
            throw new \RuntimeException("This migration is MySQL-only per instructions.");
        }

        $tz = config('app.timezone') ?: 'Europe/Sofia';

        // Rolling 30-day window: today and the preceding 29 days (inclusive)
        $end   = Carbon::now($tz)->toDateString();              // e.g., 2025-10-15
        $start = Carbon::now($tz)->subDays(29)->toDateString(); // e.g., 2025-09-16

        /**
         * 1) Helper covering index to accelerate the join-based dedupe
         */
        try {
            Schema::table('order_line', function (Blueprint $table) {
                $table->index(
                    ['franchise_store','business_date','order_id','item_id','created_at','id'],
                    'idx_order_line_cleanup_helper'
                );
            });
        } catch (\Throwable $e) {
            // ignore if exists or insufficient privileges
        }

        /**
         * 2) Best-effort: relax session timeouts (may be no-op on shared hosts)
         */
        try {
            DB::statement("SET SESSION net_read_timeout=600");
            DB::statement("SET SESSION net_write_timeout=600");
            DB::statement("SET SESSION wait_timeout=600");
            DB::statement("SET SESSION innodb_lock_wait_timeout=120");
            DB::statement("SET SESSION sql_big_selects=1");
        } catch (\Throwable $e) {}

        /**
         * 3) Deduplicate ONLY the last 30 days, day-by-day, in chunks.
         *    Rule: keep newest created_at (NULLs treated as oldest), tie-break with highest id.
         *    Implementation note: we may not use ORDER BY/LIMIT in multi-table DELETE,
         *    so we select ordered IDs in a subquery and delete by id IN (...).
         */
        $batchLimit = 1000; // tune 500..2000 based on load/timeouts
        $sleepMs    = 150;  // tiny pause to appease shared hosting watchdogs

        // Iterate each calendar day in the rolling window
        for ($d = Carbon::parse($start, $tz); $d->lte(Carbon::parse($end, $tz)); $d->addDay()) {
            $dateStr = $d->toDateString();

            do {
                // Select candidate IDs to delete, ordered so we delete the "older" duplicates first
                $ids = DB::select(<<<SQL
SELECT del_ids.id
FROM (
    SELECT
        ol.id
    FROM `order_line` AS ol FORCE INDEX (`idx_order_line_cleanup_helper`)
    INNER JOIN `order_line` AS k  FORCE INDEX (`idx_order_line_cleanup_helper`)
        ON  k.`franchise_store` = ol.`franchise_store`
        AND k.`business_date`   = ol.`business_date`
        AND k.`order_id`        = ol.`order_id`
        AND k.`item_id`         = ol.`item_id`
        AND (
              (ol.`created_at` IS NULL AND k.`created_at` IS NOT NULL)
           OR (ol.`created_at` IS NOT NULL AND k.`created_at` IS NOT NULL AND k.`created_at` > ol.`created_at`)
           OR (
                ((ol.`created_at` = k.`created_at`) OR (ol.`created_at` IS NULL AND k.`created_at` IS NULL))
                AND k.`id` > ol.`id`
              )
        )
    WHERE ol.`business_date` = ?
    ORDER BY (ol.`created_at` IS NOT NULL), ol.`created_at`, ol.`id`
    LIMIT {$batchLimit}
) AS del_ids
SQL, [$dateStr]);

                $idList = array_map(fn($o) => $o->id, $ids);
                $deleted = 0;

                if (!empty($idList)) {
                    // Delete by primary key chunk
                    $deleted = DB::table('order_line')
                        ->whereIn('id', $idList)
                        ->delete();

                    if ($deleted > 0 && $sleepMs > 0) {
                        usleep($sleepMs * 1000);
                    }
                }
            } while (!empty($idList));
        }

        /**
         * 4) Enforce **last-30-days** uniqueness via generated column + UNIQUE index.
         *    Generated column equals business_date inside [start, end], else NULL.
         *    UNIQUE(franchise_store, generated, order_id, item_id) => only rows in the window are constrained.
         *    Index name per request: unique_order_lines
         */
        $colName = 'business_date_last_30_tmp';
        try {
            DB::statement(sprintf(
                "ALTER TABLE `order_line`
                 ADD COLUMN `%s` DATE
                 GENERATED ALWAYS AS (
                   CASE
                     WHEN `business_date` >= DATE '%s' AND `business_date` <= DATE '%s'
                     THEN `business_date`
                     ELSE NULL
                   END
                 ) STORED",
                $colName,
                $start,
                $end
            ));
        } catch (\Throwable $e) {
            // ignore if already added
        }

        try {
            Schema::table('order_line', function (Blueprint $table) use ($colName) {
                $table->unique(
                    ['franchise_store', $colName, 'order_id', 'item_id'],
                    'unique_order_lines'
                );
            });
        } catch (\Throwable $e) {
            // ignore if it already exists
        }

        /**
         * 5) Optional: drop the helper cleanup index (comment this out if you run cleaners later)
         */
        try {
            Schema::table('order_line', function (Blueprint $table) {
                $table->dropIndex('idx_order_line_cleanup_helper');
            });
        } catch (\Throwable $e) {}
    }

    public function down(): void
    {
        if (!Schema::hasTable('order_line')) {
            return;
        }

        // Drop unique + generated column
        try {
            Schema::table('order_line', function (Blueprint $table) {
                $table->dropUnique('unique_order_lines');
            });
        } catch (\Throwable $e) {}

        try {
            DB::statement("ALTER TABLE `order_line` DROP COLUMN `business_date_last_30_tmp`");
        } catch (\Throwable $e) {}

        // Drop helper index if it still exists
        try {
            Schema::table('order_line', function (Blueprint $table) {
                $table->dropIndex('idx_order_line_cleanup_helper');
            });
        } catch (\Throwable $e) {}
    }
};
