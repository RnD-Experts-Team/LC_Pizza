<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Helper\CSVs\ProcessCsvServices;
use App\Services\Helper\Insert\InsertDataServices;

class ImportOneTimeOrderLines extends Command
{
    // Run: php artisan orders:import-onetime
    protected $signature = 'orders:import-onetime
        {--path= : Absolute/relative path to the CSV (defaults to public/onetimecsv.csv)}
        {--batch=1000 : Number of rows per DB batch}
        {--delimiter= : Force delimiter ("," or "\t"); auto-detect if omitted}';

    protected $description = 'Stream-import order lines from public/onetimecsv.csv (or --path) in memory-safe chunks.';

    public function handle(ProcessCsvServices $processor, InsertDataServices $inserter): int
    {
        $path = $this->option('path') ?: public_path('onetimecsv.csv');
        $batchSize = (int) $this->option('batch') ?: 1000;

        if (! file_exists($path)) {
            $this->error("CSV not found at: {$path}");
            return self::FAILURE;
        }

        $delimiter = $this->option('delimiter') ?? $this->detectDelimiter($path);
        if (!in_array($delimiter, [",", "\t"], true)) {
            $this->error('Unsupported delimiter. Use "," or "\t".');
            return self::FAILURE;
        }

        $this->info("Streaming import from: {$path}");
        $this->line("Delimiter: " . ($delimiter === "\t" ? 'TAB' : 'COMMA'));
        $this->line("Batch size: {$batchSize}");

        // --- same column map as processOrderLine() ---
        $columnMap = [
            'franchise_store' => 'franchisestore',
            'business_date' => 'businessdate',
            'date_time_placed' => 'datetimeplaced',
            'date_time_fulfilled' => 'datetimefulfilled',
            'net_amount' => 'netamount',
            'quantity' => 'quantity',
            'royalty_item' => 'royaltyitem',
            'taxable_item' => 'taxableitem',
            'order_id' => 'orderid',
            'item_id' => 'itemid',
            'menu_item_name' => 'menuitemname',
            'menu_item_account' => 'menuitemaccount',
            'bundle_name' => 'bundlename',
            'employee' => 'employee',
            'override_approval_employee' => 'overrideapprovalemployee',
            'order_placed_method' => 'orderplacedmethod',
            'order_fulfilled_method' => 'orderfulfilledmethod',
            'modified_order_amount' => 'modifiedorderamount',
            'modification_reason' => 'modificationreason',
            'payment_methods' => 'paymentmethods',
            'refunded' => 'refunded',
            'tax_included_amount' => 'taxincludedamount',
        ];

        // --- transformers identical to processOrderLine() ---
        $transform = function(array $normalizedRow) use ($processor, $columnMap): array {
            $out = [];
            foreach ($columnMap as $appKey => $csvKey) {
                $val = $normalizedRow[$csvKey] ?? null;

                if (in_array($appKey, ['date_time_placed','date_time_fulfilled'], true)) {
                    $val = $processor->parseDateTime($val);
                }

                $out[$appKey] = $val;
            }
            return $out;
        };

        $total = 0;
        $batch = [];

        if (($h = fopen($path, 'r')) === false) {
            $this->error('Failed to open file.');
            return self::FAILURE;
        }

        // read + normalize header (trim -> lowercase -> remove spaces)
        $header = fgetcsv($h, 0, $delimiter);
        if ($header === false) {
            fclose($h);
            $this->error('Empty or invalid CSV header.');
            return self::FAILURE;
        }
        $normalizedHeader = array_map(fn($k) => str_replace(' ', '', strtolower(trim((string)$k))), $header);

        // main streaming loop
        while (($row = fgetcsv($h, 0, $delimiter)) !== false) {
            // skip blank lines that parse as [null] etc.
            if ($this->isEffectivelyEmptyRow($row)) {
                continue;
            }

            // match header -> row
            if (count($row) !== count($normalizedHeader)) {
                // Optional: warn once or log, then skip
                continue;
            }

            $values = array_map('trim', $row);
            $normalizedRow = array_combine($normalizedHeader, $values);

            $mapped = $transform($normalizedRow);
            $batch[] = $mapped;

            if (count($batch) >= $batchSize) {
                $inserter->replaceOrderLinePartitionKeepAll($batch, $batchSize);
                $total += count($batch);
                $batch = [];
                $this->output->write("."); // progress dot
            }
        }

        // flush remainder
        if (!empty($batch)) {
            $inserter->replaceOrderLinePartitionKeepAll($batch, $batchSize);
            $total += count($batch);
        }

        fclose($h);

        $this->newLine();
        $this->info("Imported {$total} rows successfully.");
        return self::SUCCESS;
    }

    private function detectDelimiter(string $path): string
    {
        $first = '';
        $h = fopen($path, 'r');
        if ($h !== false) {
            $first = fgets($h, 4096) ?: '';
            fclose($h);
        }
        $commas = substr_count($first, ',');
        $tabs = substr_count($first, "\t");
        return $tabs > $commas ? "\t" : ",";
    }

    private function isEffectivelyEmptyRow(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string)$cell) !== '') {
                return false;
            }
        }
        return true;
    }
}
