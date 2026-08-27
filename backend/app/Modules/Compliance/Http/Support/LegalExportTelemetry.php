<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Support;

use App\Modules\Compliance\Application\UseCase\LegalExport;
use App\Modules\Shared\Application\Support\SpanScope;
use OpenTelemetry\API\Trace\SpanKind;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * La traza y el log de una exportacion legal descargada desde el panel (doc 02
 * §8.1, RF-IN-05).
 *
 * Mismo sitio y mismo motivo que `ScanTelemetry` y `CorrectionTelemetry`: en el
 * borde, para que el `trace_id` que llega en `traceparent` sea el padre del span
 * y para que el caso de uso se quede orquestando reglas sin un `try/finally` de
 * medicion alrededor.
 *
 * ## Aqui NO se cuenta
 *
 * `legal_exports_total{scope}` lo incrementa el caso de uso, no este envoltorio,
 * y es deliberado: la misma exportacion se puede pedir desde el panel o desde
 * `php artisan compliance:legal-export`, y las dos tienen que contar. Con el
 * contador en el borde HTTP, un requerimiento atendido por consola —que es
 * justo el que el runbook describe— no aparecería en ninguna metrica.
 *
 * ## Que lleva el log y que no
 *
 * **Ni un nombre** (regla dura 21). El fichero contiene datos personales por su
 * finalidad legal; el log de que se genero, no: periodo, alcance, cifras y, si
 * se acoto a una persona, su `employee_uuid`. Un log tecnico viaja a Loki y al
 * paquete de diagnostico (ADR-020), y ahi una lista nominal seria una fuga.
 *
 * **Duracion incluida**, porque es la unica forma de descubrir que un mes de una
 * plantilla grande empieza a tardar demasiado antes de que lo descubra quien
 * tiene un plazo.
 *
 * ## Medir no puede romper una exportacion
 *
 * Cuando se llega a la parte de medir, la transaccion ya confirmo: el fichero
 * esta escrito y el asiento de auditoria cerrado. Un fallo del exportador de
 * trazas no puede convertir eso en un `500`, porque quien exporto lo repetiria y
 * duplicaria el asiento. Lo garantiza {@see SpanScope}, que es el andamiaje comun
 * de las siete telemetrias; lo propio de esta son sus atributos y su nivel de
 * log.
 */
final readonly class LegalExportTelemetry
{
    public function __construct(private LoggerInterface $logger) {}

    /**
     * @param  callable(): LegalExport  $generate
     */
    public function measure(callable $generate): LegalExport
    {
        $span = SpanScope::start('kronoqr.compliance', 'compliance.generate_legal_export', SpanKind::KIND_SERVER);
        $startedAt = microtime(true);

        try {
            $export = $generate();
        } catch (Throwable $failure) {
            $span->end();

            throw $failure;
        }

        $span->end($this->spanAttributes($export));
        $this->log($export, $span, microtime(true) - $startedAt);

        return $export;
    }

    /**
     * Solo cifras y el alcance en su forma de dos valores: un UUID en un atributo
     * de traza acaba en el mismo sitio que un log (regla dura 21).
     *
     * @return array<string, scalar|null>
     */
    private function spanAttributes(LegalExport $export): array
    {
        return [
            'legal_export.scope' => $export->manifest->scope->metricLabel(),
            'legal_export.shift_entry_rows' => $export->tally->shiftEntries,
            'legal_export.correction_rows' => $export->tally->corrections,
            'legal_export.employees' => $export->tally->employees,
        ];
    }

    private function log(LegalExport $export, SpanScope $span, float $seconds): void
    {
        // `notice` y no `info`: una exportacion legal es un hecho raro —unas
        // pocas al año— y con relevancia legal. Que destaque sobre el ruido de
        // los fichajes es lo que permite ver de un vistazo quien se llevo que.
        $this->logger->notice('compliance.legal_export_generated', [
            'trace_id' => $span->traceId(),
            'period_from' => $export->manifest->period->from,
            'period_to' => $export->manifest->period->to,
            'scope' => $export->manifest->scope->metricLabel(),
            'employee_uuid' => $export->manifest->scope->employeeUuid,
            'shift_entry_rows' => $export->tally->shiftEntries,
            'correction_rows' => $export->tally->corrections,
            'employees_exported' => $export->tally->employees,
            'duration_seconds' => round($seconds, 3),
            'channel' => 'api',
        ]);
    }
}
