<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hold;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class HoldController extends Controller
{
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors(),
            ], 422);
        }

        try {
            $hold = Hold::createWithStockReservation(
                $request->product_id,
                $request->quantity
            );

            return response()->json([
                'hold_id' => $hold->id,
                'product_id' => $hold->product_id,
                'quantity' => $hold->quantity,
                'expires_at' => $hold->expires_at->toIso8601String(),
            ], 201);
        } catch (Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 400);
        }
    }
}
