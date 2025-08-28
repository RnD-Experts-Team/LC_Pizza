<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('detail_orders', function (Blueprint $table) {
            // Change from VARCHAR(20) → VARCHAR(191)
            $table->string('tax_exemption_entity_name', 191)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('detail_orders', function (Blueprint $table) {
            // Rollback to original definition (20 chars)
            $table->string('tax_exemption_entity_name', 20)->nullable()->change();
        });
    }
};
