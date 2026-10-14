<?php

declare(strict_types=1);

namespace Database\Seeders\Support;

use App\Modules\Attendance\Domain\Event\EmployeeClockedIn;
use App\Modules\Attendance\Domain\Event\EmployeeClockedOut;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Attendance\Domain\ValueObject\TimeRange;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Attendance\Domain\ValueObject\WorkedDuration;
use App\Modules\Compliance\Application\Port\AuditLogPartitions;
use App\Modules\Compliance\Infrastructure\Listener\RecordShiftEntryAudit;
use App\Modules\Compliance\Infrastructure\Persistence\AuditLogSchema;
use Database\Seeders\VolumeSeeder;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Los asientos de `audit_log` de los tramos que la semilla escribe como si los
 * hubiera fichado el quiosco (ADR-057 §4, regla dura 6, bloque 11 de la 2.2.1).
 *
 * ## Por que existe
 *
 * Las semillas de volumen y de casos limite escriben `shift_entries` directamente
 * —cien mil fichajes por la API del quiosco tardarian una eternidad y exigirian
 * firmar tarjetas—, y sin sus asientos `compliance:reconcile-work-record` veia
 * cada tramo como un `INSERT` inventado (`entry_without_audit`): la alerta de
 * manipulacion saltaba nada mas sembrar el entorno de desarrollo.
 *
 * ## Por que escribe el listener del producto y no un `INSERT`
 *
 * Los asientos los escribe {@see RecordShiftEntryAudit}, el mismo que reacciona
 * a un fichaje real, con los mismos eventos de dominio que publicaria
 * `RegisterScanHandler`. Asi el `payload` es byte a byte el del producto, pasan
 * por `AuditTrail` —el unico camino de escritura de `audit_log`— y quedan
 * encadenados por hash bajo el candado de la cadena, como cualquier otro. Una
 * semilla que copiara la forma del asiento divergiria en silencio el dia que
 * esa forma cambie; esta sigue al listener.
 *
 * **Solo el asiento.** Los eventos no se publican en el despachador: los demas
 * oyentes del fichaje (proyeccion, incidencias, difusion) no tienen nada que
 * hacer con datos que ya se escribieron proyectados.
 *
 * **El actor es `system`.** `CurrentAuditContext` lo deduce de la peticion
 * autenticada, y en una semilla no hay ninguna: no se finge un dispositivo.
 *
 * **El orden es el del reloj.** Los asientos de un lote se escriben por orden de
 * su `occurred_at` —entradas y salidas intercaladas—, que es como los escribe un
 * quiosco en linea.
 */
final readonly class KioskClockingAudit
{
    public function __construct(
        private RecordShiftEntryAudit $listener,
        private AuditLogPartitions $partitions,
    ) {}

    /**
     * Escribe la entrada de cada tramo y, si esta cerrado, su salida, en una sola
     * transaccion: o quedan todos los asientos del lote, o ninguno.
     *
     * @param  list<KioskClocking>  $clockings
     */
    public function record(array $clockings): void
    {
        if ($clockings === []) {
            return;
        }

        $this->ensurePartitionsFor($clockings);

        $events = [];

        foreach ($clockings as $clocking) {
            $events[] = [$clocking->clockedInAt, 0, $this->clockedIn($clocking)];

            if ($clocking->clockedOutAt instanceof DateTimeImmutable) {
                $events[] = [$clocking->clockedOutAt, 1, $this->clockedOut($clocking, $clocking->clockedOutAt)];
            }
        }

        // Por instante y, en el mismo instante, la salida del tramo anterior
        // antes que la entrada del siguiente: por eso se ordena por el tipo
        // cambiado de signo (salida `-1`, entrada `0`).
        usort($events, static fn (array $a, array $b): int => [$a[0], -$a[1]] <=> [$b[0], -$b[1]]);

        DB::transaction(function () use ($events): void {
            foreach ($events as [, , $event]) {
                if ($event instanceof EmployeeClockedIn) {
                    $this->listener->clockedIn($event);
                } else {
                    $this->listener->clockedOut($event);
                }
            }
        });
    }

    private function clockedIn(KioskClocking $clocking): EmployeeClockedIn
    {
        return new EmployeeClockedIn(
            employeeUuid: $clocking->employeeUuid,
            siteId: $clocking->siteId,
            shiftEntryUuid: $clocking->shiftEntryUuid,
            workDate: WorkDate::fromIsoDate($clocking->workDate, $clocking->timezone),
            clockedInAt: $clocking->clockedInAt,
            origin: ScanOrigin::QR_KIOSK,
        );
    }

    private function clockedOut(KioskClocking $clocking, DateTimeImmutable $clockedOutAt): EmployeeClockedOut
    {
        return new EmployeeClockedOut(
            employeeUuid: $clocking->employeeUuid,
            siteId: $clocking->siteId,
            shiftEntryUuid: $clocking->shiftEntryUuid,
            workDate: WorkDate::fromIsoDate($clocking->workDate, $clocking->timezone),
            clockedInAt: $clocking->clockedInAt,
            clockedOutAt: $clockedOutAt,
            origin: ScanOrigin::QR_KIOSK,
            // La misma aritmetica que el registro (RN-09): la del objeto de
            // valor del dominio, no una copia.
            worked: new TimeRange($clocking->clockedInAt, $clockedOutAt)->duration(),
            dailyTotal: WorkedDuration::ofMinutes($clocking->dailyTotalMinutes),
        );
    }

    /**
     * Un asiento cuyo `occurred_at` no cae en ninguna particion anual hace
     * fallar el `INSERT` (ADR-027). Las migraciones crean el primer año del
     * producto, el en curso y el siguiente; la ventana de noventa dias de
     * {@see VolumeSeeder} puede empezar en el anterior. La
     * funcion de la base solo crea el año en curso o el siguiente, asi que la
     * que falte la crea aqui el rol de migracion, con los mismos permisos
     * solo-append que la migracion (regla dura 6). Es una semilla de desarrollo:
     * en una instalacion no corre (SC7-03).
     *
     * @param  list<KioskClocking>  $clockings
     */
    private function ensurePartitionsFor(array $clockings): void
    {
        $needed = [];

        foreach ($clockings as $clocking) {
            $needed[(int) $clocking->clockedInAt->format('Y')] = true;

            if ($clocking->clockedOutAt instanceof DateTimeImmutable) {
                $needed[(int) $clocking->clockedOutAt->format('Y')] = true;
            }
        }

        $missing = array_diff(array_keys($needed), $this->partitions->years());

        if ($missing === []) {
            return;
        }

        $migrator = DB::connection(config()->string('database.migrations.connection'));

        foreach ($missing as $year) {
            foreach (AuditLogSchema::createPartitionStatements($year) as $statement) {
                $migrator->statement($statement);
            }
        }
    }
}
