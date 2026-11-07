<?php namespace App\Classes;

use Tailor\Models\EntryRecord;
use Tailor\Models\GlobalRecord;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;

class BookingHelper
{
    /**
     * Цена из строки: "$1,200", "from 80", "50-80" → берётся ПЕРВОЕ число (50), нет числа → 0.
     * Раньше preg_replace склеивал диапазон "50-80" в 5080.
     */
    public static function parsePrice($value): float
    {
        if (is_int($value) || is_float($value)) {
            return max(0.0, (float) $value);
        }

        $s = str_replace([' ', "\xC2\xA0"], '', (string) $value);
        if (!preg_match('/\d[\d,]*(?:\.\d+)?|\.\d+/', $s, $m)) {
            return 0.0;
        }

        return max(0.0, (float) str_replace(',', '', $m[0]));
    }

    /** Дата из строки (Y-m-d или любой формат, понятный DateTime). Не распознана → null */
    public static function parseDate(string $value): ?\DateTime
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $dt = \DateTime::createFromFormat('!Y-m-d', $value);
        $errors = \DateTime::getLastErrors();
        if ($dt && (!$errors || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
            return $dt;
        }

        try {
            $dt = new \DateTime($value);
        } catch (\Throwable $e) {
            return null;
        }
        $dt->setTime(0, 0);

        return $dt;
    }

    /**
     * Ночи в отеле считаем на сервере по диапазону "дата to дата".
     * Число от клиента — только запасной вариант, если даты не распознаны (и с потолком).
     */
    public static function hotelNights(string $dates, int $clientNights = 0): int
    {
        $parts = array_map('trim', explode(' to ', $dates));
        $start = self::parseDate($parts[0] ?? '');
        $end   = self::parseDate($parts[1] ?? '');

        if ($start && $end && $end > $start) {
            return min(90, max(1, (int) $start->diff($end)->days));
        }

        return min(30, max(1, $clientNights));
    }

    /** Honeypot: человек это поле не видит и не заполняет, боты — заполняют */
    public static function isHoneypotTripped(array $data): bool
    {
        return !empty($data['website']);
    }

    /**
     * Ограничение частоты отправки форм: не более 5 заявок за 10 минут с одного IP
     * и не более 3 в час на один email (защита от рассылки писем на чужие адреса).
     * Вызывать ПОСЛЕ успешной валидации.
     */
    public static function throttle(string $bucket, string $email = ''): void
    {
        $ipKey   = "form:{$bucket}:ip:" . request()->ip();
        $mailKey = $email !== '' ? "form:{$bucket}:mail:" . sha1(strtolower(trim($email))) : null;

        if (RateLimiter::tooManyAttempts($ipKey, 5) || ($mailKey && RateLimiter::tooManyAttempts($mailKey, 3))) {
            throw ValidationException::withMessages([
                'form' => ['Too many requests. Please try again in a few minutes or contact us on WhatsApp.'],
            ]);
        }

        RateLimiter::hit($ipKey, 600);
        if ($mailKey) {
            RateLimiter::hit($mailKey, 3600);
        }
    }

  public static function getCategoryOptions(): array
    {
        return EntryRecord::inSection('Categories')
            ->pluck('title', 'slug')
            ->toArray();
    }

    /**
     * Опции для выпадающего списка в Tailor (Orders Blueprint)
     */
    public static function getAddonsDropdownOptions(): array
    {
        $globals = GlobalRecord::findForGlobal('GlobalSettings');

        if (!$globals || empty($globals->addons)) {
            return [];
        }

        $options = [];
        foreach ($globals->addons as $addon) {
            $title = is_array($addon) ? ($addon['title'] ?? '') : ($addon->title ?? '');
            $price = is_array($addon) ? ($addon['price'] ?? 0) : ($addon->price ?? 0);
            
            if ($title) {
                $options[$title] = "{$title} (\${$price})";
            }
        }

        return $options;
    }

