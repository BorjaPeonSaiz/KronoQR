<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Support;

use App\Modules\Reporting\Http\Policy\SelfJournalPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * La persona que hay detras de una sesion de portal (RF-ID-07, RL-05).
 *
 * **El empleado no viaja en la URL, y esa ausencia es la autorizacion.** Las dos
 * rutas del portal que leen el registro horario —`/me/workdays` y `/me/export`—
 * no tienen segmento `{uuid}`, y no es una comodidad: con un identificador en la
 * ruta habria que confiar en que una policy lo comparase con el del token en
 * **todas** las peticiones, hoy y dentro de dos años. Sin el, no hay nada que
 * manipular. Es la misma decision, y por el mismo motivo, que toma
 * `Attendance\Http\Support\ScanningDevice` con el quiosco —nombrada en prosa y
 * no con `@see`, porque una referencia resoluble seria la dependencia entre
 * modulos que la frontera del §1.6 prohibe—.
 *
 * **Se identifica por la tabla y no por la clase**, por lo mismo: `Reporting` no
 * puede importar el modelo `Employee` de `Workforce`, y la tabla es lo estable.
 *
 * **Devuelve el UUID y nada mas.** Ni el nombre, ni el codigo, ni la fila: lo
 * unico que estas dos rutas necesitan saber de quien pregunta es de quien es el
 * registro que van a leer, y el UUID publico es el unico identificador de
 * persona admitido en un log tecnico (regla dura 21).
 */
final readonly class PortalEmployee
{
    /**
     * El UUID publico del empleado autenticado en esta peticion.
     *
     * Solo se llama **despues** de la policy, que es quien garantiza que el
     * portador es una sesion de portal. Por eso aqui un actor que no lo sea es
     * un fallo del programa —una ruta sin autorizar— y no una respuesta `403`
     * mas: fallar en voz alta es lo correcto cuando el orden de los controles se
     * ha roto.
     */
    public static function uuidOf(Request $request): string
    {
        $actor = $request->user();

        if (! $actor instanceof Model || $actor->getTable() !== SelfJournalPolicy::EMPLOYEES_TABLE) {
            throw new RuntimeException(
                'PortalEmployee::uuidOf() se ha llamado sin que la policy del portal haya autorizado la peticion.',
            );
        }

        $uuid = $actor->getAttribute('uuid');

        if (! \is_string($uuid) || $uuid === '') {
            throw new RuntimeException('El empleado autenticado no tiene identificador publico.');
        }

        return $uuid;
    }
}
