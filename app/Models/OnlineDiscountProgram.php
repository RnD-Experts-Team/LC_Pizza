<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OnlineDiscountProgram extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'online_discount_program';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'franchise_store',
        'order_id',
        'business_date',
        'pay_type',
        'original_subtotal',
        'modified_subtotal',
        'promo_code'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'order_id' => 'integer',
        'business_date' => 'date',
        'original_subtotal' => 'decimal:2',
        'modified_subtotal' => 'decimal:2'
    ];
}
