<?php namespace DMdev\Imagetailor;

use System\Classes\PluginBase;
use System\Models\File;
use DMdev\Imagetailor\Models\Settings;
use DMdev\Imagetailor\Services\ImageProcessingService;

/**
 * ImageTailor Plugin
 *
 * Автоматически конвертирует загруженные изображения в WebP
 * с ресайзом по ограничениям blueprint или глобальным настройкам.
 */
class Plugin extends PluginBase
{
    public function pluginDetails(): array
    {
        return [
            'name'        => 'Image Tailor',
            'description' => 'Resizes and converts uploaded images to WebP format.',
            'author'      => 'DMdev',
            'icon'        => 'icon-image',
        ];
    }

    public function registerSettings(): array
    {
        return [
            'settings' => [
                'label'       => 'Image Tailor',
                'description' => 'Настройки автоматической конвертации изображений в WebP',
                'category'    => 'CATEGORY_CMS',
                'icon'        => 'icon-image',
                'class'       => Settings::class,
                'order'       => 600,
                'keywords'    => 'image webp resize tailor convert',
            ],
        ];
    }

    public function boot(): void
    {
        File::extend(function (File $file) {
            $file->bindEvent('model.afterSave', function () use ($file) {
                // Обрабатываем только новые файлы (первичная загрузка)
                if (!$file->wasRecentlyCreated) {
                    return;
                }

                app(ImageProcessingService::class)->process($file);
            });
        });
    }
}
