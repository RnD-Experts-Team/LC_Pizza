<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCashManagementTable extends Migration
{
    public function up()
    {
        Schema::create('cash_management', function (Blueprint $table) {
            $table->id();
            $table->string('franchise_store',20)->index();
            $table->date('business_date')->index();
            $table->dateTime('create_datetime')->nullable();
            $table->dateTime('verified_datetime')->nullable();
            $table->string('till',15)->nullable();
            $table->string('check_type',50)->nullable();
            $table->decimal('system_totals', 15, 2)->nullable();
            $table->decimal('verified', 15, 2)->nullable();
            $table->decimal('variance', 15, 2)->nullable();
            $table->string('created_by',50)->nullable();
            $table->string('verified_by',50)->nullable();
            $table->timestamps();

            $table->index(['franchise_store', 'business_date']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('cash_managements');
    }
}
