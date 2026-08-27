<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v1/health` — sonda de vida (doc 01 Anexo B, doc 02 §10.5).
 *
 * Dice dos cosas y ninguna mas: que el proceso responde y **con que version
 * esta desplegado**. Lo segundo es lo que permite correlacionar una incidencia
 * con una version concreta cuando el cliente llama por telefono.
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
    public function __invoke(): JsonResponse
    {
        return new JsonResponse([
            // Unico valor posible: si el proceso no esta vivo, no responde.
            'status' => 'ok',
            'version' => config()->string('app.version'),
        ]);
    }
}
