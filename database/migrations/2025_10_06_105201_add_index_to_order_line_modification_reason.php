<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('order_line', function (Blueprint $table) {
            // Use a concise, unique name to avoid exceeding index name length limits
            $table->index('modification_reason', 'idx_order_line_modification_reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_line', function (Blueprint $table) {
            $table->dropIndex('idx_order_line_modification_reason');
        });
    }
};
