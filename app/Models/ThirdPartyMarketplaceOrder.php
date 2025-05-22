<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ThirdPartyMarketplaceOrder extends Model
{
    /**
     * The table associated with the model.
     *ubereats_product_costs_Marketplace
     * @var string
     */
    protected $table = 'third_party_marketplace_orders';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'date',
        'franchise_store',
        'doordash_product_costs_Marketplace',
        'doordash_tax_Marketplace',
        'doordash_order_total_Marketplace',
        'ubereats_product_costs_Marketplace',
        'ubereats_tax_Marketplace',
        'uberEats_order_total_Marketplace',
        'grubhub_product_costs_Marketplace',
        'grubhub_tax_Marketplace',
        'grubhub_order_total_Marketplace'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'date' => 'date',
        'doordash_product_costs' => 'decimal:2',
        'doordash_tax' => 'decimal:2',
        'doordash_order_total' => 'decimal:2',
        'ubereats_product_costs' => 'decimal:2',
        'ubereats_tax' => 'decimal:2',
        'ubereats_order_total' => 'decimal:2',
        'grubhub_product_costs' => 'decimal:2',
        'grubhub_tax' => 'decimal:2',
        'grubhub_order_total' => 'decimal:2'
    ];
}
