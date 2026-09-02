<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Health\LicenseStateProbe;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v1/health` — sonda de vida (doc 01 Anexo B, doc 02 §10.5).
 *
 * Dice tres cosas y ninguna mas: que el proceso responde, **con que version
 * esta desplegado** y **en que estado esta su licencia**. La version es lo que
 * permite correlacionar una incidencia con una version concreta cuando el
 * cliente llama por telefono; el estado de licencia es lo que permite a
 * `doctor` y al paquete de diagnostico informarlo (RF-PD-09, tarea 5.9).
 *
 * ## No toca ninguna dependencia, y esa es toda la decision
 *
 * Ni base de datos, ni Redis, ni disco. Una sonda de vida que tocara
 * dependencias haria que Docker reiniciara el contenedor de PHP cuando lo que
 * esta caido es PostgreSQL: se perderian las conexiones sanas, se reiniciaria en
 * bucle mientras dura la incidencia y el diagnostico apuntaria al sitio
 * equivocado. Lo que comprueba las dependencias es `GET /api/v1/ready`.
 *
 * La version sale de `config('app.version')`, resuelta al cargar la
 * configuracion (ver `App\Support\Version\DeployedVersion`), no en esta
 * peticion.
 *
 * **Y el estado de licencia tampoco toca nada.** Sale de la copia que el
 * `FeatureGate` deja en cache cada vez que resuelve el estado de verdad; si esa
 * copia no esta —Redis caido, o nadie ha entrado al panel desde el arranque— la
 * respuesta es `unknown`, que es la verdad. El dato autoritativo, que si
 * consulta y verifica, esta en `GET /api/v1/license` y en `php artisan
 * license:show`. Ver `CachedLicenseStateProbe`.
 *
 * ## Que estado se puede publicar sin autenticacion, y que no
 *
 * **Una palabra**: `valid`, `expiring_soon`, `expired`, `absent`,
 * `not_yet_valid`, `unverifiable` o `unknown`. Ni el nombre del cliente, ni el
 * plan, ni los limites, ni las fechas: eso es informacion comercial del cliente
 * y su sitio es el endpoint autenticado (ADR-020, regla dura 21). El estado a
 * secas es lo que necesitan el orquestador, la comprobacion posterior a una
 * actualizacion (RF-PD-10) y quien diagnostica desde el propio servidor, y no
 * dice de quien es la instalacion.
 *
 * **Y no cambia el codigo de respuesta.** Una licencia caducada devuelve `200`
 * igual que una vigente: esta sonda dice si el proceso vive, y la licencia no
 * tiene nada que ver con eso (regla dura 15, ADR-019). Devolver `503` por una
 * licencia haria que el orquestador retirara del balanceo un contenedor que
 * ficha perfectamente.
 *
 * **Lo unico que roza Redis en esta ruta ocurre despues de la respuesta**:
 * `RecordHttpMetrics` anota `http_requests_total{route=health.live}` en
 * `terminate()`, con el cliente ya servido y con su propio `try/catch`. Si Redis
 * no responde, la sonda sigue devolviendo `200`; por eso esta ruta no emite
 * ninguna metrica propia.
 *
 * ## Publica y sin autenticacion
 *
 * El orquestador la consulta antes de que exista sesion alguna. Que no se pueda
 * alcanzar desde fuera de la red del cliente es una decision de Nginx, no de la
 * aplicacion.
 *
 * Sin `FormRequest` y sin policy porque no hay nada que validar ni nada que
 * autorizar: no recibe parametros y no revela mas que un numero de version que
 * el propio cliente conoce. Es la unica excepcion a la regla dura 18 en toda la
 * API, y lo es porque el contrato la declara `security: []`.
 */
final class HealthController extends Controller
{
    public function __invoke(LicenseStateProbe $license): JsonResponse
    {
        return new JsonResponse([
            // Unico valor posible: si el proceso no esta vivo, no responde.
            'status' => 'ok',
            'version' => config()->string('app.version'),
            // `unknown` cuando la copia en cache no esta disponible. No es un
            // fallo de la sonda: es que esta sonda no toca la base de datos.
            'license' => $license->lastKnownState() ?? 'unknown',
        ]);
    }
}
