<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Exception;

/**
 * Dos escrituras simultaneas de la plantilla se han cruzado y PostgreSQL ha
 * deshecho esta para romper el ciclo (`40P01`), tambien despues de reintentarla
 * una vez (ADR-046 §1.3, caso conocido).
 *
 * Ocurre cuando un alta y una modificacion o importacion escriben **a la vez el
 * mismo correo o el mismo documento**: el alta inserta antes de la cadena de
 * `audit_log` y la espera; la otra ya tiene la cadena y espera a que el alta
 * confirme para comprobar el indice unico. Lo normal es que el reintento acabe
 * en el `409` de dato duplicado; este es el `409` de reserva para que nunca
 * salga un `500`. Nada se ha escrito: basta con repetir la peticion.
 */
final class ConcurrentEmployeeWrite extends WorkforceConflict
{
    public static function make(): self
    {
        return new self('Otra escritura simultanea de la plantilla ha chocado con esta. No se ha guardado nada: repitela.');
    }
}
