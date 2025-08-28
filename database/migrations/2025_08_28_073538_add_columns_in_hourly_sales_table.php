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
        Schema::table('hourly_sales', function (Blueprint $table) {
            $table->decimal('website_sales_delivery',12,2)->nullable()->after('mobile_sales');
            $table->decimal('mobile_sales_delivery',12, 2)->nullable()->after('website_sales_delivery');
            $table->decimal('doordash_sales',12,2)->nullable()->after('mobile_sales_delivery');
            $table->decimal('ubereats_sales',12, 2)->nullable()->after('doordash_sales');
            $table->decimal('grubhub_sales',12, 2)->nullable()->after('ubereats_sales');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('hourly_sales', function (Blueprint $table) {
            //
        });
    }
};
