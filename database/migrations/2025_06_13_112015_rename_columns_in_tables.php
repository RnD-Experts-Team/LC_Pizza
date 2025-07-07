<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected array $renames = [
        ['online_discount_program', 'date', 'business_date'],
        ['delivery_order_summary', 'date', 'business_date'],
        ['third_party_marketplace_orders', 'date', 'business_date'],
        ['bread_boost', 'date', 'business_date'],
        ['channel_data', 'date', 'business_date']
    ];

    public function up(): void
    {
        foreach ($this->renames as $rename) {
            list($tableName, $oldColumn, $newColumn) = $rename;

            // Check if the table and old column exist before attempting to rename
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, $oldColumn)) {
                Schema::table($tableName, function (Blueprint $table) use ($oldColumn, $newColumn) {
                    $table->renameColumn($oldColumn, $newColumn);
                });
            } else {
                // You can log a warning if a table or column isn't found
                // This is useful during development to catch typos or already applied changes
                if (!Schema::hasTable($tableName)) {
                    \Log::warning("Migration skipped: Table '{$tableName}' does not exist.");
                } elseif (!Schema::hasColumn($tableName, $oldColumn)) {
                    \Log::warning("Migration skipped: Column '{$oldColumn}' not found in table '{$tableName}'.");
                }
            }
        }
    }

    public function down(): void
    {
        // Loop in reverse order for the down method to undo correctly
        foreach (array_reverse($this->renames) as $rename) {
            list($tableName, $oldColumn, $newColumn) = $rename;

            // Check if the table and new column exist before attempting to revert the rename
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, $newColumn)) {
                Schema::table($tableName, function (Blueprint $table) use ($oldColumn, $newColumn) {
                    $table->renameColumn($newColumn, $oldColumn);
                });
            } else {
                // Log a warning if the table or new column isn't found during rollback
                if (!Schema::hasTable($tableName)) {
                    \Log::warning("Rollback skipped: Table '{$tableName}' does not exist for reverting column rename.");
                } elseif (!Schema::hasColumn($tableName, $newColumn)) {
                    \Log::warning("Rollback skipped: Column '{$newColumn}' not found in table '{$tableName}' for reverting rename.");
                }
            }
        }
    }
};
