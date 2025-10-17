<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Models\OrderLine;
use App\Models\BreadBoostModel;

class ImportOneTimeOrderLines extends Command
{
    protected $signature = 'orders:import-onetime
        {--path= : CSV path (defaults to public/onetimecsv.csv)}
        {--batch=1000 : DB insert batch size}
        {--delimiter= : Force delimiter ("," or "\t"); auto-detect if omitted}
    ';

    protected $description = 'Stream-import OrderLine for ALL stores/dates in the CSV and run orderline-only logic (Bread Boost).';

    /** orderline-only header mapping (matches your processOrderLine) */
    protected array $columnMap = [
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

    /** bread boost excludes (from your logic) */
    protected array $breadExcludedItemIds = [
        '-1','6','7','8','9','101001','101002','101288','103044','202901','101289','204100','204200',
    ];

    public function handle(): int
    {
        $path = $this->option('path') ?: public_path('onetimecsv.csv');
        $batchSize = max(1, (int)($this->option('batch') ?: 1000));
        $delimiter = $this->option('delimiter');

        if (!file_exists($path)) {
            $this->error("CSV not found: {$path}");
            return self::FAILURE;
        }

        if (!$delimiter) $delimiter = $this->detectDelimiter($path);
        if (!in_array($delimiter, [",", "\t"], true)) {
            $this->error('Unsupported delimiter. Use "," or "\t".');
            return self::FAILURE;
        }

        $this->info("Importing (streamed): {$path}");
        $this->line("Delimiter: " . ($delimiter === "\t" ? 'TAB' : 'COMMA'));
        $this->line("Batch size: {$batchSize}");

        if (($h = fopen($path, 'r')) === false) {
            $this->error('Failed to open file.');
            return self::FAILURE;
        }

        // read + normalize header
        $header = fgetcsv($h, 0, $delimiter);
        if ($header === false) {
            fclose($h);
            $this->error('Empty or invalid CSV header.');
            return self::FAILURE;
        }
        $normalizedHeader = array_map(fn($k) => str_replace(' ', '', strtolower(trim((string)$k))), $header);

        // per-partition state
        $cleared = [];                 // set of "store|date" partitions we already deleted
        $buffers = [];                 // "store|date" => row[]
        $partitionsSeen = [];          // unique partitions for later logic

        $rowsTotal = 0;

        while (($row = fgetcsv($h, 0, $delimiter)) !== false) {
            if ($this->isEmptyRow($row)) continue;
            if (count($row) !== count($normalizedHeader)) continue;

            $values = array_map('trim', $row);
            $r = array_combine($normalizedHeader, $values);

            $mapped = $this->mapOrderLineRow($r);
            if (!$mapped['franchise_store'] || !$mapped['business_date']) {
                // skip rows missing required partition keys
                continue;
            }

            $key = $mapped['franchise_store'] . '|' . $mapped['business_date'];

            // first time we see this partition -> clear existing DB rows once
            if (!isset($cleared[$key])) {
                DB::transaction(function () use ($mapped) {
                    OrderLine::where('franchise_store', $mapped['franchise_store'])
                        ->where('business_date', $mapped['business_date'])
                        ->delete();
                });
                $cleared[$key] = true;
                $partitionsSeen[$key] = ['store' => $mapped['franchise_store'], 'date' => $mapped['business_date']];
            }

            // buffer + chunked insert
            $buffers[$key][] = $mapped;
            if (count($buffers[$key]) >= $batchSize) {
                OrderLine::insert($buffers[$key]);
                $rowsTotal += count($buffers[$key]);
                $buffers[$key] = [];
                $this->output->write('.');
            }
        }

        // flush all remaining buffers
        foreach ($buffers as $key => $buf) {
            if (!empty($buf)) {
                OrderLine::insert($buf);
                $rowsTotal += count($buf);
            }
        }

        fclose($h);
        $this->newLine();
        $this->info("Inserted {$rowsTotal} order_line rows across ".count($partitionsSeen)." partitions.");

        // ====== ORDERLINE-ONLY LOGIC: BREAD BOOST per (store,date) ======
        $this->info('Running Bread Boost (orderline-only) for all partitions...');
        $bbUpserts = 0;

        foreach ($partitionsSeen as $p) {
            $store = $p['store'];
            $date  = $p['date'];

            // fetch only needed columns
            $lines = OrderLine::query()
                ->where('franchise_store', $store)
                ->where('business_date', $date)
                ->get(['order_id','item_id','menu_item_name','order_fulfilled_method','order_placed_method','bundle_name']);

            if ($lines->isEmpty()) continue;

            // classic orders = Classic Pepperoni / Classic Cheese
            $classicOrders = $lines
                ->whereIn('menu_item_name', ['Classic Pepperoni', 'Classic Cheese'])
                ->whereIn('order_fulfilled_method', ['Register', 'Drive-Thru'])
                ->whereIn('order_placed_method', ['Phone', 'Register', 'Drive Thru'])
                ->pluck('order_id')
                ->unique();

            $classicOrdersCount = $classicOrders->count();

            $classicWithBreadCount = $lines
                ->whereIn('order_id', $classicOrders)
                ->where('menu_item_name', 'Crazy Bread')
                ->pluck('order_id')
                ->unique()
                ->count();

            $otherPizzaOrders = $lines
                ->reject(fn($row) => in_array((string)($row['item_id'] ?? ''), $this->breadExcludedItemIds, true))
                ->whereIn('order_fulfilled_method', ['Register', 'Drive-Thru'])
                ->whereIn('order_placed_method', ['Phone', 'Register', 'Drive Thru'])
                ->pluck('order_id')
                ->unique();

            $otherPizzaOrderCount = $otherPizzaOrders->count();

            $otherPizzaWithBreadCount = $lines
                ->whereIn('order_id', $otherPizzaOrders)
                ->where('menu_item_name', 'Crazy Bread')
                ->pluck('order_id')
                ->unique()
                ->count();

            // upsert bread_boost
            BreadBoostModel::upsert([[
                'franchise_store'         => $store,
                'business_date'           => $date,
                'classic_order'           => $classicOrdersCount,
                'classic_with_bread'      => $classicWithBreadCount,
                'other_pizza_order'       => $otherPizzaOrderCount,
                'other_pizza_with_bread'  => $otherPizzaWithBreadCount,
            ]], ['franchise_store','business_date'], [
                'classic_order','classic_with_bread','other_pizza_order','other_pizza_with_bread'
            ]);

            $bbUpserts++;
        }

        $this->info("Bread Boost upserts: {$bbUpserts}");

        return self::SUCCESS;
    }

    /* ---------------- helpers ---------------- */

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

    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string)$cell) !== '') return false;
        }
        return true;
    }

    private function mapOrderLineRow(array $normalizedRow): array
    {
        $out = [];
        foreach ($this->columnMap as $appKey => $csvKey) {
            $val = $normalizedRow[$csvKey] ?? null;
            if (in_array($appKey, ['date_time_placed','date_time_fulfilled'], true)) {
                $val = $this->parseDateTime($val);
            }
            $out[$appKey] = $val;
        }
        return $out;
    }

    private function parseDateTime($dateTimeString)
    {
        if (empty($dateTimeString)) return null;

        $dateTimeString = Str::of((string)$dateTimeString)->replace('Z', '')->trim();
        try {
            $dt = Carbon::createFromFormat('m-d-Y h:i:s A', (string)$dateTimeString);
            return $dt->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {}
        try {
            $dt = Carbon::parse((string)$dateTimeString);
            return $dt->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            Log::error("Error parsing datetime string: {$dateTimeString} - {$e->getMessage()}");
            return null;
        }
    }
}
