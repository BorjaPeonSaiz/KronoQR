<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\ValueObject;

/**
 * Que se hace —o que se haria, en simulacion— con una linea del fichero de
 * ausencias (**RF-GP-04**).
 *
 * **Un solo enum para los dos modos y en presente**, igual que
 * {@see ImportOutcome}: con `create` para la simulacion y `created` para la
 * aplicacion, el panel tendria que pintar dos tablas distintas para el mismo
 * informe. Lo que dice si ocurrio o no es el modo del informe.
 *
 * ## No existe `update`, y esa es la diferencia con la plantilla
 *
 * Corregir una ausencia crea una version nueva con motivo (RN-13, regla dura 5),
 * y eso no se hace de pasada en un fichero de cuarenta lineas: se hace en
 * `PATCH /api/v1/absences/{uuid}`, a conciencia y con su asiento propio. Un
 * `update` aqui seria la via por la que una carga silenciosa reescribe el
 * absentismo de un mes.
 *
 * ## `unchanged` no es un adorno
 *
 * Reimportar el mismo cuadrante es normal —se corrige una fila y se vuelve a
 * subir entero— y la respuesta correcta a las otras treinta y nueve no es
 * «solapan», que suena a error, sino «ya estaban asi». Sin este valor, reimportar
 * seria indistinguible de un fichero roto.
 */
enum AbsenceImportOutcome: string
{
    case CREATE = 'create';
    case UNCHANGED = 'unchanged';
    case REJECT = 'reject';
}
