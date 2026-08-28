<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Audit;

use Illuminate\Support\Facades\DB;

/**
 * Del UUID publico de una cuenta de gestion a su `users.id`, que es lo que
 * `audit_log.actor_id` guarda.
 *
 * ## Por que hace falta justo aqui
 *
 * {@see CurrentAuditContext} resuelve el actor de la peticion en curso, y en el
 * unico momento en que eso no sirve es **el propio acceso**: mientras se
 * comprueba una contrasena todavia no hay sesion, asi que `Auth::user()` es nulo
 * y el actor saldria `system`. Un asiento que dice que el sistema inicio sesion
 * es una entrada que miente en la tabla que se enseña en una inspeccion.
 *
 * El caso de uso que autentica solo tiene el UUID —`AuthenticatedUser` no lleva
 * la clave interna a proposito, para que la capa de aplicacion no pueda acabar
 * con un modelo Eloquent a mano—, asi que la traduccion ocurre aqui, en
 * infraestructura, y cuesta una consulta por indice unico en un endpoint que se
 * usa unas cuantas veces al dia.
 *
 * ## Por que `Compliance` puede leer `users`
 *
 * Por lo mismo que {@see CurrentAuditContext} clasifica al actor por el nombre
 * de la tabla del `tokenable`: `audit_log.actor_id` **es** una clave foranea
 * logica hacia `users`, este modulo es el dueno de esa columna y no puede
 * importar el modelo de `Identity` (doc 02 §1.6, verificado por Deptrac). La
 * dependencia es sobre el nombre de la tabla y sobre dos columnas, que es
 * exactamente lo que `audit_log` ya promete.
 *
 * **Si la cuenta no aparece, se devuelve `null` y el asiento se escribe sin
 * actor identificado.** Nunca se inventa un identificador ni se deja de escribir:
 * perder el asiento seria peor que tenerlo incompleto.
 */
final readonly class ManagementUserDirectory
{
    public function idOf(?string $uuid): ?int
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }

        $id = DB::table('users')->where('uuid', $uuid)->value('id');

        return is_numeric($id) ? (int) $id : null;
    }
}
