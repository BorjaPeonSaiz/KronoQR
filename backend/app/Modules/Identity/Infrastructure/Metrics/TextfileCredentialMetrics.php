<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Metrics;

use App\Modules\Identity\Application\Port\CredentialMetrics;
use App\Modules\Identity\Domain\ValueObject\SiteCredentialCoverage;
use DateTimeImmutable;
use Illuminate\Support\Facades\Config;
use RuntimeException;

/**
 * Publica las dos metricas de credenciales para el colector *textfile* de
 * `node-exporter` (doc 02 §8.2).
 *
 * ```
 * employees_without_delivered_credential{site="1",site_name="Hotel Marina"}
 * credentials_pending_print{site="1",site_name="Hotel Marina"}
 * ```
 *
 * **Por que un fichero y no un contador en memoria.** `/metrics` lo expone la
 * aplicacion a partir de la tarea 3.1. Hasta entonces, quien produce estos
 * numeros es un comando programado que corre y termina, y un contador en memoria
 * de un proceso que termina no lo lee nadie. Es el mismo mecanismo que usan las
 * metricas de la copia y las de la cadena de auditoria, y por la misma razon.
 *
 * **Se escriben SIEMPRE, tambien cuando todo esta a cero.** Una serie que
 * desaparece es indistinguible de una que nunca tuvo nada, y aqui el cero es
 * justo el valor que importa: *«debe llegar a cero antes del primer dia de cada
 * incorporacion»* (§8.2). Si al entregarse la ultima tarjeta la serie
 * desapareciera, el panel no podria distinguir «ya esta todo entregado» de «el
 * comando dejo de ejecutarse».
 *
 * **Los dos son `gauge` y no `counter`.** Suben y bajan: cada alta los sube y
 * cada entrega los baja. Un `counter` con `rate()` no diria nada util sobre
 * cuanta gente no puede fichar mañana.
 *
 * **El nombre del centro va como etiqueta y el nombre de nadie mas** (regla dura
 * 21). `site_name` es informacion de la instalacion, no un dato personal, y sin
 * el un panel de Grafana muestra `site="3"` y nadie sabe de que hotel habla.
 *
 * **Escritura atomica:** temporal en el mismo directorio y `rename()`.
 * `node-exporter` no puede leer media metrica.
 */
final readonly class TextfileCredentialMetrics implements CredentialMetrics
{
    private const string FILE = 'kronoqr_credentials.prom';

    public function recordCoverage(array $coverage, DateTimeImmutable $at): void
    {
        $lines = [
            '# HELP employees_without_delivered_credential Empleados de alta que todavia no tienen su tarjeta entregada en la mano (RF-QR-08). Debe llegar a cero antes del primer dia de cada incorporacion.',
            '# TYPE employees_without_delivered_credential gauge',
        ];

        foreach ($coverage as $site) {
            $lines[] = 'employees_without_delivered_credential'.$this->labels($site).' '.$site->withoutDeliveredCredential;
        }

        $lines[] = '# HELP credentials_pending_print Credenciales activas emitidas y todavia sin imprimir (RF-QR-08).';
        $lines[] = '# TYPE credentials_pending_print gauge';

        foreach ($coverage as $site) {
            $lines[] = 'credentials_pending_print'.$this->labels($site).' '.$site->pendingPrint;
        }

        $lines[] = '# HELP credentials_coverage_employees Empleados de alta del centro. Es el denominador de las dos metricas anteriores.';
        $lines[] = '# TYPE credentials_coverage_employees gauge';

        foreach ($coverage as $site) {
            $lines[] = 'credentials_coverage_employees'.$this->labels($site).' '.$site->employees;
        }

        $lines[] = '# HELP credentials_coverage_check_timestamp_seconds Momento del ultimo recuento. Su ausencia delata que el comando programado dejo de ejecutarse.';
        $lines[] = '# TYPE credentials_coverage_check_timestamp_seconds gauge';
        $lines[] = 'credentials_coverage_check_timestamp_seconds '.$at->getTimestamp();

        $this->write($lines);
    }

    private function labels(SiteCredentialCoverage $site): string
    {
        return '{site="'.$site->siteId.'",site_name="'.$this->escape($site->siteName).'"}';
    }

    /**
     * El formato de exposicion de Prometheus escapa `\`, `"` y el salto de linea
     * dentro del valor de una etiqueta. Sin esto, un centro llamado `Hotel "El
     * Faro"` produciria un fichero que `node-exporter` descarta entero — y con el
     * las dos metricas de todos los demas centros.
     */
    private function escape(string $value): string
    {
        return str_replace(['\\', '"', "\n"], ['\\\\', '\"', '\n'], $value);
    }

    /**
     * @param  list<string>  $lines
     */
    private function write(array $lines): void
    {
        if (! Config::boolean('observability.metrics.enabled', true)) {
            return;
        }

        $directory = rtrim(Config::string('observability.metrics.textfile_path'), '/');

        if (! is_dir($directory) && ! mkdir($directory, 0o750, true) && ! is_dir($directory)) {
            throw new RuntimeException('No se ha podido crear el directorio de metricas «'.$directory.'».');
        }

        $target = $directory.'/'.self::FILE;
        $temporary = $target.'.tmp';

        if (file_put_contents($temporary, implode("\n", $lines)."\n") === false) {
            throw new RuntimeException('No se ha podido escribir la metrica en «'.$temporary.'».');
        }

        if (! rename($temporary, $target)) {
            throw new RuntimeException('No se ha podido publicar la metrica en «'.$target.'».');
        }
    }
}
