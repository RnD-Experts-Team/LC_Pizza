<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAltaInventoryIngredientUsageTable extends Migration
{
    public function up()
    {
        Schema::create('alta_inventory_ingredient_usage', function (Blueprint $table) {
            $table->id();
            $table->string('franchise_store',20)->nullable();
            $table->date('business_date')->nullable();
            $table->string('count_period',20)->nullable();
            $table->string('ingredient_id',50)->nullable();
            $table->string('ingredient_description')->nullable();
            $table->string('ingredient_category')->nullable();
            $table->string('ingredient_unit',20)->nullable();
            $table->decimal('ingredient_unit_cost', 10, 2)->default(0);
            $table->decimal('starting_inventory_qty', 10, 2)->default(0);
            $table->decimal('received_qty', 10, 2)->default(0);
            $table->decimal('net_transferred_qty', 10, 2)->default(0);
            $table->decimal('ending_inventory_qty', 10, 2)->default(0);
            $table->decimal('actual_usage', 10, 2)->default(0);
            $table->decimal('theoretical_usage', 10, 2)->default(0);
            $table->decimal('variance_qty', 10, 2)->default(0);
            $table->decimal('waste_qty', 10, 2)->default(0);
            $table->timestamps();
            $table->unique(
                ['franchise_store', 'business_date', 'count_period', 'ingredient_id'],
                'inv_ing_usage_unique'
            );
        });
    }

    public function down()
    {
        Schema::dropIfExists('alta_inventory_ingredient_usage');
    }
}
