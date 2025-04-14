<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DetailOrder extends Model
{

    use HasFactory;

    protected $fillable = [
        'franchise_store',
        'business_date',
        'date_time_placed',
        'date_time_fulfilled',
        'royalty_obligation',
        'quantity',
        'customer_count',
        'order_id',
        'taxable_amount',
        'non_taxable_amount',
        'tax_exempt_amount',
        'non_royalty_amount',
        'sales_tax',
        'employee',
        'gross_sales',
        'occupational_tax',
        'override_approval_employee',
        'order_placed_method',
        'delivery_tip',
        'delivery_tip_tax',
        'order_fulfilled_method',
        'delivery_fee',
        'modified_order_amount',
        'delivery_fee_tax',
        'modification_reason',
        'payment_methods',
        'delivery_service_fee',
        'delivery_service_fee_tax',
        'refunded',
        'delivery_small_order_fee',
        'delivery_small_order_fee_tax',
        'transaction_type',
        'store_tip_amount',
        'promise_date',
        'tax_exemption_id',
        'tax_exemption_entity_name',
        'user_id',
        'hnrOrder',
        'broken_promise',
        'portal_eligible',
        'portal_used',
        'put_into_portal_before_promise_time',
        'portal_compartments_used'

    ];
}
