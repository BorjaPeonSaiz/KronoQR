<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Exception;

use RuntimeException;

/**
 * Lo escrito en `--responsible` no identifica una cuenta de gestion: ni es un
 * `uuid` ni es un correo (V21-7-SC-1, 2.3.0 bloque 3).
 *
 * **El caso del numero tiene su propia fabrica** porque es el que va a ver
 * quien tenga un runbook anterior a la 2.3.0 delante: hasta entonces
 * `--responsible` era el id interno de la cuenta, y un numero mal tecleado
 * firmaba con otra persona sin que nadie lo notara. El mensaje explica el
 * cambio en lugar de decir solo «no vale».
 *
 * `getMessage()` es tecnico y en ingles —va al log y no lleva el valor, que
 * puede ser un correo (regla dura 21)—; lo que lee la persona sale de
 * {@see self::$translationKey}.
 */
final class InvalidResponsibleReference extends RuntimeException
{
    private function __construct(public readonly string $translationKey, string $message)
    {
        parent::__construct($message);
    }

    public static function empty(): self
    {
        return new self('responsible.missing', 'The responsible account reference is empty.');
    }

    public static function numericId(): self
    {
        return new self(
            'responsible.numeric_id',
            'The responsible account must be given by its uuid or e-mail since 2.3.0, not by its internal id.',
        );
    }

    public static function malformed(): self
    {
        return new self('responsible.malformed', 'The responsible account reference is not a valid uuid or e-mail address.');
    }
}
