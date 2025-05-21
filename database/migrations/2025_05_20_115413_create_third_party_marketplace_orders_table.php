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
        Schema::create('third_party_marketplace_orders', function (Blueprint $table) {
            $table->id();
            $table->date('date')->nullable();
            $table->string('franchise_store',20)->nullable();
            $table->decimal('doordash_product_costs_Marketplace', 10, 2)->nullable();
            $table->decimal('doordash_tax_Marketplace', 10, 2)->nullable();
            $table->decimal('doordash_order_total_Marketplace', 10, 2)->nullable();
            $table->decimal('ubereats_product_costs_Marketplace', 10, 2)->nullable();
            $table->decimal('ubereats_tax_Marketplace', 10, 2)->nullable();
            $table->decimal('uberEats_order_total_Marketplace', 10, 2)->nullable();
            $table->decimal('grubhub_product_costs_Marketplace', 10, 2)->nullable();
            $table->decimal('grubhub_tax_Marketplace', 10, 2)->nullable();
            $table->decimal('grubhub_order_total_Marketplace', 10, 2)->nullable();
            $table->timestamps();

            // Add indexes for better query performance
            $table->index('franchise_store');
            $table->index('date');
            $table->index(['franchise_store', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('third_party_marketplace_orders');
    }
};
