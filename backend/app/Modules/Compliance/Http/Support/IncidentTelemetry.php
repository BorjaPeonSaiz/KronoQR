<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Support;

use App\Modules\Compliance\Application\Port\IncidentBoardRow;
use App\Modules\Compliance\Application\UseCase\IncidentBoardView;
use App\Modules\Shared\Application\Support\SpanScope;
use OpenTelemetry\API\Trace\SpanKind;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * La traza y el log de la bandeja de incidencias y de su resolucion (doc 02
 * §8.1, RF-PA-05).
 *
 * Mismo sitio y mismo motivo que `LegalExportTelemetry` y `CorrectionTelemetry`:
 * en el borde, para que el `trace_id` que llega en `traceparent` sea el padre del
 * span y para que el caso de uso se quede orquestando reglas sin un
 * `try/finally` de medicion alrededor.
 *
 * ## Aqui NO se cuenta
 *
 * `incident_resolution_seconds{type}` lo observa el caso de uso, no este
 * envoltorio, y es deliberado: si alguna vez se resuelve una incidencia por otra
 * via —una consola, una reconciliacion— tiene que contar igual. Con el
 * histograma en el borde HTTP, esa resolucion no aparecería en ninguna metrica.
 *
 * ## Que lleva el log y que no
 *
 * **Ni un nombre** (regla dura 21). La respuesta de la bandeja lleva nombres
 * porque va a la pantalla de quien esta autorizado; el log viaja a Loki y al
 * paquete de diagnostico (ADR-020), asi que aqui van el `employee_uuid`, el tipo,
 * la severidad y las cifras. **Tampoco la nota**: es texto libre escrito por una
 * persona sobre otra, su sitio es `audit_log` —donde hay control de acceso y
 * retencion— y no un log tecnico que sale de la instalacion.
 *
 * ## Medir no puede romper una resolucion
 *
 * Cuando se llega a la parte de medir, la transaccion ya confirmo. Lo garantiza
 * {@see SpanScope}, que es el andamiaje comun de las telemetrias del producto; lo
 * propio de esta son sus atributos y su nivel de log.
 */
final readonly class IncidentTelemetry
{
    public function __construct(private LoggerInterface $logger) {}

    /**
     * @param  callable(): IncidentBoardView  $read
     */
    public function measureBoard(callable $read): IncidentBoardView
    {
        $span = SpanScope::start('kronoqr.compliance', 'compliance.read_incident_board', SpanKind::KIND_SERVER);
        $startedAt = microtime(true);

        try {
            $view = $read();
        } catch (Throwable $failure) {
            $span->end();

            throw $failure;
        }

        $span->end([
            'incident_board.rows' => \count($view->page->rows),
            'incident_board.total' => $view->page->total,
            'incident_board.page' => $view->page->page,
        ]);

        // `info` y no `notice`: leer la bandeja es lo que hace un responsable
        // varias veces al dia. Lo que destaca sobre el ruido es la resolucion,
        // que es la que cambia algo.
        $this->logger->info('compliance.incident_board_read', [
            'trace_id' => $span->traceId(),
            'rows' => \count($view->page->rows),
            'total' => $view->page->total,
            'page' => $view->page->page,
            'duration_seconds' => round(microtime(true) - $startedAt, 3),
        ]);

        return $view;
    }

    /**
     * @param  callable(): IncidentBoardRow  $resolve
     */
    public function measureResolution(callable $resolve): IncidentBoardRow
    {
        $span = SpanScope::start('kronoqr.compliance', 'compliance.resolve_incident', SpanKind::KIND_SERVER);
        $startedAt = microtime(true);

        try {
            $row = $resolve();
        } catch (Throwable $failure) {
            $span->end();

            throw $failure;
        }

        $span->end([
            'incident.id' => $row->id,
            'incident.type' => $row->incident->type->value,
            'incident.severity' => $row->incident->severity->value,
            'incident.outcome' => $row->incident->status->value,
        ]);

        // `notice`: una resolucion es un acto de una persona con relevancia
        // legal —cierra una revision sobre las horas de alguien— y tiene que
        // destacar sobre el ruido de las lecturas.
        $this->logger->notice('compliance.incident_resolved', [
            'trace_id' => $span->traceId(),
            'incident_id' => $row->id,
            // El identificador publico de la persona afectada, nunca su nombre
            // (regla dura 21).
            'employee_uuid' => $row->subject->employeeUuid,
            'type' => $row->incident->type->value,
            'severity' => $row->incident->severity->value,
            'outcome' => $row->incident->status->value,
            'resolution_seconds' => $row->incident->resolutionSeconds(),
            'duration_seconds' => round(microtime(true) - $startedAt, 3),
        ]);

        return $row;
    }
}
