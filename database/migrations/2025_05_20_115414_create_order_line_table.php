<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOrderLineTable extends Migration
{
    public function up()
    {
        Schema::create('order_line', function (Blueprint $table) {

            $table->id();

            $table->string('franchise_store',20)->index();
            $table->date('business_date')->index();

            $table->dateTime('date_time_placed')->nullable();
            $table->dateTime('date_time_fulfilled')->nullable();

            $table->decimal('net_amount', 15, 2)->nullable();
            $table->integer('quantity')->nullable();


            $table->string('royalty_item',10)->nullable();
            $table->string('taxable_item',10)->nullable();

            $table->string('order_id',20)->nullable();
            $table->string('item_id',20)->nullable();
            $table->string('menu_item_name')->nullable();
            $table->string('menu_item_account')->nullable();
            $table->string('bundle_name')->nullable();

            $table->string('employee')->nullable();
            $table->string('override_approval_employee')->nullable();

            $table->string('order_placed_method',50)->nullable();
            $table->string('order_fulfilled_method',50)->nullable();

            $table->decimal('modified_order_amount', 15, 2)->nullable();
            $table->string('modification_reason')->nullable();
            $table->string('payment_methods',50)->nullable();
            $table->string('refunded',50)->nullable();
            $table->decimal('tax_included_amount', 15, 2)->nullable();

            $table->timestamps();

            $table->index(['franchise_store', 'business_date']);
        });
    }

    public function down()
    {

        Schema::dropIfExists('detail_orders');

    }
}
