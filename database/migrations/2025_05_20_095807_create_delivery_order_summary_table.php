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
        Schema::create('delivery_order_summary', function (Blueprint $table) {
            $table->id();
            $table->date('date')->nullable();
            $table->string('franchise_store',20)->nullable();
            $table->integer('orders_count')->nullable();
            $table->decimal('product_cost', 10, 2)->nullable();
            $table->decimal('tax', 10, 2)->nullable();
            $table->decimal('occupational_tax', 10, 2)->nullable();
            $table->decimal('delivery_charges', 10, 2)->nullable();
            $table->decimal('delivery_charges_taxes', 10, 2)->nullable();
            $table->decimal('service_charges', 10, 2)->nullable();
            $table->decimal('service_charges_taxes', 10, 2)->nullable();
            $table->decimal('small_order_charge', 10, 2)->nullable();
            $table->decimal('small_order_charge_taxes', 10, 2)->nullable();
            $table->decimal('delivery_late_charge', 10, 2)->nullable();
            $table->decimal('tip', 10, 2)->nullable();
            $table->decimal('tip_tax', 10, 2)->nullable();
            $table->decimal('total_taxes', 10, 2)->nullable();
            $table->decimal('order_total', 10, 2)->nullable();
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
        Schema::dropIfExists('delivery_order_summary');
    }
};
