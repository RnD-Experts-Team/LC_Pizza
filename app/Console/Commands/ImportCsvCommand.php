<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Carbon\Carbon;
use App\Models\CashManagement;
use App\Models\DetailOrder;
use App\Models\FinancialView;
use App\Models\SummaryItem;
use App\Models\SummarySale;
use App\Models\SummaryTransaction;

class ImportCsvCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * php artisan import:csv
     *
     * @var string
     */
    protected $signature = 'import:csv';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import CSV files into the database using chunked processing';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Define the mapping between CSV file paths and model classes.
        $files = [
            public_path('DB_Move/cash_management.csv')      => CashManagement::class,
            public_path('DB_Move/detail_orders.csv')          => DetailOrder::class,
            public_path('DB_Move/financial_views.csv')        => FinancialView::class,
            public_path('DB_Move/summary_items.csv')          => SummaryItem::class,
            public_path('DB_Move/summary_sales.csv')          => SummarySale::class,
            public_path('DB_Move/summary_transactions.csv')   => SummaryTransaction::class,
        ];

        foreach ($files as $filePath => $modelClass) {
            $this->info("Starting import for file: {$filePath}");
            $this->importFile($filePath, $modelClass);
        }

        $this->info("All CSV files imported successfully.");

        return 0;
    }

    /**
     * Import CSV file using chunked processing.
     *
     * @param string $filePath The absolute file path.
     * @param string $modelClass The Eloquent model class to use for the import.
     * @return void
     */
    protected function importFile(string $filePath, string $modelClass)
    {
        if (!file_exists($filePath)) {
            $this->error("File not found: {$filePath}");
            return;
        }

        if (($handle = fopen($filePath, 'r')) === false) {
            $this->error("Could not open file: {$filePath}");
            return;
        }

        // Read the first row from the CSV file as header.
        $header = fgetcsv($handle);
        if (!$header) {
            $this->error("The file is empty or invalid: {$filePath}");
            fclose($handle);
            return;
        }

        // Ask user if they want to truncate the table before importing
        if ($this->confirm("Do you want to clear existing data in the " . class_basename($modelClass) . " table before importing?")) {
            $modelClass::truncate();
            $this->info("Cleared existing data from " . class_basename($modelClass) . " table.");
        } else {
            $this->info("Importing without clearing existing data. Duplicate primary keys will be skipped.");
        }

        $batchSize = 500; // Adjust this based on your system's memory/resources.
        $dataChunk = [];
        $rowCount = 0;
        $skippedCount = 0;

        // Process each row of the CSV.
        while (($row = fgetcsv($handle)) !== false) {
            // Ensure each row has the same number of columns as the header.
            if (count($header) !== count($row)) {
                $this->warn("Skipping a row due to column mismatch: " . json_encode($row));
                continue;
            }

            // Map the CSV row to an associative array using the header.
            $rowData = array_combine($header, $row);

            // Convert string 'NULL' values to actual PHP null

            foreach ($rowData as $key => $value) {
                if ($value === 'NULL' || $value === 'null' || $value === '') {
                    $rowData[$key] = null;
                }
            }

            // Special handling for detail_orders table
            if ($modelClass === DetailOrder::class) {
                // Handle specific date formats for detail_orders
                $orderDateFields = [
                    'created_at', 'updated_at', 'business_date',
                    'date_time_placed', 'date_time_fulfilled', 'promise_date'
                ];

                foreach ($orderDateFields as $field) {
                    if (isset($rowData[$field]) && !empty($rowData[$field]) && $rowData[$field] !== null) {
                        try {
                            // Try to parse the date with different formats
                            if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}\s\d{1,2}:\d{1,2}(:\d{1,2})?$/', $rowData[$field])) {
                                // Format like '11/27/2024 8:37' or '11/27/2024 8:37:42'
                                if (strpos($rowData[$field], ':') === strrpos($rowData[$field], ':')) {
                                    $date = Carbon::createFromFormat('m/d/Y G:i', $rowData[$field]);
                                } else {
                                    $date = Carbon::createFromFormat('m/d/Y G:i:s', $rowData[$field]);
                                }
                                $rowData[$field] = $date->format('Y-m-d H:i:s');
                            } elseif (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $rowData[$field])) {
                                // Format like '11/27/2024'
                                $date = Carbon::createFromFormat('m/d/Y', $rowData[$field]);
                                // For date-only fields, use date format
                                if ($field === 'business_date' || $field === 'promise_date') {
                                    $rowData[$field] = $date->format('Y-m-d');
                                } else {
                                    $rowData[$field] = $date->format('Y-m-d H:i:s');
                                }
                            } else {
                                // Try default Carbon parsing
                                $date = Carbon::parse($rowData[$field]);
                                if ($field === 'business_date' || $field === 'promise_date') {
                                    $rowData[$field] = $date->format('Y-m-d');
                                } else {
                                    $rowData[$field] = $date->format('Y-m-d H:i:s');
                                }
                            }
                        } catch (\Exception $e) {
                            $this->warn("Could not parse {$field} in detail_orders: {$rowData[$field]} - {$e->getMessage()}");
                        }
                    }
                }

                // Check for typos in field names in the CSV
                if (isset($rowData['date_time_palced1']) && !isset($rowData['date_time_placed'])) {
                    // Fix typo in field name
                    $rowData['date_time_placed'] = $rowData['date_time_palced1'];
                    unset($rowData['date_time_palced1']);

                    // Format the date
                    if (!empty($rowData['date_time_placed']) && $rowData['date_time_placed'] !== null) {
                        try {
                            $date = Carbon::parse($rowData['date_time_placed']);
                            $rowData['date_time_placed'] = $date->format('Y-m-d H:i:s');
                        } catch (\Exception $e) {
                            $this->warn("Could not parse date_time_placed (fixed from typo): {$rowData['date_time_placed']} - {$e->getMessage()}");
                        }
                    }
                }

                // Check for promised_date vs promise_date
                if (isset($rowData['promised_date']) && !isset($rowData['promise_date'])) {
                    // Fix field name to match model
                    $rowData['promise_date'] = $rowData['promised_date'];
                    unset($rowData['promised_date']);

                    // Format the date
                    if (!empty($rowData['promise_date']) && $rowData['promise_date'] !== null) {
                        try {
                            $date = Carbon::parse($rowData['promise_date']);
                            $rowData['promise_date'] = $date->format('Y-m-d');
                        } catch (\Exception $e) {
                            $this->warn("Could not parse promise_date (fixed from promised_date): {$rowData['promise_date']} - {$e->getMessage()}");
                        }
                    }
                }
            } else {
                // Handle datetime fields for other tables
                $dateTimeFields = ['created_at', 'updated_at', 'verified_datetime', 'business_date'];
                foreach ($dateTimeFields as $field) {
                    if (isset($rowData[$field]) && !empty($rowData[$field]) && $rowData[$field] !== null) {
                        try {
                            // Try to parse the date with different formats
                            if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}\s\d{1,2}:\d{1,2}$/', $rowData[$field])) {
                                // Format like '11/27/2024 8:37'
                                $date = Carbon::createFromFormat('m/d/Y G:i', $rowData[$field]);
                            } elseif (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $rowData[$field])) {
                                // Format like '11/27/2024'
                                $date = Carbon::createFromFormat('m/d/Y', $rowData[$field]);
                            } else {
                                // Try default Carbon parsing
                                $date = Carbon::parse($rowData[$field]);
                            }
                            $rowData[$field] = $date->format('Y-m-d H:i:s');
                        } catch (\Exception $e) {
                            $this->warn("Could not parse {$field}: {$rowData[$field]} - {$e->getMessage()}");
                        }
                    }
                }

                // --- Date Transformation Example Start ---
                // If a row has a date field that needs reformatting:
                if (isset($rowData['business_date']) && !empty($rowData['business_date'])) {
                    try {
                        // Suppose the original format is "m/d/Y" and we need "Y-m-d".
                        $date = Carbon::createFromFormat('m/d/Y', $rowData['business_date']);
                        $rowData['business_date'] = $date->format('Y-m-d');
                    } catch (\Exception $e) {
                        $this->warn("Could not parse business_date: {$rowData['business_date']}");
                    }
                }
                // Repeat similar blocks for other date fields if required.
                // --- Date Transformation Example End ---
            }

            $dataChunk[] = $rowData;
            $rowCount++;

            // Insert the chunk if it reaches the batch size.
            if (count($dataChunk) >= $batchSize) {
                try {
                    $modelClass::insertOrIgnore($dataChunk);
                    $this->info("Processed {$rowCount} rows so far for file: {$filePath}");
                } catch (\Exception $e) {
                    $skippedCount += count($dataChunk);
                    $this->warn("Error inserting batch: " . $e->getMessage());
                }
                $dataChunk = [];
            }
        }

        // Insert any remaining rows.
        if (!empty($dataChunk)) {
            try {
                $modelClass::insertOrIgnore($dataChunk);
                $this->info("Processed final batch, total rows for file: {$rowCount}");
            } catch (\Exception $e) {
                $skippedCount += count($dataChunk);
                $this->warn("Error inserting final batch: " . $e->getMessage());
            }
        }

        $this->info("Import completed for {$filePath}. Total rows processed: {$rowCount}, Skipped: {$skippedCount}");
        fclose($handle);
    }
}
