<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\Exception;

use DomainException;

/**
 * Los hechos del cuadro de impacto no pueden ser esos (**RF-IN-08**).
 *
 * ## Es un error del programa, no de quien pregunta
 *
 * Nadie puede provocarlo desde una peticion: `from` y `to` los valida el borde y
 * los recuentos los produce una consulta. Si salta, lo que hay mal es la consulta
 * —un `LEFT JOIN` que multiplica filas, un `FILTER` invertido— y por eso no tiene
 * traduccion a un codigo HTTP: sale como `500` y queda en `error_events`, que es
 * lo correcto.
 *
 * ## Por que se comprueba, si «no puede pasar»
 *
 * Porque el fallo silencioso seria peor que el ruidoso. Con `complete` por encima
 * de `total`, el cuadro publicaria «el 104 % de las jornadas quedo registrado
 * completo»: un numero que nadie cree y que desacredita la pantalla entera, o
 * peor, «el 99,2 %» si el error es mas pequeño — creible y falso, en el cuadro con
 * el que se discute una renovacion de licencia.
 */
final class InvalidAdoptionFacts extends DomainException
{
    public static function negative(string $what, int $value): self
    {
        return new self('El cuadro de impacto ha recibido '.$what.' con valor '.$value.': un recuento no puede ser negativo.');
    }

    public static function completeExceedsTotal(int $complete, int $total): self
    {
        return new self(
            'El cuadro de impacto ha recibido '.$complete.' jornadas completas sobre '.$total
            .' con actividad: no puede haber mas jornadas completas que jornadas.',
        );
    }
}
