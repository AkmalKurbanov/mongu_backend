<?php namespace DMdev\Imagetailor\Services;

use DB;
use File as FileHelper;
use Log;
use October\Rain\Resize\Resizer;
use System\Models\File;
use DMdev\Imagetailor\Models\Settings;
use Tailor\Classes\BlueprintModel;
use Throwable;

/**
 * ImageProcessingService
 *
 * Обрабатывает новые загруженные изображения: ресайз + конвертация в WebP.
 */
class ImageProcessingService
{
    /**
     * Точка входа: обработать файл с перехватом ошибок.
     */
    public function process(File $file): void
    {
        try {
            $this->doProcess($file);
        } catch (Throwable $e) {
            Log::error('[ImageTailor] Ошибка обработки файла #' . $file->id . ': ' . $e->getMessage(), [
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
                'disk_name' => $file->disk_name ?? null,
            ]);
        }
    }

    /**
     * Основная логика обработки изображения.
     */
    protected function doProcess(File $file): void
    {
        // Проверяем включён ли плагин
        $settings = Settings::instance();
        if (!(bool) $settings->get('enabled', true)) {
            return;
        }

        // Обрабатываем только изображения (не PDF, не видео и т.д.)
        if (!$file->isImage()) {
            return;
        }

        $localPath = $file->getLocalPath();

        if (!FileHelper::exists($localPath)) {
            return;
        }

        // Определяем максимальные размеры
        [$maxW, $maxH] = $this->getMaxDimensions($file);

        // Получаем реальные размеры изображения
        $dims = @getimagesize($localPath);
        if (!$dims) {
            return;
        }

        [$actualW, $actualH] = $dims;

        // Пропускаем, если уже WebP и вписывается в лимиты
        $ext = strtolower($file->getExtension());
        if ($ext === 'webp' && $actualW <= $maxW && $actualH <= $maxH) {
            return;
        }

        // Целевые размеры: не увеличиваем оригинал
        $targetW = min($actualW, $maxW);
        $targetH = min($actualH, $maxH);

        // Сохраняем обработанный WebP во временный файл
        $tempName = str_replace('.', '', uniqid('it_', true)) . '.webp';
        $tempPath = temp_path($tempName);

        try {
            Resizer::open($localPath)
                ->resize($targetW, $targetH, ['mode' => 'auto', 'quality' => 85])
                ->save($tempPath);

            if (!FileHelper::exists($tempPath)) {
                return;
            }

            // Вычисляем новые имена файлов (меняем расширение на .webp)
            $oldDiskName  = $file->disk_name;
            $newDiskName  = pathinfo($oldDiskName, PATHINFO_FILENAME) . '.webp';
            $newFileName  = pathinfo($file->file_name, PATHINFO_FILENAME) . '.webp';

            // Итоговый путь в хранилище
            $storageDir   = dirname($localPath);
            $newLocalPath = $storageDir . DIRECTORY_SEPARATOR . $newDiskName;

            // Копируем обработанный файл в хранилище
            FileHelper::copy($tempPath, $newLocalPath);
            FileHelper::chmod($newLocalPath);
        } finally {
            // Гарантируем удаление временного файла
            if (FileHelper::exists($tempPath)) {
                FileHelper::delete($tempPath);
            }
        }

        // Удаляем оригинал, если имя файла изменилось
        if ($oldDiskName !== $newDiskName && FileHelper::exists($localPath)) {
            FileHelper::delete($localPath);
        }

        $newSize = FileHelper::size($newLocalPath);

        // Обновляем запись в БД напрямую, чтобы не вызвать afterSave повторно
        DB::table($file->getTable())
            ->where('id', $file->id)
            ->update([
                'file_name'    => $newFileName,
                'disk_name'    => $newDiskName,
                'content_type' => 'image/webp',
                'file_size'    => $newSize,
            ]);

        // Синхронизируем атрибуты in-memory модели
        $file->file_name    = $newFileName;
        $file->disk_name    = $newDiskName;
        $file->content_type = 'image/webp';
        $file->file_size    = $newSize;
    }

    /**
     * Возвращает [maxWidth, maxHeight] для данного файла.
     *
     * Приоритет:
     *  1. Конфиг поля в blueprint (imageWidth / imageHeight)
     *  2. Глобальные настройки плагина
     *  3. Умолчания (1200 × 1200)
     */
    protected function getMaxDimensions(File $file): array
    {
        $fieldConfig = $this->getBlueprintFieldConfig($file);

        if ($fieldConfig !== null) {
            $w = isset($fieldConfig['imageWidth'])  ? (int) $fieldConfig['imageWidth']  : null;
            $h = isset($fieldConfig['imageHeight']) ? (int) $fieldConfig['imageHeight'] : null;

            if ($w || $h) {
                $settings = Settings::instance();
                return [
                    $w ?: (int) $settings->get('default_max_width', 1200),
                    $h ?: (int) $settings->get('default_max_height', 1200),
                ];
            }
        }

        $settings = Settings::instance();

        return [
            (int) $settings->get('default_max_width', 1200),
            (int) $settings->get('default_max_height', 1200),
        ];
    }

    /**
     * Пытается получить конфиг поля fileupload из blueprint Tailor.
     * При любой ошибке возвращает null (fallback к глобальным настройкам).
     */
    protected function getBlueprintFieldConfig(File $file): ?array
    {
        try {
            if (!$file->attachment_type || !$file->attachment_id || !$file->field) {
                return null;
            }

            $class = $file->attachment_type;

            // Только для моделей Tailor
            if (!class_exists($class) || !is_subclass_of($class, BlueprintModel::class)) {
                return null;
            }

            /** @var BlueprintModel|null $record */
            $record = $class::withoutGlobalScopes()->find($file->attachment_id);
            if (!$record) {
                return null;
            }

            $blueprint = $record->getBlueprintDefinition();
            $fields    = $blueprint->fields ?? [];

            return is_array($fields) ? ($fields[$file->field] ?? null) : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}
