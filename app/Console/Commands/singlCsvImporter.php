<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use DateTime;
use SplFileObject;

class SinglCsvImporter extends Command
{
    protected $signature = 'singlCsvImporter 
        {--path= : Path to CSV (defaults to public/onetimecsv.csv)} 
        {--delimiter= : Force delimiter: comma|tab (auto-detect if omitted)}
        {--skip-header=0 : Use 1 if CSV has no header row}';

    protected $description = 'Stream large CSV into order_line (low memory, normalizes date formats)';

    public function handle(): int
    {
        DB::connection()->disableQueryLog();

        $path = $this->option('path') ?: public_path('onetimecsv.csv');
        if (!is_file($path)) {
            $this->error("CSV not found at {$path}");
            return self::FAILURE;
        }

        $f = new SplFileObject($path, 'r');
        $f->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);

        // detect delimiter
        $forced = $this->option('delimiter');
        if ($forced) {
            $delim = ($forced === 'tab') ? "\t" : ',';
        } else {
            $first = $f->fgets();
            $first = $first ? ltrim($first, "\xEF\xBB\xBF") : '';
            $delim = (substr_count($first, "\t") > substr_count($first, ",")) ? "\t" : ",";
            $f->rewind();
        }
        $f->setCsvControl($delim);

        // header
        $hasHeader = !$this->option('skip-header');
        $header = $hasHeader ? $f->fgetcsv() : null;
        if ($hasHeader && (!$header || $header === false)) {
            $this->error('Cannot read header row.');
            return self::FAILURE;
        }

        $map = [
            'Franchise Store'            => 'franchise_store',
            'Business Date'              => 'business_date',
            'Date Time Placed'           => 'date_time_placed',
            'Date Time Fulfilled'        => 'date_time_fulfilled',
            'Net Amount'                 => 'net_amount',
            'Quantity'                   => 'quantity',
            'Royalty Item'               => 'royalty_item',
            'Taxable Item'               => 'taxable_item',
            'Order Id'                   => 'order_id',
            'Item Id'                    => 'item_id',
            'Menu Item Name'             => 'menu_item_name',
            'Menu Item Account'          => 'menu_item_account',
            'Bundle Name'                => 'bundle_name',
            'Employee'                   => 'employee',
            'Override Approval Employee' => 'override_approval_employee',
            'Order Placed Method'        => 'order_placed_method',
            'Order Fulfilled Method'     => 'order_fulfilled_method',
            'Modified Order Amount'      => 'modified_order_amount',
            'Modification Reason'        => 'modification_reason',
            'Payment Methods'            => 'payment_methods',
            'Refunded'                   => 'refunded',
            'Tax Included Amount'        => 'tax_included_amount',
        ];

        // index map
        $indexes = [];
        if ($hasHeader) {
            $header = array_map(fn($h) => is_string($h) ? trim($h) : $h, $header);
            foreach ($map as $csvCol => $dbCol) {
                $idx = array_search($csvCol, $header, true);
                $indexes[$dbCol] = ($idx === false) ? null : $idx;
            }
        } else {
            $i = 0;
            foreach ($map as $csvCol => $dbCol) {
                $indexes[$dbCol] = $i++;
            }
        }

        $inserted = 0;
        $rowCount = 0;

        while (!$f->eof()) {
            $row = $f->fgetcsv();
            if ($row === false || $row === null) continue;
            if (!array_filter($row, fn($v) => $v !== null && $v !== '')) continue;

            $rowCount++;

            $get = function (string $dbCol) use ($row, $indexes) {
                $idx = $indexes[$dbCol];
                if ($idx === null || !isset($row[$idx])) return null;
                $v = trim((string)$row[$idx]);
                return $v === '' ? null : $v;
            };

            // convert to MySQL-friendly formats
            $toDate = function ($v) {
                if (!$v) return null;
                $dt = DateTime::createFromFormat('n/j/Y', $v)
                    ?: DateTime::createFromFormat('Y-m-d', $v)
                    ?: DateTime::createFromFormat('m/d/Y', $v);
                return $dt ? $dt->format('Y-m-d') : $v;
            };
            $toDateTime = function ($v) {
                if (!$v) return null;
                $dt = DateTime::createFromFormat('n/j/Y H:i', $v)
                    ?: DateTime::createFromFormat('n/j/Y H:i:s', $v)
                    ?: DateTime::createFromFormat('Y-m-d H:i', $v)
                    ?: DateTime::createFromFormat('Y-m-d H:i:s', $v);
                return $dt ? $dt->format('Y-m-d H:i:s') : $v;
            };

            $data = [
                'franchise_store'          => $get('franchise_store'),
                'business_date'            => $toDate($get('business_date')),
                'date_time_placed'         => $toDateTime($get('date_time_placed')),
                'date_time_fulfilled'      => $toDateTime($get('date_time_fulfilled')),
                'net_amount'               => $get('net_amount'),
                'quantity'                 => $get('quantity'),
                'royalty_item'             => $get('royalty_item'),
                'taxable_item'             => $get('taxable_item'),
                'order_id'                 => $get('order_id'),
                'item_id'                  => $get('item_id'),
                'menu_item_name'           => $get('menu_item_name'),
                'menu_item_account'        => $get('menu_item_account'),
                'bundle_name'              => $get('bundle_name'),
                'employee'                 => $get('employee'),
                'override_approval_employee' => $get('override_approval_employee'),
                'order_placed_method'      => $get('order_placed_method'),
                'order_fulfilled_method'   => $get('order_fulfilled_method'),
                'modified_order_amount'    => $get('modified_order_amount'),
                'modification_reason'      => $get('modification_reason'),
                'payment_methods'          => $get('payment_methods'),
                'refunded'                 => $get('refunded'),
                'tax_included_amount'      => $get('tax_included_amount'),
                'created_at'               => now(),
                'updated_at'               => now(),
            ];

            if (empty($data['franchise_store']) || empty($data['business_date'])) continue;

            DB::table('order_line')->insert($data);
            $inserted++;

            if (($inserted % 10000) === 0) {
                $this->info("Inserted {$inserted} rows...");
            }
        }

        $this->info("Done. Read {$rowCount} rows, inserted {$inserted}.");
        return self::SUCCESS;
    }
}
