<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Exception;

use App\Modules\Shared\Domain\Exception\BusinessRejection;

/**
 * Se pide anular una baja programada cuya fecha de cese **ya ha pasado** en la
 * zona del centro (RN-14, ADR-062 §3).
 *
 * Pasado el cese, la baja rige aunque la tarea del sistema no la haya aplicado
 * todavia: desde el dia siguiente nadie pudo anotar ni fichar jornadas de esa
 * persona. Anularla borraria ese hecho. Si la persona sigue trabajando, la via es
 * readmitirla o corregir la fecha cuando la baja sea efectiva (ADR-062 §6 y §7).
 *
 * `getMessage()` es tecnico y en ingles, con fechas y nada que identifique a
 * nadie (regla dura 21); lo que ve una persona sale de
 * {@see self::$translationKey} y {@see self::$parameters}.
 */
final class ScheduledOffboardingDue extends WorkforceDomainException implements BusinessRejection
{
    /**
     * @param  array<string, string>  $parameters  sustituciones del mensaje traducido
     */
    private function __construct(
        public readonly string $translationKey,
        public readonly array $parameters,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function forTermination(string $terminatedOn, string $today): self
    {
        return new self(
            'employees.errors.offboarding_due',
            ['terminated_on' => $terminatedOn, 'today' => $today],
            sprintf('The termination date %s has already passed (today is %s at the site): the offboarding cannot be cancelled (RN-14).', $terminatedOn, $today),
        );
    }

    /**
     * Lo mismo al reprogramar: pasado el cese, la fecha de una baja programada
     * ya no cambia. Con la misma fecha se aplica en el acto.
     */
    public static function forReschedule(string $terminatedOn, string $today): self
    {
        return new self(
            'employees.errors.offboarding_due_reschedule',
            ['terminated_on' => $terminatedOn, 'today' => $today],
            sprintf('The termination date %s has already passed (today is %s at the site): it can no longer be moved (RN-14).', $terminatedOn, $today),
        );
    }
}
