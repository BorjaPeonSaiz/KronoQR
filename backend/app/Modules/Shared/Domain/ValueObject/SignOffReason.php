<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

use Closure;
use Throwable;

/**
 * El motivo con el que una persona **firma una accion de consola con
 * relevancia legal**: dar por revisada una purga no admisible (ADR-057
 * enmienda del 10-10-2026 §6) o corregir la zona horaria del centro con
 * registro horario (RN-23, ADR-056 §3).
 *
 * La firma no puede servir para tapar nada sin explicarlo: de 10 a 1000
 * caracteres —contados como caracteres, no como bytes— sin los espacios de los
 * extremos. Va al asiento de `audit_log` y **nunca** al log tecnico: es texto
 * libre y podria llevar un nombre (regla dura 21).
 *
 * **Una regla y no dos copias.** Cada accion conserva su excepcion y su texto
 * —la persona lee «el motivo de la revision» o «el motivo de la correccion»—,
 * y por eso el rechazo lo construye quien llama: `$reject` recibe que falla y
 * el limite, y devuelve la excepcion que se lanza.
 */
final readonly class SignOffReason
{
    public const int MIN_LENGTH = 10;

    public const int MAX_LENGTH = 1000;

    private function __construct(public string $text) {}

    /**
     * @param  Closure(SignOffReasonViolation, int): Throwable  $reject  la excepcion de quien firma, dado el fallo y su limite
     *
     * @throws Throwable la que devuelva `$reject`
     */
    public static function fromText(string $text, Closure $reject): self
    {
        $trimmed = trim($text);
        $length = mb_strlen($trimmed);

        if ($length < self::MIN_LENGTH) {
            throw $reject(SignOffReasonViolation::TooShort, self::MIN_LENGTH);
        }

        if ($length > self::MAX_LENGTH) {
            throw $reject(SignOffReasonViolation::TooLong, self::MAX_LENGTH);
        }

        return new self($trimmed);
    }
}
