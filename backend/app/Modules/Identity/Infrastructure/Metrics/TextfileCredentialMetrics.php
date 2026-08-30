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
 * **La etiqueta `site` describe el centro de la instalacion** (ADR-040): hay
 * uno, y la etiqueta se conserva para que ningun panel ni alerta que ya la use
 * cambie de forma.
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

    public function recordCoverage(SiteCredentialCoverage $coverage, DateTimeImmutable $at): void
    {
        $labels = $this->labels($coverage);

        $lines = [
            '# HELP employees_without_delivered_credential Empleados de alta que todavia no tienen su tarjeta entregada en la mano (RF-QR-08). Debe llegar a cero antes del primer dia de cada incorporacion.',
            '# TYPE employees_without_delivered_credential gauge',
            'employees_without_delivered_credential'.$labels.' '.$coverage->withoutDeliveredCredential,
            '# HELP credentials_pending_print Credenciales activas emitidas y todavia sin imprimir (RF-QR-08).',
            '# TYPE credentials_pending_print gauge',
            'credentials_pending_print'.$labels.' '.$coverage->pendingPrint,
            '# HELP credentials_coverage_employees Empleados de alta de la instalacion. Es el denominador de las dos metricas anteriores.',
            '# TYPE credentials_coverage_employees gauge',
            'credentials_coverage_employees'.$labels.' '.$coverage->employees,
        ];

        // Avance de una rotacion de clave (RF-QR-07, §5.3). Se escribe SIEMPRE,
        // tambien fuera de una rotacion: entonces vale cero y lleva
        // `key_id=""`. Una serie que aparece y desaparece con la variable de
        // entorno no se puede graficar ni alertar, y el cero es justo el valor
        // que autoriza a retirar la clave anterior.
        $lines[] = '# HELP credentials_pending_reprint Personas cuya tarjeta en uso sigue firmada con la clave saliente de una rotacion (RF-QR-07). Llega a cero cuando la clave anterior se puede retirar.';
        $lines[] = '# TYPE credentials_pending_reprint gauge';
        $lines[] = 'credentials_pending_reprint'.$this->reprintLabels($coverage).' '.$coverage->pendingReprint;

        $lines[] = '# HELP credentials_coverage_check_timestamp_seconds Momento del ultimo recuento. Su ausencia delata que el comando programado dejo de ejecutarse.';
        $lines[] = '# TYPE credentials_coverage_check_timestamp_seconds gauge';
        $lines[] = 'credentials_coverage_check_timestamp_seconds '.$at->getTimestamp();

        $this->write($lines);
    }

    /**
     * Las mismas etiquetas del centro mas el `key_id` saliente.
     *
     * **El `key_id` es publico**: va impreso en cada tarjeta como primera parte
     * del payload (ADR-005). Lo que no sale por aqui, ni por ningun sitio, es la
     * clave.
     */
    private function reprintLabels(SiteCredentialCoverage $site): string
    {
        return '{site="'.$site->siteId.'",site_name="'.$this->escape($site->siteName).'"'
            .',key_id="'.$this->escape($site->retiringKeyId ?? '').'"}';
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
