<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AltaInventoryWaste extends Model
{
    // explicit table name since it doesn't follow the plural convention
    protected $table = 'alta_inventory_waste';

    // mass-assignable fields
    protected $fillable = [
        'franchise_store',
        'business_date',
        'item_id',
        'item_description',
        'waste_reason',
        'unit_food_cost',
        'qty',
    ];

    // cast date & decimal columns
    protected $casts = [
        'business_date'   => 'date',
        'unit_food_cost'  => 'decimal:2',
        'qty'             => 'decimal:2',
    ];
}
