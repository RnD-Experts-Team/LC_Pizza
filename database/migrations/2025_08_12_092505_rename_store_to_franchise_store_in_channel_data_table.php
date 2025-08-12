<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_data', function (Blueprint $table) {
            // Rename column `store` -> `franchise_store`
            $table->renameColumn('store', 'franchise_store');
        });
    }

    public function down(): void
    {
        Schema::table('channel_data', function (Blueprint $table) {
            $table->renameColumn('franchise_store', 'store');
        });
    }
};
