<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Waste extends Model
{
    use HasFactory;

    protected $table = 'waste';

    protected $fillable = [
        'business_date',
        'franchise_store',
        'cv_item_id',
        'menu_item_name',
        'expired',
        'waste_date_time',
        'produce_date_time',
        'waste_reason',
        'cv_order_id',
        'waste_type',
        'item_cost',
        'quantity',
        //'age_in_minutes',
    ];


}
