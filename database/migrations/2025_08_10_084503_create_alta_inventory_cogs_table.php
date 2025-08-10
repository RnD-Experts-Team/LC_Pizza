<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAltaInventoryCogsTable extends Migration
{
    public function up()
    {
        Schema::create('alta_inventory_cogs', function (Blueprint $table) {
            $table->id();
            $table->string('franchise_store')->nullable();
            $table->date('business_date')->nullable();
            $table->string('count_period')->nullable();
            $table->string('inventory_category')->nullable();
            $table->decimal('starting_value', 10, 2)->default(0);
            $table->decimal('received_value', 10, 2)->default(0);
            $table->decimal('net_transfer_value', 10, 2)->default(0);
            $table->decimal('ending_value', 10, 2)->default(0);
            $table->decimal('used_value', 10, 2)->default(0);
            $table->decimal('theoretical_usage_value', 10, 2)->default(0);
            $table->decimal('variance_value', 10, 2)->default(0);
            $table->timestamps();
            $table->unique(
                ['franchise_store', 'business_date', 'count_period', 'inventory_category'],
                'inv_cogs_unique'
            );
        });
    }

    public function down()
    {
        Schema::dropIfExists('alta_inventory_cogs');
    }
}
