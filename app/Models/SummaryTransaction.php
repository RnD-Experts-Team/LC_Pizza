<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SummaryTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'franchise_store',
        'business_date',
        'payment_method',
        'sub_payment_method',
        'total_amount',
        'saf_qty',
        'saf_total',
    ];
}
