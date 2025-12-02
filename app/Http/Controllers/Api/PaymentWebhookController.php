<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentWebhook;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class PaymentWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'idempotency_key' => ['required', 'string', 'max:255'],
            'order_id' => ['required', 'integer'],
            'status' => ['required', 'in:success,failure'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors(),
            ], 422);
        }

        $idempotency_key = $request->idempotency_key;
        $orderId = $request->order_id;
        $status = $request->status;
        $payload = $request->all();

        Log::info('Payment webhook received', [
            'idempotency_key' => $idempotency_key,
            'order_id' => $orderId,
            'status' => $status,
        ]);

        try {
            $result = PaymentWebhook::process(
                $idempotency_key,
                $orderId,
                $status,
                $payload
            );

            $httpStatus = match($result['status']) {
                'already_processed' => 200,
                'order_not_found' => 202,
                'processed' => 200,
                default => 200,
            };

            return response()->json($result, $httpStatus);
        } catch (Exception $e) {
            Log::error('Webhook processing failed', [
                'idempotency_key' => $idempotency_key,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Internal server error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
