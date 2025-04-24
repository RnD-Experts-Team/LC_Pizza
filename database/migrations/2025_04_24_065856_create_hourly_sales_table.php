<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateHourlySalesTable extends Migration
{
    public function up()
    {
        Schema::create('hourly_sales', function (Blueprint $table) {

            $table->id();

            // Identifies the store
            $table->string('franchise_store', 20)->index();

            // Business date
            $table->date('business_date')->index();

            // Hour of the day (0 - 23)
            $table->unsignedTinyInteger('hour')->index();

            // Total sales for that hour
            $table->decimal('total_sales', 15, 2)->default(0);

            // Sales channels
            $table->decimal('phone_sales', 12, 2)->default(0);
            $table->decimal('call_center_sales', 12, 2)->default(0);
            $table->decimal('drive_thru_sales', 12, 2)->default(0);
            $table->decimal('website_sales', 12, 2)->default(0);
            $table->decimal('mobile_sales', 12, 2)->default(0);

            // Optional: number of orders (handy for analytics)
            $table->integer('order_count')->nullable();

            $table->timestamps();

            // Composite index for efficient filtering
            $table->index(['franchise_store', 'business_date', 'hour']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('hourly_sales');
    }
}
