<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\ValueObject;

/**
 * Que se hace —o que se haria, en simulacion— con una linea del fichero
 * (**RF-GP-05**).
 *
 * **Un solo enum para los dos modos, y en presente.** Con `create` para la
 * simulacion y `created` para la aplicacion, el panel tendria que pintar dos
 * tablas distintas para el mismo informe y quien lo lee tendria que aprender dos
 * vocabularios. Lo que dice si ocurrio o no es el `mode` del informe.
 *
 * **`unchanged` existe y no es lo mismo que `update`.** Reimportar el mismo
 * fichero es normal —se corrige una linea y se vuelve a subir entero— y la
 * respuesta correcta a las otras treinta y nueve no es «actualizadas», que
 * sugiere un cambio que no hubo, sino «ya estaban asi». Sin este valor, la regla
 * dura 5 seria indistinguible de su incumplimiento a ojos de quien mira el
 * informe.
 */
enum ImportOutcome: string
{
    case CREATE = 'create';
    case UPDATE = 'update';
    case UNCHANGED = 'unchanged';
    case REJECT = 'reject';
}
