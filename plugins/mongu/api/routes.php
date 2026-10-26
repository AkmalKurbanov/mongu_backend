<?php

use Illuminate\Http\Request;
use Tailor\Models\EntryRecord;
use Illuminate\Support\Facades\Log;

// Обработка GET (если открыть в браузере) и POST (для банка)
Route::any('api/v1/payment/webhook', function (Request $request) {
    if ($request->isMethod('get')) {
        return response()->json([
            'status'  => 'error',
            'message' => 'Method not allowed. Webhook endpoint expects POST requests from the bank.'
        ], 405);
    }

    $data = $request->all();
    if (empty($data)) {
        $data = json_decode($request->getContent(), true) ?? [];
    }

    $incomingSecret = $request->header('X-Webhook-Secret') ?? $data['secret'] ?? '';
    $mySecretKey = env('BANK_WEBHOOK_SECRET', 'fallback_secret');

    if (!hash_equals($mySecretKey, $incomingSecret)) {
        return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
    }

    $secureToken = $data['secure_token'] ?? null;
    $orderId = $data['order_id'] ?? null;
    $status = strtolower($data['status'] ?? '');

    $order = null;
    if ($secureToken) {
        $order = EntryRecord::inSection('Orders')->where('secure_token', $secureToken)->first()
              ?? EntryRecord::inSection('StayBookings')->where('secure_token', $secureToken)->first();
    } elseif ($orderId) {
        $order = EntryRecord::inSection('Orders')->find($orderId)
              ?? EntryRecord::inSection('StayBookings')->find($orderId);
    }

    if (!$order) {
        return response()->json(['status' => 'error', 'message' => 'Order not found'], 404);
    }

    if ($order->status === 'paid') {
        return response()->json(['status' => 'success', 'message' => 'Already paid'], 200);
    }

    if (in_array($status, ['success', 'paid', 'completed', 'succeeded'])) {
        if (isset($data['amount']) && floatval($data['amount']) > 0) {
            if (abs(floatval($data['amount']) - floatval($order->total_price)) > 0.01) {
                return response()->json(['status' => 'error', 'message' => 'Amount mismatch'], 400);
            }
        }

        $order->status = 'paid';
        $order->transaction_id = $data['transaction_id'] ?? ('BANK-' . time());
        $order->save();

        Log::info("Order #{$order->id} successfully paid via webhook.");
        return response()->json(['status' => 'success', 'message' => 'Order updated']);
    }

    $failedStatuses = ['failed', 'canceled', 'expired', 'declined'];
    if (in_array($status, $failedStatuses, true)) {
        $order->status = 'canceled';
        $order->save();
        return response()->json(['status' => 'success', 'message' => 'Order cancelled']);
    }

    $order->status = 'payment_pending';
    $order->save();

    return response()->json(['status' => 'error', 'message' => 'Status invalid'], 400);
});