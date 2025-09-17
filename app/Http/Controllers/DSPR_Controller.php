<?php

namespace App\Http\Controllers;

use App\Models\HourlySales;
use App\Models\SummaryItem;
use App\Models\FinalSummary;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

class DSPR_Controller extends Controller
{
    public function index($store, $date, $items = null)
    {
        // --- guards ---
        if (empty($store) || empty($date)) {
            return response()->noContent();
        }
        if (!preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', $date)) {
            return response()->json(['error' => 'Invalid date format, expected YYYY-MM-DD or YYYY-M-D'], 400);
        }

        // URL decode the items parameter
        $decodedItems = $items ? urldecode($items) : null;
        // $itemsArray = $decodedItems ? $this->parseItemsString($decodedItems) : [];

        // External deposit/delivery
        $base = rtrim('https://hook.pneunited.com/api/deposit-delivery-dsqr', '/');
        $url  = $base.'/'.rawurlencode($store).'/'.rawurlencode($date);

    }

    /**
     * Parse comma-separated items string to array
     */





}
