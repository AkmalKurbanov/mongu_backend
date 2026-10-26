<?php namespace Mongu\Api;

use System\Classes\PluginBase;
use Tailor\Models\EntryRecord;
use App\Classes\PaymentService;
use App\Classes\BookingHelper;
use App\Classes\StayHelper;
use Illuminate\Support\Facades\Log;

class Plugin extends PluginBase
{
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
        if (file_exists(__DIR__ . '/routes.php')) {
            include __DIR__ . '/routes.php';
        }

        // Автоматическая генерация ссылки при смене статуса на approved
        EntryRecord::saved(function ($model) {
            if (!in_array($model->sectionHandle, ['Orders', 'StayBookings'], true)) {
                return;
            }

            if ($model->status === 'approved' && empty($model->payment_url) && floatval($model->total_price) > 0) {
                $payload = ($model->sectionHandle === 'Orders')
                    ? BookingHelper::getPaymentPayload($model)
                    : StayHelper::getPaymentPayload($model);

                if (!empty($payload)) {
                    $paymentUrl = PaymentService::createPaymentLink($payload);
                    if ($paymentUrl) {
                        $model->withoutEvents(function () use ($model, $paymentUrl) {
                            $model->payment_url = $paymentUrl;
                            $model->payment_method = 'Bank Gateway';
                            $model->save();
                        });
                        Log::info("Payment URL generated for {$model->sectionHandle} #{$model->id}");
                    }
                }
            }
        });
    }
}