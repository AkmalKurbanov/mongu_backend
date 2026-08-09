# OctoberCMS Marketplace — Plugin Submission Data

## Plugin Metadata

| Field | Value |
|---|---|
| **Plugin Code** | `DMdev.Imagetailor` |
| **Composer Package** | `dmdev/imagetailor-plugin` |
| **Repository** | https://github.com/mifasu/imagetailor-plugin |
| **License** | MIT |
| **Author** | Denis Mishin |
| **Author URL** | https://dmdev.ru |

---

## Title

```
Image Tailor
```

---

## Short Description (≤ 160 characters)

```
Automatically resizes and converts uploaded images to WebP format in OctoberCMS Tailor fileupload fields.
```

---

## Full Description

Image Tailor is a lightweight OctoberCMS plugin that automatically processes images on upload.
Every new image uploaded through a Tailor `fileupload` field is resized to fit within configurable
dimension limits and converted to WebP format — without any extra code in your blueprints or templates.

**How it works:**

When a new `System\Models\File` record is saved, the plugin checks whether the file is an image.
If it exceeds the configured maximum width or height, it is resized (fit-inside, aspect ratio preserved,
no cropping). The result is saved as WebP (quality 85) and replaces the original file in storage.
The database record is updated in a single direct query to avoid re-triggering the event.

**Key behaviours:**

- Images already within limits and already in WebP format are skipped entirely.
- Dimension limits can be set per-field in the blueprint (`imageWidth` / `imageHeight`) or globally
  in the backend settings panel.
- Non-image files (PDF, video, etc.) are passed through unchanged.
- If processing fails for any reason, the error is logged and the upload succeeds normally.

---

## Key Features

- Automatic WebP conversion on every image upload
- Configurable max width / height (global defaults + per-field blueprint overrides)
- Aspect ratio preserved, no cropping
- Never upscales — only downscales when needed
- Replaces original file, no duplicate storage
- Global enable/disable toggle in backend settings
- Zero blueprint changes required for basic usage
- Safe: upload never interrupted by processing failures

---

## Keywords / Tags

```
image, webp, resize, tailor, fileupload, convert, optimize, compression
```

---

## Compatibility

| OctoberCMS | PHP | Composer |
|---|---|---|
| 4.x | 8.1+ | 2.x |

Requires PHP GD extension with WebP support (LibGD ≥ 2.2.0).

---

## Installation Command

```bash
composer require dmdev/imagetailor-plugin
php artisan october:migrate
```

---

## Support / Contact

- **Author:** DMdev / Denis Mishin
- **Website:** https://dmdev.ru
- **GitHub Issues:** https://github.com/mifasu/imagetailor-plugin/issues
