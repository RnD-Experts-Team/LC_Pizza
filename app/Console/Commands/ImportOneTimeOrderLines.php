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
        {--path= : CSV/TSV path (defaults to public/onetimecsv.csv)}
        {--batch=1000 : DB insert batch size}
        {--delimiter= : Force delimiter ("," or "\t"); auto-detect if omitted}
    ';

    protected $description = 'Stream-import OrderLine for ALL stores/dates in the file and run Bread Boost logic. OrderLine-only.';

    /** mapping identical to your processOrderLine() */
    protected array $columnMap = [
        'franchise_store'       => 'franchisestore',
        'business_date'         => 'businessdate',
        'date_time_placed'      => 'datetimeplaced',
        'date_time_fulfilled'   => 'datetimefulfilled',
        'net_amount'            => 'netamount',
        'quantity'              => 'quantity',
        'royalty_item'          => 'royaltyitem',
        'taxable_item'          => 'taxableitem',
        'order_id'              => 'orderid',
        'item_id'               => 'itemid',
        'menu_item_name'        => 'menuitemname',
        'menu_item_account'     => 'menuitemaccount',
        'bundle_name'           => 'bundlename',
        'employee'              => 'employee',
        'override_approval_employee' => 'overrideapprovalemployee',
        'order_placed_method'   => 'orderplacedmethod',
        'order_fulfilled_method'=> 'orderfulfilledmethod',
        'modified_order_amount' => 'modifiedorderamount',
        'modification_reason'   => 'modificationreason',
        'payment_methods'       => 'paymentmethods',
        'refunded'              => 'refunded',
        'tax_included_amount'   => 'taxincludedamount',
    ];

    /** Bread Boost excludes (from your logic) */
    protected array $breadExcludedItemIds = [
        '-1','6','7','8','9','101001','101002','101288','103044','202901','101289','204100','204200',
    ];

    public function handle(): int
    {
        $path      = $this->option('path') ?: public_path('onetimecsv.csv');
        $batchSize = max(1, (int)($this->option('batch') ?: 1000));
        $delimiter = $this->option('delimiter');

        if (!file_exists($path)) {
            $this->error("CSV/TSV not found: {$path}");
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

        // header
        $header = fgetcsv($h, 0, $delimiter);
        if ($header === false) {
            fclose($h);
            $this->error('Empty or invalid header.');
            return self::FAILURE;
        }
        $normalizedHeader = array_map(
            fn($k) => str_replace(' ', '', strtolower(trim((string)$k))),
            $header
        );

        // partition state
        $cleared        = [];  // "store|date" => true (deleted already)
        $buffers        = [];  // "store|date" => rows[]
        $partitionsSeen = [];  // "store|date" => ['store'=>..., 'date'=>...]

        $rowsTotal = 0;

        while (($row = fgetcsv($h, 0, $delimiter)) !== false) {
            if ($this->rowIsEmpty($row)) continue;
            if (count($row) !== count($normalizedHeader)) continue;

            // build assoc row with normalized keys
            $values = array_map(fn($v) => $this->trimCell($v), $row);
            $assoc  = array_combine($normalizedHeader, $values);

            // map + normalize to app schema
            $mapped = $this->mapOrderLineRow($assoc);

            $store = (string)($mapped['franchise_store'] ?? '');
            $date  = (string)($mapped['business_date'] ?? '');

            if ($store === '' || $date === '') {
                // skip rows missing required partition keys
                continue;
            }

            $key = $store.'|'.$date;

            // first time seeing this partition -> delete existing
            if (!isset($cleared[$key])) {
                DB::transaction(function () use ($store, $date) {
                    OrderLine::where('franchise_store', $store)
                        ->where('business_date', $date)
                        ->delete();
                });
                $cleared[$key] = true;
                $partitionsSeen[$key] = ['store' => $store, 'date' => $date];
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

        // flush leftovers
        foreach ($buffers as $buf) {
            if (!empty($buf)) {
                OrderLine::insert($buf);
                $rowsTotal += count($buf);
            }
        }

        fclose($h);
        $this->newLine();
        $this->info("Inserted {$rowsTotal} order_line rows across ".count($partitionsSeen)." (store,date) partitions.");

        // ========= ORDERLINE-ONLY LOGIC: Bread Boost for every partition =========
        $this->info('Running Bread Boost for all partitions...');
        $bbUpserts = 0;

        foreach ($partitionsSeen as $p) {
            $store = $p['store'];
            $date  = $p['date'];

            // get only needed columns
            $lines = OrderLine::query()
                ->where('franchise_store', $store)
                ->where('business_date', $date)
                ->get([
                    'order_id',
                    'item_id',
                    'menu_item_name',
                    'order_fulfilled_method',
                    'order_placed_method',
                    'bundle_name',
                ]);

            if ($lines->isEmpty()) continue;

            // classic orders (carryout channels)
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

            // upsert to bread_boost
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

    /* ===================== helpers ===================== */

    private function trimCell($v): string
    {
        // keep empty cells as empty strings, strip quotes/spaces
        return trim((string)$v, " \t\n\r\0\x0B\"'");
    }

    private function detectDelimiter(string $path): string
    {
        $first = '';
        $h = fopen($path, 'r');
        if ($h !== false) {
            $first = fgets($h, 8192) ?: '';
            fclose($h);
        }
        $commas = substr_count($first, ',');
        $tabs   = substr_count($first, "\t");
        return $tabs > $commas ? "\t" : ",";
    }

    private function rowIsEmpty(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string)$cell) !== '') return false;
        }
        return true;
    }

    private function mapOrderLineRow(array $csv): array
    {
        $out = [];
        foreach ($this->columnMap as $appKey => $csvKey) {
            $val = $csv[$csvKey] ?? null;

            if (is_string($val)) {
                $val = $this->trimCell($val);
            }

            if ($appKey === 'business_date') {
                $val = $this->parseDate($val); // <-- normalize to Y-m-d
            } elseif (in_array($appKey, ['date_time_placed','date_time_fulfilled'], true)) {
                $val = $this->parseDateTime($val); // <-- normalize to Y-m-d H:i:s
            }

            $out[$appKey] = $val;
        }
        return $out;
    }

    private function parseDate(?string $dateString): ?string
    {
        if (!$dateString) return null;

        // try common formats first
        $try = ['n/j/Y', 'm/d/Y', 'Y-m-d'];
        foreach ($try as $fmt) {
            try {
                return Carbon::createFromFormat($fmt, $dateString)->format('Y-m-d');
            } catch (\Throwable $e) {}
        }
        // fallback
        try {
            return Carbon::parse($dateString)->format('Y-m-d');
        } catch (\Throwable $e) {
            Log::error("Error parsing date: {$dateString} - {$e->getMessage()}");
            return null;
        }
    }

    private function parseDateTime(?string $dateTimeString): ?string
    {
        if (!$dateTimeString) return null;

        $s = Str::of($dateTimeString)->replace('Z', '')->trim()->toString();

        // try multiple formats (24h + 12h)
        $formats = [
            'n/j/Y H:i',      // 9/1/2025 19:41
            'm/d/Y H:i',
            'n/j/Y H:i:s',
            'm/d/Y H:i:s',
            'n/j/Y h:i A',    // 9/1/2025 07:41 PM
            'm/d/Y h:i A',
            'n/j/Y h:i:s A',
            'm/d/Y h:i:s A',
            'Y-m-d H:i:s',
            'Y-m-d H:i',
            'm-d-Y h:i:s A',  // legacy in your service
        ];
        foreach ($formats as $fmt) {
            try {
                return Carbon::createFromFormat($fmt, $s)->format('Y-m-d H:i:s');
            } catch (\Throwable $e) {}
        }

        // fallback
        try {
            return Carbon::parse($s)->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            Log::error("Error parsing datetime: {$dateTimeString} - {$e->getMessage()}");
            return null;
        }
    }
}
