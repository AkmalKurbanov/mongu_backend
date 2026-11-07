<?php namespace Mongu\Api;

use System\Classes\PluginBase;
use Tailor\Models\EntryRecord;
use App\Classes\PaymentService;
use App\Classes\BookingHelper;
use App\Classes\StayHelper;
use Illuminate\Support\Facades\Log;

class Plugin extends PluginBase
{
    /** Защита от рекурсии, пока мы сами дописываем payment_url в запись */
    private static $busy = false;

    public function pluginDetails()
    {
        return [
            'name'        => 'Mongu API',
            'description' => 'Payment webhooks and API endpoints',
            'author'      => 'Mongu',
            'icon'        => 'icon-code'
        ];
    }

    public function boot()
    {
        // routes.php сам защищён от повторного подключения (константа MONGU_API_ROUTES_LOADED)
        if (file_exists(__DIR__ . '/routes.php')) {
            include __DIR__ . '/routes.php';
        }

        // Автоматическая генерация ссылки при статусе approved
        EntryRecord::saved(function ($model) {
            if (self::$busy) {
                return;
            }

            $section = $this->sectionOf($model);
            if (!in_array($section, ['Orders', 'StayBookings'], true)) {
                return;
            }

            $this->syncPaymentLink($model, $section);
        });
    }

    private function syncPaymentLink($model, string $section): void
    {
        if ($model->status !== 'approved') {
            return;
        }

        $total = floatval($model->total_price);
        if ($total <= 0) {
            return;
        }

        $currency = strtoupper((string) ($model->currency ?: 'USD'));
        $url      = trim((string) $model->payment_url);

        // Ссылку, вставленную менеджером вручную (payment_method != 'Bank Gateway'), не трогаем.
        // Автоссылку пересоздаём, если после её выпуска изменили сумму или валюту.
        $isAuto = $model->payment_method === 'Bank Gateway';
        $linkAmount = $model->payment_link_amount;
        $stale = $isAuto
            && $linkAmount !== null && $linkAmount !== ''   // старые записи без «снимка» не считаем устаревшими
            && (
                abs(floatval($linkAmount) - $total) > 0.001
                || strtoupper((string) $model->payment_link_currency) !== $currency
            );

        if ($url !== '' && !$stale) {
            return;
        }

        $payload = ($section === 'Orders')
            ? BookingHelper::getPaymentPayload($model)
            : StayHelper::getPaymentPayload($model);

        $paymentUrl = !empty($payload) ? PaymentService::createPaymentLink($payload) : null;

        if (!$paymentUrl) {
            Log::error("Payment URL was NOT generated for {$section} #{$model->id}");
            $this->flashWarning('Не удалось создать ссылку на оплату в банке (см. лог). Сохраните запись ещё раз или вставьте ссылку вручную.');
            return;
        }

        self::$busy = true;
        try {
            $model->withoutEvents(function () use ($model, $paymentUrl, $total, $currency) {
                $model->payment_url           = $paymentUrl;
                $model->payment_method        = 'Bank Gateway';
                $model->payment_link_amount   = $total;
                $model->payment_link_currency = $currency;
                $model->save();
            });
        } finally {
            self::$busy = false;
        }

        Log::info("Payment URL " . ($stale ? 're-generated' : 'generated') . " for {$section} #{$model->id}");

        if ($stale) {
            $this->flashInfo('Сумма или валюта изменились — ссылка на оплату создана заново. Старый инвойс в банке может остаться активным, но платёж по нему система автоматически не примет.');
        }
    }

    /** Определяем блюпринт записи (имя свойства зависит от версии Tailor — пробуем варианты) */
    private function sectionOf($model): ?string
    {
        $handle = $model->sectionHandle ?? null;

        if (!$handle && method_exists($model, 'getBlueprintAttribute')) {
            $blueprint = $model->getBlueprintAttribute();
            $handle = $blueprint->handle ?? null;
        }

        return $handle ? (string) $handle : null;
    }

    private function flashWarning(string $message): void
    {
        try { \Flash::warning($message); } catch (\Throwable $e) { /* вне контекста админки Flash недоступен */ }
    }

    private function flashInfo(string $message): void
    {
        try { \Flash::info($message); } catch (\Throwable $e) { }
    }
}
