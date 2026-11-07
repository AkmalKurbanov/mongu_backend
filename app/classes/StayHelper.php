<?php namespace App\Classes;

use Tailor\Models\EntryRecord;
use Tailor\Models\GlobalRecord;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Config;

/**
 * Бронирование ПРОЖИВАНИЯ без туров.
 * Объекты (Sokmo Lodge, Mongu Hotel, палаточные городки...) заводятся в админке
 * в блюпринте Lodgings, заявки хранятся в блюпринте StayBookings.
 */
class StayHelper
{
    public static function getPaymentPayload($stay): array
    {
        $total   = floatval($stay->total_price);
        $lodging = EntryRecord::inSection('Lodgings')->where('slug', $stay->lodging)->first();
        $place   = $lodging->title ?? $stay->lodging;

        // Итог мог быть вручную изменён менеджером (stays.yaml) — отправляем одну позицию на total_price,
        // чтобы сумма и состав платежа не расходились (в т.ч. для тарифа «за номер», где guests не множитель).
        $name = Str::limit('Проживание: ' . $place . ($stay->nights ? ', ' . intval($stay->nights) . ' ноч.' : ''), 120, '');

        return [
            // S-<id>: id у Orders и StayBookings пересекаются, см. PaymentService::makeReference
            'order_id'       => PaymentService::makeReference('StayBookings', $stay->id),
            'order_number'   => $stay->title,
            'secure_token'   => $stay->secure_token,
            'amount'         => $total,
            'currency'       => $stay->currency ?: 'USD',
            'client_name'    => $stay->client_name,
            'client_email'   => $stay->email,
            'client_phone'   => $stay->phone,
            'description'    => "Оплата бронирования #{$stay->id} - {$stay->client_name}",
            'items'          => [[
                'name'     => $name,
                'price'    => $total,
                'quantity' => 1,
                'total'    => $total,
            ]],
            'success_url'    => url('/stay-checkout/' . $stay->secure_token . '?status=success'),
            'failure_url'    => url('/stay-checkout/' . $stay->secure_token . '?status=error'),
        ];
    }

    /**
     * Опции выпадающего списка «Объект размещения» в админке (StayBookings)
     */
    public static function getLodgingOptions(): array
    {
        return EntryRecord::inSection('Lodgings')
            ->pluck('title', 'slug')
            ->toArray();
    }

