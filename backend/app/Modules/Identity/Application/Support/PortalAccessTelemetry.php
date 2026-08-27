<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Support;

use App\Modules\Shared\Application\Support\SpanScope;
use OpenTelemetry\API\Trace\SpanKind;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * El span y el log estructurado del acceso al portal del empleado (doc 02 §8.1,
 * RS-12).
 *
 * ## Aqui esta lo que la respuesta HTTP no dice
 *
 * El endpoint devuelve un `401` identico para las cinco causas de rechazo (regla
 * dura 17). Ese detalle no se pierde: se escribe aqui, que es donde el §8.1 lo
 * quiere y donde no lo ve quien teclea. Sin esta clase, un ataque de fuerza
 * bruta contra el portal seria indistinguible en el servidor de un turno de
 * gente con los dedos frios.
 *
 * ## Tres desenlaces, tres niveles
 *
 * ```
 * portal_login_succeeded   info      con employee_uuid
 * portal_login_rejected    warning   sin employee_uuid: no se sabe cual es
 * portal_login_locked      warning   sin employee_uuid, con los segundos que faltan
 * ```
 *
 * **Que el rechazo no lleve `employee_uuid` es consecuencia del diseño, no un
 * olvido.** `Shared\Domain\ValueObject\PinVerification` —nombrado en prosa y no
 * con `@see` para no traer un `use` que este fichero no necesita— no devuelve
 * nada identificable cuando no verifica, precisamente para que no haya ninguna
 * rama que distinga «ese codigo no existe» de «ese PIN no es» ni siquiera dentro
 * del servidor. La señal de que alguien esta sondeando el PIN de una persona
 * concreta no es este log: es el contador por empleado de `PinAttempts`, que si
 * se lleva por `employee_uuid` y es el que acaba bloqueando.
 *
 * ## Nunca el codigo de empleado, y nunca el PIN
 *
 * Ni siquiera en el rechazo, donde seria tentador para diagnosticar. El codigo
 * va impreso en la tarjeta que la persona lleva encima: registrarlo en un log
 * que puede acabar en el paquete de diagnostico del fabricante (ADR-020) es
 * publicar media credencial (regla dura 21).
 *
 * ## Sin metrica propia, y es deliberado
 *
 * El catalogo del §8.2 no tiene ninguna para el acceso al portal, y este
 * endpoint ya esta contado y cronometrado por `http_requests_total{route,...}`,
 * que emite `App\Http\Middleware\RecordHttpMetrics` con el nombre de la ruta como
 * etiqueta —y con el codigo de estado, asi que los `401` se distinguen de los
 * `200` sin inventar nada—. Una metrica fuera del catalogo se queda sin panel,
 * sin alerta y sin nadie que la mire.
 *
 * ## Medir no puede impedir que alguien entre a ver sus horas
 *
 * Todo va envuelto, y lo envuelve {@see SpanScope}. RL-05 no admite que el portal
 * falle porque el exportador de trazas no responda.
 *
 * ## El `trace_id` se lee del contexto en curso, y esa era la anomalia
 *
 * Las otras seis telemetrias del backend escriben en el log el `trace_id` **de su
 * propio span**; esta leia el del contexto en curso, porque sus tres apuntes se
 * emiten desde metodos publicos que el caso de uso invoca dentro del acto medido,
 * donde no hay ningun span a mano. Eran dos respuestas distintas a la misma
 * pregunta: con una peticion sin `traceparent`, el span propio abre una traza que
 * el contexto ambiente no conoce y el apunte se quedaba sin identificador.
 *
 * **Resuelto activando el span** ({@see SpanScope::startActive()}): mientras dura
 * el intento, el contexto en curso *es* el span de este acto, asi que las dos
 * lecturas devuelven lo mismo siempre. La forma de leerlo sigue siendo la de aqui
 * —es la unica posible desde estos tres metodos—, pero ya no significa otra cosa.
 */
final readonly class PortalAccessTelemetry
{
    public function __construct(private LoggerInterface $logger) {}

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

    public function succeeded(string $employeeUuid): void
    {
        $this->logger->info('identity.portal_login_succeeded', [
            'trace_id' => $this->currentTraceId(),
            'employee_uuid' => $employeeUuid,
        ]);
    }

    /**
     * Codigo inexistente, PIN incorrecto, PIN no emitido o empleado que no esta
     * en alta. Ver el docblock de la clase: los cuatro son este mismo apunte.
     */
    public function rejected(): void
    {
        $this->logger->warning('identity.portal_login_rejected', [
            'trace_id' => $this->currentTraceId(),
            'employee_uuid' => null,
        ]);
    }

    /**
     * El bloqueo por intentos estaba activo, asi que el PIN **ni se comprobo**.
     *
     * Los segundos que faltan se registran aqui y **no salen por la API**: son
     * la señal operativa que permite ver un escalon alcanzado sin decirselo a
     * quien lo alcanzo.
     */
    public function locked(int $retryAfterSeconds): void
    {
        $this->logger->warning('identity.portal_login_locked', [
            'trace_id' => $this->currentTraceId(),
            'employee_uuid' => null,
            'retry_after_seconds' => $retryAfterSeconds,
        ]);
    }

    /**
     * El `trace_id` de la traza en curso. Ver el docblock de la clase: dentro de
     * {@see self::measure()} es la del span de este mismo acto, porque ese span
     * esta activado.
     */
    private function currentTraceId(): ?string
    {
        return SpanScope::currentTraceId();
    }
}
