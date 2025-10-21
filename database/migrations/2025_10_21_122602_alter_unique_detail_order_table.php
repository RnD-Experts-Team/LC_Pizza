<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('detail_orders', function (Blueprint $table) {
          try { $table->dropUnique('unique_detail_orders'); } catch (\Throwable $e) {}
          $table->unique(['franchise_store','business_date','order_id','transaction_type'], 'unique_detail_orders');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('detail_orders', function (Blueprint $table) {
          try { $table->dropUnique('unique_detail_orders'); } catch (\Throwable $e) {}
          $table->unique(['franchise_store','business_date','order_id'], 'unique_detail_orders');
        });
    }
};
