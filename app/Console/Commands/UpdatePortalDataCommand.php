<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\FinalSummary;

class UpdatePortalDataCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * php artisan update:portal-data
     *
     * @var string
     */
    protected $signature = 'update:portal-data';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update database with missing portal data from CSV file';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $filePath = public_path('MissingDataPortal.csv');

        if (!file_exists($filePath)) {
            $this->error("File not found: {$filePath}");
            return 1;
        }

        $this->info("Starting update from file: {$filePath}");
        $this->updateFromFile($filePath);

        $this->info("Portal data updated successfully.");
        return 0;
    }

    /**
     * Update database records from the CSV file.
     *
     * @param string $filePath
     * @return void
     */
    protected function updateFromFile($filePath)
    {
        // Open the file
        $file = fopen($filePath, 'r');
        if (!$file) {
            $this->error("Could not open file: {$filePath}");
            return;
        }

        // Read the header row to get column names
        $headers = fgetcsv($file);
        if (!$headers) {
            $this->error("Could not read headers from file: {$filePath}");
            fclose($file);
            return;
        }

        // Find the column indexes for the fields we need
        $columnIndexes = $this->getColumnIndexes($headers, [
            'franchise_store', 'business_date',
            'portal_transactions', 'put_into_portal',
            'portal_used_percent', 'put_in_portal_on_time',
            'in_portal_on_time_percent'
        ]);

        // Process the file in chunks
        $chunkSize = 100;
        $processed = 0;
        $updated = 0;
        $chunk = [];

        // Read the file line by line
        while (($row = fgetcsv($file)) !== false) {
            // Skip empty rows
            if (empty($row) || count($row) < count($headers)) {
                continue;
            }

            $chunk[] = $this->mapRowToData($row, $columnIndexes);

            if (count($chunk) >= $chunkSize) {
                $updated += $this->processChunk($chunk);
                $processed += count($chunk);
                $chunk = [];

                $this->info("Processed {$processed} rows, updated {$updated} records");
            }
        }

        // Process any remaining records
        if (!empty($chunk)) {
            $updated += $this->processChunk($chunk);
            $processed += count($chunk);
            $this->info("Processed {$processed} rows, updated {$updated} records");
        }

        fclose($file);
        $this->info("Total processed: {$processed}, Total updated: {$updated}");
    }

    /**
     * Get the column indexes for the specified fields.
     *
     * @param array $headers
     * @param array $fields
     * @return array
     */
    protected function getColumnIndexes($headers, $fields)
    {
        $indexes = [];

        // Clean up headers - trim whitespace and convert to lowercase for comparison
        $cleanHeaders = array_map(function($header) {
            return trim(strtolower($header));
        }, $headers);

        foreach ($fields as $field) {
            // Look for the field in a case-insensitive way
            $cleanField = trim(strtolower($field));
            $index = array_search($cleanField, $cleanHeaders);

            if ($index !== false) {
                $indexes[$field] = $index;
            } else {
                // Try a more flexible approach - check if the field is contained in any header
                foreach ($cleanHeaders as $i => $header) {
                    if (strpos($header, $cleanField) !== false) {
                        $indexes[$field] = $i;
                        $this->info("Found column '{$field}' as '{$headers[$i]}'");
                        break;
                    }
                }

                // If still not found, warn the user
                if (!isset($indexes[$field])) {
                    $this->warn("Column '{$field}' not found in CSV headers");
                }
            }
        }

        return $indexes;
    }

    /**
     * Map a CSV row to data array using column indexes.
     *
     * @param array $row
     * @param array $columnIndexes
     * @return array
     */
    protected function mapRowToData($row, $columnIndexes)
    {
        $data = [];
        foreach ($columnIndexes as $field => $index) {
            if (isset($row[$index])) {
                $data[$field] = $row[$index];
            }
        }
        return $data;
    }

    /**
     * Process a chunk of data and update the database.
     *
     * @param array $chunk
     * @return int Number of records updated
     */
    protected function processChunk($chunk)
    {
        $updated = 0;

        foreach ($chunk as $data) {
            // Format the date to match database format
            try {
                $date = Carbon::createFromFormat('m/d/Y', $data['business_date'])->format('Y-m-d');
            } catch (\Exception $e) {
                $this->warn("Invalid date format: {$data['business_date']}");
                continue;
            }

            // Prepare update data
            $updateData = [
                'portal_transactions' => $data['portal_transactions'],
                'put_into_portal' => $data['put_into_portal'],
                'portal_used_percent' => $data['portal_used_percent'],
                'put_in_portal_on_time' => $data['put_in_portal_on_time'],
                'in_portal_on_time_percent' => $data['in_portal_on_time_percent'],
                'updated_at' => now()
            ];

            // Update the record in the database using FinalSummary model
            $result = FinalSummary::where('franchise_store', $data['franchise_store'])
                ->where('business_date', $date)
                ->update($updateData);

            if ($result) {
                $updated++;
            }
        }

        return $updated;
    }
}
