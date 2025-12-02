<?php

use App\Jobs\ReleaseExpiredHolds;
use App\Models\Hold;
use App\Models\Product;
use function Pest\Laravel\postJson;

it('can create hold', function () {
   $product = Product::create([
       'name' => 'Test Product',
       'price' => 99.99,
       'total_stock' => 100,
       'available_stock' => 100,
   ]);

   postJson('api/holds', [
       'product_id' => $product->id,
       'quantity' => 10,
   ])
       ->assertCreated()
       ->assertJsonStructure([
           'hold_id',
           'product_id',
           'quantity',
           'expires_at',
       ]);

   expect($product->fresh()->available_stock)->toBe(90);
});

it('cannot create hold with insufficient stock', function () {
    $product = Product::create([
        'name' => 'Test Product',
        'price' => 99.99,
        'total_stock' => 10,
        'available_stock' => 5,
    ]);

    postJson('api/holds', [
        'product_id' => $product->id,
        'quantity' => 10,
    ])
        ->assertStatus(400)
        ->assertJson([
            'error' => 'Insufficient stock available',
        ]);

    expect($product->fresh()->available_stock)->toBe(5);
});

it('parallel holds do not oversell', function () {
    $product = Product::create([
        'name' => 'Test Product',
        'price' => 99.99,
        'total_stock' => 10,
        'available_stock' => 10,
    ]);

    $successCount = 0;
    $failureCount = 0;

    for ($i = 0; $i < 5; ++$i) {
        try {
            Hold::createWithStockReservation($product->id, 8);
            $successCount++;
        } catch (Exception $e) {
            $failureCount++;
        }
    }

    expect($successCount)->toBe(1)
        ->and($failureCount)->toBe(4)
        ->and($product->fresh()->available_stock)->toBe(2);
});

it('expired holds release stock', function () {
    $product = Product::create([
        'name' => 'Test Product',
        'price' => 99.99,
        'total_stock' => 100,
        'available_stock' => 100,
    ]);

    $hold = Hold::create([
        'product_id' => $product->id,
        'quantity' => 20,
        'expires_at' => now()->subMinute(),
        'is_consumed' => false,
    ]);

    $product->available_stock -= 20;
    $product->save();

    ReleaseExpiredHolds::dispatchSync();

    expect($product->fresh()->available_stock)->toBe(100)
        ->and($hold->fresh()->is_consumed)->toBeTrue();
});

it('consumed holds cannot be released twice', function () {
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

    $hold->release();
    expect($product->fresh()->available_stock)->toBe(100);

    $hold->release();
    expect($product->fresh()->available_stock)->toBe(100);
});

it('validates hold before creating order', function () {
    $product = Product::create([
        'name' => 'Test Product',
        'price' => 99.99,
        'total_stock' => 100,
        'available_stock' => 100,
    ]);

    $validHold = Hold::create([
        'product_id' => $product->id,
        'quantity' => 10,
        'expires_at' => now()->addMinutes(2),
        'is_consumed' => false,
    ]);
    expect($validHold->isValid())->toBeTrue();

    $expiredHold = Hold::create([
        'product_id' => $product->id,
        'quantity' => 10,
        'expires_at' => now()->subMinute(),
        'is_consumed' => false,
    ]);
    expect($expiredHold->isValid())->toBeFalse();

    $consumedHold = Hold::create([
        'product_id' => $product->id,
        'quantity' => 10,
        'expires_at' => now()->addMinutes(2),
        'is_consumed' => true,
    ]);
    expect($consumedHold->isValid())->toBeFalse();
});
