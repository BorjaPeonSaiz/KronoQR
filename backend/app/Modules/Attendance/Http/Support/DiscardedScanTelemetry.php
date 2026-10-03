<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Support;

use App\Modules\Attendance\Application\Command\ReportDiscardedScansCommand;
use App\Modules\Shared\Application\Support\SpanScope;
use Illuminate\Support\Facades\Context;
use OpenTelemetry\API\Trace\SpanKind;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * La traza y el log de un envio de avisos de fichajes descartados (RN-22,
 * ADR-047, doc 02 §8.1).
 *
 * Un span por peticion que cuelga del `traceparent` del quiosco, y un apunte de
 * log con el dispositivo, cuantos avisos traia y sus `scan_id` —que genera la
 * tablet y no identifican a nadie—. **Nunca el `qr_payload` ni el codigo de
 * empleado** (regla dura 21), y tampoco si se atribuyo cada aviso: eso se queda
 * en `discarded_scan_reports` y en `kiosk_discarded_scans_total`, que no dicen a
 * quien.
 *
 * Como {@see ScanBatchTelemetry}: medir no puede romper un aviso que ya esta
 * guardado. Todo va envuelto por {@see SpanScope}.
 */
final readonly class DiscardedScanTelemetry
{
    private const string SPAN_NAME = 'attendance.report_discarded_scans';

    public function __construct(private LoggerInterface $logger) {}

    /**
     * @param  callable(): list<string>  $process
     * @return list<string>
     */
    public function measure(ReportDiscardedScansCommand $command, callable $process): array
    {
        $span = SpanScope::start('kronoqr.attendance', self::SPAN_NAME, SpanKind::KIND_SERVER, [
            'discarded_scans.size' => \count($command->reports),
            'device.id' => $command->deviceUuid,
        ]);

        try {
            Context::add('device_id', $command->deviceUuid);
        } catch (Throwable) {
            // Correlacionar no puede impedir el aviso.
        }

        $startedAt = microtime(true);

        try {
            $acknowledged = $process();
        } catch (Throwable $failure) {
            $span->end(['discarded_scans.acknowledged' => 0]);

            throw $failure;
        }

        $span->end(['discarded_scans.acknowledged' => \count($acknowledged)]);

        $this->logger->warning('attendance.discarded_scans_reported', [
            'trace_id' => $span->traceId(),
            'device_id' => $command->deviceUuid,
            'reports' => \count($command->reports),
            'scan_ids' => $acknowledged,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return $acknowledged;
    }
}