    /**
     * Основной обработчик формы бронирования
     */
    public static function processBooking()
    {
        $data = post();

        // Honeypot: бот получает «успех» и уходит, ничего не сохраняется и письма не отправляются
        if (self::isHoneypotTripped($data)) {
            return Redirect::to('/');
        }

        $rules = [
            'full_name'    => 'required|string|min:2|max:100',
            'email'        => 'required|email:rfc',
            'phone'        => 'required|min:7|regex:/^[\+\d\s\-\(\)]+$/',
            'booking_type' => 'required|string',
            'tour_id'      => 'nullable|string',
            'guests'       => 'nullable|integer|min:1|max:100',
            'comments'     => 'nullable|string|max:2000',
            'notes'        => 'nullable|string|max:2000',
        ];

        $validator = Validator::make($data, $rules);
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        self::throttle('booking', (string) $data['email']);

        $globalSettings = GlobalRecord::findForGlobal('GlobalSettings');
        $addonsConfig = $globalSettings->addons ?? [];

        $bookingType = $data['booking_type'] ?? '';
        $tourSlug = $data['tour_id'] ?? null;
        $guestsCount = max(1, intval($data['guests'] ?? 1));

        // 1. Определение категории тура
        $categoryRecord = EntryRecord::inSection('Categories')->where('slug', $bookingType)->first();
        $categoryTitle = $categoryRecord ? $categoryRecord->title : ucwords(str_replace(['-', '_'], ' ', $bookingType));

        // 2. Детали выбранного тура
        $totalPrice = 0.00;
        $selectedTour = null;
        $tourTitle = null;
        $tourDuration = null;
        $basePricePerPerson = 0.00;

        if ($tourSlug) {
            $selectedTour = EntryRecord::inSection('Tours')->where('slug', $tourSlug)->first();
            if ($selectedTour) {
                $tourTitle = $selectedTour->title;
                $tourDuration = $selectedTour->tour_duration ?? null;
                if ($selectedTour->program_price) {
                    $basePricePerPerson = self::parsePrice($selectedTour->program_price);
                    $totalPrice += $basePricePerPerson * $guestsCount;
                }
            }
        }

        $addonsInput = is_array($data['addons'] ?? null) ? $data['addons'] : [];
        $addonsTextArray = [];
        $addonsHtmlRows = '';
        $selectedAddonsRepeater = [];

        // 3. Доп. услуга: проживание в Mongu Hotel до/после тура
        $hotelPrice = self::parsePrice($globalSettings->hotel_price_per_night ?? 50);
        if ($hotelPrice <= 0) $hotelPrice = 50.00;

        $hotelPax = min($guestsCount, max(0, intval($addonsInput['mongu_hotel_pax'] ?? 0)));
        $hotelDates = trim((string) ($addonsInput['mongu_hotel_dates'] ?? ''));
        $hotelNotes = trim((string) ($addonsInput['mongu_hotel_notes'] ?? ''));
        // Ночи считаем по датам на сервере; число от клиента — только запасной вариант
        $hotelNights = self::hotelNights($hotelDates, intval($addonsInput['mongu_hotel_nights'] ?? 0));

        if ($hotelPax > 0) {
            $hotelTotal = $hotelPrice * $hotelPax * max(1, $hotelNights);
            $totalPrice += $hotelTotal;

            $addonsTextArray[] = "Mongu Hotel Stay: {$hotelPax} guests, " . ($hotelDates ?: 'Not specified') . " ({$hotelNights} nights) — $" . number_format($hotelTotal, 2) . (!empty($hotelNotes) ? " [Notes: {$hotelNotes}]" : "");
            
            $addonsHtmlRows .= '
            <tr>
              <td style="padding: 10px 0; border-bottom: 1px dashed #e2e8f0; color: #2d3748; font-weight: 600;">
                Mongu Hotel Stay
                <div style="font-size: 12px; color: #718096; font-weight: normal; margin-top: 3px;">
                  ' . $hotelPax . ' guest(s) × ' . $hotelNights . ' night(s) @ $' . number_format($hotelPrice, 2) . '/night<br>
                  Stay Dates: ' . e($hotelDates ?: 'Not specified') . (!empty($hotelNotes) ? '<br><em>Note: ' . e($hotelNotes) . '</em>' : '') . '
                </div>
              </td>
              <td style="padding: 10px 0; border-bottom: 1px dashed #e2e8f0; text-align: right; color: #2d3748; font-weight: 600; white-space: nowrap; vertical-align: top;">
                +$' . number_format($hotelTotal, 2) . '
              </td>
            </tr>';
        }

        // 4. Динамические доп. услуги из GlobalSettings
        foreach ($addonsInput as $key => $val) {
            if (in_array($key, ['mongu_hotel_pax', 'mongu_hotel_dates', 'mongu_hotel_nights', 'mongu_hotel_notes'])) {
                continue;
            }
            
            if (is_numeric($key)) {
                $targetCode = is_string($val) ? trim($val) : '';
                $targetVal = 1;
            } else {
                $targetCode = trim((string)$key);
                $targetVal = $val;
            }

            if (empty($targetCode) || empty($targetVal) || $targetVal === '0' || $targetVal === false) {
                continue;
            }

            foreach ($addonsConfig as $cfgAddon) {
                $code = is_array($cfgAddon) ? ($cfgAddon['code'] ?? '') : ($cfgAddon->code ?? '');

                if ($code === $targetCode) {
                    if (self::isAddonExcluded($cfgAddon, $bookingType)) {
                        continue 2;
                    }

                    $addonTitle = is_array($cfgAddon) ? ($cfgAddon['title'] ?? $targetCode) : ($cfgAddon->title ?? $targetCode);
                    $addonPrice = self::parsePrice(is_array($cfgAddon) ? ($cfgAddon['price'] ?? 0) : ($cfgAddon->price ?? 0));
                    $inputType = is_array($cfgAddon) ? ($cfgAddon['input_type'] ?? '') : ($cfgAddon->input_type ?? '');

                    if ($inputType === 'counter') {
                        $count = min($guestsCount, max(1, intval($targetVal)));
                        $itemTotal = $addonPrice * $count;
                        $totalPrice += $itemTotal;

                        $selectedAddonsRepeater[] = [
                            'title'       => $addonTitle,
                            'addon_title' => $addonTitle,
                            'quantity'    => $count,
                            'count'       => $count,
                            'price'       => $itemTotal,
                            'total_price' => $itemTotal
                        ];

                        $addonsTextArray[] = "+ {$addonTitle} ({$count}x) — $" . number_format($itemTotal, 2);
                        $addonsHtmlRows .= '
                        <tr>
                          <td style="padding: 10px 0; border-bottom: 1px dashed #e2e8f0; color: #2d3748;">
                            ' . e($addonTitle) . ' <span style="font-size: 12px; color: #718096;">(' . $count . 'x @ $' . number_format($addonPrice, 2) . ')</span>
                          </td>
                          <td style="padding: 10px 0; border-bottom: 1px dashed #e2e8f0; text-align: right; color: #2d3748; font-weight: 600; white-space: nowrap;">
                            +$' . number_format($itemTotal, 2) . '
                          </td>
                        </tr>';
                    } else {
                        $totalPrice += $addonPrice;

                        $selectedAddonsRepeater[] = [
                            'title'       => $addonTitle,
                            'addon_title' => $addonTitle,
                            'quantity'    => 1,
                            'count'       => 1,
                            'price'       => $addonPrice,
                            'total_price' => $addonPrice
                        ];

                        $addonsTextArray[] = "+ {$addonTitle} — $" . number_format($addonPrice, 2);
                        $addonsHtmlRows .= '
                        <tr>
                          <td style="padding: 10px 0; border-bottom: 1px dashed #e2e8f0; color: #2d3748;">
                            ' . e($addonTitle) . '
                          </td>
                          <td style="padding: 10px 0; border-bottom: 1px dashed #e2e8f0; text-align: right; color: #2d3748; font-weight: 600; white-space: nowrap;">
                            +$' . number_format($addonPrice, 2) . '
                          </td>
                        </tr>';
                    }
                    break;
                }
            }
        }

        // Убираем двойное экранирование при сохранении в БД (оставляем «чистый» текст)
        $clientComment = trim($data['comments'] ?? $data['notes'] ?? '');
        $secureToken = Str::random(32);
        $isFlexible = !empty($data['is_flexible_dates']);
        
        $dateStart = trim($data['booking_date'] ?? '') ?: null;

        $hDates = array_map('trim', explode(' to ', $hotelDates));
        $hotelStartDt = self::parseDate($hDates[0] ?? '');
        $hotelEndDt = self::parseDate($hDates[1] ?? '') ?: $hotelStartDt;
        $hotelStart = $hotelStartDt ? $hotelStartDt->format('Y-m-d') : null;
        $hotelEnd = $hotelEndDt ? $hotelEndDt->format('Y-m-d') : null;

        // 5. Сохранение заказа в Tailor Orders без лишнего e()
        $order = EntryRecord::inSection('Orders');
        $order->title = trim($data['full_name']) . ' - ' . ($tourTitle ?: $categoryTitle);
        $order->slug = Str::slug(trim($data['full_name']) . '-' . time() . '-' . Str::random(4));
        
        $order->client_name = trim($data['full_name']);
        $order->email = trim($data['email']);
        $order->phone = trim($data['phone']);
        
        if ($selectedTour) {
            $order->tour = $selectedTour->id;
            $order->tour_snapshot_price = $basePricePerPerson;
        }

        $order->date_start = $dateStart;
        $order->is_flexible_dates = $isFlexible;
        $order->guests = $guestsCount;

        $order->hotel_stay_pax = $hotelPax;
        $order->hotel_stay_start = $hotelStart;
        $order->hotel_stay_end = $hotelEnd;
        $order->hotel_stay_notes = $hotelNotes;
        $order->hotel_stay_nights = $hotelPax > 0 ? $hotelNights : null;

        $order->total_price = $totalPrice;
        $order->secure_token = $secureToken;
        $order->status = 'new';
        
        // Поле comments добавлено в orders.yaml (раньше его не было, и комментарий клиента терялся)
        $order->comments = $clientComment;
        
        $order->save();

        foreach ($selectedAddonsRepeater as $row) {
            $order->selected_addons()->create([
                'title'       => $row['title'],
                'quantity'    => $row['quantity'],
                'total_price' => $row['total_price'],
            ]);
        }

        $checkoutUrl = url('/checkout/' . $secureToken);

        // Отправка писем
        self::sendEmails(
            $order, 
            $categoryTitle, 
            $tourTitle, 
            $tourDuration,
            $basePricePerPerson, 
            $checkoutUrl, 
            $addonsHtmlRows, 
            $clientComment, 
            $globalSettings
        );

        return Redirect::to('/checkout/' . $secureToken);
    }

