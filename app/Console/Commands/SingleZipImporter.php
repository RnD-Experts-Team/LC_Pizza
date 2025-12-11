<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use ZipArchive;
use Illuminate\Support\Facades\File;
use App\Services\Helper\CSVs\ProcessCsvServices;

class SingleZipImporter extends Command
{
    protected $signature = 'zip:one-time-import
        {--path= : Path to ZIP (default: public/onetimezip.zip)}
        {--date= : Business date used in filenames, e.g. 2025-09-09}';

    protected $description = 'ONE-TIME loader for a ZIP of CSVs. Extracts, processes via ProcessCsvServices, then cleans up.';

    protected ProcessCsvServices $processor;

    public function __construct(ProcessCsvServices $processor)
    {
        parent::__construct();
        $this->processor = $processor;
    }

    public function handle(): int
    {
        // ---- resolve ZIP path ----
        $zipPath = $this->option('path') ?: public_path('onetimezip.zip'); // adjust if your file has no .zip
        if (!is_file($zipPath)) {
            $this->error("ZIP not found at: {$zipPath}");
            return self::FAILURE;
        }
        $this->info("ZIP path: {$zipPath}");

        // ---- resolve selected date ----
        $selectedDate = $this->option('date');
        if (!$selectedDate) {
            $this->error('Please pass --date=YYYY-MM-DD (must match the date in the CSV filenames).');
            return self::FAILURE;
        }
        $this->info("Selected date: {$selectedDate}");

        // ---- prepare extract directory ----
        $extractPath = storage_path('app/onetimezip_extract');
        if (is_dir($extractPath)) {
            $this->info("Cleaning existing extract dir: {$extractPath}");
            File::deleteDirectory($extractPath);
        }
        File::makeDirectory($extractPath, 0777, true, true);

        // ---- extract ZIP ----
        $this->info('Extracting ZIP...');
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            $this->error('Unable to open ZIP file.');
            return self::FAILURE;
        }

        if (!$zip->extractTo($extractPath)) {
            $zip->close();
            $this->error('Failed to extract ZIP.');
            return self::FAILURE;
        }
        $zip->close();
        $this->info("ZIP extracted to: {$extractPath}");

        // ---- process CSV files via your service ----
        try {
            $this->info('Processing extracted CSV files via ProcessCsvServices...');
            $allData = $this->processor->processCsvFiles($extractPath, $selectedDate);

            // Optional: show some summary counts
            foreach ($allData as $processorMethod => $rows) {
                $count = is_array($rows) ? count($rows) : 0;
                $this->info(sprintf('  • %s: %d rows processed', $processorMethod, $count));
            }

        } catch (\Throwable $e) {
            $this->error('Error while processing CSVs: ' . $e->getMessage());
            // keep extracted files for debugging
            return self::FAILURE;
        }

        // ---- cleanup extracted files ----
        $this->info('Cleaning up extracted files...');
        File::deleteDirectory($extractPath);

        $this->info('ALL DONE. ZIP import successful.');
        return self::SUCCESS;
    }
}
