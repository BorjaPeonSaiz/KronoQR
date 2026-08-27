<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ProblemDetails;
use App\Support\Health\DependencyProbe;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * `GET /api/v1/ready` — sonda de disponibilidad (doc 01 Anexo B).
 *
 * Dice si esta instancia puede aceptar trafico: comprueba las dependencias de
 * las que depende una peticion real (`App\Support\Health\DependencyProbe`). Es
 * la sonda que gobierna el despliegue sin parada (RNF-D-04) y la comprobacion
 * posterior a una actualizacion (RF-PD-10).
 *
 * ## El `503` no dice que ha fallado
 *
 * El cuerpo es el mismo con la base de datos caida, con Redis caido o con las
 * dos: `application/problem+json` con `urn:kronoqr:problem:not-ready` y nada
 * mas. La causa va al log de la instalacion, que ya esta acotado a quien
 * administra el servidor.
 *
 * Es publica y sin autenticacion —el orquestador la consulta antes de que exista
 * sesion alguna—, asi que enumerar los servicios caidos seria repartir un mapa
 * de la instalacion a cualquiera que sepa la URL. El diagnostico por componente
 * existe y es otra cosa: el paquete de diagnostico y la comprobacion de salud
 * posinstalacion (RF-PD-09, RF-PD-13), acciones autenticadas del administrador.
 *
 * ## Lo que no hace
 *
 * No escribe en `audit_log`: una sonda no es una accion con relevancia legal y,
 * sobre todo, escribir en la base de datos para responder «puedo aceptar
 * trafico» convertiria la sonda en una escritura por segundo en una tabla
 * solo-append encadenada por hash.
 *
 * Tampoco tiene `FormRequest` ni policy: no recibe parametros y el contrato la
 * declara `security: []`. Ver `HealthController` sobre esa excepcion.
 */
final class ReadinessController extends Controller
{
    public function __invoke(DependencyProbe $probe): JsonResponse
    {
        $failure = $probe->firstFailure();

        if ($failure === null) {
            return new JsonResponse(['status' => 'ready']);
        }

        /*
         * El unico sitio donde consta que dependencia fallo. `warning` y no
         * `error`: durante un arranque o una actualizacion es el estado
         * esperado, y un `error` por cada sondeo ahogaria las alertas de verdad.
         *
         * Sin datos personales, que aqui ni siquiera hay: el contexto son el
         * nombre del componente y el mensaje de conexion (regla dura 21).
         */
        Log::warning('readiness.dependency_unavailable', [
            'component' => $failure->component,
            'detail' => $failure->detail,
        ]);

        return ProblemDetails::notReady();
    }
}
