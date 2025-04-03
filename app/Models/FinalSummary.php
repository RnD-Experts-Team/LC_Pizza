<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class FinalSummary extends Model
{

    use HasFactory;

    protected $table = 'final_summaries';
    protected $fillable = [
        'franchise_store',
        'business_date',
        'total_sales',
        'modified_order_qty',
        'refunded_order_qty',
        'customer_count',

        'phone_sales',
        'call_center_sales',
        'drive_thru_sales',
        'website_sales',
        'mobile_sales',

        'doordash_sales',
        'grubhub_sales',
        'ubereats_sales',
        'delivery_sales',
        'digital_sales_percent',

        'portal_transactions',
        'put_into_portal',
        'portal_used_percent',
        'put_in_portal_on_time',
        'in_portal_on_time_percent',

        'delivery_tips',
        'prepaid_delivery_tips',
        'in_store_tip_amount',
        'prepaid_instore_tip_amount',
        'total_tips',

        'over_short',
        'cash_sales',
        'total_cash',

        'total_waste_cost',
    ];

    public $timestamps = true;
}
