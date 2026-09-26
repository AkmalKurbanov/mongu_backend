<?php namespace Acme\Resizer\Console;

use Illuminate\Console\Command;
use File;

/**
 * Удаляет старые файлы из кэша smart_crop.
 *
 * Запуск вручную:
 *   php artisan smartcrop:clean
 *   php artisan smartcrop:clean --days=30
 *
 * Автоматический запуск раз в день — добавь в plugins/acme/resizer/Plugin.php,
 * в метод register(), внутри Event::listen('console.schedule') (см. пример ниже).
 */
class CleanSmartCrops extends Command
{
    protected $signature = 'smartcrop:clean {--days=60 : Удалить файлы старше N дней}';

    protected $description = 'Удаляет старые закэшированные картинки smart_crop';

    public function handle()
    {
        $days = (int) $this->option('days');
        $cacheDir = storage_path('app/smart_crops');

        if (!File::isDirectory($cacheDir)) {
            $this->info('Папка smart_crops ещё не создана, чистить нечего.');
            return;
        }

        $cutoff = time() - ($days * 86400);
        $deleted = 0;

        foreach (File::files($cacheDir) as $file) {
            // .lock-файлы старше 5 минут тоже подчищаем на всякий случай
            // (например, если процесс генерации упал и не удалил за собой)
            $isStaleLock = str_ends_with($file->getFilename(), '.lock')
                && $file->getMTime() < (time() - 300);

            if ($file->getMTime() < $cutoff || $isStaleLock) {
                File::delete($file->getPathname());
                $deleted++;
            }
        }

        $this->info("Готово. Удалено файлов: {$deleted}.");
    }
}
