<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Support;

use App\Modules\Product\Domain\ValueObject\ResolvedSettings;
use App\Modules\Shared\Application\Support\SpanScope;
use Psr\Log\LoggerInterface;

/**
 * El log de `GET` y `PATCH /api/v1/settings` (doc 02 §8.1,
 * RF-PD-01).
 *
 * En el borde, junto al controlador. El span del acto lo abre el caso de uso
 * con `UseCaseInstrumentation`, la medida comun; aqui se añaden al
 * span en curso los atributos que solo conoce la peticion y se escribe el log
 * con su `trace_id`.
 *
 * ## El log del `PATCH` dice QUE claves, nunca sus valores
 *
 * Este log viaja a Loki y al paquete de diagnostico (ADR-020), que sale de la
 * instalacion. Las claves del catalogo son nombres del producto y no revelan
 * nada; sus valores son del cliente —el nombre del hotel, la ruta de su
 * logotipo— y su sitio es `audit_log`, donde hay control de acceso y retencion.
 * El antes y el despues completos estan alli, que es donde tienen valor
 * probatorio.
 *
 * ## Y `notice` en el `PATCH`
 *
 * Cambiar un umbral operativo puede cambiar los minutos que quedan registrados
 * (RF-AT-06): tiene que destacar sobre el ruido de las lecturas, igual que la
 * resolucion de una incidencia. La lectura es `info`, porque es lo que hace una
 * pantalla al abrirse.
 *
 * ## `unknown_keys` se registra a proposito
 *
 * Una fila que este binario no reconoce es el sintoma de una actualizacion a
 * medias, y el sitio donde alguien la va a ver sin ir a buscarla es el log de la
 * instalacion. No rompe nada y por eso no es un aviso: es un dato del `info`.
 */
final readonly class SettingsTelemetry
{
    public function __construct(private LoggerInterface $logger) {}

    /**
     * @param  callable(): ResolvedSettings  $read
     */
    public function measureRead(callable $read): ResolvedSettings
    {
        $startedAt = microtime(true);

        $settings = $read();

        SpanScope::annotateCurrent([
            'settings.keys' => \count($settings->all()),
            'settings.unknown_keys' => \count($settings->unknownKeys),
        ]);

        $this->logger->info('product.settings_retrieved', [
            'trace_id' => SpanScope::currentTraceId(),
            'keys' => \count($settings->all()),
            'unknown_keys' => $settings->unknownKeys,
            'duration_seconds' => round(microtime(true) - $startedAt, 3),
        ]);

        return $settings;
    }

    /**
     * @param  list<string>  $requestedKeys  las claves que la peticion pretende cambiar
     * @param  callable(): ResolvedSettings  $update
     */
    public function measureUpdate(array $requestedKeys, callable $update): ResolvedSettings
    {
        $startedAt = microtime(true);

        $settings = $update();

        SpanScope::annotateCurrent(['settings.requested_keys' => \count($requestedKeys)]);

        $this->logger->notice('product.settings_updated', [
            'trace_id' => SpanScope::currentTraceId(),
            // Los nombres de las claves, nunca sus valores. Ver el docblock.
            'requested_keys' => $requestedKeys,
            'duration_seconds' => round(microtime(true) - $startedAt, 3),
        ]);

        return $settings;
    }
}
