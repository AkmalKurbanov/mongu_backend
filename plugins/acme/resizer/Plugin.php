<?php namespace Acme\Resizer;

use System\Classes\PluginBase;
use File;
use Log;

class Plugin extends PluginBase
{
    public function pluginDetails()
    {
        return [
            'name'        => 'Smart Resizer',
            'description' => 'Адаптивный физический кроп на базе движка October CMS.',
            'author'      => 'Acme',
            'icon'        => 'icon-picture-o'
        ];
    }

    public function register()
    {
        $this->registerConsoleCommand('smartcrop.clean', \Acme\Resizer\Console\CleanSmartCrops::class);
    }

    public function registerSchedule($schedule)
    {
        // Раз в сутки в 03:00 чистит файлы старше 60 дней
        $schedule->command('smartcrop:clean --days=60')->dailyAt('03:00');
    }

    public function registerMarkupTags()
    {
        return [
            'filters' => [
                'smart_crop' => [$this, 'makeSmartCrop']
            ]
        ];
    }

    public function makeSmartCrop($image, $width, $height, $position = 'center-bg', $quality = 75, $extension = 'webp')
    {
        if (!$image || !$width || !$height) {
            return '';
        }

        $absolutePath = null;

        // 1. Извлечение физического пути к файлу
        if (is_object($image) && method_exists($image, 'getLocalPath')) {
            $absolutePath = $image->getLocalPath();
        } elseif (is_string($image)) {
            $cleanPath = ltrim($image, '/');
            if (str_starts_with($cleanPath, 'storage/app/media/')) {
                $absolutePath = base_path($cleanPath);
            } else {
                $absolutePath = storage_path('app/media/' . $cleanPath);
            }

            if (!File::exists($absolutePath) && File::exists($image)) {
                $absolutePath = $image;
            }
        }

        if (!$absolutePath || !File::exists($absolutePath)) {
            return '';
        }

        $extension = strtolower($extension);
        if (!in_array($extension, ['webp', 'avif', 'jpg', 'jpeg', 'png'])) {
            $extension = 'webp';
        }

        // 2. Проверка кэша.
        // ВАЖНО: в хэш добавлен filemtime исходника — если файл в медиатеке
        // перезалит по тому же пути, старая версия кропа больше не будет
        // залипать в кэше навсегда.
        $sourceMtime = File::lastModified($absolutePath);
        $hash = md5($absolutePath . $sourceMtime . $width . $height . $position . $quality . $extension);
        $cacheDir = storage_path('app/smart_crops');
        $cacheFileName = $hash . '.' . $extension;
        $cacheFilePath = $cacheDir . '/' . $cacheFileName;
        $publicUrl = url('storage/app/smart_crops/' . $cacheFileName);

        if (File::exists($cacheFilePath)) {
            return $publicUrl;
        }

        if (!File::isDirectory($cacheDir)) {
            File::makeDirectory($cacheDir, 0775, true, true);
        }


        $lockFilePath = $cacheFilePath . '.lock';
        if (File::exists($lockFilePath) && (time() - File::lastModified($lockFilePath)) < 30) {
            return $publicUrl;
        }
        File::put($lockFilePath, '1');

        try {
            $this->renderCropped($absolutePath, $cacheFilePath, (int)$width, (int)$height, $position, (int)$quality, $extension);
            return $publicUrl;
        } catch (\Throwable $e) {
            Log::error('[SmartResizer] Error resizing image ' . $absolutePath . ': ' . $e->getMessage());
            return '';
        } finally {
            File::delete($lockFilePath);
        }
    }

    /**
     *  Ручной кроп через GD для всех форматов (avif, webp, jpg, png).
     */
    protected function renderCropped(string $srcPath, string $destPath, int $width, int $height, string $position, int $quality, string $extension): void
    {
        list($origW, $origH, $type) = getimagesize($srcPath);

        $src = match ($type) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($srcPath),
            IMAGETYPE_PNG  => imagecreatefrompng($srcPath),
            IMAGETYPE_WEBP => imagecreatefromwebp($srcPath),
            default        => null,
        };

        if (!$src) {
            throw new \RuntimeException('Unsupported source image format for crop generation: ' . $srcPath);
        }

        // Автоповорот по EXIF для JPEG
        if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($srcPath);
            if (!empty($exif['Orientation'])) {
                $src = match ($exif['Orientation']) {
                    3 => imagerotate($src, 180, 0),
                    6 => imagerotate($src, -90, 0),
                    8 => imagerotate($src, 90, 0),
                    default => $src,
                };
                $origW = imagesx($src);
                $origH = imagesy($src);
            }
        }

        // Область кропа под нужное соотношение сторон
        $targetRatio = $width / $height;
        $origRatio = $origW / $origH;

        if ($origRatio > $targetRatio) {
            $cropH = $origH;
            $cropW = (int) round($origH * $targetRatio);
        } else {
            $cropW = $origW;
            $cropH = (int) round($origW / $targetRatio);
        }

        [$offsetX, $offsetY] = $this->resolvePosition($position);

        $srcX = match ($offsetX) {
            'left'  => 0,
            'right' => $origW - $cropW,
            default => (int) round(($origW - $cropW) / 2), // center
        };

        $srcY = match ($offsetY) {
            'top'    => 0,
            'bottom' => $origH - $cropH,
            default  => (int) round(($origH - $cropH) / 2), // center
        };

        $dst = imagecreatetruecolor($width, $height);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);

        imagecopyresampled($dst, $src, 0, 0, $srcX, $srcY, $width, $height, $cropW, $cropH);

        match ($extension) {
            'avif'         => imageavif($dst, $destPath, $quality),
            'webp'         => imagewebp($dst, $destPath, $quality),
            'png'          => imagepng($dst, $destPath, (int) round((100 - $quality) / 100 * 9)),
            default        => imagejpeg($dst, $destPath, $quality), // jpg, jpeg
        };

        imagedestroy($dst);
        imagedestroy($src);
    }

   
    protected function resolvePosition(string $position): array
    {
        $map = [
            'top-bg'          => [0, 'top'],
            'bottom-bg'       => [0, 'bottom'],
            'left-bg'         => ['left', 0],
            'right-bg'        => ['right', 0],
            'top-left-bg'     => ['left', 'top'],
            'top-right-bg'    => ['right', 'top'],
            'bottom-left-bg'  => ['left', 'bottom'],
            'bottom-right-bg' => ['right', 'bottom'],
            'center-bg'       => [0, 0],
        ];

        return $map[$position] ?? $map['center-bg'];
    }
}