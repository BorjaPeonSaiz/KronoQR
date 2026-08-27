<?php

declare(strict_types=1);

namespace App\Http\RateLimiting;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Los limites de la capa de **Aplicacion** para el camino del quiosco (doc 02
 * §7.1, RS-02).
 *
 * ## Por dispositivo, y no solo por IP
 *
 * Es la mitad que Nginx no puede dar. El borde limita por origen, y en un hotel
 * **todos los quioscos salen por la misma IP**: con un limite unico por IP, una
 * tablet con un bucle defectuoso consumiria la cuota de todas las demas y el
 * sintoma seria «el quiosco de recepcion no ficha», con la causa a tres metros de
 * distancia. RS-02 lo dice literalmente: se limita «por dispositivo, por
 * credencial y por IP».
 *
 * La clave por dispositivo sale del **token autenticado**, nunca de un campo de
 * la peticion: si viajara en el cuerpo, quien quisiera saltarse su cuota solo
 * tendria que cambiar el numero.
 *
 * ## Y por IP tambien, porque RS-02 enumera las tres
 *
 * El limite por IP **no se elimina para la red interna: se eleva** (§7.1). Se
 * fija al mismo valor que la zona interna de Nginx para que el techo efectivo lo
 * ponga el borde, que es donde cuesta menos —una peticion frenada por Nginx no
 * llega a arrancar PHP—. Si este numero fuera mas bajo, el limite del borde no
 * mediria nada y la verificacion de las dos zonas del §7.1 daria un falso verde.
 *
 * ## Se aplica DESPUES de autenticar, y es deliberado
 *
 * El middleware va detras de `auth:sanctum` en la ruta, asi que cuando este
 * codigo corre ya se sabe que dispositivo pregunta. Ponerlo delante habria hecho
 * imposible la clave por dispositivo —no hay token resuelto todavia— y habria
 * dejado el limite reducido al de IP, que es justo el que no distingue quioscos.
 *
 * El trafico **sin autenticar** lo para Nginx (30 r/m desde fuera de
 * `KIOSK_VLAN_CIDR`), que es la capa que corresponde: un limitador de aplicacion
 * que quiera frenar peticiones anonimas ya ha pagado el arranque del framework.
 *
 * ## El 429 sale en `problem+json`
 *
 * No hace falta hacer nada aqui: `bootstrap/app.php` traduce
 * `ThrottleRequestsException` a `ProblemDetails::tooManyRequests()`, con su
 * `Retry-After`. Se anota porque es lo que hace que el contrato sea cierto
 * tambien en el camino de error.
 */
final class KioskRateLimit
{
    /**
     * Los dos limites de una ruta del quiosco: el suyo por dispositivo y el
     * general por origen.
     *
     * @param  string  $zone  Nombre de la zona, para que el contador de `/scan` no se
     *                        mezcle con el de `/scan/batch`: son limites distintos sobre
     *                        el mismo dispositivo.
     * @return list<Limit>
     */
    public static function of(Request $request, string $zone, int $perDevice, int $perIp): array
    {
        return [
            Limit::perMinute(max(1, $perDevice))->by('kiosk:'.$zone.':device:'.self::actorKeyOf($request)),
            Limit::perMinute(max(1, $perIp))->by('kiosk:ip:'.((string) $request->ip())),
        ];
    }

    /**
     * Identificador del portador del token.
     *
     * Un dispositivo se identifica por su clave interna, que es estable y no
     * viaja por la red. Si no hubiera portador —no deberia, porque este limitador
     * corre despues de autenticar— se cae a la IP en lugar de a una clave comun:
     * una clave compartida convertiria el limite por dispositivo en un limite
     * global, y bastaria con una peticion rara para dejar sin cuota a todo el
     * hotel.
     */
    private static function actorKeyOf(Request $request): string
    {
        $actor = $request->user();

        if ($actor instanceof Model) {
            $key = $actor->getKey();

            if (is_numeric($key) || is_string($key)) {
                return $actor->getTable().':'.$key;
            }
        }

        return 'anonymous:'.((string) $request->ip());
    }
}
