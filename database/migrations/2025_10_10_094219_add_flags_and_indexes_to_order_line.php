<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Adds generated flags & indexes on order_line.
 * Pre-step: sanitize zero-dates that break strict mode during ALTER TABLE.
 */
return new class extends Migration {
    public function up(): void
    {
        // 0) PRE-CLEAN bad timestamps that violate NO_ZERO_DATE
        //    Use small targeted UPDATEs — no row loading into PHP.
        DB::table('order_line')
            ->where('created_at', '0000-00-00 00:00:00')
            ->update(['created_at' => null]);

        DB::table('order_line')
            ->where('updated_at', '0000-00-00 00:00:00')
            ->update(['updated_at' => null]);

        // (Optional) If you have doctrine/dbal installed, uncomment to enforce nullable()
        // try {
        //     Schema::table('order_line', function (Blueprint $table) {
        //         $table->timestamp('created_at')->nullable()->change();
        //         $table->timestamp('updated_at')->nullable()->change();
        //     });
        // } catch (\Throwable $e) {
        //     // safe to ignore if already nullable / no dbal installed
        // }

        // 1) Add generated flags (STORED) and indexes
        Schema::table('order_line', function (Blueprint $table) {
            // Generated STORED flags (indexable)
            $table->boolean('is_pizza')->storedAs("
                (
                    (menu_item_name IN ('Classic Pepperoni','Classic Cheese'))
                    OR
                    (item_id IN ('-1','6','7','8','9','101001','101002','101288','103044','202901','101289','204100','204200'))
                )
            ")->nullable();

            $table->boolean('is_companion_crazy_bread')->storedAs("
                (menu_item_name IN ('Crazy Bread'))
            ")->nullable();

            $table->boolean('is_companion_cookie')->storedAs("
                (menu_item_name IN ('Cookie Dough Brownie M&M','Cookie Dough Brownie - Twix'))
            ")->nullable();

            $table->boolean('is_companion_sauce')->storedAs("
                (menu_item_name IN ('Crazy Sauce'))
            ")->nullable();

            $table->boolean('is_companion_wings')->storedAs("
                (menu_item_name IN ('Caesar Wings'))
            ")->nullable();

            // Indexes for per-store and all-store scans
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

            // For item breakdown by ID
            $table->index(['franchise_store','business_date','item_id'], 'idx_ol_store_date_item');
            $table->index(['business_date','item_id'], 'idx_ol_date_item');

            // Optional fallback if someone filters by name
            $table->index(['franchise_store','business_date','menu_item_name'], 'idx_ol_store_date_name');
            $table->index(['business_date','menu_item_name'], 'idx_ol_date_name');
        });
    }

    public function down(): void
    {
        Schema::table('order_line', function (Blueprint $table) {
            // Drop indexes
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

            // Drop generated columns
            $table->dropColumn([
                'is_pizza',
                'is_companion_crazy_bread',
                'is_companion_cookie',
                'is_companion_sauce',
                'is_companion_wings',
            ]);
        });
    }
};
