<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChannelData extends Model
{
    protected $table = 'channel_data';

    protected $fillable = [
        'store',
        'business_date',
        'category',
        'sub_category',
        'order_placed_method',
        'order_fulfilled_method',
        'amount'
    ];

    protected $casts = [
        'business_date' => 'date',
        'amount' => 'decimal:2'
    ];
}
