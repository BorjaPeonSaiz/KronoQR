<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Support;

use App\Modules\Product\Domain\ValueObject\ComplianceProfileSnapshot;
use App\Modules\Shared\Application\Support\SpanScope;
use OpenTelemetry\API\Trace\SpanKind;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * La traza y el log de `GET` y `PATCH /api/v1/compliance-profile` (doc 02 §8.1,
 * RF-PD-07).
 *
 * Mismo sitio y mismo motivo que el resto de las telemetrias del producto: en el
 * borde, para que el `trace_id` de `traceparent` sea el padre del span y para que
 * el caso de uso se quede orquestando reglas sin un `try/finally` alrededor.
 *
 * ## El log del `PATCH` dice QUE campos, nunca sus valores
 *
 * Este log viaja a Loki y al paquete de diagnostico (ADR-020), que sale de la
 * instalacion. Los nombres de los campos son del producto y no revelan nada; sus
 * valores describen el convenio del cliente y su sitio es `audit_log`, donde hay
 * control de acceso y retencion. El antes y el despues completos estan alli, que
 * es donde tienen valor probatorio.
 *
 * ## `warning` y no `notice` en el `PATCH`
 *
 * Es el unico log del producto que sube de nivel al cambiar configuracion, y
 * tiene motivo: cambiar un umbral legal altera **que incumplimientos se
 * detectan**, y bajar `retention_years` autoriza a borrar registro que hay
 * obligacion de conservar cuatro años. Quien revise el log de una instalacion
 * seis meses despues tiene que tropezarse con esta linea sin ir a buscarla. La
 * lectura sigue siendo `info`, porque es lo que hace una pantalla al abrirse.
 */
final readonly class ComplianceProfileTelemetry
{
    public function __construct(private LoggerInterface $logger) {}

    /**
     * @param  callable(): ?ComplianceProfileSnapshot  $read
     */
    public function measureRead(callable $read): ?ComplianceProfileSnapshot
    {
        $span = SpanScope::start('kronoqr.product', 'product.read_compliance_profile', SpanKind::KIND_SERVER);
        $startedAt = microtime(true);

        try {
            $profile = $read();
        } catch (Throwable $failure) {
            $span->end();

            throw $failure;
        }

        $span->end(['compliance_profile.resolved' => $profile !== null]);

        $this->logger->info('product.compliance_profile_read', [
            'trace_id' => $span->traceId(),
            'resolved' => $profile !== null,
            'duration_seconds' => round(microtime(true) - $startedAt, 3),
        ]);

        return $profile;
    }

    /**
     * @param  list<string>  $requestedFields  los campos que la peticion pretende cambiar
     * @param  callable(): ?ComplianceProfileSnapshot  $update
     */
    public function measureUpdate(array $requestedFields, callable $update): ?ComplianceProfileSnapshot
    {
        $span = SpanScope::start('kronoqr.product', 'product.update_compliance_profile', SpanKind::KIND_SERVER);
        $startedAt = microtime(true);

        try {
            $profile = $update();
        } catch (Throwable $failure) {
            $span->end();

            throw $failure;
        }

        $span->end(['compliance_profile.requested_fields' => \count($requestedFields)]);

        $this->logger->warning('product.compliance_profile_updated', [
            'trace_id' => $span->traceId(),
            // Los nombres de los campos, nunca sus valores. Ver el docblock.
            'requested_fields' => $requestedFields,
            'duration_seconds' => round(microtime(true) - $startedAt, 3),
        ]);

        return $profile;
    }
}
