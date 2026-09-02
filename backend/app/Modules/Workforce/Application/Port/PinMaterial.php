<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Port;

use SensitiveParameter;

/**
 * Un PIN recien generado **y su hash ya calculado** (RF-ID-09).
 *
 * ## Por que existe: bcrypt no cabe dentro de una transaccion larga
 *
 * El hash de un PIN cuesta unos **160 ms** con el coste 12 de produccion
 * (medido en el contenedor de la 5.5). Es deliberado y es correcto: encarece el
 * ataque por fuerza bruta contra `pin_hash`. Pero convierte una importacion de
 * 500 personas en **80 segundos de calculo**, y ese calculo ocurria **dentro**
 * de la transaccion del lote, con dos consecuencias serias:
 *
 * 1. **El candado global de `audit_log`.** El primer asiento del lote toma
 *    `pg_advisory_xact_lock` y no lo suelta hasta el commit. Cada fichaje del
 *    hotel se serializa detras de el (ADR-010): una importacion a media mañana
 *    dejaba la tablet de la entrada esperando un minuto y medio.
 * 2. **`max_execution_time = 60`.** La peticion moria antes de terminar, y lo
 *    hacia **despues** de que quien importa hubiera confirmado.
 *
 * Ninguna de las dos la veia la suite, porque `phpunit.xml` fija
 * `BCRYPT_ROUNDS=4` —0,7 ms por hash— para que las pruebas no tarden horas.
 *
 * ## La solucion: el calculo fuera, la escritura dentro
 *
 * Este objeto es el resultado del calculo. `ApplyEmployeeImport` lo produce para
 * todas las filas **antes** de abrir la transaccion, y dentro solo quedan las
 * inserciones y sus asientos. **El todo-o-nada no cambia**: si una fila falla, el
 * lote entero revierte igual que antes.
 *
 * ## Vive junto al puerto que lo devuelve
 *
 * En `Application\Port\`, como {@see PinStatus} y {@see PinDeliveryRecord}: es el
 * tipo de retorno de {@see PinHasher}, y un puerto no puede depender del resto de
 * `Application` (doc 02 §1.6, verificado por Deptrac).
 *
 * ## El PIN sigue sin almacenarse
 *
 * Viaja aqui para poder devolverlo una vez —es lo que se entrega en mano— y
 * muere con la peticion. Lo que se escribe es {@see self::$hash}. Los dos van
 * marcados como sensibles para que no aparezcan en una traza de PHP.
 */
final readonly class PinMaterial
{
    public function __construct(
        #[SensitiveParameter] public string $pin,
        #[SensitiveParameter] public string $hash,
    ) {}
}
