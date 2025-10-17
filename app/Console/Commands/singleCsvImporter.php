<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use SplFileObject;
use DateTime;

class SingleCsvImporter extends Command
{
    protected $signature = 'singleCsvImporter
        {--path= : Path to CSV (default: public/onetimecsv.csv)}
        {--delimiter= : Force delimiter: comma|tab (auto-detect if omitted)}
        {--skip-header=0 : Use 1 if CSV has no header row}
        {--encoding=auto : Source encoding: auto|utf8|cp1252|latin1}
        {--chunk=1000 : Insert chunk size per partition}';

    protected $description = 'Stream a large CSV into order_line (UTF-8 safe, ISO dates, partitioned delete+insert, O(1) memory)';

    public function handle(): int
    {
        // Keep memory flat and ensure MySQL session uses utf8mb4
        DB::connection()->disableQueryLog();
        DB::statement("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

        $csvPath = $this->option('path') ?: public_path('onetimecsv.csv');
        if (!is_file($csvPath)) {
            $this->error("CSV not found at: {$csvPath}");
            return self::FAILURE;
        }

        $chunkSize = max(1, (int)($this->option('chunk') ?: 1000));
        $forcedEncoding = (string)($this->option('encoding') ?: 'auto');

        // ---- Pass 1: stream CSV -> spill rows into temp files per (store|date) partition ----
        $partDir = storage_path('app/csv_partitions');
        if (!is_dir($partDir)) {
            @mkdir($partDir, 0777, true);
        } else {
            // clean previous run
            foreach (glob($partDir . DIRECTORY_SEPARATOR . '*.jsonl') as $f) {
                @unlink($f);
            }
        }

        $file = new SplFileObject($csvPath, 'r');
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);

        // delimiter detection
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

        // CSV header -> DB columns map
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

        // Build header index map
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

        $rowsSeen = 0;
        $partitions = []; // track which partition files were created

        // Helpers
        $getStr = function (array $row, string $dbCol) use ($indexes, $forcedEncoding) {
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
            $dt = DateTime::createFromFormat('n/j/Y', $v)
                ?: DateTime::createFromFormat('m/d/Y', $v)
                ?: DateTime::createFromFormat('Y-m-d', $v);
            return $dt ? $dt->format('Y-m-d') : $v;
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

        // PASS 1: write JSONL rows to partition files
        while (!$file->eof()) {
            $row = $file->fgetcsv();
            if ($row === false || $row === null) continue;
            if (!array_filter($row, fn($v) => $v !== null && $v !== '')) continue;

            $rowsSeen++;

            $franchise = $getStr($row, 'franchise_store');
            $bizDate   = $toDate($getStr($row, 'business_date'));

            // required fields
            if (empty($franchise) || empty($bizDate)) {
                continue;
            }

            // Decode menu_item_name strictly; if undecodable but present, skip row
            $origName = $indexes['menu_item_name'] !== null ? ($row[$indexes['menu_item_name']] ?? null) : null;
            $name = $getStr($row, 'menu_item_name');
            if ($origName !== null && trim((string)$origName) !== '' && $name === null) {
                // undecodable text -> skip to avoid corruption
                continue;
            }

            $record = [
                'franchise_store'            => $franchise,
                'business_date'              => $bizDate,
                'date_time_placed'           => $toDateTime($getStr($row, 'date_time_placed')),
                'date_time_fulfilled'        => $toDateTime($getStr($row, 'date_time_fulfilled')),
                'net_amount'                 => $getStr($row, 'net_amount'),
                'quantity'                   => $getStr($row, 'quantity'),
                'royalty_item'               => $getStr($row, 'royalty_item'),
                'taxable_item'               => $getStr($row, 'taxable_item'),
                'order_id'                   => $getStr($row, 'order_id'),
                'item_id'                    => $getStr($row, 'item_id'),
                'menu_item_name'             => $name,
                'menu_item_account'          => $getStr($row, 'menu_item_account'),
                'bundle_name'                => $getStr($row, 'bundle_name'),
                'employee'                   => $getStr($row, 'employee'),
                'override_approval_employee' => $getStr($row, 'override_approval_employee'),
                'order_placed_method'        => $getStr($row, 'order_placed_method'),
                'order_fulfilled_method'     => $getStr($row, 'order_fulfilled_method'),
                'modified_order_amount'      => $getStr($row, 'modified_order_amount'),
                'modification_reason'        => $getStr($row, 'modification_reason'),
                'payment_methods'            => $getStr($row, 'payment_methods'),
                'refunded'                   => $getStr($row, 'refunded'),
                'tax_included_amount'        => $getStr($row, 'tax_included_amount'),
            ];

            $key = $franchise . '|' . $bizDate;
            $fileName = $this->partitionPath($partDir, $franchise, $bizDate);
            $json = json_encode($record, JSON_UNESCAPED_UNICODE);
            // append line (keeps memory low; opens/closes file per write)
            file_put_contents($fileName, $json . PHP_EOL, FILE_APPEND | LOCK_EX);
            $partitions[$key] = $fileName;

            if (($rowsSeen % 100000) === 0) {
                $this->info("Pass 1: processed {$rowsSeen} rows...");
            }
        }

        $this->info("Pass 1 complete. Partitions found: " . count($partitions));

        // ---- Pass 2: for each partition -> transaction: delete then bulk insert in chunks ----
        $totalInserted = 0;
        foreach ($partitions as $key => $path) {
            // Derive store/date from filename or read first line
            [$store, $date] = $this->parsePartitionFromPath($path);

            DB::transaction(function () use ($path, $store, $date, $chunkSize, &$totalInserted) {
                // fast, indexed delete of the partition
                DB::table('order_line')
                    ->where('franchise_store', $store)
                    ->where('business_date', $date)
                    ->delete();

                // stream file and insert in chunks
                $sf = new SplFileObject($path, 'r');
                $batch = [];
                $now = now();

                while (!$sf->eof()) {
                    $line = $sf->fgets();
                    if ($line === false || trim($line) === '') continue;
                    $r = json_decode($line, true);
                    if (!is_array($r)) continue;

                    $r['created_at'] = $now;
                    $r['updated_at'] = $now;

                    $batch[] = $r;
                    if (count($batch) >= $chunkSize) {
                        DB::table('order_line')->insert($batch);
                        $totalInserted += count($batch);
                        $batch = [];
                    }
                }

                if (!empty($batch)) {
                    DB::table('order_line')->insert($batch);
                    $totalInserted += count($batch);
                }
            });

            // remove partition file to keep disk tidy
            @unlink($path);
            $this->info("Partition {$store} | {$date} done.");
        }

        $this->info("Import complete. Read {$rowsSeen} rows, inserted {$totalInserted}.");
        return self::SUCCESS;
    }

    // --- helpers ---

    private function partitionPath(string $dir, string $store, string $date): string
    {
        // sanitize for filesystem
        $safeStore = preg_replace('/[^A-Za-z0-9._-]/', '_', $store);
        $safeDate  = preg_replace('/[^0-9-]/', '_', $date);
        return $dir . DIRECTORY_SEPARATOR . "{$safeStore}__{$safeDate}.jsonl";
    }

    private function parsePartitionFromPath(string $path): array
    {
        $base = basename($path, '.jsonl');
        $parts = explode('__', $base, 2);
        $store = $parts[0] ?? '';
        $date  = $parts[1] ?? '';
        // reverse the sanitization just a bit (store/date characters already safe for queries)
        return [$store, $date];
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
            return null; // undecodable; caller should skip row
        }
        return $u;
    }
}
