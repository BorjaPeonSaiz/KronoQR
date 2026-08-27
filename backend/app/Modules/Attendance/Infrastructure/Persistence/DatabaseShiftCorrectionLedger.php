<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Infrastructure\Persistence;

use App\Modules\Attendance\Application\Port\ShiftCorrectionLedger;
use App\Modules\Attendance\Domain\Event\ShiftCorrected;
use App\Modules\Attendance\Domain\ValueObject\ShiftTimes;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * El libro de correcciones sobre PostgreSQL: `shift_corrections` (RN-13, RL-04).
 *
 * **Traduce el evento de dominio a una fila y no decide nada.** Que
 * identificador de tramo lleva la fila lo resuelve el propio evento
 * —`correctedShiftEntryUuid()`—, porque es la unica regla que distingue los
 * cuatro casos y tiene que vivir en un solo sitio: en una correccion el bueno es
 * el de la version nueva y en una anulacion el de la vieja.
 *
 * **Escribe dentro de la transaccion del caso de uso.** El caso de uso ya la
 * abrio y esta clase no abre otra: si esta insercion falla, la correccion entera
 * revierte. Una jornada cuyas horas cambiaron sin fila aqui es exactamente lo que
 * RN-13 prohibe.
 *
 * **`before` y `after` no llevan el nombre de nadie** (regla dura 21). Describen
 * horas —las dos marcas y la version a la que pertenecen—, y quien las trabajo
 * esta en el tramo, no en el JSON. Este documento acaba en la exportacion legal
 * y en el paquete de diagnostico.
 */
final readonly class DatabaseShiftCorrectionLedger implements ShiftCorrectionLedger
{
    public function __construct(private ConnectionInterface $connection) {}

    public function record(ShiftCorrected $correction): void
    {
        ShiftCorrection::query()->create([
            'shift_entry_id' => $this->shiftEntryIdOf($correction->correctedShiftEntryUuid()),
            'performed_by_user_id' => $correction->correction->performedByUserId,
            'action' => $correction->action->value,
            // La version a la que pertenece cada juego de marcas. En un alta no
            // hay anterior y en una anulacion no hay posterior: los nulos son
            // significativos y la restriccion del esquema los exige.
            'before' => $this->snapshot($correction->before, $correction->shiftEntryVersion),
            'after' => $this->snapshot(
                $correction->after,
                $correction->replacementVersion ?? $correction->shiftEntryVersion,
            ),
            'reason_code' => $correction->correction->reason->code->value,
            'reason_text' => $correction->correction->reason->text,
            // El momento de la CORRECCION, del puerto `Clock` y no de `NOW()`:
            // es lo que permite fijarlo en una prueba (regla dura 2) y lo que
            // hace que el asiento de auditoria y esta fila digan la misma hora.
            'created_at' => $correction->correction->performedAt->format('Y-m-d H:i:s.uP'),
        ]);
    }

    /**
     * Las marcas de una version, tal y como quedan en JSONB.
     *
     * Formato ISO-8601 con `Z` y microsegundos, el mismo que usa el resto de la
     * API (regla dura 3): un JSON con un desplazamiento explicito obligaria a
     * quien lea el historico dentro de dos años a saber en que zona se escribio.
     *
     * @return array<string, mixed>|null
     */
    private function snapshot(?ShiftTimes $times, int $version): ?array
    {
        if (! $times instanceof ShiftTimes) {
            return null;
        }

        return [
            'version' => $version,
            'clocked_in_at' => $this->iso($times->clockedInAt),
            'clocked_out_at' => $times->clockedOutAt === null ? null : $this->iso($times->clockedOutAt),
            // Los minutos van escritos y no se deducen al leer: el historico
            // tiene que poder responder «cuanto decia este tramo antes» sin
            // recalcular nada, que es lo que se ensena en el detalle de jornada.
            'worked_minutes' => $times->duration()->minutes,
        ];
    }

    private function iso(DateTimeImmutable $instant): string
    {
        return $instant->format('Y-m-d\TH:i:s.u\Z');
    }

    /**
     * `shift_entries.id` a partir del identificador publico.
     *
     * Es la clave interna de una tabla de este mismo modulo, asi que se resuelve
     * aqui sin puerto de por medio. Que no exista es un error de programa —el
     * caso de uso acaba de guardarla en esta misma transaccion— y por eso rompe
     * en voz alta en vez de escribir una fila huerfana.
     */
    private function shiftEntryIdOf(string $shiftEntryUuid): int
    {
        $id = $this->connection->table('shift_entries')->where('uuid', $shiftEntryUuid)->value('id');

        if (! is_numeric($id)) {
            throw new RuntimeException(
                'Cannot record a correction for shift entry '.$shiftEntryUuid.': the row was not persisted.',
            );
        }

        return (int) $id;
    }
}
