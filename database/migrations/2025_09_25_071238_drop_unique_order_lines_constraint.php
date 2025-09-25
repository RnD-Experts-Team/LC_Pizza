<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('order_line', function (Blueprint $table) {
            // remove the composite unique key so duplicates are allowed
            try { $table->dropUnique('unique_order_lines'); } catch (\Throwable $e) {}

            // add a non-unique composite index to keep lookups fast
            // (e.g. queries/groupings by these columns)
            $table->index(
                ['franchise_store','business_date','order_id','item_id'],
                'idx_order_line_lookup'
            );
        });
    }

    public function down(): void
    {
        Schema::table('order_line', function (Blueprint $table) {
            try { $table->dropIndex('idx_order_line_lookup'); } catch (\Throwable $e) {}
            // restore the original unique if you ever roll back
            $table->unique(
                ['franchise_store','business_date','order_id','item_id'],
                'unique_order_lines'
            );
        });
    }
};
