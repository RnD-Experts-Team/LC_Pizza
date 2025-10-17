<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Helper\CSVs\ProcessCsvServices;

class ImportOneTimeOrderLines extends Command
{
    // Run with: php artisan orders:import-onetime
    protected $signature = 'orders:import-onetime {--path= : Optional absolute/relative path to the CSV}';

    protected $description = 'Import order lines from public/onetimecsv.csv (or a provided --path) using existing ProcessCsvServices logic.';

    public function handle(ProcessCsvServices $processor): int
    {
        // default to public/onetimecsv.csv unless --path is provided
        $path = $this->option('path')
            ?: public_path('onetimecsv.csv');

        if (! file_exists($path)) {
            $this->error("CSV not found at: {$path}");
            return self::FAILURE;
        }

        $this->info("Importing order lines from: {$path}");

        try {
            // Reuse your existing mapping + transformers + inserter
            $rows = $processor->processOrderLine($path);

            $count = is_array($rows) ? count($rows) : 0;
            $this->info("Processed {$count} rows.");
            $this->info('Done.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Import failed: '.$e->getMessage());
            report($e);
            return self::FAILURE;
        }
    }
}
