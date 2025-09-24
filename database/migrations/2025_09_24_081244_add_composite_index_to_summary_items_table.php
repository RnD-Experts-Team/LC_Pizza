<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('summary_items', function (Blueprint $table) {
            // Composite index for optimized "latest per item_id per store" queries
            $table->index(
                ['franchise_store', 'item_id', 'business_date', 'id'],
                'si_store_item_date_id_idx'
            );
        });
    }

    public function down()
    {
        Schema::table('summary_items', function (Blueprint $table) {
            $table->dropIndex('si_store_item_date_id_idx');
        });
    }
};
