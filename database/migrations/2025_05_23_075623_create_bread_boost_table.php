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
        Schema::create('bread_boost', function (Blueprint $table) {
            $table->id();
            $table->date('date')->nullable();
            $table->string('franchise_store')->nullable();
            $table->integer('classic_order')->nullable();
            $table->integer('classic_with_bread')->nullable();
            $table->integer('other_pizza_order')->nullable();
            $table->integer('other_pizza_with_bread')->nullable();
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
        Schema::dropIfExists('bread_boost');
    }
};
