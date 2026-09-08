<?php

declare(strict_types=1);

namespace App\Support\Locale;

use App\Http\Middleware\NegotiateLocale;
use App\Support\Health\LicenseStateProbe;

/**
 * Los idiomas con los que el borde HTTP negocia cada respuesta (RF-PD-08,
 * tarea 5.8).
 *
 * ## Por que existe esta interfaz teniendo ya `LocalePolicyProvider`
 *
 * Porque su consumidor es {@see NegotiateLocale}, que esta
 * **fuera de los modulos y no puede depender de ninguno**: Deptrac no deja que
 * `AppFramework` alcance `App\Modules\*`, y esa frontera lleva intacta desde la
 * tarea 0.2. La direccion que si esta admitida es la contraria —un modulo
 * alcanza el armazon—, que es exactamente la que ya usa
 * {@see LicenseStateProbe} para el estado de licencia de
 * `GET /health`.
 *
 * Asi que el contrato vive aqui y lo implementa el adaptador de `Product`, que
 * es quien tiene la tabla. Es la misma solucion, por la misma razon y en el
 * mismo sitio.
 *
 * **Dos escalares y no un objeto de valor** por lo mismo: `LocalePolicy` es de
 * `Shared/Domain` y este lado no puede nombrarlo. El objeto sigue existiendo y
 * es lo que reciben los consumidores que si son de un modulo —el endpoint
 * publico de la marca—; aqui llegan sus dos campos.
 *
 * ## Nunca lanza
 *
 * Quien lo implementa responde siempre, con respaldo de configuracion si la base
 * de datos no esta. Esto se pregunta en **todas** las peticiones, incluida la que
 * devuelve un error: una excepcion aqui seria un 500 en cada endpoint del
 * producto por una fila de configuracion.
 */
interface NegotiableLocales
{
    /** Idioma con el que se responde cuando nadie pide otro que se ofrezca. */
    public function defaultLocale(): string;

    /**
     * Idiomas que la instalacion ofrece. Nunca vacio, y el de arriba esta dentro.
     *
     * @return list<string>
     */
    public function availableLocales(): array;
}
