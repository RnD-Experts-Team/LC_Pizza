<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CashManagement extends Model
{
    use HasFactory;

    protected $fillable = [
        'franchise_store',
        'business_date',
        'create_datetime',
        'verified_datetime',
        'till',
        'check_type',
        'system_totals',
        'verified',
        'variance',
        'created_by',
        'verified_by',
    ];
}
