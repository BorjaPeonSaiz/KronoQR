<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Support\SpanScope;
use App\Modules\Workforce\Application\Command\CorrectSiteTimezoneCommand;
use App\Modules\Workforce\Application\Port\ManagementAccountLookup;
use App\Modules\Workforce\Application\Port\SiteRepository;
use App\Modules\Workforce\Application\Port\WorkforceEventPublisher;
use App\Modules\Workforce\Application\Port\WorkRecordExtent;
use App\Modules\Workforce\Application\Port\WorkRecordPresence;
use App\Modules\Workforce\Domain\Event\SiteConfigured;
use App\Modules\Workforce\Domain\Exception\InvalidSiteTimezoneCorrection;
use App\Modules\Workforce\Domain\Exception\UnknownTimezone;
use App\Modules\Workforce\Domain\Model\Site;
use App\Modules\Workforce\Domain\ValueObject\SiteTimezone;
use App\Modules\Workforce\Domain\ValueObject\SiteTimezoneCorrection;
use Illuminate\Database\ConnectionInterface;
use OpenTelemetry\API\Trace\SpanKind;
use Throwable;

/**
 * **Corrige la zona horaria de un centro que ya tiene registro horario**
 * (RN-23, ADR-056 §3). Lo lanza `workforce:correct-site-timezone`, en el
 * servidor; no hay endpoint.
 *
 * Es la unica salida a {@see Site::relocateTo()} cuando la zona ya esta fija, y
 * existe para un solo caso: la instalacion se puso en marcha con la zona
 * equivocada. Por eso exige lo que el panel no exige —motivo de 10 a 1000
 * caracteres, una cuenta de gestion activa que responde y, en la consola,
 * confirmacion— y deja asiento `site.updated` con el motivo, el numero de
 * tramos que ya existian y la cuenta.
 *
 * ## No recalcula nada
 *
 * Ni `shift_entries` ni `daily_totals` se tocan (RN-13, regla dura 5): cada
 * tramo conserva el `work_date` con que se ficho, y el historico pasa a
 * presentarse con la zona corregida. Un tramo de cerca de medianoche que con la
 * zona correcta habria sido de otra jornada se arregla anulandolo y dandolo de
 * alta en la correcta, cada paso con su motivo: una correccion no cambia de
 * jornada (ADR-035).
 *
 * ## Lo que no hace
 *
 * - **Sin registro horario no corrige**: responde `NoWorkRecord`, y la zona se
 *   cambia desde el panel, que es el camino normal y tambien queda auditado.
 * - **La misma zona no es una correccion**: `SameTimezone`, sin asiento.
 *
 * ## Orden y candados
 *
 * El motivo y el responsable se comprueban **antes** de la transaccion: no
 * dependen de nada que la transaccion proteja. Dentro, como `UpdateSiteHandler`:
 * la fila del centro con `FOR UPDATE`, despues el recuento del registro y, al
 * publicar, la cadena de `audit_log` (ADR-046: filas padre antes que la cadena).
 *
 * **El motivo nunca sale de aqui hacia un log** (regla dura 21): es texto libre
 * y va solo al asiento. El span lleva el resultado y el recuento.
 */
final readonly class CorrectSiteTimezoneHandler
{
    public function __construct(
        private SiteRepository $sites,
        private WorkRecordPresence $workRecord,
        private ManagementAccountLookup $accounts,
        private WorkforceEventPublisher $events,
        private Clock $clock,
        private ConnectionInterface $connection,
    ) {}

    /**
     * Lo que haria la correccion, sin escribir nada.
     *
     * @throws UnknownTimezone
     */
    public function preview(string $timezone): SiteTimezoneCorrectionReport
    {
        $requested = SiteTimezone::fromString($timezone);
        $site = $this->sites->installationSite();

        return $this->assess($site, $requested, $site === null ? null : $this->workRecord->extent());
    }

    /**
     * @throws UnknownTimezone
     * @throws InvalidSiteTimezoneCorrection el motivo no cumple o el responsable no es una cuenta de gestion activa
     */
    public function handle(CorrectSiteTimezoneCommand $command): SiteTimezoneCorrectionReport
    {
        $span = SpanScope::start('kronoqr.workforce', 'workforce.correct_site_timezone', SpanKind::KIND_INTERNAL);

        try {
            $report = $this->correct($command);
        } catch (Throwable $failure) {
            $span->end(['outcome' => $failure instanceof InvalidSiteTimezoneCorrection || $failure instanceof UnknownTimezone
                ? 'rejected'
                : 'error']);

            throw $failure;
        }

        $span->end([
            'outcome' => match ($report->outcome) {
                SiteTimezoneCorrectionOutcome::Corrected => 'corrected',
                SiteTimezoneCorrectionOutcome::NoSite => 'no_site',
                SiteTimezoneCorrectionOutcome::SameTimezone => 'same_timezone',
                SiteTimezoneCorrectionOutcome::NoWorkRecord => 'no_work_record',
            },
            'shift_entry_count' => $report->extent->shiftEntryCount ?? 0,
        ]);

        return $report;
    }

    /**
     * @throws UnknownTimezone
     * @throws InvalidSiteTimezoneCorrection
     */
    private function correct(CorrectSiteTimezoneCommand $command): SiteTimezoneCorrectionReport
    {
        // Fuera de la transaccion: no dependen de nada que esta proteja.
        $requested = SiteTimezone::fromString($command->timezone);
        $reason = SiteTimezoneCorrection::reason($command->reason);

        if (! $this->accounts->isActiveManagementAccount($command->responsibleUserId)) {
            throw InvalidSiteTimezoneCorrection::responsibleNotActive($command->responsibleUserId);
        }

        return $this->connection->transaction(function () use ($command, $requested, $reason): SiteTimezoneCorrectionReport {
            $current = $this->sites->installationSiteForUpdate();
            $extent = $current === null ? null : $this->workRecord->extent();
            $report = $this->assess($current, $requested, $extent);

            if ($current === null || $extent === null || $report->outcome !== SiteTimezoneCorrectionOutcome::Corrected) {
                return $report;
            }

            $corrected = $current->correctTimezone($requested);
            $this->sites->save($corrected);

            $this->events->publish(SiteConfigured::corrected(
                siteId: (int) $corrected->id,
                name: $corrected->name,
                timezone: $corrected->timezone->identifier,
                previousTimezone: $current->timezone->identifier,
                correction: SiteTimezoneCorrection::of($reason, $extent->shiftEntryCount, $command->responsibleUserId),
                occurredAt: $this->clock->now(),
            ));

            return $report;
        });
    }

    private function assess(?Site $site, SiteTimezone $requested, ?WorkRecordExtent $extent): SiteTimezoneCorrectionReport
    {
        $outcome = match (true) {
            $site === null => SiteTimezoneCorrectionOutcome::NoSite,
            $site->timezone->equals($requested) => SiteTimezoneCorrectionOutcome::SameTimezone,
            $extent === null => SiteTimezoneCorrectionOutcome::NoWorkRecord,
            default => SiteTimezoneCorrectionOutcome::Corrected,
        };

        return new SiteTimezoneCorrectionReport($outcome, $site?->timezone->identifier, $requested->identifier, $extent);
    }
}
