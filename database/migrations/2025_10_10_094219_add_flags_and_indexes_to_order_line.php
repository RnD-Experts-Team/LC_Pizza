<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fix zero-dates under strict mode, then add STORED generated flags + indexes.
 * We temporarily remove NO_ZERO_DATE / NO_ZERO_IN_DATE, clean data, restore mode,
 * and proceed with the ALTER TABLE work.
 */
return new class extends Migration {
    public function up(): void
    {
        // ---- 0) Capture and relax sql_mode (strict) just for the cleanup ----
        $oldMode = (string) (DB::select("SELECT @@sql_mode AS m")[0]->m ?? '');
        $newMode = $this->removeZeroDateFlags($oldMode);

        $modeChanged = false;
        try {
            if ($oldMode !== $newMode) {
                DB::statement("SET SESSION sql_mode = ?", [$newMode]);
                $modeChanged = true;
            }

            // ---- 1) Sanitize bad timestamps that violate strict mode ----
            // Use raw statements so the literal stays quoted and MySQL won’t try to coerce it.
            DB::statement("UPDATE `order_line` SET `created_at` = NULL WHERE `created_at` = '0000-00-00 00:00:00'");
            DB::statement("UPDATE `order_line` SET `updated_at` = NULL WHERE `updated_at` = '0000-00-00 00:00:00'");

        } finally {
            // ---- 2) Restore original sql_mode BEFORE schema changes ----
            if ($modeChanged) {
                DB::statement("SET SESSION sql_mode = ?", [$oldMode]);
            }
        }

        // ---- 3) Add generated STORED flags + indexes (indexable booleans) ----
        Schema::table('order_line', function (Blueprint $table) {
            $table->boolean('is_pizza')->storedAs("
                (
                    (menu_item_name IN ('Classic Pepperoni','Classic Cheese'))
                    OR
                    (item_id IN ('-1','6','7','8','9','101001','101002','101288','103044','202901','101289','204100','204200'))
                )
            ")->nullable();

            $table->boolean('is_companion_crazy_bread')->storedAs("(menu_item_name IN ('Crazy Bread'))")->nullable();
            $table->boolean('is_companion_cookie')->storedAs("(menu_item_name IN ('Cookie Dough Brownie M&M','Cookie Dough Brownie - Twix'))")->nullable();
            $table->boolean('is_companion_sauce')->storedAs("(menu_item_name IN ('Crazy Sauce'))")->nullable();
            $table->boolean('is_companion_wings')->storedAs("(menu_item_name IN ('Caesar Wings'))")->nullable();

            // Per-store & all-store indexes (short names to avoid length limits)
            $table->index(['franchise_store','business_date','is_pizza','order_id'], 'idx_ol_store_date_pizza_ord');
            $table->index(['business_date','is_pizza','order_id'], 'idx_ol_date_pizza_ord');

            $table->index(['franchise_store','business_date','is_companion_crazy_bread','order_id'], 'idx_ol_store_date_czb_ord');
            $table->index(['business_date','is_companion_crazy_bread','order_id'], 'idx_ol_date_czb_ord');

            $table->index(['franchise_store','business_date','is_companion_cookie','order_id'], 'idx_ol_store_date_cookie_ord');
            $table->index(['business_date','is_companion_cookie','order_id'], 'idx_ol_date_cookie_ord');

            $table->index(['franchise_store','business_date','is_companion_sauce','order_id'], 'idx_ol_store_date_sauce_ord');
            $table->index(['business_date','is_companion_sauce','order_id'], 'idx_ol_date_sauce_ord');

            $table->index(['franchise_store','business_date','is_companion_wings','order_id'], 'idx_ol_store_date_wings_ord');
            $table->index(['business_date','is_companion_wings','order_id'], 'idx_ol_date_wings_ord');

            $table->index(['franchise_store','business_date','item_id'], 'idx_ol_store_date_item');
            $table->index(['business_date','item_id'], 'idx_ol_date_item');

            $table->index(['franchise_store','business_date','menu_item_name'], 'idx_ol_store_date_name');
            $table->index(['business_date','menu_item_name'], 'idx_ol_date_name');
        });
    }

    public function down(): void
    {
        Schema::table('order_line', function (Blueprint $table) {
            $table->dropIndex('idx_ol_store_date_pizza_ord');
            $table->dropIndex('idx_ol_date_pizza_ord');

            $table->dropIndex('idx_ol_store_date_czb_ord');
            $table->dropIndex('idx_ol_date_czb_ord');

            $table->dropIndex('idx_ol_store_date_cookie_ord');
            $table->dropIndex('idx_ol_date_cookie_ord');

            $table->dropIndex('idx_ol_store_date_sauce_ord');
            $table->dropIndex('idx_ol_date_sauce_ord');

            $table->dropIndex('idx_ol_store_date_wings_ord');
            $table->dropIndex('idx_ol_date_wings_ord');

            $table->dropIndex('idx_ol_store_date_item');
            $table->dropIndex('idx_ol_date_item');

            $table->dropIndex('idx_ol_store_date_name');
            $table->dropIndex('idx_ol_date_name');

            $table->dropColumn([
                'is_pizza',
                'is_companion_crazy_bread',
                'is_companion_cookie',
                'is_companion_sauce',
                'is_companion_wings',
            ]);
        });
    }

    /**
     * Remove NO_ZERO_DATE / NO_ZERO_IN_DATE from a sql_mode string.
     */
    private function removeZeroDateFlags(string $mode): string
    {
        if ($mode === '') return $mode;

        $parts = array_filter(array_map('trim', explode(',', $mode)), function ($p) {
            return !in_array(strtoupper($p), ['NO_ZERO_DATE','NO_ZERO_IN_DATE'], true);
        });

        return implode(',', $parts);
    }
};
