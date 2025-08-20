<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        schema::create('waste', function (blueprint $table) {
            $table->id();
            $table->date('business_date')->index();
            $table->string('franchise_store',20)->index();
            $table->string('cv_item_id',10)->nullable();
            $table->string('menu_item_name')->nullable();
            $table->boolean('expired')->nullable();
            $table->timestamp('waste_date_time')->nullable();
            $table->timestamp('produce_date_time')->nullable();
            $table->string('waste_reason')->nullable();
            $table->string('cv_order_id',10)->nullable();
            $table->string('waste_type',20)->nullable();
            $table->decimal('item_cost', 8, 4)->nullable();
            $table->integer('quantity')->nullable();
            //$table->integer('age_in_minutes')->nullable();
            $table->timestamps();

            $table->index(['franchise_store', 'business_date']);
        });
    }

    public function down(): void
    {
        schema::dropIfExists('waste');
    }
};
