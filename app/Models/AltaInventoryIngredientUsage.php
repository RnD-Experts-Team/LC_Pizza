<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AltaInventoryIngredientUsage extends Model
{
    protected $table = 'alta_inventory_ingredient_usage';

    protected $fillable = [
        'franchise_store',
        'business_date',
        'count_period',
        'ingredient_id',
        'ingredient_description',
        'ingredient_category',
        'ingredient_unit',
        'ingredient_unit_cost',
        'starting_inventory_qty',
        'received_qty',
        'net_transferred_qty',
        'ending_inventory_qty',
        'actual_usage',
        'theoretical_usage',
        'variance_qty',
        'waste_qty',
    ];

    protected $casts = [
        'business_date'            => 'date',
        'ingredient_unit_cost'     => 'decimal:2',
        'starting_inventory_qty'   => 'decimal:2',
        'received_qty'             => 'decimal:2',
        'net_transferred_qty'      => 'decimal:2',
        'ending_inventory_qty'     => 'decimal:2',
        'actual_usage'             => 'decimal:2',
        'theoretical_usage'        => 'decimal:2',
        'variance_qty'             => 'decimal:2',
        'waste_qty'                => 'decimal:2',
    ];
}
