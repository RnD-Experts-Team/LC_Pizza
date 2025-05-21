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
        Schema::create('online_discount_program', function (Blueprint $table) {
            $table->id();
            $table->string('franchise_store',20)->nullable();
            $table->bigInteger('order_id')->nullable();
            $table->date('date')->nullable();
            $table->string('pay_type')->nullable();
            $table->decimal('original_subtotal', 10, 2)->nullable();
            $table->decimal('modified_subtotal', 10, 2)->nullable();
            $table->string('promo_code')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('online_discount_program');
    }
};
