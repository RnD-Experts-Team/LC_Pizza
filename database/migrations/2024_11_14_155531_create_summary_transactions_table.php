<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSummaryTransactionsTable extends Migration
{
    public function up()
    {

        Schema::create('summary_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('franchise_store',20)->index();
            $table->date('business_date')->index();
            $table->string('payment_method',20)->nullable();
            $table->string('sub_payment_method',25)->nullable();
            $table->decimal('total_amount', 15, 2)->nullable();
            $table->integer('saf_qty')->nullable();
            $table->decimal('saf_total', 15, 2)->nullable();
            $table->timestamps();

            $table->index(['franchise_store', 'business_date']);
        });

    }

    public function down()
    {

        Schema::dropIfExists('summary_transactions');

    }
}
