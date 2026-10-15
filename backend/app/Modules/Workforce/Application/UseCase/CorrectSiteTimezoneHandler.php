<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\ResponsibleAccounts;
use App\Modules\Shared\Application\Port\SerializedLedgerWrite;
use App\Modules\Shared\Application\Support\SpanScope;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Workforce\Application\Command\CorrectSiteTimezoneCommand;
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
 * caracteres, una cuenta que responde y, en la consola, confirmacion— y deja
 * asiento `site.updated` con el motivo, el numero de tramos que ya existian y
 * la cuenta.
 *
 * ## Quien puede responder
 *
 * Una cuenta de gestion **activa** con rol {@see self::RESPONSIBLE_ROLES}: los
 * mismos que pueden modificar el centro por la API (`SitePolicy::update`). La
 * consola no puede ser la via por la que alguien hace lo que el panel le niega.
 * Se comprueba en la vista previa —para no pedir confirmacion de algo que se va
 * a negar— y **otra vez dentro de la transaccion, con la cadena de `audit_log`
 * tomada**: la baja de una cuenta toma la cadena antes de tocar su fila
 * (ADR-051 §6), asi que el asiento nunca queda firmado por una cuenta que ya no
 * estaba activa al confirmarse.
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
 * ## Orden, candados y lo que cuesta
 *
 * El motivo se comprueba **antes** de la transaccion: no depende de nada que
 * esta proteja. Dentro: la fila del centro con `FOR UPDATE` y despues la
 * cadena de `audit_log` (ADR-046: filas padre antes que la cadena), con la
 * cuenta, el recuento, el guardado y el asiento.
 *
 * **Mientras el centro esta bloqueado, los fichajes esperan** (leen la zona con
 * `FOR KEY SHARE`). Por eso dentro solo va lo imprescindible para el asiento:
 * el recuento de tramos (`WorkRecordPresence::count()`), que es un barrido del
 * indice de `shift_entries`. La primera y la ultima jornada, que solo sirven a
 * la vista previa, se leen fuera y sin candado. **El coste del recuento se
 * acepta**: es una orden manual y rara —una vez en la vida de una instalacion,
 * si acaso— y la espera de un fichaje es la de una consulta, nunca un rechazo
 * (regla dura 19).
 *
 * **El motivo nunca sale de aqui hacia un log** (regla dura 21): es texto libre
 * y va solo al asiento. El span lleva el resultado y el recuento.
 */
final readonly class CorrectSiteTimezoneHandler
{
    /**
     * Los roles que pueden responder de la correccion: los que pueden
     * modificar el centro por la API. `SitePolicy::update` los lee de aqui, y
     * asi los dos caminos no pueden separarse.
     *
     * @var list<UserRole>
     */
    public const array RESPONSIBLE_ROLES = [UserRole::ADMIN, UserRole::RRHH];

    public function __construct(
        private SiteRepository $sites,
        private WorkRecordPresence $workRecord,
        private ResponsibleAccounts $accounts,
        private WorkforceEventPublisher $events,
        private Clock $clock,
        private ConnectionInterface $connection,
        private SerializedLedgerWrite $ledgerWrite,
    ) {}

    /**
     * Lo que haria la correccion, sin escribir nada ni bloquear nada.
     *
     * @throws UnknownTimezone
     * @throws InvalidSiteTimezoneCorrection si el responsable no puede firmarla
     */
    public function preview(string $timezone, int $responsibleUserId): SiteTimezoneCorrectionReport
    {
        $requested = SiteTimezone::fromString($timezone);
        $this->assertResponsible($responsibleUserId);

        $site = $this->sites->installationSite();
        $extent = $site === null ? null : $this->workRecord->extent();

        return $this->assess($site, $requested, $extent->shiftEntryCount ?? 0, $extent);
    }

    /**
     * @throws UnknownTimezone
     * @throws InvalidSiteTimezoneCorrection el motivo no cumple o el responsable no puede firmarla
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
            'shift_entry_count' => $report->shiftEntryCount,
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

        return $this->connection->transaction(function () use ($command, $requested, $reason): SiteTimezoneCorrectionReport {
            // La fila padre primero (ADR-046)...
            $current = $this->sites->installationSiteForUpdate();

            // ...y la cadena despues: con ella tomada, la cuenta que firma no
            // puede darse de baja hasta que esto confirme.
            return $this->ledgerWrite->withChainLock(function () use ($command, $requested, $reason, $current): SiteTimezoneCorrectionReport {
                $this->assertResponsible($command->responsibleUserId);

                $count = $current === null ? 0 : $this->workRecord->count();
                $report = $this->assess($current, $requested, $count, null);

                if ($current === null || $report->outcome !== SiteTimezoneCorrectionOutcome::Corrected) {
                    return $report;
                }

                $corrected = $current->correctTimezone($requested);
                $this->sites->save($corrected);

                $this->events->publish(SiteConfigured::corrected(
                    siteId: (int) $corrected->id,
                    name: $corrected->name,
                    timezone: $corrected->timezone->identifier,
                    previousTimezone: $current->timezone->identifier,
                    correction: SiteTimezoneCorrection::of($reason, $count, $command->responsibleUserId),
                    occurredAt: $this->clock->now(),
                ));

                return $report;
            });
        });
    }

    /**
     * @throws InvalidSiteTimezoneCorrection
     */
    private function assertResponsible(int $userId): void
    {
        if (! $this->accounts->isActiveManagementAccount($userId, self::RESPONSIBLE_ROLES)) {
            throw InvalidSiteTimezoneCorrection::responsibleNotActive($userId);
        }
    }

    private function assess(?Site $site, SiteTimezone $requested, int $count, ?WorkRecordExtent $extent): SiteTimezoneCorrectionReport
    {
        $outcome = match (true) {
            $site === null => SiteTimezoneCorrectionOutcome::NoSite,
            $site->timezone->equals($requested) => SiteTimezoneCorrectionOutcome::SameTimezone,
            $count === 0 => SiteTimezoneCorrectionOutcome::NoWorkRecord,
            default => SiteTimezoneCorrectionOutcome::Corrected,
        };

        return new SiteTimezoneCorrectionReport($outcome, $site?->timezone->identifier, $requested->identifier, $count, $extent);
    }
}
