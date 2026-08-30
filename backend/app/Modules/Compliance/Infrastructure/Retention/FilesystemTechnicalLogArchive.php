<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Retention;

use App\Modules\Compliance\Application\Port\TechnicalLogArchive;
use App\Modules\Compliance\Domain\ValueObject\RetentionScope;
use App\Modules\Compliance\Domain\ValueObject\RetentionTally;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Config;

/**
 * El log tecnico a 90 dias (RL-11).
 *
 * ## No sustituye a la rotacion de Monolog: la remata
 *
 * `LOG_DAILY_DAYS` limita **cuantos ficheros** guarda el canal diario. Esto
 * cumple el **plazo**: alcanza lo que quede en el directorio -otros canales, un
 * `laravel.log` heredado de antes de la rotacion, un volcado que alguien copio
 * ahi para mirarlo-. Sin esta pasada, RL-11 dependeria de que nadie hubiera
 * dejado nunca un fichero suelto.
 *
 * ## Por antiguedad del fichero, no por su nombre
 *
 * Se mira `mtime` y no la fecha del nombre: un nombre se puede cambiar y un
 * canal puede no llevarla. Un fichero que sigue recibiendo lineas no es
 * historico, es el log de hoy, y por su `mtime` nunca vence.
 *
 * ## El informe no lleva rutas absolutas
 *
 * La ruta del directorio es del servidor del cliente y el informe se archiva y
 * se adjunta: en el aparece `storage/logs`, no `/var/www/kronoqr/storage/logs`
 * (regla dura 21 aplicada tambien a lo que describe la maquina).
 */
final readonly class FilesystemTechnicalLogArchive implements TechnicalLogArchive
{
    public function scope(): RetentionScope
    {
        return RetentionScope::TechnicalLog;
    }

    public function inspect(DateTimeImmutable $cutoff): RetentionTally
    {
        return $this->sweep($cutoff, delete: false);
    }

    public function purge(DateTimeImmutable $cutoff, int $batchSize): RetentionTally
    {
        // `$batchSize` no aplica: borrar un fichero no bloquea nada ni construye
        // una sentencia. Se acepta por la forma del puerto y se ignora aqui.
        return $this->sweep($cutoff, delete: true);
    }

    private function sweep(DateTimeImmutable $cutoff, bool $delete): RetentionTally
    {
        $directory = $this->directory();
        $label = $this->label();

        if (! is_dir($directory)) {
            return RetentionTally::unavailable(RetentionScope::TechnicalLog, $label);
        }

        $limit = $cutoff->getTimestamp();
        $files = 0;
        $oldest = null;
        $newest = null;

        foreach (glob($directory.DIRECTORY_SEPARATOR.'*.log') ?: [] as $file) {
            if (! is_file($file)) {
                continue;
            }

            $modifiedAt = filemtime($file);

            if ($modifiedAt === false || $modifiedAt >= $limit) {
                continue;
            }

            $files++;
            $oldest = $oldest === null ? $modifiedAt : min($oldest, $modifiedAt);
            $newest = $newest === null ? $modifiedAt : max($newest, $modifiedAt);

            if ($delete) {
                unlink($file);
            }
        }

        return new RetentionTally(
            scope: RetentionScope::TechnicalLog,
            dataset: $label,
            rows: $files,
            oldest: $this->asDate($oldest),
            newest: $this->asDate($newest),
        );
    }

    private function asDate(?int $timestamp): ?string
    {
        return $timestamp === null
            ? null
            : (new DateTimeImmutable('@'.$timestamp))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');
    }

    private function directory(): string
    {
        $configured = Config::string('compliance.retention.technical_log_path', '');

        return rtrim($configured === '' ? storage_path('logs') : $configured, '/\\');
    }

    /** Como se nombra el almacen en el informe: relativo, nunca la ruta del servidor. */
    private function label(): string
    {
        $directory = $this->directory();
        $base = rtrim(base_path(), '/\\');

        return str_starts_with($directory, $base)
            ? ltrim(str_replace('\\', '/', substr($directory, \strlen($base))), '/')
            : basename($directory);
    }
}
