<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain;

use App\Modules\Compliance\Domain\ValueObject\AuditedShiftEntry;
use App\Modules\Compliance\Domain\ValueObject\ExpectedCorrection;
use App\Modules\Compliance\Domain\ValueObject\RecordedShiftEntry;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordDiscrepancy;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordDiscrepancyKind;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordInstant;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordPair;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordPurgeBoundary;
use DateTimeImmutable;

/**
 * La regla de la conciliacion entre el registro horario y su auditoria
 * (ADR-057 §4, RL-04, RS-07, reglas duras 5 y 6).
 *
 * ## Por que hace falta, si la cadena de hash ya se verifica
 *
 * La cadena protege `audit_log`, no `shift_entries`. La aplicacion tiene
 * `INSERT`, `UPDATE` y `DELETE` sobre el registro horario (ADR-057, contexto), y
 * quien ejecute codigo con su credencial puede cambiar la entrada de un tramo,
 * borrarlo o inventarse uno **sin tocar la cadena**, que sigue en verde. La
 * exportacion para la Inspeccion lee `shift_entries` y saldria manipulada. Esta
 * regla compara cada tramo con los asientos que la aplicacion escribio sobre el
 * en la misma transaccion, y un asiento ya escrito no se puede reescribir sin
 * romper la cadena.
 *
 * **Lo que no impide**: con la misma credencial se pueden **añadir** asientos
 * nuevos y bien encadenados —la cadena no lleva secreto y la aplicacion tiene
 * `INSERT` sobre `audit_log`—. Una escritura en el registro acompañada de su
 * asiento falsificado cuadra. Lo recoge el runbook, «Lo que la conciliacion no
 * ve».
 *
 * ## Que NO es una discrepancia, y por que
 *
 * Es la mitad de la regla, y es la que decide si la alerta se cree:
 *
 * - **Un tramo sin asiento de la noche del 31 de diciembre cuyo año de
 *   `audit_log` ya esta purgado** (ADR-027). La particion se suelta entera
 *   cuando todo el año vence, y un tramo que entra el 31 en UTC con `work_date`
 *   del 1 o el 2 de enero sobrevive a su asiento. Solo ese caso: cualquier otro
 *   tramo sin asiento es un `INSERT`.
 * - **Un asiento sin tramo cuya jornada es anterior al corte admitido de su
 *   centro** ({@see WorkRecordPurgeBoundary}): la purga borra los tramos y deja
 *   su asiento con la fecha de corte, y ese asiento se comprueba antes de
 *   creerlo.
 *
 * Y nada mas. No hay tolerancia ni umbral: una sola discrepancia es una
 * escritura en el registro por fuera de la aplicacion.
 *
 * ## Las transiciones legitimas que no escriben asiento
 *
 * No existen sobre `shift_entries`, y eso es lo que permite comparar sin falsos
 * positivos. Las cinco escrituras del producto —fichar entrada, fichar salida,
 * alta manual, correccion y anulacion— pasan por `WorkDay` y publican su evento
 * **dentro** de la transaccion, y `RecordShiftEntryAudit` escribe el asiento en
 * esa misma transaccion. No hay cierre automatico (RN-08): la deteccion de
 * incidencias abre incidencias y no toca el tramo. La proyeccion escribe en
 * `daily_totals`, no aqui. La sustitucion de una version por otra la cuenta el
 * asiento de la correccion por los dos lados (`shift_entry_uuid` y
 * `superseded_shift_entry_uuid`).
 *
 * ## Pura
 *
 * Sin reloj, sin base de datos y sin `Illuminate` (regla dura 1). Recibe el par
 * ya leido y el limite de purgas ya resuelto.
 */
final class WorkRecordReconciliation
{
    private function __construct() {}

    /**
     * Compara un tramo con sus asientos.
     */
    public static function compare(WorkRecordPair $pair, WorkRecordPurgeBoundary $boundary): ?WorkRecordDiscrepancy
    {
        $recorded = $pair->recorded;
        $audited = $pair->audited;

        if ($recorded instanceof RecordedShiftEntry && $audited instanceof AuditedShiftEntry) {
            return self::compareBoth($pair->shiftEntryUuid, $recorded, $audited);
        }

        if ($recorded instanceof RecordedShiftEntry) {
            return self::auditPurgedAlongTheYear($recorded, $boundary->sealedAuditYears)
                ? null
                : new WorkRecordDiscrepancy($pair->shiftEntryUuid, WorkRecordDiscrepancyKind::EntryWithoutAudit);
        }

        if ($audited instanceof AuditedShiftEntry) {
            return self::entryPurged($audited, $boundary)
                ? null
                : new WorkRecordDiscrepancy(
                    $pair->shiftEntryUuid,
                    WorkRecordDiscrepancyKind::AuditWithoutEntry,
                    [],
                    $audited->auditEntryId,
                );
        }

        return null;
    }

