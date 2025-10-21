<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use SplFileObject;
use DateTime;

class SingleCsvImporter extends Command
{
    protected $signature = 'csv:one-time-import
        {--model= : Target table. One of: order_line, detail_orders}
        {--path= : Path to CSV (default: public/onetimecsv.csv)}
        {--delimiter= : Force delimiter: comma|tab (auto-detect if omitted)}
        {--skip-header=0 : Use 1 if CSV has no header row}
        {--encoding=auto : Source encoding: auto|utf8|cp1252|latin1}
        {--chunk=1000 : Insert chunk size per partition}';

    protected $description = 'ONE-TIME loader for huge CSVs. Streams -> partitions by (store|date) -> per-partition delete+insert.';

    /** @var array<string,array> */
    private array $cfg;

    public function __construct()
    {
        parent::__construct();

        // configure per target model/table without modifying your models or services
        $this->cfg = [
            // --- order_line: same mapping as your current command ---
            'order_line' => [
                'table'       => 'order_line',
                'required'    => ['franchise_store', 'business_date'],
                'partitionBy' => ['franchise_store', 'business_date'],
                'dateCols'    => ['business_date' => true],
                'datetimeCols'=> ['date_time_placed' => true, 'date_time_fulfilled' => true],
                'headerMap'   => [
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
                ],
                // If a non-empty original menu_item_name is undecodable -> skip row
                'strictField' => 'menu_item_name',
            ],

            // --- detail_orders: maps exactly the headers you provided (PascalCase, no spaces) ---
            'detail_orders' => [
                'table'       => 'detail_orders',
                'required'    => ['franchise_store', 'business_date'],
                'partitionBy' => ['franchise_store', 'business_date'],
                'dateCols'    => ['business_date' => true, 'promise_date' => true],
                'datetimeCols'=> [
                    'date_time_placed'        => true,
                    'date_time_fulfilled'     => true,
                    'time_loaded_into_portal' => true,
                    'date_time_promised'      => true, // in CSV; safe to keep even if nullable/absent in DB
                ],
                'headerMap'   => [
                    'FranchiseStore'               => 'franchise_store',
                    'BusinessDate'                 => 'business_date',
                    'DateTimePlaced'               => 'date_time_placed',
                    'DateTimeFulfilled'            => 'date_time_fulfilled',
                    'RoyaltyObligation'            => 'royalty_obligation',
                    'Quantity'                     => 'quantity',
                    'CustomerCount'                => 'customer_count',
                    'OrderId'                      => 'order_id',
                    'TaxableAmount'                => 'taxable_amount',
                    'NonTaxableAmount'             => 'non_taxable_amount',
                    'TaxExemptAmount'              => 'tax_exempt_amount',
                    'NonRoyaltyAmount'             => 'non_royalty_amount',
                    'SalesTax'                     => 'sales_tax',
                    'Employee'                     => 'employee',
                    'GrossSales'                   => 'gross_sales',
                    'OccupationalTax'              => 'occupational_tax',
                    'OverrideApprovalEmployee'     => 'override_approval_employee',
                    'OrderPlacedMethod'            => 'order_placed_method',
                    'DeliveryTip'                  => 'delivery_tip',
                    'DeliveryTipTax'               => 'delivery_tip_tax',
                    'OrderFulfilledMethod'         => 'order_fulfilled_method',
                    'DeliveryFee'                  => 'delivery_fee',
                    'ModifiedOrderAmount'          => 'modified_order_amount',
                    'DeliveryFeeTax'               => 'delivery_fee_tax',
                    'ModificationReason'           => 'modification_reason',
                    'PaymentMethods'               => 'payment_methods',
                    'DeliveryServiceFee'           => 'delivery_service_fee',
                    'DeliveryServiceFeeTax'        => 'delivery_service_fee_tax',
                    'Refunded'                     => 'refunded',
                    'DeliverySmallOrderFee'        => 'delivery_small_order_fee',
                    'DeliverySmallOrderFeeTax'     => 'delivery_small_order_fee_tax',
                    'TransactionType'              => 'transaction_type',
                    'StoreTipAmount'               => 'store_tip_amount',
                    'PromiseDate'                  => 'promise_date',
                    'TaxExemptionId'               => 'tax_exemption_id',
                    'TaxExemptionEntityName'       => 'tax_exemption_entity_name',
                    'UserId'                       => 'user_id',
                    'DateTimePromised'             => 'date_time_promised',
                    'hnrOrder'                     => 'hnrOrder',
                    'BrokenPromise'                => 'broken_promise',
                    'PortalEligible'               => 'portal_eligible',
                    'PortalUsed'                   => 'portal_used',
                    'TimeLoadedIntoPortal'         => 'time_loaded_into_portal',
                    'PutIntoPortalBeforePromiseTime'=> 'put_into_portal_before_promise_time',
                    'PortalCompartmentsUsed'       => 'portal_compartments_used',
                ],
                'strictField' => null,
            ],
        ];
    }

