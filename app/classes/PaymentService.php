<?php namespace App\Classes;

use Tailor\Models\EntryRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaymentService
{
    /**
     * ID у Orders и StayBookings пересекаются (у каждого блюпринта своя таблица),
     * поэтому банку уходит не голый id, а референс: "O-12" (тур) / "S-12" (проживание).
     */
    private const PREFIXES = ['Orders' => 'O', 'StayBookings' => 'S'];

    private const SUCCESS_STATUSES = ['success', 'paid', 'completed', 'succeeded'];
    private const FAILED_STATUSES  = ['failed', 'canceled', 'cancelled', 'expired', 'declined'];
    private const PENDING_STATUSES = ['pending', 'processing', 'in_progress', 'created'];

    public static function makeReference(string $section, $id): string
    {
        return (self::PREFIXES[$section] ?? 'X') . '-' . $id;
    }

    /** "O-12" → ['Orders', 12]; всё остальное → null */
    private static function parseReference(string $reference): ?array
    {
        if (!preg_match('/^([OS])-(\d+)$/', $reference, $m)) {
            return null;
        }
        $section = array_search($m[1], self::PREFIXES, true);

        return $section !== false ? [$section, (int) $m[2]] : null;
    }

    /**
     * Создание ссылки на оплату через выбранный банк
     */
    public static function createPaymentLink(array $payload): ?string
    {
        $apiUrl     = env('BANK_API_URL', 'https://api.bank.kg/v1/invoices');
        $apiKey     = env('BANK_API_KEY');
        $merchantId = env('BANK_MERCHANT_ID');

        if (!$apiKey || !$merchantId) {
            Log::warning('Bank API credentials missing in .env');
            return null;
        }

        if (empty($payload['order_id']) || floatval($payload['amount'] ?? 0) <= 0) {
            Log::warning('Payment link not created: empty order_id or non-positive amount.');
            return null;
        }

        try {
            // timeout: запрос идёт прямо во время сохранения записи в админке — не даём ему повиснуть.
            // retry намеренно нет: POST создаёт инвойс, повтор может создать дубль.
            $response = Http::timeout(10)->withHeaders([
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
                $url = $response->json('payment_url') ?? $response->json('url');
                if (!$url) {
                    Log::error('Bank responded OK but returned no payment URL: ' . Str::limit($response->body(), 500));
                }
                return $url ?: null;
            }

            Log::error('Bank payment link creation failed: HTTP ' . $response->status() . ' ' . Str::limit($response->body(), 500));
            return null;

        } catch (\Throwable $e) {
            Log::error('Bank payment exception: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * HTTP-вход для вебхука банка. Роут только вызывает этот метод.
     */
    public static function webhook(Request $request)
    {
        if (!$request->isMethod('post')) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Method not allowed. Webhook endpoint expects POST requests from the bank.',
            ], 405);
        }

        // Fail closed: без секрета в .env вебхук не принимаем вообще.
        $secret = (string) env('BANK_WEBHOOK_SECRET', '');
        if ($secret === '') {
            Log::critical('Payment webhook rejected: BANK_WEBHOOK_SECRET is not configured.');
            return response()->json(['status' => 'error', 'message' => 'Webhook is not configured'], 503);
        }

        // Данные берём только из тела запроса (не из query-строки: она попадает в логи).
        $data = $request->isJson()
            ? (array) $request->json()->all()
            : (array) $request->request->all();

        if (empty($data)) {
            $decoded = json_decode($request->getContent(), true);
            $data = is_array($decoded) ? $decoded : [];
        }

        $incoming = $request->header('X-Webhook-Secret') ?? ($data['secret'] ?? '');
        if (!is_string($incoming) || !hash_equals($secret, $incoming)) {
            Log::warning('Payment webhook rejected: invalid secret.', ['ip' => $request->ip()]);
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }
        unset($data['secret']);

        [$code, $status, $message] = self::handleWebhook($data);

        return response()->json(['status' => $status, 'message' => $message], $code);
    }

    /**
     * Единая бизнес-логика вебхука (раньше была продублирована в routes.php).
     *
     * @return array [http_code, 'success'|'error', message]
     */
    public static function handleWebhook(array $data): array
    {
        $status    = strtolower(trim((string) ($data['status'] ?? '')));
        $reference = trim((string) ($data['order_id'] ?? ''));
        $token     = trim((string) ($data['secure_token'] ?? ''));

        if ($reference === '' && $token === '') {
            Log::warning('Payment webhook: order_id and secure_token are both missing.', self::sanitize($data));
            return [400, 'error', 'order_id or secure_token is required'];
        }

        try {
            // Транзакция + lockForUpdate: два одновременных вебхука не обработают заказ дважды.
            return DB::transaction(function () use ($data, $status, $reference, $token) {

                [$order, $section, $error] = self::locateRecord($reference, $token);

                if (!$order) {
                    Log::warning("Payment webhook: record not resolved ({$error}).", self::sanitize($data));
                    return $error === 'ambiguous'
                        ? [409, 'error', 'Ambiguous order reference']
                        : [404, 'error', 'Order not found'];
                }

                // Если банк прислал токен вместе с референсом — они обязаны указывать на одну запись.
                if ($token !== '' && !hash_equals((string) $order->secure_token, $token)) {
                    Log::warning("Payment webhook: token mismatch for {$section} #{$order->id}.");
                    return [404, 'error', 'Order not found'];
                }

                $label = "{$section} #{$order->id}";

                // Идемпотентность
                if ($order->status === 'paid') {
                    Log::info("{$label} is already paid. Webhook skipped.");
                    return [200, 'success', 'Already paid'];
                }

                // --- Успех ---
                if (in_array($status, self::SUCCESS_STATUSES, true)) {
                    $amount = $data['amount'] ?? null;
                    if (!is_numeric($amount) || floatval($amount) <= 0) {
                        Log::error("Payment webhook for {$label}: amount is missing or invalid.", self::sanitize($data));
                        return [400, 'error', 'Amount is required'];
                    }

                    $expected = floatval($order->total_price);
                    if (abs(floatval($amount) - $expected) > 0.01) {
                        Log::error("Payment webhook amount mismatch for {$label}. Expected: {$expected}, got: {$amount}");
                        return [400, 'error', 'Amount mismatch'];
                    }

                    if (!empty($data['currency'])) {
                        $expectedCurrency = strtoupper((string) ($order->currency ?: 'USD'));
                        if (strtoupper((string) $data['currency']) !== $expectedCurrency) {
                            Log::error("Payment webhook currency mismatch for {$label}. Expected: {$expectedCurrency}, got: {$data['currency']}");
                            return [400, 'error', 'Currency mismatch'];
                        }
                    }

                    if ($order->status === 'canceled') {
                        Log::warning("{$label} was canceled but the bank reports a successful payment — check manually.");
                    }

                    $transactionId = (string) ($data['transaction_id'] ?? ('BANK-' . time()));
                    $order->status         = 'paid';
                    $order->transaction_id = $transactionId;
                    $order->save();

                    Log::info("{$label} successfully paid via bank webhook. Transaction: {$transactionId}");
                    return [200, 'success', 'Order updated'];
                }

                // --- Неуспех ---
                if (in_array($status, self::FAILED_STATUSES, true)) {
                    $order->status = 'canceled';
                    $order->save();

                    Log::warning("{$label}: payment failed or expired (status '{$status}'). Set to canceled.");
                    return [200, 'success', 'Order cancelled'];
                }

                // --- Промежуточные статусы: отвечаем 200, чтобы банк не ретраил ---
                if (in_array($status, self::PENDING_STATUSES, true)) {
                    if (in_array($order->status, ['new', 'approved'], true)) {
                        $order->status = 'payment_pending';
                        $order->save();
                    }
                    return [200, 'success', 'Payment is pending'];
                }

                Log::warning("Payment webhook for {$label}: unknown status '{$status}'.", self::sanitize($data));
                return [400, 'error', 'Unknown status'];
            });
        } catch (\Throwable $e) {
            // 500 → банк повторит доставку позже
            Log::error('Payment webhook exception: ' . $e->getMessage());
            return [500, 'error', 'Internal error'];
        }
    }

    /**
     * Поиск записи. Порядок: референс (O-/S-) → токен → «старый» числовой id.
     *
     * @return array [?EntryRecord, ?string section, ?string error ('not_found'|'ambiguous')]
     */
    private static function locateRecord(string $reference, string $token): array
    {
        // 1. Референс O-12 / S-12 — однозначно
        if ($reference !== '' && ($parsed = self::parseReference($reference))) {
            [$section, $id] = $parsed;
            $record = EntryRecord::inSection($section)->where('id', $id)->lockForUpdate()->first();

            return [$record, $section, $record ? null : 'not_found'];
        }

        // 2. Токен
        if ($token !== '') {
            foreach (array_keys(self::PREFIXES) as $section) {
                $record = EntryRecord::inSection($section)->where('secure_token', $token)->lockForUpdate()->first();
                if ($record) {
                    return [$record, $section, null];
                }
            }
        }

        // 3. Совместимость с инвойсами, созданными до введения префиксов (order_id = голый id).
        //    Если такой id есть в обеих таблицах — однозначно определить нельзя, отказываем.
        if ($reference !== '' && ctype_digit($reference)) {
            $id    = (int) $reference;
            $found = [];
            foreach (array_keys(self::PREFIXES) as $section) {
                if (EntryRecord::inSection($section)->where('id', $id)->exists()) {
                    $found[] = $section;
                }
            }

            if (count($found) === 1) {
                $record = EntryRecord::inSection($found[0])->where('id', $id)->lockForUpdate()->first();
                return [$record, $found[0], $record ? null : 'not_found'];
            }
            if (count($found) > 1) {
                return [null, null, 'ambiguous'];
            }
        }

        return [null, null, 'not_found'];
    }

    /** Убираем секреты и платёжные данные из того, что пишем в лог */
    private static function sanitize(array $data): array
    {
        return Arr::except($data, ['secret', 'secure_token', 'token', 'signature', 'card', 'card_number', 'pan', 'cvv']);
    }
}
