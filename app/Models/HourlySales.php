<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HourlySales extends Model
{
    protected $table = 'hourly_sales';

    protected $fillable = [
        'franchise_store',
        'business_date',
        'hour',
        'total_sales',
        'phone_sales',
        'call_center_sales',
        'drive_thru_sales',
        'website_sales',
        'mobile_sales',
        'website_sales_delivery',
        'mobile_sales_delivery',
        'doordash_sales',
        'ubereats_sales',
        'grubhub_sales',
        'order_count',
    ];
}
