<?php namespace Acme\Resizer;

use System\Classes\PluginBase;
use October\Rain\Resize\Resizer;
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

        // 2. Проверка кэша
        $hash = md5($absolutePath . $width . $height . $position . $quality . $extension);
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

        // 3. Маппинг позиций кропа для нативного Resizer
        $offsetMap = [
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

        $offset = $offsetMap[$position] ?? [0, 0];

        try {
            // Если запрошен настоящий AVIF и сервер его поддерживает
            if ($extension === 'avif' && function_exists('imageavif')) {
                $this->renderAvifNative($absolutePath, $cacheFilePath, (int)$width, (int)$height, $offset, (int)$quality);
            } else {
                // Во всех остальных случаях используем нативный Resizer October CMS
                Resizer::open($absolutePath)
                    ->resize((int)$width, (int)$height, [
                        'mode'    => 'crop',
                        'offset'  => $offset,
                        'quality' => (int)$quality
                    ])
                    ->save($cacheFilePath);
            }

            return $publicUrl;
        } catch (\Throwable $e) {
            Log::error('[SmartResizer] Error resizing image ' . $absolutePath . ': ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Прямая генерация валидного AVIF через GD с поддержкой EXIF
     */
    protected function renderAvifNative(string $srcPath, string $destPath, int $width, int $height, array $offset, int $quality): void
    {
        list($origW, $origH, $type) = getimagesize($srcPath);
        
        $src = match ($type) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($srcPath),
            IMAGETYPE_PNG  => imagecreatefrompng($srcPath),
            IMAGETYPE_WEBP => imagecreatefromwebp($srcPath),
            default        => null,
        };

        if (!$src) {
            throw new \RuntimeException('Unsupported image format for AVIF generation');
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

        $targetRatio = $width / $height;
        $origRatio = $origW / $origH;

        if ($origRatio > $targetRatio) {
            $cropH = $origH;
            $cropW = (int)round($origH * $targetRatio);
        } else {
            $cropW = $origW;
            $cropH = (int)round($origW / $targetRatio);
        }

        $srcX = match ($offset[0]) {
            'left'  => 0,
            'right' => $origW - $cropW,
            default => (int)round(($origW - $cropW) / 2),
        };

        $srcY = match ($offset[1]) {
            'top'    => 0,
            'bottom' => $origH - $cropH,
            default  => (int)round(($origH - $cropH) / 2),
        };

        $dst = imagecreatetruecolor($width, $height);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);

        imagecopyresampled($dst, $src, 0, 0, $srcX, $srcY, $width, $height, $cropW, $cropH);
        
        imageavif($dst, $destPath, $quality);

        imagedestroy($dst);
        imagedestroy($src);
    }
}