    public function handle(): int
    {
        // keep memory flat & proper charset for MySQL
        DB::connection()->disableQueryLog();
        DB::statement("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

        $modelKey = strtolower((string)$this->option('model'));
        if (!$modelKey || !isset($this->cfg[$modelKey])) {
            $this->error('Please pass --model=order_line or --model=detail_orders');
            return self::FAILURE;
        }
        $cfg = $this->cfg[$modelKey];

        $csvPath = $this->option('path') ?: public_path('onetimecsv.csv');
        if (!is_file($csvPath)) {
            $this->error("CSV not found at: {$csvPath}");
            return self::FAILURE;
        }

        $chunkSize      = max(1, (int)($this->option('chunk') ?: 1000));
        $forcedEncoding = (string)($this->option('encoding') ?: 'auto');
        $hasHeader      = !$this->option('skip-header');

        // partitions dir (fresh every run)
        $partDir = storage_path('app/csv_partitions');
        if (!is_dir($partDir)) {
            @mkdir($partDir, 0777, true);
        } else {
            foreach (glob($partDir . DIRECTORY_SEPARATOR . '*.jsonl') as $f) {
                @unlink($f);
            }
        }

        // open + detect delimiter
        $file = new SplFileObject($csvPath, 'r');
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);

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

        // header
        $header = $hasHeader ? $file->fgetcsv() : null;
        if ($hasHeader && (!$header || $header === false)) {
            $this->error('Unable to read CSV header.');
            return self::FAILURE;
        }
        if ($header) {
            $header = array_map(fn($h) => is_string($h) ? trim($h) : $h, $header);
        }

        // header index map
        $indexes = [];
        if ($hasHeader) {
            foreach ($cfg['headerMap'] as $csvCol => $dbCol) {
                $idx = array_search($csvCol, $header, true);
                $indexes[$dbCol] = ($idx === false) ? null : $idx;
            }
        } else {
            $i = 0;
            foreach ($cfg['headerMap'] as $_ => $dbCol) {
                $indexes[$dbCol] = $i++;
            }
        }

        [$storeCol, $dateCol] = $cfg['partitionBy'];
        $rowsSeen   = 0;
        $partitions = [];

        $getStr = function (array $row, string $dbCol) use ($indexes, $forcedEncoding) {
            $idx = $indexes[$dbCol] ?? null;
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

        $this->info("Pass 1/2 (model={$modelKey}): streaming & partitioning…");
        $t0 = microtime(true);

        // PASS 1: stream -> partitions
        while (!$file->eof()) {
            $row = $file->fgetcsv();
            if ($row === false || $row === null) continue;
            if (!array_filter($row, fn($v) => $v !== null && $v !== '')) continue;

            $rowsSeen++;

            $record = [];
            foreach ($cfg['headerMap'] as $_csv => $dbCol) {
                $record[$dbCol] = $getStr($row, $dbCol);
            }
            foreach (array_keys($cfg['dateCols'] ?? []) as $dbCol) {
                if (array_key_exists($dbCol, $record)) $record[$dbCol] = $toDate($record[$dbCol]);
            }
            foreach (array_keys($cfg['datetimeCols'] ?? []) as $dbCol) {
                if (array_key_exists($dbCol, $record)) $record[$dbCol] = $toDateTime($record[$dbCol]);
            }

            // required
            foreach ($cfg['required'] as $req) {
                if (empty($record[$req])) continue 2;
            }

            // undecodable guard for noisy free-text (order_line only)
            if ($cfg['strictField']) {
                $sf = $cfg['strictField'];
                $idx = $indexes[$sf] ?? null;
                if ($idx !== null) {
                    $orig = $row[$idx];
                    if ($orig !== null && trim((string)$orig) !== '' && $record[$sf] === null) {
                        continue; // skip undecodable row
                    }
                }
            }

            $store = (string)($record[$storeCol] ?? '');
            $date  = (string)($record[$dateCol]  ?? '');

            $json = json_encode($record, JSON_UNESCAPED_UNICODE);
            $fileName = $this->partitionPath($partDir, $store, $date);
            file_put_contents($fileName, $json . PHP_EOL, FILE_APPEND | LOCK_EX);
            $partitions[$store.'|'.$date] = $fileName;

            if (($rowsSeen % 100000) === 0) {
                $this->info(sprintf("  • %,d rows streamed (%.1fs)", $rowsSeen, microtime(true)-$t0));
            }
        }

        $this->info(sprintf("Pass 1 complete: %,d rows, %,d partitions.", $rowsSeen, count($partitions)));

        // PASS 2: per-partition delete+insert
        $this->info("Pass 2/2: deleting & inserting per partition…");
        $totalInserted = 0;
        $i=0; $n = count($partitions);

        foreach ($partitions as $key => $path) {
            $i++;
            [$store, $date] = $this->parsePartitionFromPath($path);

            DB::transaction(function () use ($cfg, $path, $store, $date, $chunkSize, &$totalInserted) {
                DB::table($cfg['table'])
                    ->where('franchise_store', $store)
                    ->where('business_date', $date)
                    ->delete();

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
                        DB::table($cfg['table'])->insert($batch);
                        $totalInserted += count($batch);
                        $batch = [];
                    }
                }
                if (!empty($batch)) {
                    DB::table($cfg['table'])->insert($batch);
                    $totalInserted += count($batch);
                }
            });

            @unlink($path);
            $this->info(sprintf("  [%d/%d] %s | %s done (total inserted: %,d)", $i, $n, $store, $date, $totalInserted));
        }

        $this->info(sprintf("ALL DONE (model=%s). Read %,d rows, inserted %,d.", $modelKey, $rowsSeen, $totalInserted));
        return self::SUCCESS;
    }

    // --- helpers ---

    private function partitionPath(string $dir, string $store, string $date): string
    {
        $safeStore = preg_replace('/[^A-Za-z0-9._-]/', '_', $store);
        $safeDate  = preg_replace('/[^0-9-]/', '_', $date);
        return $dir . DIRECTORY_SEPARATOR . "{$safeStore}__{$safeDate}.jsonl";
    }

    private function parsePartitionFromPath(string $path): array
    {
        $base = basename($path, '.jsonl');
        $parts = explode('__', $base, 2);
        return [$parts[0] ?? '', $parts[1] ?? ''];
    }

    /**
     * Strict UTF-8 conversion; return null if undecodable.
     */
    private function cleanStrStrict(?string $v, string $forced = 'auto'): ?string
    {
        if ($v === null) return null;
        $v = trim($v);
        if ($v === '') return null;

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
            return null;
        }
        return $u;
    }
}
