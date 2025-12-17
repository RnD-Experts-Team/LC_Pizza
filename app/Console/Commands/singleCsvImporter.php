<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use SplFileObject;
use DateTime;

class SingleCsvImporter extends Command
{
    protected $signature = 'csv:one-time-import
    {--model= : Target table. One of: order_line, detail_orders, alta_cogs, alta_waste, alta_usage, alta_purchase}
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

        $this->cfg = [
            // ===== order_line =====
            'order_line' => [
                'table'       => 'order_line',
                'required'    => ['franchise_store', 'business_date'],
                'partitionBy' => ['franchise_store', 'business_date'],
                'allowed'     => [
                    'franchise_store','business_date','date_time_placed','date_time_fulfilled','net_amount',
                    'quantity','royalty_item','taxable_item','order_id','item_id','menu_item_name','menu_item_account',
                    'bundle_name','employee','override_approval_employee','order_placed_method','order_fulfilled_method',
                    'modified_order_amount','modification_reason','payment_methods','refunded','tax_included_amount',
                ],
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
                'strictField' => 'menu_item_name',
            ],

            // ===== detail_orders =====
            'detail_orders' => [
                'table'       => 'detail_orders',
                'required'    => ['franchise_store', 'business_date'],
                'partitionBy' => ['franchise_store', 'business_date'],
                'allowed'     => [
                    'franchise_store','business_date','date_time_placed','date_time_fulfilled','royalty_obligation',
                    'quantity','customer_count','order_id','taxable_amount','non_taxable_amount','tax_exempt_amount',
                    'non_royalty_amount','sales_tax','employee','gross_sales','occupational_tax','override_approval_employee',
                    'order_placed_method','delivery_tip','delivery_tip_tax','order_fulfilled_method','delivery_fee',
                    'modified_order_amount','delivery_fee_tax','modification_reason','payment_methods','delivery_service_fee',
                    'delivery_service_fee_tax','refunded','delivery_small_order_fee','delivery_small_order_fee_tax',
                    'transaction_type','store_tip_amount','promise_date','tax_exemption_id','tax_exemption_entity_name',
                    'user_id','hnrOrder','broken_promise','portal_eligible','portal_used','put_into_portal_before_promise_time',
                    'portal_compartments_used','time_loaded_into_portal',
                ],
                // keep business_date as DATE only
                'dateCols'    => ['business_date' => true],
                // parse promise_date as DATETIME to match the service transformer
                'datetimeCols'=> [
                    'date_time_placed'        => true,
                    'date_time_fulfilled'     => true,
                    'time_loaded_into_portal' => true,
                    'promise_date'            => true, // <-- moved here
                ],
                'headerMap'   => [
                    'FranchiseStore'                 => 'franchise_store',
                    'BusinessDate'                   => 'business_date',
                    'DateTimePlaced'                 => 'date_time_placed',
                    'DateTimeFulfilled'              => 'date_time_fulfilled',
                    'RoyaltyObligation'              => 'royalty_obligation',
                    'Quantity'                       => 'quantity',
                    'CustomerCount'                  => 'customer_count',
                    'OrderId'                        => 'order_id',
                    'TaxableAmount'                  => 'taxable_amount',
                    'NonTaxableAmount'               => 'non_taxable_amount',
                    'TaxExemptAmount'                => 'tax_exempt_amount',
                    'NonRoyaltyAmount'               => 'non_royalty_amount',
                    'SalesTax'                       => 'sales_tax',
                    'Employee'                       => 'employee',
                    'GrossSales'                     => 'gross_sales',
                    'OccupationalTax'                => 'occupational_tax',
                    'OverrideApprovalEmployee'       => 'override_approval_employee',
                    'OrderPlacedMethod'              => 'order_placed_method',
                    'DeliveryTip'                    => 'delivery_tip',
                    'DeliveryTipTax'                 => 'delivery_tip_tax',
                    'OrderFulfilledMethod'           => 'order_fulfilled_method',
                    'DeliveryFee'                    => 'delivery_fee',
                    'ModifiedOrderAmount'            => 'modified_order_amount',
                    'DeliveryFeeTax'                 => 'delivery_fee_tax',
                    'ModificationReason'             => 'modification_reason',
                    'PaymentMethods'                 => 'payment_methods',
                    'DeliveryServiceFee'             => 'delivery_service_fee',
                    'DeliveryServiceFeeTax'          => 'delivery_service_fee_tax',
                    'Refunded'                       => 'refunded',
                    'DeliverySmallOrderFee'          => 'delivery_small_order_fee',
                    'DeliverySmallOrderFeeTax'       => 'delivery_small_order_fee_tax',
                    'TransactionType'                => 'transaction_type',
                    'StoreTipAmount'                 => 'store_tip_amount',

                    // map BOTH headers to the SAME db column; DateTimePromised is preferred
                    'DateTimePromised'               => 'promise_date',       // preferred source (e.g. 9/9/2025 16:03)

                    'TaxExemptionId'                 => 'tax_exemption_id',
                    'TaxExemptionEntityName'         => 'tax_exemption_entity_name',
                    'UserId'                         => 'user_id',

                    // keep as ignored (not in allowed[])
                    'hnrOrder'                       => 'hnrOrder',
                    'BrokenPromise'                  => 'broken_promise',
                    'PortalEligible'                 => 'portal_eligible',
                    'PortalUsed'                     => 'portal_used',
                    'TimeLoadedIntoPortal'           => 'time_loaded_into_portal',
                    'PutIntoPortalBeforePromiseTime' => 'put_into_portal_before_promise_time',
                    'PortalCompartmentsUsed'         => 'portal_compartments_used',
                ],
                'strictField' => null,
            ],

            // ===== alta_cogs =====
'alta_cogs' => [
    'table'       => 'alta_inventory_cogs',
    'required'    => ['franchise_store', 'business_date'],
    'partitionBy' => ['franchise_store', 'business_date'],
    'allowed'     => [
        'franchise_store','business_date','count_period','inventory_category',
        'starting_value','received_value','net_transfer_value','ending_value',
        'used_value','theoretical_usage_value','variance_value',
    ],
    'dateCols'    => ['business_date' => true],
    'datetimeCols'=> [],
    'headerMap'   => [
        'FranchiseStore'        => 'franchise_store',
        'BusinessDate'          => 'business_date',
        'CountPeriod'           => 'count_period',
        'InventoryCategory'     => 'inventory_category',
        'StartingValue'         => 'starting_value',
        'ReceivedValue'         => 'received_value',
        'NetTransferValue'      => 'net_transfer_value',
        'EndingValue'           => 'ending_value',
        'UsedValue'             => 'used_value',
        'TheoreticalUsageValue' => 'theoretical_usage_value',
        'VarianceValue'         => 'variance_value',
    ],
    'strictField' => null,
],

// ===== alta_waste =====
'alta_waste' => [
    'table'       => 'alta_inventory_waste',
    'required'    => ['franchise_store', 'business_date'],
    'partitionBy' => ['franchise_store', 'business_date'],
    'allowed'     => [
        'franchise_store','business_date','item_id','item_description',
        'waste_reason','unit_food_cost','qty',
    ],
    'dateCols'    => ['business_date' => true],
    'datetimeCols'=> [],
    'headerMap'   => [
        'FranchiseStore'   => 'franchise_store',
        'BusinessDate'     => 'business_date',
        'ItemId'           => 'item_id',
        'ItemDescription'  => 'item_description',
        'WasteReason'      => 'waste_reason',
        'UnitFoodCost'     => 'unit_food_cost',
        'Qty'              => 'qty',
    ],
    'strictField' => null,
],

// ===== alta_usage =====
'alta_usage' => [
    'table'       => 'alta_inventory_ingredient_usage',
    'required'    => ['franchise_store', 'business_date'],
    'partitionBy' => ['franchise_store', 'business_date'],
    'allowed'     => [
        'franchise_store','business_date','count_period','ingredient_id',
        'ingredient_description','ingredient_category','ingredient_unit',
        'ingredient_unit_cost','starting_inventory_qty','received_qty',
        'net_transferred_qty','ending_inventory_qty','actual_usage',
        'theoretical_usage','variance_qty','waste_qty',
    ],
    'dateCols'    => ['business_date' => true],
    'datetimeCols'=> [],
    'headerMap'   => [
        'FranchiseStore'        => 'franchise_store',
        'BusinessDate'          => 'business_date',
        'CountPeriod'           => 'count_period',
        'IngredientId'          => 'ingredient_id',
        'IngredientDescription' => 'ingredient_description',
        'IngredientCategory'    => 'ingredient_category',
        'IngredientUnit'        => 'ingredient_unit',
        'IngredientUnitCost'    => 'ingredient_unit_cost',
        'StartingInventoryQty'  => 'starting_inventory_qty',
        'ReceivedQty'           => 'received_qty',
        'NetTransferredQty'     => 'net_transferred_qty',
        'EndingInventoryQty'    => 'ending_inventory_qty',
        'ActualUsage'           => 'actual_usage',
        'TheoreticalUsage'      => 'theoretical_usage',
        'VarianceQty'           => 'variance_qty',
        'WasteQty'              => 'waste_qty',
    ],
    'strictField' => null,
],

// ===== alta_purchase =====
'alta_purchase' => [
    'table'       => 'alta_inventory_ingredient_orders',
    'required'    => ['franchise_store', 'business_date'],
    'partitionBy' => ['franchise_store', 'business_date'],
    'allowed'     => [
        'franchise_store','business_date','supplier','invoice_number',
        'purchase_order_number','ingredient_id','ingredient_description',
        'ingredient_category','ingredient_unit','unit_price','order_qty',
        'sent_qty','received_qty','total_cost',
    ],
    'dateCols'    => ['business_date' => true],
    'datetimeCols'=> [],
    'headerMap'   => [
        'FranchiseStore'        => 'franchise_store',
        'BusinessDate'          => 'business_date',
        'Supplier'              => 'supplier',
        'InvoiceNumber'         => 'invoice_number',
        'PurchaseOrderNumber'   => 'purchase_order_number',
        'IngredientId'          => 'ingredient_id',
        'IngredientDescription' => 'ingredient_description',
        'IngredientCategory'    => 'ingredient_category',
        'IngredientUnit'        => 'ingredient_unit',
        'UnitPrice'             => 'unit_price',
        'OrderQty'              => 'order_qty',
        'SentQty'               => 'sent_qty',
        'ReceivedQty'           => 'received_qty',
        'TotalCost'             => 'total_cost',
    ],
    'strictField' => null,
],
        ];
    }

    public function handle(): int
    {
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
        $this->info("CSV path: {$csvPath}");

        $chunkSize      = max(1, (int)($this->option('chunk') ?: 1000));
        $forcedEncoding = (string)($this->option('encoding') ?: 'auto');
        $hasHeader      = !$this->option('skip-header');

        // fresh partitions dir
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

        // ---- header ----
        $header = $hasHeader ? $file->fgetcsv() : null;
        if ($hasHeader && (!$header || $header === false)) {
            $this->error('Unable to read CSV header.');
            return self::FAILURE;
        }
        if ($header) {
            $header = array_map(fn($h) => is_string($h) ? trim($h) : $h, $header);
            if (isset($header[0]) && is_string($header[0])) {
                $header[0] = ltrim($header[0], "\xEF\xBB\xBF");
            }
        }

        // normalize headers
        $normalize = function (?string $s): string {
            $s = (string)$s;
            $s = trim($s);
            $s = preg_replace('/[^A-Za-z0-9]/', '', $s);
            return strtolower($s);
        };

        // map normalized header => index
        $indexByNorm = [];
        if ($hasHeader) {
            foreach ($header as $i => $h) {
                $indexByNorm[$normalize((string)$h)] = $i;
            }
        }

        // build indexes (do not overwrite a non-null index; lets DateTimePromised win but keeps PromiseDate as fallback)
        $indexes = [];
        if ($hasHeader) {
            foreach ($cfg['headerMap'] as $csvCol => $dbCol) {
                $norm = $normalize($csvCol);
                $idx  = $indexByNorm[$norm] ?? null;

                if (!array_key_exists($dbCol, $indexes) || $indexes[$dbCol] === null) {
                    $indexes[$dbCol] = $idx;
                }
            }
        } else {
            $i = 0;
            foreach ($cfg['headerMap'] as $_ => $dbCol) {
                if (!array_key_exists($dbCol, $indexes)) {
                    $indexes[$dbCol] = $i++;
                }
            }
        }

        // ensure required partition headers exist
        [$storeCol, $dateCol] = $cfg['partitionBy'];
        if (($indexes[$storeCol] ?? null) === null || ($indexes[$dateCol] ?? null) === null) {
            $this->error('Required headers for partitioning not found.');
            $this->line('Seen headers: ' . implode(' | ', array_map(fn($h) => (string)$h, $header ?? [])));
            $this->line("Expected something like: {$storeCol}, {$dateCol}");
            return self::FAILURE;
        }

        $rowsSeen   = 0;
        $partitions = [];
        $allowedSet = array_flip($cfg['allowed']);

        // helpers
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

        // PASS 1
        $file->rewind();
        if ($hasHeader) { $file->fgetcsv(); } // skip header row we already read
        while (!$file->eof()) {
            $row = $file->fgetcsv();
            if ($row === false || $row === null) continue;
            if (!array_filter($row, fn($v) => $v !== null && $v !== '')) continue;

            $rowsSeen++;

            // build record from headerMap
            $record = [];
            foreach ($cfg['headerMap'] as $_csv => $dbCol) {
                $record[$dbCol] = $getStr($row, $dbCol);
            }

            // normalize dates/datetimes
            foreach (array_keys($cfg['dateCols'] ?? []) as $dbCol) {
                if (array_key_exists($dbCol, $record)) {
                    $record[$dbCol] = $toDate($record[$dbCol]);
                }
            }
            foreach (array_keys($cfg['datetimeCols'] ?? []) as $dbCol) {
                if (array_key_exists($dbCol, $record)) {
                    $record[$dbCol] = $toDateTime($record[$dbCol]);
                }
            }

            // filter to allowed columns ONLY (drops anything not in allowed[])
            $record = array_intersect_key($record, $allowedSet);

            // required checks
            foreach ($cfg['required'] as $req) {
                if (empty($record[$req])) continue 2;
            }

            // undecodable guard for order_line free-text
            if (!empty($cfg['strictField'])) {
                $sf  = $cfg['strictField'];
                $idx = $indexes[$sf] ?? null;
                if ($idx !== null) {
                    $orig = $row[$idx];
                    if ($orig !== null && trim((string)$orig) !== '' && ($record[$sf] ?? null) === null) {
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
                $this->info(sprintf(
                    "  • %s rows streamed (%.1fs)",
                    number_format($rowsSeen),
                    microtime(true) - $t0
                ));
            }
        }

        $this->info(sprintf(
            "Pass 1 complete: %s rows, %s partitions.",
            number_format($rowsSeen),
            number_format(count($partitions))
        ));

        // PASS 2
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
            $this->info(sprintf(
                "  [%d/%d] %s | %s done (total inserted: %s)",
                $i, $n, $store, $date, number_format($totalInserted)
            ));
        }

        $this->info(sprintf(
            "ALL DONE (model=%s). Read %s rows, inserted %s.",
            $modelKey, number_format($rowsSeen), number_format($totalInserted)
        ));
        return self::SUCCESS;
    }

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
