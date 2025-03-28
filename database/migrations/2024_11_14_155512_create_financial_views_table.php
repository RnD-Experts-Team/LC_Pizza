<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateFinancialViewsTable extends Migration
{
    public function up()
    {
        Schema::create('financial_views', function (Blueprint $table) {
            $table->id();
            $table->string('franchise_store',20)->index();
            $table->date('business_date')->index();
            $table->string('area')->nullable();
            $table->string('sub_account')->nullable();
            $table->decimal('amount', 15, 2)->nullable();
            $table->timestamps();

            $table->index(['franchise_store', 'business_date']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('financial_views');
    }
}
