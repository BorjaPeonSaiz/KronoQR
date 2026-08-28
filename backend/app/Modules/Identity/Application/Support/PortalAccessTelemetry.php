<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Support;

use App\Modules\Shared\Application\Support\SpanScope;
use OpenTelemetry\API\Trace\SpanKind;
use Throwable;

/**
 * El span del acceso al portal del empleado (doc 02 §8.1, RS-12).
 *
 * ## Un solo acto medido, y ningun apunte propio
 *
 * Esta clase **abrio** el rastro del portal y ya no lo escribe. Antes emitia sus
 * tres apuntes —`identity.portal_login_succeeded`, `_rejected` y `_locked`—, y
 * cuando `Shared\Application\Port\AuthenticationJournal` llego con `auth.*`, el
 * mismo hecho quedaba apuntado dos veces con dos nombres y dos campos distintos:
 * dos poblaciones que un panel cuenta como una y dos sitios donde arreglar la
 * proxima regla de minimizacion. **Una sola autoridad**, y es el journal —lo es
 * tambien para el panel y para el PIN del quiosco, que no tienen equivalente de
 * esta clase—.
 *
 * Lo que queda aqui es lo que solo puede vivir aqui: el span que envuelve el
 * intento entero, con su duracion y su desenlace implicito. El `retry_after` que
 * el apunte `_locked` llevaba **no vuelve en otro sitio**, y es correcto que no
 * vuelva: era el unico campo del log que separaba «este codigo existe y esta
 * bloqueado» de «este codigo no existe», que es justo el oraculo que RS-03 evita
 * hacia fuera. Los segundos del bloqueo se leen donde tienen que leerse, en el
 * asiento `auth.lockout_started` (ADR-039).
 *
 * ## Con metrica, y no es la de esta clase
 *
 * `kronoqr_auth_attempts_total{channel="portal"}` cuenta los tres desenlaces
 * desde el journal, y `http_requests_total{route,...}` sigue cronometrando el
 * endpoint. Nada de eso se cuenta aqui: una metrica emitida por dos sitios son
 * dos series con el mismo nombre y distinto criterio.
 *
 * ## Medir no puede impedir que alguien entre a ver sus horas
 *
 * Todo va envuelto, y lo envuelve {@see SpanScope}. RL-05 no admite que el portal
 * falle porque el exportador de trazas no responda.
 *
 * ## El span se **activa**, y de eso depende el `trace_id` del log
 *
 * Es la unica de las siete telemetrias del backend que lo hace, y tiene motivo:
 * los apuntes del intento no los escribe esta clase, sino el journal, desde
 * dentro del acto medido y sin el span a mano. Su unica forma de fechar el
 * apunte es {@see SpanScope::currentTraceId()}. Con el span sin activar, una
 * peticion sin `traceparent` —la mayoria: al portal se llega escribiendo la
 * direccion— abria una traza que el contexto ambiente no conocia, y el apunte
 * salia con `trace_id: null`. Un apunte de acceso sin `trace_id` no es un detalle
 * de observabilidad: es el unico hilo que une «alguien intento entrar 40 veces»
 * con la traza que dice desde donde.
 */
final readonly class PortalAccessTelemetry
{
    /**
     * @template T
     *
     * @param  callable(): T  $attempt
     * @return T
     */
    public function measure(callable $attempt): mixed
    {
        // El span **no lleva atributos**, y `end()` se llama sin ninguno a
        // proposito: cualquiera que se pudiera poner —el desenlace, el codigo de
        // empleado— seria una pista sobre una credencial en un almacen distinto
        // del log, con otra retencion y otro control de acceso.
        $span = SpanScope::startActive('kronoqr.identity', 'identity.portal_login', SpanKind::KIND_SERVER);

        try {
            $result = $attempt();
        } catch (Throwable $failure) {
            $span->end();

            throw $failure;
        }

        $span->end();

        return $result;
    }
}
