<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function show($id)
    {
        $product = Product::findWithCache($id);

        if (!$product) {
            return response()->json([
                'error' => 'Product not found!'
            ], 404);
        }

        return response()->json([
            'id'  => $id,
            'name' => $product->name,
            'price' => $product->price,
            'available_stock' => $product->available_stock,
            'total_stock' => $product->total_stock,
        ]);
    }
}
