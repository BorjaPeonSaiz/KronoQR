<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Port;

use App\Modules\Product\Domain\Model\SupportGrant;

/**
 * Acuña y retira el token de una concesion de soporte (**RF-PD-11**, ADR-020).
 *
 * ## Por que el puerto esta en `Product` y su adaptador tambien
 *
 * Porque el `tokenable` del token es **una fila de `support_grants`**, que es
 * una tabla de este modulo. Un adaptador en `Identity` —que es donde vive el
 * resto de la maquinaria de sesion— tendria que tocar el modelo Eloquent de otro
 * modulo, y eso el doc 02 §1.6 lo prohibe sin matices: *«nunca por acceso
 * directo a los modelos Eloquent de otro modulo»*. Asi que la inversion de
 * ADR-025 no aplica aqui: no hay dos modulos, hay uno que necesita el token y
 * que ademas tiene la tabla de la que cuelga.
 *
 * El puerto existe igualmente, y por el motivo de siempre: el caso de uso no
 * puede conocer Sanctum (doc 02 §3.5, verificado por Deptrac), y una prueba
 * unitaria de la caducidad no puede necesitar una base de datos.
 *
 * ## El token en claro se devuelve una vez y no se guarda
 *
 * Sale de {@see self::issueFor()}, viaja en el `201` o en la salida del comando
 * y se olvida. Lo que queda en la fila es su `token_hash` (SHA-256 del secreto),
 * igual que en `devices`. Si se pierde, se revoca la concesion y se crea otra:
 * no hay forma de volver a pedirlo, y es deliberado.
 */
interface SupportTokenIssuer
{
    /**
     * Emite el token de esta concesion.
     *
     * **Su caducidad es la de la concesion**, no una vida fija: es la mitad de
     * la «caducidad efectiva» de RF-PD-11 que no depende de que nadie ejecute
     * nada. La otra mitad la comprueba `Identity` en cada peticion.
     *
     * Sus ambitos salen de `SupportScope::abilities()` y de ningun otro sitio,
     * por lo mismo que los del quiosco salen de `TokenAbility::kioskAbilities()`:
     * si la lista se repitiera en el emisor, bastaria con que una copia ganara un
     * ambito de mas para que la promesa del §7.3 dejara de ser cierta sin que
     * nada fallara.
     *
     * @return string El token en claro. **Es la unica vez que existe.**
     */
    public function issueFor(SupportGrant $grant): string;

    /**
     * Retira todos los tokens de esta concesion.
     *
     * **Borra**, no marca, y aqui no choca con la regla dura 5: lo que se borra
     * es la llave, no el registro. La concesion se conserva entera con su
     * `revoked_at`, que es lo que tiene valor probatorio.
     *
     * Es lo que hace que revocar surta efecto **en el acto** y no cuando caduque
     * el token.
     */
    public function revokeAllFor(SupportGrant $grant): void;
}
