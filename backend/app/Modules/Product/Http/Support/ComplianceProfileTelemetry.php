<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Support;

use App\Modules\Product\Application\Query\ComplianceProfileView;
use App\Modules\Shared\Application\Support\SpanScope;
use Psr\Log\LoggerInterface;

/**
 * El log de `GET` y `PATCH /api/v1/compliance-profile` (doc 02 §8.1,
 * RF-PD-07).
 *
 * En el borde, junto al controlador. El span del acto lo abre el caso de uso
 * con `UseCaseInstrumentation`, la medida comun (V1-RV-03); aqui se añaden al
 * span en curso los atributos que solo conoce la peticion y se escribe el log
 * con su `trace_id`.
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
     * @param  callable(): ?ComplianceProfileView  $read
     */
    public function measureRead(callable $read): ?ComplianceProfileView
    {
        $startedAt = microtime(true);

        $profile = $read();

        SpanScope::annotateCurrent(['compliance_profile.resolved' => $profile !== null]);

        $this->logger->info('product.compliance_profile_read', [
            'trace_id' => SpanScope::currentTraceId(),
            'resolved' => $profile !== null,
            'duration_seconds' => round(microtime(true) - $startedAt, 3),
        ]);

        return $profile;
    }

    /**
     * @param  list<string>  $requestedFields  los campos que la peticion pretende cambiar
     * @param  callable(): ?ComplianceProfileView  $update
     */
    public function measureUpdate(array $requestedFields, callable $update): ?ComplianceProfileView
    {
        $startedAt = microtime(true);

        $profile = $update();

        SpanScope::annotateCurrent(['compliance_profile.requested_fields' => \count($requestedFields)]);

        $this->logger->warning('product.compliance_profile_updated', [
            'trace_id' => SpanScope::currentTraceId(),
            // Los nombres de los campos, nunca sus valores. Ver el docblock.
            'requested_fields' => $requestedFields,
            'duration_seconds' => round(microtime(true) - $startedAt, 3),
        ]);

        return $profile;
    }
}
