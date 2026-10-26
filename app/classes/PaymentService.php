<?php namespace App\Classes;

use Tailor\Models\EntryRecord;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class PaymentService
{
    /**
     * Создание ссылки на оплату через выбранный банк
     */
    public static function createPaymentLink(array $payload): ?string
    {
        $apiUrl = env('BANK_API_URL', 'https://api.bank.kg/v1/invoices');
        $apiKey = env('BANK_API_KEY');
        $merchantId = env('BANK_MERCHANT_ID');

        if (!$apiKey || !$merchantId) {
            Log::warning('Bank API credentials missing in .env');
            return null;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Accept'        => 'application/json',
            ])->post($apiUrl, [
                'merchant_id'  => $merchantId,
                'order_id'     => $payload['order_id'],
                'amount'       => $payload['amount'],
                'currency'     => $payload['currency'] ?? 'USD',
                'description'  => $payload['description'],
                'success_url'  => $payload['success_url'],
                'failure_url'  => $payload['failure_url'],
                'customer'     => [
                    'name'  => $payload['client_name'],
                    'email' => $payload['client_email'],
                    'phone' => $payload['client_phone'],
                ],
                'items'        => $payload['items'] ?? [],
            ]);

            if ($response->successful()) {
                return $response->json('payment_url') ?? $response->json('url');
            }

            Log::error('Bank payment link creation failed: ' . $response->body());
            return null;

        } catch (\Throwable $e) {
            Log::error('Bank payment exception: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Универсальный обработчик Callback / Webhook от банка
     */
    public static function handleWebhook(array $bankData): bool
    {
        $token = $bankData['secure_token'] ?? null;
        $orderId = $bankData['order_id'] ?? null;
        $status = strtolower($bankData['status'] ?? '');
        $transactionId = $bankData['transaction_id'] ?? 'BANK-' . time();

        if (!$token && !$orderId) {
            Log::warning('Payment Webhook error: Token or Order ID missing.', $bankData);
            return false;
        }

        $order = null;
        if ($token) {
            $order = EntryRecord::inSection('Orders')->where('secure_token', $token)->first()
                  ?? EntryRecord::inSection('StayBookings')->where('secure_token', $token)->first();
        } elseif ($orderId) {
            $order = EntryRecord::inSection('Orders')->find($orderId)
                  ?? EntryRecord::inSection('StayBookings')->find($orderId);
        }

        if (!$order) {
            Log::warning('Payment Webhook error: Order not found.', $bankData);
            return false;
        }

        // Защита от повторной обработки (идемпотентность)
        if ($order->status === 'paid') {
            Log::info("Order #{$order->id} is already paid. Webhook skipped.");
            return true;
        }

        $successStatuses = ['success', 'paid', 'completed', 'succeeded'];

        if (in_array($status, $successStatuses, true)) {
            if (isset($bankData['amount']) && floatval($bankData['amount']) > 0) {
                $bankAmount = floatval($bankData['amount']);
                if (abs($bankAmount - floatval($order->total_price)) > 0.01) {
                    Log::error("Payment Webhook amount mismatch for Order #{$order->id}. Expected: {$order->total_price}, Got: {$bankAmount}");
                    return false;
                }
            }

            $order->status = 'paid';
            $order->transaction_id = $transactionId;
            $order->save();

            Log::info("Order #{$order->id} successfully paid via Bank Webhook. Transaction: {$transactionId}");
            return true;
        }

        $failedStatuses = ['failed', 'canceled', 'expired', 'declined'];
        if (in_array($status, $failedStatuses, true)) {
            $order->status = 'canceled';
            $order->save();
            Log::warning("Order #{$order->id} payment failed or expired. Status set to canceled.");
            return true;
        }

        return false;
    }
}