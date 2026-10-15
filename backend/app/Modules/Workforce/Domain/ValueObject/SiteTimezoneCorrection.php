<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\ValueObject;

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
    public const int MIN_REASON_LENGTH = 10;

    public const int MAX_REASON_LENGTH = 1000;

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
     * @throws InvalidSiteTimezoneCorrection
     */
    public static function reason(string $text): string
    {
        $trimmed = trim($text);
        $length = mb_strlen($trimmed);

        if ($length < self::MIN_REASON_LENGTH) {
            throw InvalidSiteTimezoneCorrection::reasonTooShort(self::MIN_REASON_LENGTH);
        }

        if ($length > self::MAX_REASON_LENGTH) {
            throw InvalidSiteTimezoneCorrection::reasonTooLong(self::MAX_REASON_LENGTH);
        }

        return $trimmed;
    }
}