    private static function isAddonExcluded($cfgAddon, string $bookingType): bool
    {
        $catRaw = is_array($cfgAddon)
            ? ($cfgAddon['exclude_categories'] ?? [])
            : ($cfgAddon->exclude_categories ?? []);

        $excluded = Arr::flatten((array)$catRaw);

        return in_array($bookingType, $excluded, true);
    }

    private static function sendEmails($order, $categoryTitle, $tourTitle, $tourDuration, $basePricePerPerson, $checkoutUrl, $addonsHtmlRows, $clientComment, $globalSettings)
    {
        $companyName = $globalSettings->website_name ?? 'Mongu';
        $companyPhone = $globalSettings->whatsapp_number ?? '+996 123 456 789';
        $cleanCompanyPhone = preg_replace('/[^0-9]/', '', $companyPhone);
        
        // Исправлено: теперь адрес админа берется из настроек GlobalSettings с фоллбеком на конфиг почты
        $adminEmail = $globalSettings->email_address ?? Config::get('mail.from.address');

        $cleanClientPhone = preg_replace('/[^0-9]/', '', $order->phone);
        $clientWaUrl = "https://wa.me/{$cleanClientPhone}";

        $displayOptionName = $tourTitle ? "{$categoryTitle} — {$tourTitle}" : $categoryTitle;

        $formattedTotal = !$order->total_price
            ? 'On Request' 
            : '$' . number_format($order->total_price, 2);

        $bookingDateDisplay = $order->date_start
            ? date('M j, Y', strtotime((string) $order->date_start))
            : 'Flexible / Not specified';

        $baseTourRow = '';
        if ($tourTitle) {
            $baseTotal = $basePricePerPerson * $order->guests;
            $baseTourRow = '
            <tr>
              <td style="padding: 10px 0; border-bottom: 1px solid #edf2f7; color: #2d3748; font-weight: 600;">
                ' . e($tourTitle) . '
                <div style="font-size: 12px; color: #718096; font-weight: normal; margin-top: 3px;">
                  Category: ' . e($categoryTitle) . ($tourDuration ? ' | Duration: ' . e($tourDuration) : '') . '<br>
                  Base Rate: $' . number_format($basePricePerPerson, 2) . ' × ' . $order->guests . ' pax
                </div>
              </td>
              <td style="padding: 10px 0; border-bottom: 1px solid #edf2f7; text-align: right; color: #2d3748; font-weight: 600; white-space: nowrap; vertical-align: top;">
                $' . number_format($baseTotal, 2) . '
              </td>
            </tr>';
        } else {
            $baseTourRow = '
            <tr>
              <td style="padding: 10px 0; border-bottom: 1px solid #edf2f7; color: #2d3748; font-weight: 600;">
                Category Request: ' . e($categoryTitle) . '
                <div style="font-size: 12px; color: #718096; font-weight: normal; margin-top: 3px;">
                  Specific tour program to be agreed with manager (' . $order->guests . ' pax)
                </div>
              </td>
              <td style="padding: 10px 0; border-bottom: 1px solid #edf2f7; text-align: right; color: #2d3748; font-weight: 600; white-space: nowrap; vertical-align: top;">
                On Request
              </td>
            </tr>';
        }
        
        $commentHtmlBlock = '';
        if (!empty($clientComment)) {
            $commentHtmlBlock = '
            <div style="margin-top: 20px; padding: 15px; background-color: #fffaf0; border-left: 4px solid #dd6b20; border-radius: 4px;">
              <strong style="color: #c05621; font-size: 13px; display: block; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.5px;">Client Special Comment:</strong>
              <div style="color: #7b341e; font-size: 14px; line-height: 1.5;">' . nl2br(e($clientComment)) . '</div>
            </div>';
        }

        $addonsTableBlock = '';
        if (!empty($addonsHtmlRows)) {
            $addonsTableBlock = '
            <div style="margin-top: 20px;">
              <strong style="color: #4a5568; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; display: block; margin-bottom: 8px;">Selected Add-ons & Services:</strong>
              <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                ' . $addonsHtmlRows . '
              </table>
            </div>';
        }

        // Письмо админу
        $adminHtml = '
        <!DOCTYPE html>
        <html>
        <head><meta charset="utf-8"></head>
        <body style="margin: 0; padding: 0; background-color: #f4f6f8; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif;">
          <table width="100%" border="0" cellspacing="0" cellpadding="0" style="background-color: #f4f6f8; padding: 30px 10px;">
            <tr>
              <td align="center">
                <table width="100%" border="0" cellspacing="0" cellpadding="0" style="max-width: 620px; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.08);">
                  <tr>
                    <td style="background-color: #1a202c; padding: 24px 30px;">
                      <table width="100%" border="0" cellspacing="0" cellpadding="0">
                        <tr>
                          <td>
                            <span style="background: #e53e3e; color: #ffffff; font-size: 11px; font-weight: 700; text-transform: uppercase; padding: 4px 8px; border-radius: 4px; letter-spacing: 0.5px;">New Request</span>
                            <h1 style="margin: 8px 0 0 0; color: #ffffff; font-size: 22px; font-weight: 700;">' . e($order->client_name) . '</h1>
                          </td>
                          <td align="right" valign="top">
                            <span style="color: #a0aec0; font-size: 13px;">' . date('M j, Y H:i') . '</span>
                          </td>
                        </tr>
                      </table>
                    </td>
                  </tr>
                  <tr>
                    <td style="padding: 30px;">
                      <table width="100%" border="0" cellspacing="0" cellpadding="0" style="margin-bottom: 25px;">
                        <tr>
                          <td width="48%">
                            <a href="' . $clientWaUrl . '" target="_blank" style="display: block; text-align: center; background-color: #25d366; color: #ffffff; text-decoration: none; font-weight: 700; font-size: 14px; padding: 12px 15px; border-radius: 6px;">
                              💬 Chat in WhatsApp
                            </a>
                          </td>
                          <td width="4%"></td>
                          <td width="48%">
                            <a href="tel:' . $order->phone . '" style="display: block; text-align: center; background-color: #3182ce; color: #ffffff; text-decoration: none; font-weight: 700; font-size: 14px; padding: 12px 15px; border-radius: 6px;">
                              📞 Call Client
                            </a>
                          </td>
                        </tr>
                      </table>

                      <table width="100%" border="0" cellspacing="0" cellpadding="0" style="background: #f7fafc; border-radius: 8px; padding: 18px; margin-bottom: 25px;">
                        <tr>
                          <td style="font-size: 14px; color: #4a5568; padding-bottom: 8px; width: 140px;">Full Name:</td>
                          <td style="font-size: 14px; color: #1a202c; font-weight: 700; padding-bottom: 8px;">' . e($order->client_name) . '</td>
                        </tr>
                        <tr>
                          <td style="font-size: 14px; color: #4a5568; padding-bottom: 8px;">Email:</td>
                          <td style="font-size: 14px; padding-bottom: 8px;"><a href="mailto:' . e($order->email) . '" style="color: #3182ce; text-decoration: none; font-weight: 600;">' . e($order->email) . '</a></td>
                        </tr>
                        <tr>
                          <td style="font-size: 14px; color: #4a5568; padding-bottom: 8px;">Phone / WA:</td>
                          <td style="font-size: 14px; padding-bottom: 8px;"><a href="tel:' . e($order->phone) . '" style="color: #3182ce; text-decoration: none; font-weight: 600;">' . e($order->phone) . '</a></td>
                        </tr>
                        <tr>
                          <td style="font-size: 14px; color: #4a5568; padding-bottom: 8px;">Category / Target:</td>
                          <td style="font-size: 14px; color: #1a202c; font-weight: 600; padding-bottom: 8px;">' . e($categoryTitle) . '</td>
                        </tr>
                        ' . ($tourTitle ? '
                        <tr>
                          <td style="font-size: 14px; color: #4a5568; padding-bottom: 8px;">Selected Tour:</td>
                          <td style="font-size: 14px; color: #2b6cb0; font-weight: 700; padding-bottom: 8px;">' . e($tourTitle) . ($tourDuration ? ' (' . e($tourDuration) . ')' : '') . '</td>
                        </tr>' : '') . '
                        <tr>
                          <td style="font-size: 14px; color: #4a5568; padding-bottom: 8px;">Start Date:</td>
                          <td style="font-size: 14px; color: #1a202c; font-weight: 600; padding-bottom: 8px;">' . e($bookingDateDisplay) . '</td>
                        </tr>
                        <tr>
                          <td style="font-size: 14px; color: #4a5568;">Guests Count:</td>
                          <td style="font-size: 14px; color: #1a202c; font-weight: 600;">' . $order->guests . ' pax</td>
                        </tr>
                      </table>

                      <strong style="color: #4a5568; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; display: block; margin-bottom: 8px;">Calculation Breakdown:</strong>
                      <table width="100%" border="0" cellspacing="0" cellpadding="0" style="font-size: 14px;">
                        ' . $baseTourRow . '
                      </table>

                      ' . $addonsTableBlock . '
                      ' . $commentHtmlBlock . '

                      <table width="100%" border="0" cellspacing="0" cellpadding="0" style="margin-top: 20px; background: #2c7a7b; color: #ffffff; border-radius: 8px; padding: 16px 20px;">
                        <tr>
                          <td style="font-size: 16px; font-weight: 600;">Calculated Total:</td>
                          <td align="right" style="font-size: 22px; font-weight: 800;">' . $formattedTotal . '</td>
                        </tr>
                      </table>

                      <div style="text-align: center; margin-top: 30px;">
                        <a href="' . $checkoutUrl . '" style="display: inline-block; background-color: #1a202c; color: #ffffff; text-decoration: none; font-weight: 700; font-size: 14px; padding: 14px 28px; border-radius: 6px;">
                          Open Receipt Page
                        </a>
                      </div>
                    </td>
                  </tr>
                </table>
              </td>
            </tr>
          </table>
        </body>
        </html>';

        // Письмо клиенту
        $clientHtml = '
        <!DOCTYPE html>
        <html>
        <head><meta charset="utf-8"></head>
        <body style="margin: 0; padding: 0; background-color: #f4f6f8; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif;">
          <table width="100%" border="0" cellspacing="0" cellpadding="0" style="background-color: #f4f6f8; padding: 30px 10px;">
            <tr>
              <td align="center">
                <table width="100%" border="0" cellspacing="0" cellpadding="0" style="max-width: 620px; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.08);">
                  <tr>
                    <td style="background-color: #2c7a7b; padding: 30px; text-align: center;">
                      <h1 style="margin: 0 0 6px 0; color: #ffffff; font-size: 24px; font-weight: 800; letter-spacing: -0.5px;">' . e($companyName) . '</h1>
                      <p style="margin: 0; color: #e6fffa; font-size: 15px;">Booking Request Received</p>
                    </td>
                  </tr>
                  <tr>
                    <td style="padding: 30px;">
                      <p style="margin-top: 0; font-size: 16px; color: #2d3748; line-height: 1.5;">
                        Hello, <strong>' . e($order->client_name) . '</strong>!
                      </p>
                      <p style="color: #4a5568; font-size: 15px; line-height: 1.6; margin-bottom: 25px;">
                        Thank you for choosing <strong>' . e($companyName) . '</strong>. We have received your request and our travel specialist will contact you shortly to confirm dates and details.
                      </p>

                      <div style="border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden; margin-bottom: 25px;">
                        <div style="background-color: #f8fafc; padding: 14px 20px; border-bottom: 1px solid #e2e8f0;">
                          <strong style="color: #2d3748; font-size: 15px;">Your Booking Summary</strong>
                        </div>
                        <div style="padding: 20px;">
                          <table width="100%" border="0" cellspacing="0" cellpadding="0" style="font-size: 14px; margin-bottom: 15px;">
                            <tr>
                              <td style="padding: 6px 0; color: #718096; width: 140px;">Category / Option:</td>
                              <td style="padding: 6px 0; color: #2d3748; font-weight: 700;">' . e($categoryTitle) . '</td>
                            </tr>
                            ' . ($tourTitle ? '
                            <tr>
                              <td style="padding: 6px 0; color: #718096;">Selected Tour:</td>
                              <td style="padding: 6px 0; color: #2b6cb0; font-weight: 700;">' . e($tourTitle) . ($tourDuration ? ' (' . e($tourDuration) . ')' : '') . '</td>
                            </tr>' : '') . '
                            <tr>
                              <td style="padding: 6px 0; color: #718096;">Start Date:</td>
                              <td style="padding: 6px 0; color: #2d3748; font-weight: 600;">' . e($bookingDateDisplay) . '</td>
                            </tr>
                            <tr>
                              <td style="padding: 6px 0; color: #718096;">Guests:</td>
                              <td style="padding: 6px 0; color: #2d3748; font-weight: 600;">' . $order->guests . ' pax</td>
                            </tr>
                          </table>

                          <strong style="color: #4a5568; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; display: block; margin-top: 15px; margin-bottom: 8px;">Breakdown:</strong>
                          <table width="100%" border="0" cellspacing="0" cellpadding="0" style="font-size: 14px;">
                            ' . $baseTourRow . '
                            ' . $addonsHtmlRows . '
                          </table>

                          ' . $commentHtmlBlock . '

                          <table width="100%" border="0" cellspacing="0" cellpadding="0" style="margin-top: 20px; border-top: 2px solid #e2e8f0; padding-top: 12px;">
                            <tr>
                              <td style="font-size: 15px; font-weight: 700; color: #2d3748;">Estimated Total:</td>
                              <td align="right" style="font-size: 20px; font-weight: 800; color: #2c7a7b;">' . $formattedTotal . '</td>
                            </tr>
                          </table>
                        </div>
                      </div>

                      <div style="background-color: #f7fafc; border-radius: 8px; padding: 20px; margin-bottom: 25px;">
                        <strong style="color: #2d3748; font-size: 14px; display: block; margin-bottom: 10px;">What happens next?</strong>
                        <ol style="margin: 0; padding-left: 20px; color: #4a5568; font-size: 14px; line-height: 1.7;">
                          <li>Our manager checks availability for your selected dates.</li>
                          <li>We connect with you via WhatsApp or Email to confirm final details.</li>
                          <li>You receive formal confirmation and payment details.</li>
                        </ol>
                      </div>

                      <div style="text-align: center; margin: 30px 0;">
                        <a href="' . $checkoutUrl . '" style="display: inline-block; background-color: #2c7a7b; color: #ffffff; text-decoration: none; font-weight: 700; font-size: 15px; padding: 15px 32px; border-radius: 6px; box-shadow: 0 4px 12px rgba(44,122,123,0.3);">
                          View Your Online Receipt
                        </a>
                      </div>

                      <div style="text-align: center; border-top: 1px solid #edf2f7; padding-top: 20px; color: #718096; font-size: 13px; line-height: 1.5;">
                        Have questions or urgent changes? Contact us on WhatsApp:<br>
                        <a href="https://wa.me/' . $cleanCompanyPhone . '" style="color: #25d366; text-decoration: none; font-weight: 700; font-size: 14px;">💬 Chat with ' . e($companyName) . ' Specialist</a>
                      </div>
                    </td>
                  </tr>
                  <tr>
                    <td style="background-color: #1a202c; padding: 20px 30px; text-align: center; color: #a0aec0; font-size: 12px; line-height: 1.6;">
                      <strong>' . e($companyName) . ' Travel Team</strong><br>
                      ' . e($globalSettings->address ?? 'Karakol, Kyrgyzstan') . '<br>
                      Email: <a href="mailto:' . e($globalSettings->email_address ?? '') . '" style="color: #cbd5e0; text-decoration: none;">' . e($globalSettings->email_address ?? '') . '</a> | Phone: ' . e($companyPhone) . '
                    </td>
                  </tr>
                </table>
              </td>
            </tr>
          </table>
        </body>
        </html>';

        $fromEmail = Config::get('mail.from.address', 'noreply@mongu.kg');
        $fromName  = Config::get('mail.from.name', $companyName);

        try {
            // Исправлено: письмо админу уходит на $adminEmail вместо системного noreply
            Mail::html($adminHtml, function($message) use ($order, $fromEmail, $fromName, $displayOptionName, $adminEmail) {
                $message->from($fromEmail, $fromName);
                $message->to($adminEmail);
                $message->subject('⚡ New Request: ' . $order->client_name . ' (' . $displayOptionName . ')');
            });

            Mail::html($clientHtml, function($message) use ($order, $fromEmail, $fromName, $companyName) {
                $message->from($fromEmail, $fromName);
                $message->to($order->email, $order->client_name);
                $message->subject('Booking Confirmation - ' . $companyName);
            });
        } catch (\Throwable $e) {
            trace_log('Booking email notification error: ' . $e->getMessage());
        }
    }

    public static function getPaymentPayload($order): array
    {
        $total = floatval($order->total_price);

        // Менеджер может вручную поменять итог (orders.yaml), поэтому банку отправляем одну позицию
        // ровно на total_price — так сумма и состав платежа никогда не разойдутся.
        $name = Str::limit('Заказ #' . $order->id . ($order->title ? ' — ' . $order->title : ''), 120, '');

        return [
            // O-<id>: id у Orders и StayBookings пересекаются, см. PaymentService::makeReference
            'order_id'       => PaymentService::makeReference('Orders', $order->id),
            'order_number'   => $order->title,
            'secure_token'   => $order->secure_token,
            'amount'         => $total,
            'currency'       => $order->currency ?: 'USD',
            'client_name'    => $order->client_name,
            'client_email'   => $order->email,
            'client_phone'   => $order->phone,
            'description'    => "Оплата заказа #{$order->id} - {$order->client_name}",
            'items'          => [[
                'name'     => $name,
                'price'    => $total,
                'quantity' => 1,
                'total'    => $total,
            ]],
            'success_url'    => url('/checkout/' . $order->secure_token . '?status=success'),
            'failure_url'    => url('/checkout/' . $order->secure_token . '?status=error'),
        ];
    }
}
