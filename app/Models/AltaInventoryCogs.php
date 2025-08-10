<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AltaInventoryCogs extends Model
{
    protected $table = 'alta_inventory_cogs';

    protected $fillable = [
        'franchise_store',
        'business_date',
        'count_period',
        'inventory_category',
        'starting_value',
        'received_value',
        'net_transfer_value',
        'ending_value',
        'used_value',
        'theoretical_usage_value',
        'variance_value',
    ];

    protected $casts = [
        'business_date'             => 'date',
        'starting_value'            => 'decimal:2',
        'received_value'            => 'decimal:2',
        'net_transfer_value'        => 'decimal:2',
        'ending_value'              => 'decimal:2',
        'used_value'                => 'decimal:2',
        'theoretical_usage_value'   => 'decimal:2',
        'variance_value'            => 'decimal:2',
    ];
}
