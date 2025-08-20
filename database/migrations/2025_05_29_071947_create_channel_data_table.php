<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('channel_data', function (Blueprint $table) {
            $table->id();
            $table->string('store',20);
            $table->date('date');
            $table->string('category',30);
            $table->string('sub_category',50);
            $table->string('order_placed_method',30);
            $table->string('order_fulfilled_method',20);
            $table->decimal('amount', 10, 2);
            $table->timestamps();

            // Add indexes for commonly queried fields
            $table->index('store');
            $table->index('date');
            $table->index(['store', 'date']);
            $table->index('category');
        });
    }

    public function down()
    {
        Schema::dropIfExists('channel_data');
    }
};
