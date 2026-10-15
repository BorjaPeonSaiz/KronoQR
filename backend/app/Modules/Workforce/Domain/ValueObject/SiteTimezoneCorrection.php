<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\ValueObject;

use App\Modules\Shared\Domain\ValueObject\SignOffReason;
use App\Modules\Shared\Domain\ValueObject\SignOffReasonViolation;
use App\Modules\Workforce\Domain\Exception\InvalidSiteTimezoneCorrection;
use InvalidArgumentException;

/**
 * Por que y bajo la responsabilidad de quien se corrige la zona horaria de un
 * centro que ya tiene registro horario (RN-23, ADR-056 §3).
 *
 * Es lo que distingue el asiento de una correccion del de un cambio desde el
 * panel: el **motivo**, de 10 a 1000 caracteres sin los espacios de los
 * extremos —la misma forma que la revision de una purga—; el **numero de
 * tramos** que ya existian, que dice cuanto historico pasa a presentarse con la
 * zona nueva; y la **cuenta de gestion** que responde.
 *
 * El motivo va al asiento de `audit_log` y **nunca** al log tecnico: es texto
 * libre y podria llevar un nombre (regla dura 21).
 */
final readonly class SiteTimezoneCorrection
{
    private function __construct(
        public string $reason,
        public int $shiftEntryCount,
        public int $responsibleUserId,
    ) {}

    /**
     * @throws InvalidSiteTimezoneCorrection si el motivo es demasiado corto o demasiado largo
     */
    public static function of(string $reason, int $shiftEntryCount, int $responsibleUserId): self
    {
        if ($shiftEntryCount < 1) {
            // Sin registro horario no hay nada que corregir por consola: la
            // zona se cambia desde el panel. Que llegue aqui es un defecto.
            throw new InvalidArgumentException('A site time zone correction needs an existing work record.');
        }

        if ($responsibleUserId < 1) {
            throw new InvalidArgumentException('A site time zone correction needs a responsible account.');
        }

        return new self(self::reason($reason), $shiftEntryCount, $responsibleUserId);
    }

    /**
     * El motivo recortado, o la excepcion que dice que le falta o le sobra.
     *
     * Publico para que la consola lo compruebe antes de pedir confirmacion: no
     * tiene sentido preguntar «¿seguro?» por algo que despues se va a negar.
     *
     * La regla es la de toda firma de consola ({@see SignOffReason}); aqui solo
     * se pone el rechazo propio de la correccion, con sus textos.
     *
     * @throws InvalidSiteTimezoneCorrection
     */
    public static function reason(string $text): string
    {
        return SignOffReason::fromText(
            $text,
            static fn (SignOffReasonViolation $violation, int $limit): InvalidSiteTimezoneCorrection => match ($violation) {
                SignOffReasonViolation::TooShort => InvalidSiteTimezoneCorrection::reasonTooShort($limit),
                SignOffReasonViolation::TooLong => InvalidSiteTimezoneCorrection::reasonTooLong($limit),
            },
        )->text;
    }
}
