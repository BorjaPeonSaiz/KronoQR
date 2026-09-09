<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Domain\ValueObject;

/**
 * El veredicto de un quiosco —y el del conjunto— en `php artisan kiosk:health`
 * (**RF-PA-07**, doc 02 Anexo C).
 *
 * ## Cuatro casos y no tres
 *
 * `Revoked` existe porque un quiosco desvinculado **no tiene salud**: no late
 * porque no debe latir, y contarlo como fallo llenaria de rojo la consola de
 * cualquier hotel que haya sustituido una tablet alguna vez (runbook
 * `alta-nuevo-quiosco.md` §5). Aparece en la tabla —quien la lee necesita ver
 * que el quiosco viejo sigue ahi revocado— y no cuenta para el codigo de salida.
 *
 * ## La severidad es el codigo de salida
 *
 * `0`, `1` y `2` son los mismos numeros y con el mismo significado que
 * `product:doctor` (RF-PD-13): todo bien, algo que mirar, algo que corregir.
 * Dos comandos de consola del mismo producto que usaran escalas distintas
 * obligarian a quien escribe un script a recordar cual es cual.
 */
enum KioskHealthVerdict: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Failure = 'failure';
    case Revoked = 'revoked';

    /**
     * Lo que este veredicto aporta al codigo de salida del conjunto.
     *
     * `Revoked` aporta `0` a proposito: ver el docblock de la clase.
     */
    public function severity(): int
    {
        return match ($this) {
            self::Failure => 2,
            self::Warning => 1,
            self::Ok, self::Revoked => 0,
        };
    }
}
