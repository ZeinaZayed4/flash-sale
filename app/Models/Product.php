<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'name',
        'price',
        'total_stock',
        'available_stock',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'total_stock' => 'integer',
        'available_stock' => 'integer',
    ];
}
