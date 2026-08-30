<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Metrics;

use App\Modules\Identity\Application\Port\CredentialMetrics;
use App\Modules\Identity\Domain\ValueObject\SiteCredentialCoverage;
use App\Modules\Shared\Infrastructure\Metrics\TextfileExposition;
use DateTimeImmutable;

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
 * **La mecanica de escritura no vive aqui.** El guard del colector, la escritura
 * atomica, el fallo ruidoso y el escapado de las etiquetas son de
 * {@see TextfileExposition}, que es la misma para los siete adaptadores del
 * producto. Aqui solo se componen las lineas.
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

        // **La serie que siempre deberia valer cero** (revision de seguridad de
        // la 2.12): tarjetas vivas firmadas con una clave que la instalacion ya
        // no reconoce. Quien lleve una de esas no puede fichar, y el panel no lo
        // delata porque su fila se ve entregada. Se escribe una linea por clave
        // huerfana y, cuando no hay ninguna, un cero con `key_id=""`: una serie
        // que aparece y desaparece no se puede alertar.
        $lines[] = '# HELP credentials_active_unknown_key Tarjetas activas firmadas con una clave que ya no esta configurada (RF-QR-07). DEBE ser cero: quien lleve una de esas tarjetas no puede fichar.';
        $lines[] = '# TYPE credentials_active_unknown_key gauge';

        if ($coverage->unknownKeyCards === []) {
            $lines[] = 'credentials_active_unknown_key'.$this->unknownKeyLabels($coverage, '').' 0';
        }

        foreach ($coverage->unknownKeyIds() as $keyId) {
            $lines[] = 'credentials_active_unknown_key'.$this->unknownKeyLabels($coverage, $keyId)
                .' '.$coverage->unknownKeyCards[$keyId];
        }

        $lines[] = '# HELP credentials_coverage_check_timestamp_seconds Momento del ultimo recuento. Su ausencia delata que el comando programado dejo de ejecutarse.';
        $lines[] = '# TYPE credentials_coverage_check_timestamp_seconds gauge';
        $lines[] = 'credentials_coverage_check_timestamp_seconds '.$at->getTimestamp();

        TextfileExposition::write(self::FILE, $lines);
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

    /** Las del centro mas el `key_id` huerfano. Vacio cuando no hay ninguno. */
    private function unknownKeyLabels(SiteCredentialCoverage $site, string $keyId): string
    {
        return '{site="'.$site->siteId.'",site_name="'.$this->escape($site->siteName).'"'
            .',key_id="'.$this->escape($keyId).'"}';
    }

    private function labels(SiteCredentialCoverage $site): string
    {
        return '{site="'.$site->siteId.'",site_name="'.$this->escape($site->siteName).'"}';
    }

    /**
     * El escapado del formato de exposicion. El nombre del centro lo teclea una
     * persona, asi que puede traer comillas: ver {@see TextfileExposition::escapeLabel()},
     * que explica por que una sola comilla sin escapar tira el fichero entero.
     */
    private function escape(string $value): string
    {
        return TextfileExposition::escapeLabel($value);
    }
}
