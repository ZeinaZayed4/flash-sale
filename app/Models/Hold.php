<?php

namespace App\Models;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Hold extends Model
{
    protected $fillable = [
        'product_id',
        'quantity',
        'expires_at',
        'is_consumed',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'expires_at' => 'datetime',
        'is_consumed' => 'boolean',
    ];

    public static function createWithStockReservation($id, $quantity)
    {
        return DB::transaction(function () use ($id, $quantity) {
            $product = Product::lockForUpdate()->find($id);

            if (!$product) {
                throw new Exception('Product not found!');
            }

            if ($product->available_stock < $quantity) {
                Log::warning('Hold creation failed, insufficient stock', [
                    'product_id' => $id,
                    'requested' => $quantity,
                    'available' => $product->available_stock,
                ]);

                throw new Exception('Insufficient stock available');
            }

            $product->available_stock -= $quantity;
            $product->save();

            $hold = self::create([
                'product_id' => $id,
                'quantity' => $quantity,
                'expires_at' => now()->addMinutes(2),
                'is_consumed' => false,
            ]);

            Cache::forget("product:$id");

            Log::info('Hold created', [
                'hold_id' => $hold->id,
                'product_id' => $id,
                'quantity' => $quantity,
                'expires_at' => $hold->expires_at,
                'remaining_stock' => $product->available_stock,
            ]);

            return $hold;
        });
    }

    public function isValid(): bool
    {
        return !$this->is_consumed && $this->expires_at->isFuture();
    }

    public function consume()
    {
        $this->is_consumed = true;
        $this->save();

        Log::info('Hold consumed', [
            'hold_id' => $this->id,
            'product_id' => $this->product_id
        ]);
    }

    public function release(): void
    {
        if ($this->is_consumed) {
            Log::info('Hold already consumed, skipping release', [
                'hold_id' => $this->id,
            ]);
            return;
        }

        DB::transaction(function () {
            $product = Product::lockForUpdate()->find($this->product_id);

            $product->available_stock += $this->quantity;
            $product->save();

            $this->is_consumed = true;
            $this->save();

            Cache::forget("product:$this->product_id");

            Log::info('Hold released', [
                'hold_id' => $this->id,
                'product_id' => $this->product_id,
                'quantity' => $this->quantity,
                'new_available_stock' => $product->available_stock,
            ]);
        });
    }

    public static function findExpired()
    {
        return self::where('expires_at', '<', now())
            ->where('is_consumed', false)
            ->get();
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function order(): HasOne
    {
        return $this->hasOne(Order::class);
    }
}
