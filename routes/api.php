<?php

use App\Http\Controllers\Api\HoldController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentWebhookController;
use App\Http\Controllers\Api\ProductController;
use Illuminate\Support\Facades\Route;

Route::get('products/{id}', [ProductController::class, 'show']);

Route::post('holds/', [HoldController::class, 'store']);

Route::post('orders/', [OrderController::class, 'store']);

Route::post('payments/webhook', [PaymentWebhookController::class, 'handle']);
