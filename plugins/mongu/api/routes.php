<?php

use Illuminate\Http\Request;
use App\Classes\PaymentService;

// October сам подхватывает routes.php из корня плагина, а Plugin::boot() подключает его вручную.
// Защита, чтобы роут не регистрировался дважды.
if (defined('MONGU_API_ROUTES_LOADED')) {
    return;
}
define('MONGU_API_ROUTES_LOADED', true);

// Вся логика — в PaymentService::webhook(). POST для банка; GET отвечает 405.
Route::any('api/v1/payment/webhook', function (Request $request) {
    return PaymentService::webhook($request);
});