    public static function processStay()
    {
        $data = post();

        // Honeypot: бот получает «успех», ничего не сохраняется и письма не отправляются
        if (BookingHelper::isHoneypotTripped($data)) {
            return Redirect::to('/');
        }

        $isFlexible = !empty($data['is_flexible_dates']);
        $datesRule = $isFlexible ? 'nullable' : 'required';

        $validator = Validator::make($data, [
            'full_name' => 'required|string|min:2|max:100',
            'email'     => 'required|email:rfc',
            'phone'     => ['required', 'min:7', 'regex:/^[\+\d\s\-\(\)]+$/'],
            'lodging'   => 'required|string',
            'comments'  => 'nullable|string|max:2000',
            'guests'    => 'nullable|integer|min:1|max:30',
            'check_in'  => "{$datesRule}|date_format:Y-m-d|after_or_equal:today",
            'check_out' => "{$datesRule}|date_format:Y-m-d|after:check_in",
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        BookingHelper::throttle('stay', (string) $data['email']);

        // is_enabled: форма показывает только включённые объекты, и сервер обязан проверять то же самое
        $lodging = EntryRecord::inSection('Lodgings')
            ->where('slug', (string) $data['lodging'])
            ->where('is_enabled', true)
            ->first();

        if (!$lodging) {
            throw ValidationException::withMessages(['lodging' => 'Please choose a place to stay.']);
        }

        $guests   = max(1, intval($data['guests'] ?? 1));
        $checkIn  = trim($data['check_in'] ?? '') ?: null;
        $checkOut = trim($data['check_out'] ?? '') ?: null;
        $notes    = trim($data['comments'] ?? '');
        $token    = Str::random(32);

        $nights = ($checkIn && $checkOut)
            ? (new \DateTime($checkIn))->diff(new \DateTime($checkOut))->days
            : 0;

        // Цена: если у объекта указана — считаем, если нет — 0 (= "On request", менеджер допишет в админке)
        $unitPrice = BookingHelper::parsePrice($lodging->price_per_night);
        $basis     = $lodging->price_basis ?: 'per_person';
        $total     = ($unitPrice > 0 && $nights > 0)
            ? $unitPrice * $nights * ($basis === 'per_unit' ? 1 : $guests)
            : 0;

        $stay = EntryRecord::inSection('StayBookings');
        $stay->title             = $data['full_name'] . ' — ' . $lodging->title;
        $stay->slug              = Str::slug($data['full_name'] . '-' . time() . '-' . Str::random(4));
        $stay->client_name       = $data['full_name'];
        $stay->email             = $data['email'];
        $stay->phone             = $data['phone'];
        $stay->lodging           = $lodging->slug;
        $stay->guests            = $guests;
        $stay->check_in          = $checkIn;
        $stay->check_out         = $checkOut;
        $stay->nights            = $nights ?: null;
        $stay->is_flexible_dates = $isFlexible;
        $stay->notes             = $notes;
        $stay->total_price       = $total;
        $stay->lodging_snapshot_price = $unitPrice;
        $stay->secure_token      = $token;
        $stay->status            = 'new';
        $stay->save();

        self::sendEmails($stay, $lodging->title, $nights, $unitPrice, $basis, url('/stay-checkout/' . $token));

        return Redirect::to('/stay-checkout/' . $token);
    }

    private static function sendEmails($stay, string $lodgingTitle, int $nights, float $unitPrice, string $basis, string $checkoutUrl): void
    {
        $globals    = GlobalRecord::findForGlobal('GlobalSettings');
        $company    = $globals->website_name ?? 'Mongu';
        $adminEmail = $globals->email_address ?? Config::get('mail.from.address');
        $fromEmail  = Config::get('mail.from.address', 'noreply@mongu.kg');
        $fromName   = Config::get('mail.from.name', $company);

        $dates = $stay->check_in
            ? date('M j, Y', strtotime((string) $stay->check_in))
                . ($stay->check_out ? ' → ' . date('M j, Y', strtotime((string) $stay->check_out)) : '')
                . ($nights ? " ({$nights} night" . ($nights > 1 ? 's' : '') . ')' : '')
            : 'Flexible / Not specified';

        $hasPrice = $stay->total_price > 0;
        if ($hasPrice) {
            $totalText = '$' . number_format($stay->total_price, 2)
                . ' (' . '$' . number_format($unitPrice, 2) . ' × ' . $nights . ' night' . ($nights > 1 ? 's' : '')
                . ($basis === 'per_unit' ? '' : ' × ' . $stay->guests . ' guest' . ($stay->guests > 1 ? 's' : '')) . ')';
        } else {
            $totalText = 'On request — will be confirmed by our manager';
        }

        $rows = [
            'Place'  => $lodgingTitle,
            'Dates'  => $dates . ($stay->is_flexible_dates ? ' — flexible' : ''),
            'Guests' => $stay->guests . ' pax',
            'Total'  => $totalText,
            'Name'   => $stay->client_name,
            'Email'  => $stay->email,
            'Phone'  => $stay->phone,
        ];
        if (!empty($stay->notes)) {
            $rows['Comment'] = $stay->notes;
        }

        $adminHtml = self::renderEmail(
            'New stay request', $stay->client_name, $rows, $checkoutUrl, 'Open request page', '#1a202c'
        );
        $clientHtml = self::renderEmail(
            "{$company} — stay request received",
            'Hello, ' . $stay->client_name . '! We received your request. Our manager will confirm availability'
                . ($hasPrice ? ' and the final price' : ' and the price') . ' and send you payment details.',
            $rows, $checkoutUrl, 'View your request', '#2c7a7b'
        );

        try {
            Mail::html($adminHtml, function ($m) use ($fromEmail, $fromName, $adminEmail, $stay, $lodgingTitle) {
                $m->from($fromEmail, $fromName);
                $m->to($adminEmail);
                $m->subject('🏨 New stay request: ' . $stay->client_name . ' (' . $lodgingTitle . ')');
            });
            Mail::html($clientHtml, function ($m) use ($fromEmail, $fromName, $stay, $company) {
                $m->from($fromEmail, $fromName);
                $m->to($stay->email, $stay->client_name);
                $m->subject('Stay request received - ' . $company);
            });
        } catch (\Throwable $e) {
            trace_log('Stay email notification error: ' . $e->getMessage());
        }
    }

    private static function renderEmail(string $title, string $intro, array $rows, string $url, string $btnLabel, string $color): string
    {
        $trs = '';
        foreach ($rows as $label => $value) {
            $trs .= '<tr>'
                . '<td style="padding:8px 0;color:#718096;width:110px;vertical-align:top;">' . e($label) . '</td>'
                . '<td style="padding:8px 0;color:#1a202c;font-weight:600;">' . nl2br(e($value)) . '</td>'
                . '</tr>';
        }

        return '<!DOCTYPE html><html><head><meta charset="utf-8"></head>'
            . '<body style="margin:0;padding:30px 10px;background:#f4f6f8;font-family:Arial,Helvetica,sans-serif;">'
            . '<table width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;">'
            . '<tr><td style="background:' . $color . ';padding:24px 30px;color:#fff;font-size:20px;font-weight:700;">' . e($title) . '</td></tr>'
            . '<tr><td style="padding:30px;">'
            . '<p style="margin:0 0 20px;color:#4a5568;font-size:15px;line-height:1.5;">' . e($intro) . '</p>'
            . '<table width="100%" cellspacing="0" cellpadding="0" style="font-size:14px;">' . $trs . '</table>'
            . '<div style="text-align:center;margin-top:30px;"><a href="' . e($url) . '" style="display:inline-block;background:' . $color . ';color:#fff;text-decoration:none;font-weight:700;padding:14px 28px;border-radius:6px;">' . e($btnLabel) . '</a></div>'
            . '</td></tr></table></body></html>';
    }
}
