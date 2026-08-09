# Image Tailor — OctoberCMS Plugin

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![OctoberCMS](https://img.shields.io/badge/OctoberCMS-4.x-orange.svg)](https://octobercms.com)

Automatically resizes uploaded images and converts them to WebP in OctoberCMS Tailor fileupload fields.

## EN

### Features

- Processes image uploads in Tailor `fileupload` fields with `mode: image`.
- Resizes images to fit within configurable maximum width and height.
- Preserves aspect ratio and never crops.
- Converts images to WebP for smaller file sizes.
- Never upscales images.
- Replaces the original file instead of storing duplicates.
- Supports per-field overrides via `imageWidth` and `imageHeight` in blueprint config.
- Can be enabled or disabled globally from backend settings.
- Fails safely: upload is never interrupted if image processing fails.

### Requirements

- OctoberCMS 4.x
- PHP 8.1+
- GD extension with WebP support
- Composer 2.x

### Installation

Via Composer:

```bash
composer require dmdev/imagetailor-plugin
php artisan october:migrate
```

Manual installation:

```bash
php artisan october:migrate
```

Copy the plugin to `plugins/dmdev/imagetailor` first if you are not using Composer.

### Backend Settings

Go to **Settings → CMS → Image Tailor**.

| Setting | Default | Description |
|---|---|---|
| Enabled | Yes | Turns image processing on or off globally. |
| Max Width | 1200 px | Default maximum width when blueprint does not define `imageWidth`. |
| Max Height | 1200 px | Default maximum height when blueprint does not define `imageHeight`. |

### Tailor Blueprint Example

```yaml
images:
  label: Images
  type: fileupload
  mode: image
  imageWidth: 800
  imageHeight: 800
```

### How It Works

1. A new `System\Models\File` record is saved.
2. The plugin checks whether the file is an image.
3. If the file is already WebP and within limits, it is skipped.
4. Otherwise, the image is resized and saved as WebP with quality 85.
5. The original file record is updated directly to avoid a second save cycle.

### Limitations

- Only processes files on first upload (`wasRecentlyCreated`).
- Requires GD with WebP support.
- WebP conversion is irreversible after the original file is replaced.
- Non-image files are passed through unchanged.

### Compatibility

| Component | Version |
|---|---|
| OctoberCMS | 4.x |
| PHP | 8.1+ |
| Composer | 2.x |

### Changelog Summary

- 1.0.2: align plugin code and author code with Marketplace author code `DMdev`.
- 1.0.1: use `SettingModel`, improve temp file cleanup.
- 1.0.0: initial release.

### License

MIT. See [LICENSE](LICENSE).

### Author

DMdev / Denis Mishin — [dmdev.ru](https://dmdev.ru)

Repository: [github.com/mifasu/imagetailor-plugin](https://github.com/mifasu/imagetailor-plugin)

## RU

### Возможности

- Обрабатывает загрузки изображений в Tailor-полях `fileupload` с `mode: image`.
- Изменяет размер изображений под заданные ограничения по ширине и высоте.
- Сохраняет пропорции и не обрезает изображение.
- Конвертирует изображения в WebP для уменьшения размера файлов.
- Никогда не увеличивает изображения.
- Заменяет оригинальный файл вместо создания копий.
- Поддерживает переопределение через `imageWidth` и `imageHeight` в blueprint.
- Может быть включён или выключен глобально через настройки в backend.
- Не ломает загрузку: если обработка не удалась, файл всё равно сохранится.

### Требования

- OctoberCMS 4.x
- PHP 8.1+
- Расширение GD с поддержкой WebP
- Composer 2.x

### Установка

Через Composer:

```bash
composer require dmdev/imagetailor-plugin
php artisan october:migrate
```

Ручная установка:

```bash
php artisan october:migrate
```

Если плагин установлен вручную, сначала скопируйте его в `plugins/dmdev/imagetailor`.

### Настройки в backend

Откройте **Settings → CMS → Image Tailor**.

| Параметр | Значение по умолчанию | Описание |
|---|---|---|
| Включён | Да | Глобально включает или отключает обработку изображений. |
| Макс. ширина | 1200 px | Значение по умолчанию, если в blueprint не задан `imageWidth`. |
| Макс. высота | 1200 px | Значение по умолчанию, если в blueprint не задан `imageHeight`. |

### Пример Tailor blueprint

```yaml
images:
  label: Изображения
  type: fileupload
  mode: image
  imageWidth: 800
  imageHeight: 800
```

### Как работает обработка

1. Сохраняется новая запись `System\Models\File`.
2. Плагин проверяет, является ли файл изображением.
3. Если файл уже WebP и укладывается в лимиты, он пропускается.
4. Иначе изображение уменьшается и сохраняется в WebP с качеством 85.
5. Запись файла обновляется напрямую, чтобы не запускать повторное сохранение.

### Ограничения

- Обрабатываются только файлы при первой загрузке (`wasRecentlyCreated`).
- Нужен GD с поддержкой WebP.
- После замены оригинала конвертация в WebP необратима.
- Не-изображения пропускаются без изменений.

### Совместимость

| Компонент | Версия |
|---|---|
| OctoberCMS | 4.x |
| PHP | 8.1+ |
| Composer | 2.x |

### Краткий changelog

- 1.0.2: code и author code синхронизированы с Marketplace author code `DMdev`.
- 1.0.1: `SettingModel`, улучшена очистка временных файлов.
- 1.0.0: первый стабильный релиз.

### Лицензия

MIT. См. [LICENSE](LICENSE).

### Автор

DMdev / Denis Mishin — [dmdev.ru](https://dmdev.ru)

Repository: [github.com/mifasu/imagetailor-plugin](https://github.com/mifasu/imagetailor-plugin)
- Читает ограничения из конфига blueprint-поля (`imageWidth` / `imageHeight`), с фоллбэком на глобальные настройки.
- Включается/выключается глобально через настройки в бэкенде.
- Безопасная обработка ошибок — загрузка не прерывается при сбое обработки.

## Требования

- OctoberCMS 4.x
- PHP 8.1+ с расширением GD (поддержка WebP)

## Установка

Скопируйте плагин в `plugins/dmdev/imagetailor` и выполните:

```bash
php artisan october:migrate
```

Или установите через Composer:

```bash
composer require dmdev/imagetailor-plugin
```

## Настройка

Перейдите в **Настройки → CMS → Image Tailor** в панели управления.

| Параметр | По умолчанию | Описание |
|---|---|---|
| Включён | Да | Глобальное включение/выключение обработки изображений. |
| Макс. ширина | 1200 px | Максимальная ширина. Используется если blueprint не задаёт `imageWidth`. |
| Макс. высота | 1200 px | Максимальная высота. Используется если blueprint не задаёт `imageHeight`. |

### Переопределение на уровне Blueprint

Если blueprint Tailor задаёт `imageWidth` и/или `imageHeight` для поля `fileupload`, эти значения имеют приоритет над глобальными настройками.

```yaml
images:
  label: Изображения
  type: fileupload
  mode: image
  imageWidth: 800
  imageHeight: 800
```

## Как это работает

1. При сохранении нового файла (`System\Models\File`) плагин проверяет, является ли он изображением.
2. Если изображение уже в WebP и вписывается в лимиты — пропускается.
3. Иначе изображение ресайзится (вписывание в рамки, пропорции сохраняются) и сохраняется как WebP (качество 85).
4. Оригинальный файл заменяется в хранилище, запись в БД обновляется.


## Автор

Denis Mishin — https://dmdev.ru

Repository: https://github.com/mifasu/imagetailor

## Лицензия

MIT
