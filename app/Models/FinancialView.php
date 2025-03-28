<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FinancialView extends Model
{
    use HasFactory;

    protected $fillable = [
        'franchise_store',
        'business_date',
        'area',
        'sub_account',
        'amount',
    ];
}
