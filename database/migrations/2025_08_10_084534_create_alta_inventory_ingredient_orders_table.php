<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAltaInventoryIngredientOrdersTable extends Migration
{
    public function up()
    {
        Schema::create('alta_inventory_ingredient_orders', function (Blueprint $table) {
            $table->id();
            $table->string('franchise_store',20)->nullable();
            $table->date('business_date')->nullable();
            $table->string('supplier',50)->nullable();
            $table->string('invoice_number',50)->nullable();
            $table->string('purchase_order_number',50)->nullable();
            $table->string('ingredient_id',50)->nullable();
            $table->string('ingredient_description')->nullable();
            $table->string('ingredient_category')->nullable();
            $table->string('ingredient_unit',50)->nullable();
            $table->decimal('unit_price', 10, 2)->default(0);
            $table->decimal('order_qty', 10, 2)->default(0);
            $table->decimal('sent_qty', 10, 2)->default(0);
            $table->decimal('received_qty', 10, 2)->default(0);
            $table->decimal('total_cost', 10, 2)->default(0);
            $table->timestamps();
            $table->unique(
                [
                    'franchise_store',
                    'business_date',
                    'supplier',
                    'invoice_number',
                    'purchase_order_number',
                    'ingredient_id'
                ],
                'inv_ing_orders_unique'
            );
        });
    }

    public function down()
    {
        Schema::dropIfExists('alta_inventory_ingredient_orders');
    }
}
