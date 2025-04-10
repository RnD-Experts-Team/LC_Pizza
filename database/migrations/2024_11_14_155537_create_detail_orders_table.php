<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDetailOrdersTable extends Migration
{
    public function up()
    {
        Schema::create('detail_orders', function (Blueprint $table) {

            $table->id();

            $table->string('franchise_store',20)->index();

            $table->date('business_date')->index();
            $table->dateTime('date_time_placed')->nullable();
            $table->dateTime('date_time_fulfilled')->nullable();
            $table->decimal('royalty_obligation', 15, 2)->nullable();
            $table->integer('quantity')->nullable();
            $table->integer('customer_count')->nullable();
            $table->string('order_id')->nullable();
            $table->decimal('taxable_amount', 15, 2)->nullable();
            $table->decimal('non_taxable_amount', 15, 2)->nullable();
            $table->decimal('tax_exempt_amount', 15, 2)->nullable();
            $table->decimal('non_royalty_amount', 15, 2)->nullable();
            $table->decimal('sales_tax', 15, 2)->nullable();
            $table->string('employee')->nullable();
            $table->decimal('gross_sales', 15, 2)->nullable();
            $table->decimal('occupational_tax', 15, 2)->nullable();
            $table->string('override_approval_employee')->nullable();
            $table->string('order_placed_method')->nullable();
            $table->decimal('delivery_tip', 15, 2)->nullable();
            $table->decimal('delivery_tip_tax', 15, 2)->nullable();
            $table->string('order_fulfilled_method')->nullable();
            $table->decimal('delivery_fee', 15, 2)->nullable();
            $table->decimal('modified_order_amount', 15, 2)->nullable();
            $table->decimal('delivery_fee_tax', 15, 2)->nullable();
            $table->string('modification_reason')->nullable();
            $table->string('payment_methods')->nullable();
            $table->decimal('delivery_service_fee', 15, 2)->nullable();
            $table->decimal('delivery_service_fee_tax', 15, 2)->nullable();
            $table->string('refunded')->nullable();
            $table->decimal('delivery_small_order_fee', 15, 2)->nullable();
            $table->decimal('delivery_small_order_fee_tax', 15, 2)->nullable();
            $table->string('transaction_type')->nullable();
            $table->decimal('store_tip_amount', 15, 2)->nullable();
            $table->dateTime('promise_date')->nullable();
            $table->string('tax_exemption_id')->nullable();
            $table->string('tax_exemption_entity_name')->nullable();
            $table->string('user_id')->nullable();
            $table->string('hnrOrder')->nullable();
            $table->string('broken_promise')->nullable();
            $table->string('portal_eligible')->nullable();
            $table->string('portal_used')->nullable();
            $table->string('put_into_portal_before_promise_time')->nullable();
            $table->string('portal_compartments_used')->nullable();

            $table->timestamps();

            $table->index(['franchise_store', 'business_date']);
        });
    }

    public function down()
    {

        Schema::dropIfExists('detail_orders');

    }
}
