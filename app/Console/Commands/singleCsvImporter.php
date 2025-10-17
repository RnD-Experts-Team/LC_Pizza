<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use SplFileObject;
use DateTime;

class singleCsvImporter extends Command
{
    protected $signature = 'singleCsvImporter
        {--path= : Path to CSV (default: public/onetimecsv.csv)}
        {--delimiter= : Force delimiter: comma|tab (auto-detect if omitted)}
        {--skip-header=0 : Use 1 if CSV has no header row}
        {--encoding=auto : Source text encoding: auto|utf8|cp1252|latin1}';

    protected $description = 'Stream a large CSV into order_line (UTF-8 safe, ISO dates, O(1) memory)';

    public function handle(): int
    {
        // keep memory flat
        DB::connection()->disableQueryLog();
        // ensure MySQL session is utf8mb4
        DB::statement("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

        $path = $this->option('path') ?: public_path('onetimecsv.csv');
        if (!is_file($path)) {
            $this->error("CSV not found at: {$path}");
            return self::FAILURE;
        }

        $file = new SplFileObject($path, 'r');
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);

        // delimiter
        $forcedDelim = $this->option('delimiter');
        if ($forcedDelim) {
            $delimiter = ($forcedDelim === 'tab') ? "\t" : ',';
        } else {
            $firstLine = $file->fgets();
            $firstLine = $firstLine ? ltrim($firstLine, "\xEF\xBB\xBF") : '';
            $delimiter = (substr_count($firstLine, "\t") > substr_count($firstLine, ",")) ? "\t" : ",";
            $file->rewind();
        }
        $file->setCsvControl($delimiter);

        $hasHeader = !$this->option('skip-header');
        $header = $hasHeader ? $file->fgetcsv() : null;
        if ($hasHeader && (!$header || $header === false)) {
            $this->error('Unable to read CSV header.');
            return self::FAILURE;
        }
        if ($header) {
            $header = array_map(fn($h) => is_string($h) ? trim($h) : $h, $header);
        }

        // CSV header -> DB columns
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

        // build index map
        $indexes = [];
        if ($hasHeader) {
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

        $forcedEncoding = (string)($this->option('encoding') ?: 'auto');
        $inserted = 0;
        $rowsSeen = 0;

        while (!$file->eof()) {
            $row = $file->fgetcsv();
            if ($row === false || $row === null) continue;
            if (!array_filter($row, fn($v) => $v !== null && $v !== '')) continue;

            $rowsSeen++;

            // accessor that trims + strict UTF-8 conversion
            $get = function (string $dbCol) use ($row, $indexes, $forcedEncoding) {
                $idx = $indexes[$dbCol];
                if ($idx === null || !array_key_exists($idx, $row)) return null;
                $raw = $row[$idx];
                if ($raw === null) return null;
                $val = is_string($raw) ? trim($raw) : (string)$raw;
                if ($val === '') return null;
                return $this->cleanStrStrict($val, $forcedEncoding);
            };

            $toDate = function ($v) {
                if (!$v) return null;
                $v = trim($v);
                // Try common inputs like 9/1/2025, 09/01/2025, 2025-09-01
                $dt = DateTime::createFromFormat('n/j/Y', $v)
                    ?: DateTime::createFromFormat('m/d/Y', $v)
                    ?: DateTime::createFromFormat('Y-m-d', $v);
                return $dt ? $dt->format('Y-m-d') : $v; // pass-through if already okay
            };
            $toDateTime = function ($v) {
                if (!$v) return null;
                $v = trim($v);
                $dt = DateTime::createFromFormat('n/j/Y H:i', $v)
                    ?: DateTime::createFromFormat('n/j/Y H:i:s', $v)
                    ?: DateTime::createFromFormat('m/d/Y H:i', $v)
                    ?: DateTime::createFromFormat('m/d/Y H:i:s', $v)
                    ?: DateTime::createFromFormat('Y-m-d H:i', $v)
                    ?: DateTime::createFromFormat('Y-m-d H:i:s', $v);
                return $dt ? $dt->format('Y-m-d H:i:s') : $v;
            };

            $data = [
                'franchise_store'            => $get('franchise_store'),
                'business_date'              => $toDate($get('business_date')),
                'date_time_placed'           => $toDateTime($get('date_time_placed')),
                'date_time_fulfilled'        => $toDateTime($get('date_time_fulfilled')),
                'net_amount'                 => $get('net_amount'),
                'quantity'                   => $get('quantity'),
                'royalty_item'               => $get('royalty_item'),
                'taxable_item'               => $get('taxable_item'),
                'order_id'                   => $get('order_id'),
                'item_id'                    => $get('item_id'),
                'menu_item_name'             => $get('menu_item_name'),
                'menu_item_account'          => $get('menu_item_account'),
                'bundle_name'                => $get('bundle_name'),
                'employee'                   => $get('employee'),
                'override_approval_employee' => $get('override_approval_employee'),
                'order_placed_method'        => $get('order_placed_method'),
                'order_fulfilled_method'     => $get('order_fulfilled_method'),
                'modified_order_amount'      => $get('modified_order_amount'),
                'modification_reason'        => $get('modification_reason'),
                'payment_methods'            => $get('payment_methods'),
                'refunded'                   => $get('refunded'),
                'tax_included_amount'        => $get('tax_included_amount'),
                'created_at'                 => now(),
                'updated_at'                 => now(),
            ];

            // required columns per schema
            if (empty($data['franchise_store']) || empty($data['business_date'])) {
                continue;
            }

            // If menu_item_name existed but failed to decode -> skip to avoid corruption
            if (($indexes['menu_item_name'] !== null)
                && isset($row[$indexes['menu_item_name']])
                && trim((string)$row[$indexes['menu_item_name']]) !== ''
                && $data['menu_item_name'] === null) {
                continue;
            }

            DB::table('order_line')->insert($data);
            $inserted++;

            if (($inserted % 10000) === 0) {
                $this->info("Inserted {$inserted} rows...");
            }
        }

        $this->info("Done. Read {$rowsSeen} rows, inserted {$inserted}.");
        return self::SUCCESS;
    }

    /**
     * Strictly convert to UTF-8 so marks like ® survive exactly.
     * If conversion fails, return null so the caller can skip the row.
     */
    private function cleanStrStrict(?string $v, string $forced = 'auto'): ?string
    {
        if ($v === null) return null;
        $v = trim($v);
        if ($v === '') return null;

        // already valid UTF-8? keep bytes as-is
        if (mb_detect_encoding($v, 'UTF-8', true)) {
            return $v;
        }

        $src = match (strtolower($forced)) {
            'utf8'   => 'UTF-8',
            'cp1252' => 'Windows-1252',
            'latin1' => 'ISO-8859-1',
            default  => (mb_detect_encoding($v, ['Windows-1252','ISO-8859-1','ISO-8859-15'], true) ?: 'Windows-1252'),
        };

        $u = @mb_convert_encoding($v, 'UTF-8', $src);
        if ($u === false || !mb_detect_encoding($u, 'UTF-8', true)) {
            return null; // undecodable; let caller decide (we skip row)
        }
        return $u;
    }
}
