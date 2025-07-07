<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryOrderSummary extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'delivery_order_summary';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'business_date',
        'franchise_store',
        'orders_count',
        'product_cost',
        'tax',
        'occupational_tax',
        'delivery_charges',
        'delivery_charges_taxes',
        'service_charges',
        'service_charges_taxes',
        'small_order_charge',
        'small_order_charge_taxes',
        'delivery_late_charge',
        'tip',
        'tip_tax',
        'total_taxes',
        'order_total'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'business_date' => 'date',
        'orders_count' => 'integer',
        'product_cost' => 'decimal:2',
        'tax' => 'decimal:2',
        'occupational_tax' => 'decimal:2',
        'delivery_charges' => 'decimal:2',
        'delivery_charges_taxes' => 'decimal:2',
        'service_charges' => 'decimal:2',
        'service_charges_taxes' => 'decimal:2',
        'small_order_charge' => 'decimal:2',
        'small_order_charge_taxes' => 'decimal:2',
        'delivery_late_charge' => 'decimal:2',
        'tip' => 'decimal:2',
        'tip_tax' => 'decimal:2',
        'total_taxes' => 'decimal:2',
        'order_total' => 'decimal:2'
    ];
}
