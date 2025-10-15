<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        DB::transaction(function () {
            // 1) Remove old non-unique index if present (ignore errors)
            try { Schema::table('order_line', function (Blueprint $table) {
                $table->dropIndex('idx_order_line_lookup');
            }); } catch (\Throwable $e) {}

            // 2) Delete older duplicates, keeping latest created_at (ties by id)
            $driver = DB::getDriverName();

            if ($driver === 'mysql') {
    // MySQL 5.7-safe: delete any row that has a strictly "newer" twin on the same key
    DB::statement(<<<'SQL'
DELETE ol
FROM order_line ol
JOIN order_line k
  ON k.franchise_store = ol.franchise_store
 AND k.business_date   = ol.business_date
 AND k.order_id        = ol.order_id
 AND k.item_id         = ol.item_id
 AND (
      /* If ol.created_at is NULL and k.created_at is NOT NULL, k is newer */
      (ol.created_at IS NULL AND k.created_at IS NOT NULL)
   OR /* Both non-null: newer timestamp wins */
      (ol.created_at IS NOT NULL AND k.created_at IS NOT NULL AND k.created_at > ol.created_at)
   OR /* Same timestamp (including both NULL): higher id wins */
      ( ( (ol.created_at = k.created_at)
          OR (ol.created_at IS NULL AND k.created_at IS NULL) )
        AND k.id > ol.id )
     )
SQL);

            } elseif ($driver === 'pgsql') {
                DB::statement(<<<'SQL'
WITH ranked AS (
  SELECT id,
         ROW_NUMBER() OVER (
           PARTITION BY franchise_store, business_date, order_id, item_id
           ORDER BY created_at DESC NULLS LAST, id DESC
         ) AS rn
  FROM order_line
)
DELETE FROM order_line ol
USING ranked r
WHERE ol.id = r.id
  AND r.rn > 1
SQL);
            } else {
                throw new \RuntimeException("Unsupported DB driver: {$driver}. Add a driver-specific cleanup.");
            }

            // 3) Restore the unique constraint
            Schema::table('order_line', function (Blueprint $table) {
                $table->unique(
                    ['franchise_store', 'business_date', 'order_id', 'item_id'],
                    'unique_order_lines'
                );
            });

            // 4) (Optional) Re-add the non-unique lookup index if you still want it alongside the unique
            // It's redundant for lookups because the UNIQUE is also an index, but include if you need name stability.
            // Schema::table('order_line', function (Blueprint $table) {
            //     $table->index(['franchise_store','business_date','order_id','item_id'], 'idx_order_line_lookup');
            // });
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            // Drop the unique constraint
            try { Schema::table('order_line', function (Blueprint $table) {
                $table->dropUnique('unique_order_lines');
            }); } catch (\Throwable $e) {}

            // Optionally put back the non-unique index for lookups
            Schema::table('order_line', function (Blueprint $table) {
                $table->index(
                    ['franchise_store', 'business_date', 'order_id', 'item_id'],
                    'idx_order_line_lookup'
                );
            });
        });
    }
};
