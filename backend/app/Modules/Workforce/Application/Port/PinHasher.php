<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Port;

use SensitiveParameter;

/**
 * Calcula el hash de un PIN (RF-ID-09).
 *
 * ## Por que es un puerto y no una llamada a `Hash::make()`
 *
 * Porque el calculo tiene que poder ocurrir **donde decide el caso de uso** y no
 * donde ocurre la escritura. El alta individual y el restablecimiento lo hacen
 * **fuera** del candado de la cadena —bcrypt cuesta unos 160 ms por PIN, y
 * pagarlos con el candado global de `audit_log` tomado serializa cada fichaje del
 * hotel detras— y la importacion masiva no calcula ninguno: sus altas nacen con
 * el PIN pendiente y se emite al entregar la tarjeta (RF-GP-05; antes del bloque
 * 12b de la 2.2.0, 500 hashes de un lote monopolizaban ese candado minuto y
 * medio). Con el hash escondido en el repositorio, esa eleccion no existia.
 *
 * Y porque `Application` no usa facades (doc 02 §3.5, verificado por Deptrac).
 *
 * **El coste del algoritmo no se decide aqui**: sale de `config/hashing.php`, que
 * es del despliegue. Este puerto solo dice *cuando* se paga.
 */
interface PinHasher
{
    /**
     * El PIN y su hash, juntos.
     *
     * Devuelve los dos porque quien pide un hash necesita despues el PIN en
     * claro para entregarlo: separarlos obligaria a que el llamante los
     * emparejara a mano, que es como se acaba escribiendo el hash de un PIN y
     * entregando otro.
     */
    public function hash(#[SensitiveParameter] string $pin): PinMaterial;
}
