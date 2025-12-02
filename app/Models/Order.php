<?php

namespace App\Models;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use function Laravel\Prompts\select;

class Order extends Model
{
    protected $fillable = [
        'hold_id',
        'product_id',
        'quantity',
        'total_price',
        'status',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'total_price' => 'decimal:2',
    ];

    const STATUS_PENDING = 'pending';
    const STATUS_PAID = 'paid';
    const STATUS_CANCELLED = 'cancelled';

    public static function createFromHold($id)
    {
        return DB::transaction(function () use ($id) {
            $hold = Hold::lockForUpdate()->find($id);

            if (!$hold) {
                throw new Exception('Hold not found!');
            }

            if ($hold->is_consumed) {
                Log::warning('Attempted to use already consumed hold', [
                    'hold_id' => $id,
                ]);
                throw new Exception('Hold has already been used');
            }

            if ($hold->expires_at->isPast()) {
                Log::warning('Attempted to use expired hold', [
                    'hold_id' => $id,
                    'expires_at' => $hold->expires_at,
                ]);
                throw new Exception('Hold has expired');
            }

            $product = $hold->product;

            $order = self::create([
                'hold_id' => $id,
                'product_id' => $hold->product_id,
                'quantity' => $hold->quantity,
                'total_price' => $product->price * $hold->quantity,
                'status' => self::STATUS_PENDING,
            ]);

            $hold->consume();

            Log::info('Order created', [
                'order_id' => $order->id,
                'hold_id' => $id,
                'product_id' => $product->id,
                'quantity' => $hold->quantity,
                'total_price' => $order->total_price,
                'status' => $order->status,
            ]);

            return $order;
        });
    }

    public function markAsPaid()
    {
        if ($this->status === self::STATUS_PAID) {
            Log::info('Order already paid, skipping', [
                'order_id' => $this->id,
            ]);
            return;
        }

        $this->status = self::STATUS_PAID;
        $this->save();

        Log::info('Order marked as paid', [
            'order_id' => $this->id,
            'product_id' => $this->product_id,
            'quantity' => $this->quantity,
        ]);
    }

    public function cancel()
    {
        if ($this->status === self::STATUS_CANCELLED) {
            Log::info('Order already cancelled, skipping', [
                'order_id' => $this->id,
            ]);
            return;
        }

        if ($this->status === self::STATUS_PAID) {
            Log::info('Order already paid, skipping', [
                'order_id' => $this->id,
            ]);
            return;
        }

        DB::transaction(function () {
            $product = Product::lockForUpdate()->find($this->product_id);

            $product->available_stock += $this->quantity;
            $product->save();

            $this->status = self::STATUS_CANCELLED;
            $this->save();

            Cache::forget("product:$this->product_id");

            Log::info('Order cancelled and stock released', [
                'order_id' => $this->id,
                'product_id' => $this->product_id,
                'quantity' => $this->quantity,
                'new_available_stock' => $product->available_stock,
            ]);
        });
    }

    public function canBeUpdated(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function hold(): BelongsTo
    {
        return $this->belongsTo(Hold::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function paymentWebhook(): HasOne
    {
        return $this->hasOne(PaymentWebhook::class);
    }
}
