<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Helpers */
    private function dropIndexIfExists(string $table, string $index): void
    {
        $exists = DB::selectOne(
            "SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1",
            [$table, $index]
        );
        if ($exists) {
            DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$index}`");
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        return Schema::hasColumn($table, $column);
    }

    public function up(): void
    {
        $table = 'order_line';

        // 1) Drop indexes that reference columns we will remove or rename
        $this->dropIndexIfExists($table, 'idx_ol_store_date_czb_ord');
        $this->dropIndexIfExists($table, 'idx_ol_date_czb_ord');

        $this->dropIndexIfExists($table, 'idx_ol_store_date_cookie_ord');
        $this->dropIndexIfExists($table, 'idx_ol_date_cookie_ord');

        $this->dropIndexIfExists($table, 'idx_ol_store_date_sauce_ord');
        $this->dropIndexIfExists($table, 'idx_ol_date_sauce_ord');

        // old "wings companion" (will be replaced by account-based is_wings)
        $this->dropIndexIfExists($table, 'idx_ol_store_date_wings_ord');
        $this->dropIndexIfExists($table, 'idx_ol_date_wings_ord');

        // 2) Modify existing generated column: is_pizza → account-based
        if ($this->columnExists($table, 'is_pizza')) {
            DB::statement("
                ALTER TABLE `{$table}`
                MODIFY COLUMN `is_pizza` TINYINT(1)
                GENERATED ALWAYS AS (
                    (menu_item_account IN ('HNR','Pizza'))
                ) STORED NULL
            ");
        }

        // 3) Drop old companion flags (if present)
        $dropBits = [];
        foreach ([
            'is_companion_crazy_bread',
            'is_companion_cookie',
            'is_companion_sauce',
            'is_companion_wings',
        ] as $col) {
            if ($this->columnExists($table, $col)) {
                $dropBits[] = "DROP COLUMN `{$col}`";
            }
        }
        if (!empty($dropBits)) {
            DB::statement("ALTER TABLE `{$table}` " . implode(",\n", $dropBits));
        }

        // 4) Add new generated flags (idempotent: only if missing)
        $addBits = [];

        if (!$this->columnExists($table, 'is_bread')) {
            $addBits[] = "
                ADD COLUMN `is_bread` TINYINT(1)
                GENERATED ALWAYS AS ((menu_item_account IN ('Bread'))) STORED NULL
            ";
        }

        if (!$this->columnExists($table, 'is_wings')) {
            $addBits[] = "
                ADD COLUMN `is_wings` TINYINT(1)
                GENERATED ALWAYS AS ((menu_item_account IN ('Wings'))) STORED NULL
            ";
        }

        if (!$this->columnExists($table, 'is_beverages')) {
            $addBits[] = "
                ADD COLUMN `is_beverages` TINYINT(1)
                GENERATED ALWAYS AS ((menu_item_account IN ('Beverages'))) STORED NULL
            ";
        }

        if (!$this->columnExists($table, 'is_crazy_puffs')) {
            $addBits[] = "
                ADD COLUMN `is_crazy_puffs` TINYINT(1)
                GENERATED ALWAYS AS (
                    (menu_item_name LIKE '%Puffs%')
                    OR (item_id IN ('103057','103044','103033'))
                ) STORED NULL
            ";
        }

        if (!$this->columnExists($table, 'is_caesar_dip')) {
            $addBits[] = "
                ADD COLUMN `is_caesar_dip` TINYINT(1)
                GENERATED ALWAYS AS (
                    item_id IN ('206117','206103','206104','206108','206101')
                ) STORED NULL
            ";
        }

        if (!empty($addBits)) {
            DB::statement("ALTER TABLE `{$table}` " . implode(",\n", $addBits));
        }

        // 5) Create indexes for the new flags (pizza indexes remain as before)
        //    (safe to run repeatedly thanks to IF NOT EXISTS emulation via information_schema)
        $newIndexes = [
            // bread
            ['idx_ol_store_date_bread_ord', "(`franchise_store`,`business_date`,`is_bread`,`order_id`)"],
            ['idx_ol_date_bread_ord',       "(`business_date`,`is_bread`,`order_id`)"],

            // wings (new account-based)
            ['idx_ol_store_date_wings_ord_new', "(`franchise_store`,`business_date`,`is_wings`,`order_id`)"],
            ['idx_ol_date_wings_ord_new',       "(`business_date`,`is_wings`,`order_id`)"],

            // beverages
            ['idx_ol_store_date_bev_ord', "(`franchise_store`,`business_date`,`is_beverages`,`order_id`)"],
            ['idx_ol_date_bev_ord',       "(`business_date`,`is_beverages`,`order_id`)"],

            // crazy puffs
            ['idx_ol_store_date_puffs_ord', "(`franchise_store`,`business_date`,`is_crazy_puffs`,`order_id`)"],
            ['idx_ol_date_puffs_ord',       "(`business_date`,`is_crazy_puffs`,`order_id`)"],

            // caesar dips
            ['idx_ol_store_date_dip_ord', "(`franchise_store`,`business_date`,`is_caesar_dip`,`order_id`)"],
            ['idx_ol_date_dip_ord',       "(`business_date`,`is_caesar_dip`,`order_id`)"],
        ];

        foreach ($newIndexes as [$name, $cols]) {
            $exists = DB::selectOne(
                "SELECT 1 FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1",
                [$table, $name]
            );
            if (!$exists) {
                DB::statement("ALTER TABLE `{$table}` ADD INDEX `{$name}` {$cols}");
            }
        }

        // NOTE: your existing indexes on item_id/name remain untouched:
        // idx_ol_store_date_item, idx_ol_date_item, idx_ol_store_date_name, idx_ol_date_name
        // pizza indexes 'idx_ol_store_date_pizza_ord' / 'idx_ol_date_pizza_ord' also remain as-is.
    }

    public function down(): void
    {
        $table = 'order_line';

        // Drop new indexes
        foreach ([
            'idx_ol_store_date_bread_ord',
            'idx_ol_date_bread_ord',
            'idx_ol_store_date_wings_ord_new',
            'idx_ol_date_wings_ord_new',
            'idx_ol_store_date_bev_ord',
            'idx_ol_date_bev_ord',
            'idx_ol_store_date_puffs_ord',
            'idx_ol_date_puffs_ord',
            'idx_ol_store_date_dip_ord',
            'idx_ol_date_dip_ord',
        ] as $idx) {
            $this->dropIndexIfExists($table, $idx);
        }

        // Remove new flags (if present)
        $dropBits = [];
        foreach (['is_bread','is_wings','is_beverages','is_crazy_puffs','is_caesar_dip'] as $col) {
            if ($this->columnExists($table, $col)) {
                $dropBits[] = "DROP COLUMN `{$col}`";
            }
        }
        if (!empty($dropBits)) {
            DB::statement("ALTER TABLE `{$table}` " . implode(",\n", $dropBits));
        }

        // Restore old companion columns + their indexes
        DB::statement("
            ALTER TABLE `{$table}`
            ADD COLUMN `is_companion_crazy_bread` TINYINT(1)
                GENERATED ALWAYS AS (menu_item_name IN ('Crazy Bread')) STORED NULL,
            ADD COLUMN `is_companion_cookie` TINYINT(1)
                GENERATED ALWAYS AS (menu_item_name IN ('Cookie Dough Brownie M&M','Cookie Dough Brownie - Twix')) STORED NULL,
            ADD COLUMN `is_companion_sauce` TINYINT(1)
                GENERATED ALWAYS AS (menu_item_name IN ('Crazy Sauce')) STORED NULL,
            ADD COLUMN `is_companion_wings` TINYINT(1)
                GENERATED ALWAYS AS (menu_item_name IN ('Caesar Wings')) STORED NULL
        ");

        // Restore their indexes
        DB::statement("
            ALTER TABLE `{$table}`
            ADD INDEX `idx_ol_store_date_czb_ord` (`franchise_store`,`business_date`,`is_companion_crazy_bread`,`order_id`),
            ADD INDEX `idx_ol_date_czb_ord` (`business_date`,`is_companion_crazy_bread`,`order_id`),
            ADD INDEX `idx_ol_store_date_cookie_ord` (`franchise_store`,`business_date`,`is_companion_cookie`,`order_id`),
            ADD INDEX `idx_ol_date_cookie_ord` (`business_date`,`is_companion_cookie`,`order_id`),
            ADD INDEX `idx_ol_store_date_sauce_ord` (`franchise_store`,`business_date`,`is_companion_sauce`,`order_id`),
            ADD INDEX `idx_ol_date_sauce_ord` (`business_date`,`is_companion_sauce`,`order_id`),
            ADD INDEX `idx_ol_store_date_wings_ord` (`franchise_store`,`business_date`,`is_companion_wings`,`order_id`),
            ADD INDEX `idx_ol_date_wings_ord` (`business_date`,`is_companion_wings`,`order_id`)
        ");

        // Put is_pizza back to previous expression
        if ($this->columnExists($table, 'is_pizza')) {
            DB::statement("
                ALTER TABLE `{$table}`
                MODIFY COLUMN `is_pizza` TINYINT(1)
                GENERATED ALWAYS AS (
                    (
                        (menu_item_name IN ('Classic Pepperoni','Classic Cheese'))
                        OR
                        (item_id IN ('-1','6','7','8','9','101001','101002','101288','103044','202901','101289','204100','204200'))
                    )
                ) STORED NULL
            ");
        }
    }
};
