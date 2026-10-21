<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Exception;

use App\Modules\Shared\Domain\Exception\BusinessRejection;

/**
 * La correccion mueve la hora de entrada a otro dia civil, y con ella el tramo a
 * otra jornada (RN-05, ADR-006, regla dura 4).
 *
 * **Que esta pasando.** `work_date` es la fecha civil, en la zona del centro,
 * del `clocked_in_at` que abrio la jornada. Corregir la salida no la toca nunca
 * —un turno 22:00 -> 06:00 sigue siendo del dia 14 aunque la salida se rectifique
 * a las 06:30—, pero corregir la **entrada** cruzando la medianoche local si:
 * esas horas dejarian de pertenecer al dia 14 para pertenecer al 15.
 *
 * **Tambien al anular** (2.3.0, {@see byVoiding()}): anular el tramo que abre la
 * jornada deja abriendola al siguiente, y si ese empieza otro dia civil sus
 * horas serian de otra jornada.
 *
 * **Por que el agregado se niega en vez de moverlo.** El tramo pasaria a otra
 * jornada, es decir, a **otro agregado**, y con el se moverian los minutos entre
 * dos filas de `daily_totals`. `WorkDay` es la frontera transaccional del
 * fichaje (doc 01 §5.2) y no puede proteger las invariantes de una jornada que
 * no ha cargado: no sabe si en el dia 15 hay ya un turno abierto (RN-01) ni si
 * el tramo movido solaparia con lo que alli haya (RN-02).
 *
 * **Que hace quien necesita mover un tramo de dia.** Dos acciones explicitas y
 * auditadas por separado: anular el tramo en la jornada de origen e introducirlo
 * como alta manual en la de destino, cada una con su motivo. El resultado en el
 * registro legal es exactamente el mismo, y ademas se lee: «este tramo no
 * ocurrio el dia 14, ocurrio el 15».
 */
final class CorrectionWouldChangeWorkDate extends AttendanceDomainException implements BusinessRejection
{
    /**
     * @param  bool  $byVoiding  la causa es anular el tramo que abria la jornada, no mover una entrada
     */
    private function __construct(
        string $message,
        public readonly bool $byVoiding = false,
        public readonly string $workDate = '',
    ) {
        parent::__construct($message);
    }

    public static function from(string $shiftEntryUuid, string $currentIsoDate, string $correctedIsoDate): self
    {
        return new self(
            'Correcting the clock-in of shift entry '.$shiftEntryUuid.' would move it from work day '
            .$currentIsoDate.' to '.$correctedIsoDate.' (RN-05). Void it here and create it there instead.',
            false,
            $currentIsoDate,
        );
    }

    public static function byVoiding(string $shiftEntryUuid, string $currentIsoDate, string $resultingIsoDate): self
    {
        return new self(
            'Voiding shift entry '.$shiftEntryUuid.' would leave work day '.$currentIsoDate
            .' opened by a shift entry of '.$resultingIsoDate.' (RN-05). Void the later entries first.',
            true,
            $currentIsoDate,
        );
    }
}
