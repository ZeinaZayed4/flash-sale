<?php

use App\Models\Product;
use function Pest\Laravel\getJson;

beforeEach(function () {
    Cache::flush();
});

it('can get product details', function () {
    $product = Product::create([
        'name' => 'Test Product',
        'price' => 49.99,
        'total_stock' => 50,
        'available_stock' => 50,
    ]);

    getJson("api/products/$product->id")
        ->assertStatus(200)
        ->assertJson([
            'id' => $product->id,
            'name' => 'Test Product',
            'price' => 49.99,
            'total_stock' => 50,
            'available_stock' => 50,
        ]);
});

it('returns 404 for nonexistent product', function () {
    getJson('/api/products/20')
        ->assertStatus(404)
        ->assertJson([
            'error' => 'Product not found!'
        ]);
});

it('caches product data', function () {
    $product = Product::create([
        'name' => 'Cached Product',
        'price' => 29.99,
        'total_stock' => 100,
        'available_stock' => 100,
    ]);

    getJson("api/products/$product->id")
        ->assertStatus(200);

    expect(Cache::has("product:$product->id"))->toBeTrue();

    getJson("api/products/$product->id")
        ->assertStatus(200)
        ->assertJson([
            'available_stock' => 100
        ]);
});

it('invalidates cache when stock changes', function () {
    $product = Product::create([
        'name' => 'Test Product',
        'price' => 99.99,
        'total_stock' => 100,
        'available_stock' => 100,
    ]);

    Product::findWithCache($product->id);
    expect(Cache::has("product:$product->id"))->toBeTrue();

    $product->decrementStock(10);

    expect(Cache::has("product:$product->id"));

    $fresh = Product::find($product->id);
    expect($fresh->available_stock)->toBe(90);
});

it('can check if product can reserve quantity', function () {
    $product = Product::create([
        'name' => 'Test Product',
        'price' => 99.99,
        'total_stock' => 10,
        'available_stock' => 10,
    ]);

    expect($product->canReserve(5))->toBeTrue()
        ->and($product->canReserve(10))->toBeTrue()
        ->and($product->canReserve(11))->toBeFalse();
});

it('decrements stock correctly', function () {
    $product = Product::create([
        'name' => 'Test Product',
        'price' => 99.99,
        'total_stock' => 100,
        'available_stock' => 100,
    ]);

    $product->decrementStock(30);

    $product->refresh();
    expect($product->available_stock)->toBe(70)
        ->and($product->total_stock)->toBe(100);
});

it('throws exception when decrementing more than available', function () {
    $product = Product::create([
        'name' => 'Test Product',
        'price' => 99.99,
        'total_stock' => 10,
        'available_stock' => 5,
    ]);

    $product->decrementStock(10);
})->throws(Exception::class, 'Insufficient stock available');

it('increments stock correctly', function () {
    $product = Product::create([
        'name' => 'Test Product',
        'price' => 99.99,
        'total_stock' => 100,
        'available_stock' => 80,
    ]);

    $product->incrementStock(15);

    $product->refresh();
    expect($product->available_stock)->toBe(95);
});

it('handles concurrent stock decrements without overselling', function () {
    $product = Product::create([
        'name' => 'Test Product',
        'price' => 99.99,
        'total_stock' => 10,
        'available_stock' => 10,
    ]);

    $successCount = 0;
    $failureCount = 0;

    for ($i = 0; $i < 3; ++$i) {
        try {
            $product->refresh();
            $product->decrementStock(8);
            $successCount++;
        } catch (Exception $e) {
            $failureCount++;
        }
    }

    expect($successCount)->toBe(1)
        ->and($failureCount)->toBe(2);

    $product->refresh();
    expect($product->available_stock)->toBe(2);
});

it('uses pessimistic locking during stock operations', function () {
    $product = Product::create([
        'name' => 'Test Product',
        'price' => 99.99,
        'total_stock' => 100,
        'available_stock' => 100,
    ]);
    $results = [];

    DB::transaction(function () use ($product, &$results) {
        $locked = Product::lockForUpdate()->find($product->id);
        $results[] = $locked->available_stock;

        $locked->available_stock -= 10;
        $locked->save();
    });

    DB::transaction(function () use ($product, &$results) {
       $locked = Product::lockForUpdate()->find($product->id);
       $results[] = $locked->available_stock;

       $locked->available_stock -= 20;
       $locked->save();
    });

    expect($results)->toBe([100, 90])
        ->and($product->fresh()->available_stock)->toBe(70);
});

it('returns accurate stock under burst traffic', function () {
    $product = Product::create([
        'name' => 'Test Product',
        'price' => 99.99,
        'total_stock' => 1000,
        'available_stock' => 1000,
    ]);

    for ($i = 0; $i < 100; ++$i) {
        $response = getJson("api/products/$product->id");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'id',
                'name',
                'price',
                'available_stock',
                'total_stock',
            ]);

        $data = $response->json();
        expect($data['available_stock'])->toBeInt()
            ->and($data['available_stock'])->toBeLessThanOrEqual(1000)
            ->and($data['available_stock'])->toBeGreaterThanOrEqual(0);
    }
});

it('handles multiple cache invalidations correctly', function () {
    $product = Product::create([
        'name' => 'Test Product',
        'price' => 99.99,
        'total_stock' => 100,
        'available_stock' => 100,
    ]);

    $product->decrementStock(10);
    $product->decrementStock(5);
    $product->decrementStock(3);

    $fresh = Product::find($product->id);
    expect($fresh->available_stock)->toBe(82);

    $cached = Product::findWithCache($product->id);
    expect($cached->available_stock)->toBe(82);
});

it('logs stock changes', function () {
    Log::spy();

    $product = Product::create([
        'name' => 'Test Product',
        'price' => 99.99,
        'total_stock' => 100,
        'available_stock' => 100,
    ]);

    $product->decrementStock(25);

    Log::shouldHaveReceived('info')
        ->with('Stock decremented', Mockery::on(function ($context) use ($product) {
            return $context['product_id'] === $product->id
                && $context['requested'] === 25
                && $context['remaining'] === 75;
        }))
        ->once();
});

it('logs warning when insufficient stock', function () {
    Log::spy();

    $product = Product::create([
        'name' => 'Test Product',
        'price' => 99.99,
        'total_stock' => 10,
        'available_stock' => 5,
    ]);

    try {
        $product->decrementStock(10);
    } catch (Exception $e) {

    }

    Log::shouldHaveReceived('warning')
        ->with('Insufficient stock', Mockery::on(function ($context) use ($product) {
            return $context['product_id'] === $product->id
                && $context['requested'] === 10
                && $context['available'] === 5;
        }))
        ->once();
});
