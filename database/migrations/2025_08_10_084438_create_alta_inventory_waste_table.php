<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAltaInventoryWasteTable extends Migration
{
    public function up()
    {
        Schema::create('alta_inventory_waste', function (Blueprint $table) {
            $table->id();
            $table->string('franchise_store')->nullable();
            $table->date('business_date')->nullable();
            $table->string('item_id')->nullable();
            $table->string('item_description')->nullable();
            $table->string('waste_reason')->nullable();
            $table->decimal('unit_food_cost', 10, 2)->default(0);
            $table->decimal('qty', 10, 2)->default(0);
            $table->timestamps();
            $table->unique(['franchise_store', 'business_date', 'item_id'], 'inv_waste_unique');
        });
    }

    public function down()
    {
        Schema::dropIfExists('alta_inventory_waste');
    }
}
