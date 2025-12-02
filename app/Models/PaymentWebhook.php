<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentWebhook extends Model
{
    protected $fillable = [
        'idempotency_key',
        'order_id',
        'status',
        'payload',
        'processed',
    ];

    protected $casts = [
        'payload' => 'array',
        'processed' => 'boolean',
    ];

    const STATUS_SUCCESS = 'success';
    const STATUS_FAILURE = 'failure';

    public static function process($idempotencyKey, $orderId, $status, $payload = [])
    {
        return DB::transaction(function () use ($idempotencyKey, $orderId, $status, $payload) {
            $existing = self::where('idempotency_key', $idempotencyKey)->first();

            if ($existing) {
                Log::info('Webhook already processed', [
                    'idempotency_key' => $idempotencyKey,
                    'existing_webhook_id' => $existing->id,
                    'existing_order_id' => $existing->order_id,
                    'processed' => $existing->processed,
                ]);

                return [
                    'status' => 'already_processed',
                    'webhook_id' => $existing->id,
                    'order_id' => $existing->order_id,
                    'payment_status' => $existing->status,
                ];
            }

            $order = Order::find($orderId);

            if (!$order) {
                Log::warning('Webhook arrived before order exists', [
                    'idempotency_key' => $idempotencyKey,
                    'order_id' => $orderId,
                    'status' => $status,
                ]);

                $payloadWithOrderId = array_merge($payload, ['order_id' => $orderId]);

                $webhook = self::create([
                    'idempotency_key' => $idempotencyKey,
                    'order_id' => null,
                    'status' => $status,
                    'payload' => $payloadWithOrderId,
                    'processed' => false,
                ]);

                return [
                    'status' => 'order_not_found',
                    'webhook_id' => $webhook->id,
                    'message' => 'Order not found, webhook stored for retry',
                ];
            }

            $webhook = self::create([
                'idempotency_key' => $idempotencyKey,
                'order_id' => $order->id,
                'status' => $status,
                'payload' => $payload,
                'processed' => false,
            ]);

            if ($status === self::STATUS_SUCCESS) {
                $order->markAsPaid();

                Log::info('Payment successful, order marked as paid', [
                    'webhook_id' => $webhook->id,
                    'order_id' => $order->id,
                    'idempotency_key' => $idempotencyKey,
                ]);
            } elseif ($status === self::STATUS_FAILURE) {
                $order->cancel();

                Log::info('Payment failed, order cancelled and stock released', [
                    'webhook_id' => $webhook->id,
                    'order_id' => $order->id,
                    'idempotency_key' => $idempotencyKey,
                ]);
            }

            $webhook->processed = true;
            $webhook->save();

            return [
                'status' => 'processed',
                'webhook_id' => $webhook->id,
                'order_id' => $order->id,
                'payment_status' => $status,
            ];
        });
    }

    public static function retryPendingWebhooks(): int
    {
        $pending = self::where('processed', false)
            ->whereNull('order_id')
            ->get();

        if ($pending->isEmpty()) {
            return 0;
        }

        Log::info('Retrying pending webhooks', [
            'count' => $pending->count(),
        ]);

        $processed = 0;

        foreach ($pending as $webhook) {
            $orderId = $webhook->payload['order_id'] ?? null;

            if (!$orderId) {
                Log::error('Webhook missing order_id in payload', [
                    'webhook_id' => $webhook->id,
                ]);
                continue;
            }

            $order = Order::find($orderId);

            if (!$order) {
                Log::debug('Order still not found for webhook', [
                    'webhook_id' => $webhook->id,
                    'order_id' => $orderId,
                ]);
                continue;
            }

            DB::transaction(function () use ($webhook, $order) {
                $webhook->order_id = $order->id;
                $webhook->save();

                if ($webhook->status === self::STATUS_SUCCESS) {
                    $order->markAsPaid();
                } elseif ($webhook->status === self::STATUS_FAILURE) {
                    $order->cancel();
                }

                $webhook->processed = true;
                $webhook->save();

                Log::info('Pending webhook processes on retry', [
                    'webhook_id' => $webhook->id,
                    'order_id' => $order->id,
                ]);
            });

            $processed++;
        }

        return $processed;
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
