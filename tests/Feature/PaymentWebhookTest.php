<?php

use App\Jobs\RetryPendingWebhooks;
use App\Models\Hold;
use App\Models\Order;
use App\Models\PaymentWebhook;
use App\Models\Product;
use Illuminate\Database\QueryException;
use function Pest\Laravel\postJson;

it('processes successful payment webhook', function () {
    $product = Product::create([
        'name' => 'Test Product',
        'price' => 99.99,
        'total_stock' => 100,
        'available_stock' => 100,
    ]);

    $hold = Hold::createWithStockReservation($product->id, 10);
    $order = Order::createFromHold($hold->id);

    postJson('api/payments/webhook', [
        'idempotency_key' => 'payment_123',
        'order_id' => $order->id,
        'status' => 'success',
    ])
        ->assertOk()
        ->assertJson([
            'status' => 'processed',
            'order_id' => $order->id,
            'payment_status' => 'success',
        ]);

    expect($order->fresh()->status)->toBe('paid')
        ->and($product->fresh()->available_stock)->toBe(90);
});

it('processes failed payment webhook and releases stock', function () {
    $product = Product::create([
        'name' => 'Test Product',
        'price' => 99.99,
        'total_stock' => 100,
        'available_stock' => 100,
    ]);

    $hold = Hold::createWithStockReservation($product->id, 10);
    $order = Order::createFromHold($hold->id);

    expect($product->fresh()->available_stock)->toBe(90);

    postJson('api/payments/webhook', [
        'idempotency_key' => 'payment_456',
        'order_id' => $order->id,
        'status' => 'failure',
    ])
        ->assertOk()
        ->assertJson([
            'status' => 'processed',
            'payment_status' => 'failure',
        ]);

    expect($order->fresh()->status)->toBe('cancelled')
        ->and($product->fresh()->available_stock)->toBe(100);
});

it('handles duplicate webhooks idempotently', function () {
    $product = Product::create([
        'name' => 'Test Product',
        'price' => 99.99,
        'total_stock' => 100,
        'available_stock' => 100,
    ]);

    $hold = Hold::createWithStockReservation($product->id, 10);
    $order = Order::createFromHold($hold->id);

    $webhookData = [
        'idempotency_key' => 'payment_duplicate_test',
        'order_id' => $order->id,
        'status' => 'success',
    ];

    postJson('api/payments/webhook', $webhookData)->assertOk();

    postJson('api/payments/webhook', $webhookData)
        ->assertOk()
        ->assertJson(['status' => 'already_processed']);

    postJson('api/payments/webhook', $webhookData)
        ->assertOk()
        ->assertJson(['status' => 'already_processed']);

    expect(PaymentWebhook::where('idempotency_key', 'payment_duplicate_test')->count())
        ->toBe(1)
        ->and($order->fresh()->status)->toBe('paid');
});

it('handles webhook arriving before order creation', function () {
    $response = postJson('api/payments/webhook', [
        'idempotency_key' => 'payment_early_bird',
        'order_id' => 9999,
        'status' => 'success',
    ]);

    $response->assertStatus(202)
        ->assertJson([
            'status' => 'order_not_found',
        ]);

    $webhook = PaymentWebhook::where('idempotency_key', 'payment_early_bird')->first();

    expect($webhook)->not->toBeNull()
        ->and($webhook->processed)->toBeFalse()
        ->and($webhook->order_id)->toBeNull();
});

it('retries pending webhooks when order becomes available', function () {
    postJson('api/payments/webhook', [
        'idempotency_key' => 'payment_retry_test',
        'order_id' => 888,
        'status' => 'success',
    ])->assertStatus(202);

    $webhook = PaymentWebhook::where('idempotency_key', 'payment_retry_test')->first();
    expect($webhook->processed)->toBeFalse();

    $product = Product::create([
        'name' => 'Test Product',
        'price' => 99.99,
        'total_stock' => 100,
        'available_stock' => 100,
    ]);

    $hold = Hold::createWithStockReservation($product->id, 10);
    $order = Order::createFromHold($hold->id);

    DB::table('orders')->where('id', $order->id)->update([
        'id' => 888,
    ]);

    $order = $order->newQuery()->find(888);

    RetryPendingWebhooks::dispatchSync();

    $webhook->refresh();

    expect($webhook->processed)->toBeTrue()
        ->and($webhook->order_id)->toBe(888)
        ->and($order->fresh()->status)->toBe('paid');
});

it('prevents double-processing via database unique constraint', function () {
    $product = Product::create([
        'name' => 'Test Product',
        'price' => 99.99,
        'total_stock' => 100,
        'available_stock' => 100,
    ]);

    $hold = Hold::createWithStockReservation($product->id, 10);
    $order = Order::createFromHold($hold->id);

    PaymentWebhook::create([
        'idempotency_key' => 'unique_test_123',
        'order_id' => $order->id,
        'status' => 'success',
        'processed' => true,
    ]);

    expect(fn() => PaymentWebhook::create([
        'idempotency_key' => 'unique_test_123',
        'order_id' => $order->id,
        'status' => 'success',
        'processed' => false,
    ]))->toThrow(QueryException::class);
});

it('handles multiple webhooks for different orders simultaneously', function () {
    $product = Product::create([
        'name' => 'Test Product',
        'price' => 99.99,
        'total_stock' => 100,
        'available_stock' => 100,
    ]);

    $orders = [];

    for ($i = 0; $i < 5; $i++) {
        $hold = Hold::createWithStockReservation($product->id, 2);
        $orders[] = Order::createFromHold($hold->id);
    }

    foreach ($orders as $index => $order) {
        postJson('api/payments/webhook', [
            'idempotency_key' => "payment_multi_$index",
            'order_id' => $order->id,
            'status' => 'success',
        ])->assertOk();
    }

    foreach ($orders as $order) {
        expect($order->fresh()->status)->toBe('paid');
    }

    expect($product->fresh()->available_stock)->toBe(90);
});

it('logs webhook processing metrics', function () {
    Log::spy();

    $product = Product::create([
        'name' => 'Test Product',
        'price' => 99.99,
        'total_stock' => 100,
        'available_stock' => 100,
    ]);

    $hold = Hold::createWithStockReservation($product->id, 10);
    $order = Order::createFromHold($hold->id);

    postJson('api/payments/webhook', [
        'idempotency_key' => 'payment_logging_test',
        'order_id' => $order->id,
        'status' => 'success',
    ]);

    Log::shouldHaveReceived('info')
        ->with('Payment webhook received', Mockery::on(function ($context) use ($order) {
            return $context['idempotency_key'] === 'payment_logging_test'
                && $context['order_id'] === $order->id
                && $context['status'] === 'success';
        }));

    Log::shouldHaveReceived('info')
        ->with('Payment successful, order marked as paid', Mockery::type('array'));
});
