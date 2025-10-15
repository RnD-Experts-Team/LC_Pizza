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
        $tz = config('app.timezone') ?: 'Europe/Sofia';

        // Rolling 30-day window: today and the preceding 29 days (inclusive)
        $end   = Carbon::now($tz)->toDateString();                // e.g., 2025-10-15
        $start = Carbon::now($tz)->subDays(29)->toDateString();   // e.g., 2025-09-16

        if ($driver === 'mysql') {
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
             */
            $batchLimit = 1000; // tune 500..2000 based on load/timeouts
            $sleepMs    = 150;  // tiny pause to appease shared hosting watchdogs

            // Iterate each calendar day in the rolling window
            for ($d = Carbon::parse($start, $tz); $d->lte(Carbon::parse($end, $tz)); $d->addDay()) {
                $dateStr = $d->toDateString();

                do {
                    $deleted = DB::affectingStatement(<<<SQL
DELETE /* dedupe chunk for {$dateStr} */
  ol
FROM order_line AS ol FORCE INDEX (idx_order_line_cleanup_helper)
JOIN order_line AS k  FORCE INDEX (idx_order_line_cleanup_helper)
  ON k.franchise_store = ol.franchise_store
 AND k.business_date   = ol.business_date
 AND k.order_id        = ol.order_id
 AND k.item_id         = ol.item_id
 AND (
      (ol.created_at IS NULL AND k.created_at IS NOT NULL) OR
      (ol.created_at IS NOT NULL AND k.created_at IS NOT NULL AND k.created_at > ol.created_at) OR
      (
        ((ol.created_at = k.created_at) OR (ol.created_at IS NULL AND k.created_at IS NULL))
        AND k.id > ol.id
      )
     )
WHERE ol.business_date = ?
ORDER BY ol.created_at IS NOT NULL, ol.created_at, ol.id
LIMIT {$batchLimit}
SQL, [$dateStr]);

                    if ($deleted > 0 && $sleepMs > 0) {
                        usleep($sleepMs * 1000);
                    }
                } while ($deleted > 0);
            }

            /**
             * 4) Enforce **last-30-days** uniqueness via generated column + UNIQUE index.
             *    Generated column equals business_date inside [start, end], else NULL.
             *    UNIQUE(franchise_store, generated, order_id, item_id) => only rows in the window are constrained.
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
             * 5) Optional: drop the helper cleanup index (or keep it if you'll re-run cleaners)
             */
            try {
                Schema::table('order_line', function (Blueprint $table) {
                    $table->dropIndex('idx_order_line_cleanup_helper');
                });
            } catch (\Throwable $e) {}

            /**
             * NOTE:
             * - This enforces uniqueness only for the rows with business_date between {$start} and {$end}.
             * - If you want this to auto-roll forward in the future, use an app-level check or a scheduled job
             *   that refreshes the index each day (or switch to global uniqueness after historical cleanup).
             */

        } elseif ($driver === 'pgsql') {
            /**
             * PostgreSQL: dedupe in the 30-day window and add a partial unique index.
             */

            // Dedupe
            DB::statement(<<<SQL
WITH ranked AS (
  SELECT id,
         ROW_NUMBER() OVER (
           PARTITION BY franchise_store, business_date, order_id, item_id
           ORDER BY created_at DESC NULLS LAST, id DESC
         ) AS rn
  FROM order_line
  WHERE business_date >= DATE '{$start}'
    AND business_date <= DATE '{$end}'
)
DELETE FROM order_line ol
USING ranked r
WHERE ol.id = r.id
  AND r.rn > 1
SQL);

            // Partial unique limited to the 30-day window
            try {
                DB::statement(<<<SQL
CREATE UNIQUE INDEX IF NOT EXISTS unique_order_lines
  ON order_line(franchise_store, business_date, order_id, item_id)
  WHERE business_date >= DATE '{$start}'
    AND business_date <= DATE '{$end}';
SQL);
            } catch (\Throwable $e) {}

        } else {
            throw new \RuntimeException("Unsupported DB driver: {$driver}. Add driver-specific logic.");
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('order_line')) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
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

        } elseif ($driver === 'pgsql') {
            // Drop partial unique index
            try {
                DB::statement("DROP INDEX IF EXISTS unique_order_lines");
            } catch (\Throwable $e) {}
        }
    }
};
