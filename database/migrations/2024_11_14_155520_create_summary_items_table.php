<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSummaryItemsTable extends Migration
{
    public function up()
    {
        Schema::create('summary_items', function (Blueprint $table) {

            $table->id();
            $table->string('franchise_store',20)->index();
            $table->date('business_date')->index();
            $table->string('menu_item_name',60)->nullable();
            $table->string('menu_item_account',25)->nullable();
            $table->string('item_id',10)->nullable();
            $table->integer('item_quantity')->nullable();
            $table->decimal('royalty_obligation', 15, 2)->nullable();
            $table->decimal('taxable_amount', 15, 2)->nullable();
            $table->decimal('non_taxable_amount', 15, 2)->nullable();
            $table->decimal('tax_exempt_amount', 15, 2)->nullable();
            $table->decimal('non_royalty_amount', 15, 2)->nullable();
            $table->decimal('tax_included_amount', 15, 2)->nullable();
            $table->timestamps();

            $table->index(['franchise_store', 'business_date']);

        });
    }

    public function down()
    {

        Schema::dropIfExists('summary_items');

    }
}
