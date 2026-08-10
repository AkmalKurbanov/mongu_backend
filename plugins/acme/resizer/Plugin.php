<?php namespace Acme\Resizer;

use System\Classes\PluginBase;
use File;

class Plugin extends PluginBase
{
    public function pluginDetails()
    {
        return [
            'name' => 'Smart Resizer',
            'description' => 'Умный физический кроп по позициям из админки.',
            'author' => 'Acme',
            'icon' => 'icon-picture-o'
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

    public function makeSmartCrop($image, $width, $height, $position = 'center', $quality = 75, $extension = 'webp')
    {
        if (!$image) {
            return '';
        }

        $absolutePath = null;

        if (is_object($image) && method_exists($image, 'getLocalPath')) {
            $absolutePath = $image->getLocalPath();
        } elseif (is_string($image)) {
            $absolutePath = base_path(ltrim($image, '/'));
        }

        if (!$absolutePath || !File::exists($absolutePath)) {
            return '';
        }

        // Уникальный хэш для кэша обрезанных картинок
        $hash = md5($absolutePath . $width . $height . $position . $quality . $extension);
        $cacheDir = storage_path('app/smart_crops');
        $cacheFileName = $hash . '.' . $extension;
        $cacheFilePath = $cacheDir . '/' . $cacheFileName;
        $publicUrl = url('storage/app/smart_crops/' . $cacheFileName);

        // Если файл уже есть в кэше — отдаем сразу
        if (File::exists($cacheFilePath)) {
            return $publicUrl;
        }

        if (!File::isDirectory($cacheDir)) {
            File::makeDirectory($cacheDir, 0777, true, true);
        }

        // Получаем размеры исходника
        list($origWidth, $origHeight, $imageType) = getimagesize($absolutePath);
        
        if (!$origWidth || !$origHeight) {
            return '';
        }

        // Создаем ресурс картинки
        switch ($imageType) {
            case IMAGETYPE_JPEG: $sourceImage = imagecreatefromjpeg($absolutePath); break;
            case IMAGETYPE_PNG:  $sourceImage = imagecreatefrompng($absolutePath); break;
            case IMAGETYPE_WEBP: $sourceImage = imagecreatefromwebp($absolutePath); break;
            default: return '';
        }

        // Расчет пропорций для точного кропа без искажений
        $targetRatio = $width / $height;
        $origRatio = $origWidth / $origHeight;

        if ($origRatio > $targetRatio) {
            $cropHeight = $origHeight;
            $cropWidth = $origHeight * $targetRatio;
        } else {
            $cropWidth = $origWidth;
            $cropHeight = $origWidth / $targetRatio;
        }

        $srcX = 0;
        $srcY = 0;

        // Позиционирование по вертикали (top, bottom, center)
        if (strpos($position, 'top') !== false) {
            $srcY = 0;
        } elseif (strpos($position, 'bottom') !== false) {
            $srcY = $origHeight - $cropHeight;
        } else {
            $srcY = ($origHeight - $cropHeight) / 2;
        }

        // Позиционирование по горизонтали (left, right, center)
        if (strpos($position, 'left') !== false) {
            $srcX = 0;
        } elseif (strpos($position, 'right') !== false) {
            $srcX = $origWidth - $cropWidth;
        } else {
            $srcX = ($origWidth - $cropWidth) / 2;
        }

        // Создаем холст под нужный размер (например, мобильные 450x400)
        $virtualImage = imagecreatetruecolor($width, $height);

        imagealphablending($virtualImage, false);
        imagesavealpha($virtualImage, true);

        imagecopyresampled(
            $virtualImage, $sourceImage,
            0, 0, $srcX, $srcY,
            $width, $height, $cropWidth, $cropHeight
        );

        // Сохраняем вwebp или avif/jpeg
        if ($extension === 'avif' || $extension === 'webp') {
            imagewebp($virtualImage, $cacheFilePath, $quality);
        } else {
            imagejpeg($virtualImage, $cacheFilePath, $quality);
        }

        imagedestroy($virtualImage);
        imagedestroy($sourceImage);

        return $publicUrl;
    }
}