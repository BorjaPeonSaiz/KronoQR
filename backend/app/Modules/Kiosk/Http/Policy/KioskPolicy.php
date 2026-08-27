<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Http\Policy;

use Illuminate\Database\Eloquent\Model;

/**
 * Quien puede leer el padron y quien puede latir (regla dura 18, RS-04).
 *
 * **Solo un quiosco activo.** No es una policy de rol: aqui no hay una persona
 * autenticada en un panel, hay una tablet colgada de una pared con un token de
 * dispositivo (RF-ID-04). Deniega a toda sesion de gestion, incluida la del
 * administrador — que si necesita ver el estado de los quioscos tiene el panel de
 * salud de RF-PA-07, no este endpoint.
 *
 * **Las dos comprobaciones, no una** (doc 02 §7.3). El ambito —`roster:read` o
 * `heartbeat:write`— lo verifica el middleware `ability` de Sanctum antes de
 * llegar aqui; esta policy verifica **quien** porta el token. Con las dos, una
 * sesion del panel con ambitos amplios no puede descargarse el padron de un
 * centro, y un token de quiosco al que alguien le añadiera ambitos de gestion
 * seguiria sin alcanzar la plantilla.
 *
 * **El alcance por centro no se comprueba aqui, y no es un olvido**: es que no hay
 * nada que comprobar. Ninguno de los dos endpoints acepta un centro como
 * parametro — sale del propio token (ver {@see KioskDevice}) —, asi que un quiosco
 * no puede ni siquiera formular la peticion «dame el padron del otro hotel». Un
 * alcance que no se puede expresar no se puede saltar; el dia que exista un
 * endpoint de este modulo con `site_id` en la ruta, la comprobacion entra aqui.
 *
 * **Se decide por la tabla y no por la clase del modelo.** `Kiosk` no puede
 * importar el modelo `Device` de `Identity` (doc 02 §1.6), y tampoco le conviene:
 * la clase puede cambiar de nombre y la tabla no, porque `devices` es donde
 * apuntan las claves ajenas. Es el mismo criterio que `Attendance\Http\Policy\
 * ScanPolicy` y que `Compliance`, y por los mismos motivos.
 *
 * **Se invoca por su nombre y no por el `Gate`**, con la misma razon tecnica que
 * documenta `ScanPolicy`: el `tokenable` de un quiosco es una fila de `devices`,
 * que no implementa `Authorizable`, y el `Gate::before` del paquete de permisos
 * reventaria con un `TypeError` antes de llegar a decidir nada.
 */
final class KioskPolicy
{
    public const string DEVICES_TABLE = 'devices';

    /**
     * `GET /api/v1/kiosk/roster`.
     *
     * @param  mixed  $actor  El `tokenable` del token de Sanctum. Se tipa laxo a
     *                        proposito: el guard entrega lo que haya autenticado, y lo
     *                        que este endpoint acepta es una sola cosa.
     */
    public function readRoster(mixed $actor): bool
    {
        return $this->isKiosk($actor);
    }

    /**
     * `POST /api/v1/kiosk/heartbeat`.
     *
     * Metodo propio aunque hoy responda lo mismo que {@see readRoster()}: la regla
     * dura 18 pide una policy por endpoint, y un endpoint que reutiliza el metodo
     * de otro es indistinguible de uno al que se le olvido la suya.
     */
    public function sendHeartbeat(mixed $actor): bool
    {
        return $this->isKiosk($actor);
    }

    private function isKiosk(mixed $actor): bool
    {
        return $actor instanceof Model && $actor->getTable() === self::DEVICES_TABLE;
    }
}