    /**
     * Minutos trabajados entre dos marcas, con la misma regla que el registro:
     * segundos enteros de diferencia divididos por sesenta, truncando
     * (`Attendance\Domain\ValueObject\TimeRange::duration()` y
     * `Reporting\Domain\ValueObject\ComplianceShiftSegment::minutes()`).
     *
     * Se repite aqui porque este modulo no puede importar `Attendance` (doc 02
     * §1.6). Que las tres den lo mismo lo comprueba
     * `tests/Unit/Compliance/Domain/WorkRecordReconciliationTest.php` contra las
     * otras dos: si una cambia, la prueba rompe antes que la alerta.
     */
    public static function workedMinutes(DateTimeImmutable $in, DateTimeImmutable $out): int
    {
        return intdiv($out->getTimestamp() - $in->getTimestamp(), 60);
    }

    private static function compareBoth(string $uuid, RecordedShiftEntry $recorded, AuditedShiftEntry $audited): ?WorkRecordDiscrepancy
    {
        $fields = self::differingFields($recorded, $audited);
        $correctionMissing = self::correctionMissing($recorded, $audited);
        $auditRetiresIt = $audited->retires();

        // Sacar un tramo del conjunto vigente sin la correccion que lo justifica
        // hace desaparecer horas de la exportacion: es un borrado con otro
        // nombre, y tiene tipo propio para que el runbook lo trate como tal.
        if (($recorded->isRetired() && ! $auditRetiresIt) || ($auditRetiresIt && $correctionMissing)) {
            return new WorkRecordDiscrepancy(
                $uuid,
                WorkRecordDiscrepancyKind::RetiredWithoutCorrection,
                $correctionMissing ? [...$fields, 'shift_corrections'] : $fields,
                $audited->auditEntryId,
            );
        }

        if ($correctionMissing) {
            $fields[] = 'shift_corrections';
        }

        return $fields === []
            ? null
            : new WorkRecordDiscrepancy($uuid, WorkRecordDiscrepancyKind::EntryDiffersFromAudit, $fields, $audited->auditEntryId);
    }

    /**
     * Los campos de la fila que no dicen lo que dicen los asientos.
     *
     * @return list<string>
     */
    private static function differingFields(RecordedShiftEntry $recorded, AuditedShiftEntry $audited): array
    {
        $checks = [
            ...self::identityChecks($recorded, $audited),
            ...self::markChecks($recorded, $audited),
            ...self::sourceChecks($recorded, $audited),
            ...self::stateChecks($recorded, $audited),
        ];

        return array_keys(array_filter($checks, static fn (bool $matches): bool => ! $matches));
    }

    /**
     * De quien es el tramo, de que centro y de que jornada. Lo que el asiento no
     * dice, cuadra.
     *
     * @return array<string, bool>
     */
    private static function identityChecks(RecordedShiftEntry $recorded, AuditedShiftEntry $audited): array
    {
        return [
            'employee_uuid' => $audited->employeeUuid === null || self::sameUuid($audited->employeeUuid, $recorded->employeeUuid),
            'site_id' => $audited->siteId === null || $audited->siteId === $recorded->siteId,
            'work_date' => $audited->workDate === null || $audited->workDate === $recorded->workDate,
        ];
    }

    /**
     * Las marcas y los minutos que salen de ellas.
     *
     * **Los minutos solo se comparan en una version vigente.** Un tramo anulado
     * o sustituido no suma en el total del dia ni en la exportacion (ADR-026),
     * y su asiento de retirada recoge sus marcas tal y como estaban, no sus
     * minutos. Exigirlos impediria lo que el runbook manda hacer con una hora
     * manipulada —corregirla desde el panel, que retira la version mala con
     * sus marcas malas como «antes»—: la version retirada conservaria los
     * minutos de antes de la manipulacion y la alerta no se apagaria nunca.
     *
     * @return array<string, bool>
     */
    private static function markChecks(RecordedShiftEntry $recorded, AuditedShiftEntry $audited): array
    {
        $knowsOut = $audited->knowsClockOut;

        return [
            'clocked_in_at' => $audited->clockedInAt === null || WorkRecordInstant::same($audited->clockedInAt, $recorded->clockedInAt),
            'clocked_out_at' => ! $knowsOut || WorkRecordInstant::same($audited->clockedOutAt, $recorded->clockedOutAt),
            'duration_minutes' => ! $knowsOut || $audited->retires() || self::durationMatches($audited, $recorded),
        ];
    }

