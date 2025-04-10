<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SummaryItem extends Model
{
    use HasFactory;

    protected $fillable = [

        'franchise_store',
        'business_date',
        'menu_item_name',
        'menu_item_account',
        'item_id',
        'item_quantity',
        'royalty_obligation',
        'taxable_amount',
        'non_taxable_amount',
        'tax_exempt_amount',
        'non_royalty_amount',
        'tax_included_amount',

    ];
}
