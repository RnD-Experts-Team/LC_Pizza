<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BreadBoostModel extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'bread_boost';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'date',
        'franchise_store',
        'classic_order',
        'classic_with_bread',
        'other_pizza_order',
        'other_pizza_with_bread'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'date' => 'date',
        'classic_order' => 'integer',
        'classic_with_bread' => 'integer',
        'other_pizza_order' => 'integer',
        'other_pizza_with_bread' => 'integer'
    ];
}
