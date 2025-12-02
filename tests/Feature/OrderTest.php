<?php

use App\Models\Hold;
use App\Models\Order;
use App\Models\Product;
use function Pest\Laravel\postJson;

it('can create order from valid hold', function () {
   $product = Product::create([
       'name' => 'Test Product',
       'price' => 99.99,
       'total_stock' => 100,
       'available_stock' => 100,
   ]);

   $hold = Hold::createWithStockReservation($product->id, 10);

   postJson('api/orders', [
       'hold_id' => $hold->id,
   ])
       ->assertCreated()
       ->assertJson([
           'hold_id' => $hold->id,
           'product_id' => $product->id,
           'quantity' => 10,
           'total_price' => 999.90,
           'status' => 'pending'
       ]);

   expect($hold->fresh()->is_consumed)->toBeTrue()
       ->and($product->fresh()->available_stock)->toBe(90);
});

it('cannot create order from expired hold', function () {
    $product = Product::create([
        'name' => 'Test Product',
        'price' => 99.99,
        'total_stock' => 100,
        'available_stock' => 90,
    ]);

    $hold = Hold::create([
        'product_id' => $product->id,
        'quantity' => 10,
        'expires_at' => now()->subMinute(),
        'is_consumed' => false,
    ]);

    postJson('api/orders', [
        'hold_id' => $hold->id,
    ])
        ->assertStatus(400)
        ->assertJson([
            'error' => 'Hold has expired',
        ]);
});

it('cannot use same hold twice', function () {
    $product = Product::create([
        'name' => 'Test Product',
        'price' => 99.99,
        'total_stock' => 100,
        'available_stock' => 100,
    ]);

    $hold = Hold::createWithStockReservation($product->id, 10);

    postJson('api/orders', [
        'hold_id' => $hold->id,
    ])->assertCreated();
    postJson('api/orders', [
        'hold_id' => $hold->id,
    ])
        ->assertStatus(400)
        ->assertJson([
            'error' => 'Hold has already been used',
        ]);

    expect(Order::count())->toBe(1);
});

it('cancelled order releases stock', function () {
    $product = Product::create([
        'name' => 'Test Product',
        'price' => 99.99,
        'total_stock' => 100,
        'available_stock' => 100,
    ]);

    $hold = Hold::createWithStockReservation($product->id, 10);
    $order = Order::createFromHold($hold->id);

    expect($product->fresh()->available_stock)->toBe(90);

    $order->cancel();

    expect($product->fresh()->available_stock)->toBe(100)
        ->and($order->fresh()->status)->toBe('cancelled');
});

it('paid order keeps stock reserved', function () {
    $product = Product::create([
        'name' => 'Test Product',
        'price' => 99.99,
        'total_stock' => 100,
        'available_stock' => 100,
    ]);

    $hold = Hold::createWithStockReservation($product->id, 10);
    $order = Order::createFromHold($hold->id);

    $order->markAsPaid();

    expect($product->fresh()->available_stock)->toBe(90)
        ->and($order->fresh()->status)->toBe('paid');
});

it('order status transitions are idempotent', function () {
    $product = Product::create([
        'name' => 'Test Product',
        'price' => 99.99,
        'total_stock' => 100,
        'available_stock' => 100,
    ]);

    $hold = Hold::createWithStockReservation($product->id, 10);
    $order = Order::createFromHold($hold->id);

    $order->markAsPaid();
    $order->markAsPaid();
    $order->markAsPaid();

    expect($order->fresh()->status)->toBe('paid')
        ->and($product->fresh()->available_stock)->toBe(90);
});

it('cannot cancel already paid order', function () {
    $product = Product::create([
        'name' => 'Test Product',
        'price' => 99.99,
        'total_stock' => 100,
        'available_stock' => 100,
    ]);

    $hold = Hold::createWithStockReservation($product->id, 10);
    $order = Order::createFromHold($hold->id);

    $order->markAsPaid();
    $order->cancel();

    expect($order->fresh()->status)->toBe('paid')
        ->and($product->fresh()->available_stock)->toBe(90);
});
