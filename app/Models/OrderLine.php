<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderLine extends Model
{
    use HasFactory;

    protected $table = 'order_line';

    protected $fillable = [
        'franchise_store',
        'business_date',
        'date_time_placed',
        'date_time_fulfilled',
        'net_amount',
        'quantity',
        'royalty_item',
        'taxable_item',
        'order_id',
        'item_id',
        'menu_item_name',
        'menu_item_account',
        'bundle_name',
        'employee',
        'override_approval_employee',
        'order_placed_method',
        'order_fulfilled_method',
        'modified_order_amount',
        'modification_reason',
        'payment_methods',
        'refunded',
        'tax_included_amount',
    ];

    protected $casts = [
        'business_date' => 'date',
        'net_amount'    => 'decimal:2',
        'quantity'      => 'integer',

        // generated flags:
        'is_pizza'                  => 'boolean',
        'is_companion_crazy_bread'  => 'boolean',
        'is_companion_cookie'       => 'boolean',
        'is_companion_sauce'        => 'boolean',
        'is_companion_wings'        => 'boolean',
    ];
}
