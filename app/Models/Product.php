<?php

namespace App\Models;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

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

    public static function findWithCache($id)
    {
        $cacheKey = "product:$id";

        return Cache::remember($cacheKey, now()->addSeconds(5), function () use ($id) {
            return self::find($id);
        });
    }

    public function canReserve($quantity): bool
    {
        return $this->available_stock >= $quantity;
    }

    /**
     * @throws Throwable
     */
    public function decrementStock($quantity): void
    {
        DB::transaction(function () use ($quantity) {
           $product = self::lockForUpdate()->find($this->id);

           if ($product->available_stock < $quantity) {
               Log::warning('Insufficient stock', [
                   'product_id' => $this->id,
                   'requested' => $quantity,
                   'available' => $product->available_stock,
               ]);

               throw new Exception('Insufficient stock available');
           }

           $product->available_stock -= $quantity;
           $product->save();

           Cache::forget("product:$this->id");

           Log::info('Stock decremented', [
               'product_id' => $this->id,
               'requested' => $quantity,
               'remaining' => $product->available_stock,
           ]);
        });
    }

    /**
     * @throws Throwable
     */
    public function incrementStock($quantity): void
    {
        DB::transaction(function () use ($quantity) {
            $product = self::lockForUpdate()->find($this->id);

            $product->available_stock += $quantity;
            $product->save();

            Cache::forget("product:$this->id");

            Log::info('Stock incremented', [
                'product_id' => $this->id,
                'quantity' => $quantity,
                'new_available' => $product->available_stock,
            ]);
        });
    }

    public function holds(): HasMany
    {
        return $this->hasMany(Hold::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
