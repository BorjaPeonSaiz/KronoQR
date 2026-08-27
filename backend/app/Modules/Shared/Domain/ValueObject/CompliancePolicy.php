<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Los umbrales **legales** ya resueltos, tal como los entrega el perfil de
 * cumplimiento del centro (`compliance_profiles`, RF-PD-07).
 *
 * Los fija la jurisdiccion, no el hotel. Por eso son otra cosa que
 * {@see OperationalSettings} y llegan por otro puerto: doc 01 §4, nota sobre
 * RN-08 y RN-16.
 *
 * **Ningun valor por defecto vive aqui** (regla dura 14, ADR-017). Los cuatro
 * son obligatorios y los sirve `CompliancePolicyProvider`; la fila semilla del
 * perfil `ES-hosteleria` —12 h, 9 h, 6 h y 4 anos— la escribe la migracion de
 * la tarea 1.3, que es donde una constante puede cambiarse sin tocar el
 * repositorio.
 *
 * Se expresa en minutos y no en horas porque es la unidad del calculo (RN-06,
 * `duration_minutes`), y en `int` y no en un objeto de valor de Attendance
 * porque Shared no puede depender de un modulo (doc 02 §1.6). Quien evalua la
 * regla lo convierte a `WorkedDuration` al recibirlo.
 */
final readonly class CompliancePolicy
{
    public function __construct(
        /** RN-10: descanso minimo entre el fin de un turno y el inicio del siguiente. */
        public int $minimumRestMinutes,
        /** RN-11: jornada diaria ordinaria por encima de la cual se alerta. */
        public int $maximumDailyMinutes,
        /** RN-12: tramo continuo maximo sin pausa registrada. */
        public int $breakRequiredAfterMinutes,
        /** RL-02: anos que se conserva el registro horario antes de la purga. */
        public int $retentionYears,
    ) {
        $this->positive($minimumRestMinutes, 'el descanso minimo entre jornadas (RN-10)');
        $this->positive($maximumDailyMinutes, 'la jornada diaria ordinaria (RN-11)');
        $this->positive($breakRequiredAfterMinutes, 'el tramo continuo sin pausa (RN-12)');
        $this->positive($retentionYears, 'los anos de retencion (RL-02)');
    }

    private function positive(int $value, string $what): void
    {
        if ($value < 1) {
            throw new InvalidArgumentException('El perfil de cumplimiento no puede fijar '.$what.' en '.$value.'.');
        }
    }
}