    /**
     * De donde vino cada marca —quiosco con tarjeta, con PIN, o una persona
     * desde el panel—, que la exportacion legal enseña al lado de la hora.
     *
     * @return array<string, bool>
     */
    private static function sourceChecks(RecordedShiftEntry $recorded, AuditedShiftEntry $audited): array
    {
        return [
            'clock_in_source' => $audited->clockInSource === null || $audited->clockInSource === $recorded->clockInSource,
            'clock_out_source' => ! $audited->knowsClockOutSource || $audited->clockOutSource === $recorded->clockOutSource,
        ];
    }

    /**
     * El estado, la version y la version que la sustituye.
     *
     * @return array<string, bool>
     */
    private static function stateChecks(RecordedShiftEntry $recorded, AuditedShiftEntry $audited): array
    {
        return [
            'status' => $audited->expectedStatuses === [] || \in_array($recorded->status, $audited->expectedStatuses, true),
            'version' => $audited->version === null || $audited->version === $recorded->version,
            'superseded_by' => self::sameUuid($audited->supersededByUuid, $recorded->supersededByUuid),
        ];
    }

    /**
     * Si alguno de los asientos es de una correccion y no esta su fila de
     * `shift_corrections` con la misma accion, el mismo motivo y el mismo
     * autor (RN-13).
     */
    private static function correctionMissing(RecordedShiftEntry $recorded, AuditedShiftEntry $audited): bool
    {
        return array_any(
            $audited->requiredCorrections,
            static fn (ExpectedCorrection $expected): bool => ! $expected->isMetBy(
                $expected->onReplacement ? $recorded->replacementCorrections : $recorded->corrections,
            ),
        );
    }

    /**
     * La duracion escrita frente a la que sale de las marcas del asiento.
     *
     * Se calcula desde el asiento y no desde la propia fila: si alguien cambia
     * a la vez la salida y los minutos, la fila es coherente consigo misma y
     * sigue estando mal.
     */
    private static function durationMatches(AuditedShiftEntry $audited, RecordedShiftEntry $recorded): bool
    {
        if ($audited->clockedOutAt === null) {
            return $recorded->durationMinutes === null;
        }

        $in = WorkRecordInstant::parse($audited->clockedInAt);
        $out = WorkRecordInstant::parse($audited->clockedOutAt);

        // Sin marcas legibles en el asiento no hay referencia: lo que no se
        // sabe no se compara (ya habra salido la marca ilegible).
        if (! $in instanceof DateTimeImmutable || ! $out instanceof DateTimeImmutable) {
            return true;
        }

        return $recorded->durationMinutes === self::workedMinutes($in, $out);
    }

    /**
     * La unica ausencia de asiento que tiene explicacion (ADR-027): un tramo que
     * entra el 31 de diciembre de un año ya sellado, en UTC, y cuya jornada es
     * el 1 o el 2 de enero siguiente, con un dia como mucho entre las dos fechas.
     *
     * @param  list<int>  $sealedAuditYears
     */
    private static function auditPurgedAlongTheYear(RecordedShiftEntry $recorded, array $sealedAuditYears): bool
    {
        $in = WorkRecordInstant::parse($recorded->clockedInAt);

        if (! $in instanceof DateTimeImmutable || preg_match('/\A(\d{4})-01-0([12])\z/', $recorded->workDate, $parts) !== 1) {
            return false;
        }

        $sealedYear = (int) $in->format('Y');
        $daysApart = (int) $in->setTime(0, 0)->diff(WorkRecordInstant::parse($recorded->workDate.'T00:00:00Z') ?? $in)->format('%r%a');

        return \in_array($sealedYear, $sealedAuditYears, true)
            && (int) $parts[1] === $sealedYear + 1
            && $daysApart >= 0
            && $daysApart <= 1;
    }

    private static function entryPurged(AuditedShiftEntry $audited, WorkRecordPurgeBoundary $boundary): bool
    {
        $cutoff = $boundary->cutoffFor($audited->siteId);

        // `YYYY-MM-DD` se ordena igual como texto que como fecha. La purga borra
        // `work_date < corte` (DatabaseWorkRecordArchive::purge), y es la misma
        // desigualdad. El corte ya esta validado; la jornada del asiento se
        // exige con la misma forma para que un texto raro no pase por debajo.
        return $cutoff !== null
            && $audited->workDate !== null
            && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $audited->workDate) === 1
            && $audited->workDate < $cutoff;
    }

    private static function sameUuid(?string $expected, ?string $actual): bool
    {
        if ($expected === null || $actual === null) {
            return $expected === $actual;
        }

        return strtolower($expected) === strtolower($actual);
    }
}
