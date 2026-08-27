<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Support;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ScopeInterface;
use Throwable;

/**
 * El andamiaje de un span: abrirlo sin que abrirlo pueda romper nada, cerrarlo
 * igual, y responder cual es el `trace_id` que va al log (doc 02 §8.1).
 *
 * ## Por que existe
 *
 * Siete clases de telemetria —tres en `Attendance`, una en `Compliance`, una en
 * `Reporting` y dos en `Identity`— repetian palabra por palabra el mismo
 * `try/catch` alrededor de `Globals::tracerProvider()`, el mismo `$span->end()`
 * protegido y el mismo `traceIdOf()`. Lo que las diferencia de verdad —sus
 * atributos, su nivel de log y sus reglas de minimizacion de datos personales—
 * sigue en cada una: eso es dominio de cada acto y no debe fusionarse. Lo que
 * estaba duplicado era el andamiaje, y con el se habian colado **dos respuestas
 * distintas** a «que `trace_id` se escribe en el log». Ahora hay una sola, y
 * esta aqui.
 *
 * ## Medir no puede romper lo que se mide
 *
 * Todo va envuelto en `try/catch`, sin excepcion. La regla dura 19 es tajante
 * —el quiosco nunca bloquea al empleado— y RL-05 no admite que el portal falle
 * porque el exportador de trazas no responda: perder una traza es infinitamente
 * mas barato que perder una jornada o negar a alguien la vista de sus horas.
 *
 * `Globals` devuelve un proveedor inerte mientras el SDK no este configurado,
 * asi que esto no cuesta nada en la instalacion de un cliente que no exporta
 * trazas — que es la de la mayoria.
 *
 * ## Por que en `Application` y no en `Infrastructure`
 *
 * Porque dos de sus siete consumidores son casos de uso.
 * `Identity\Application\Support\CredentialTelemetry` esta dentro de
 * `Application` a proposito —los actos de credencial entran por el endpoint y
 * por el comando de consola, y instrumentar solo el borde HTTP dejaria sin traza
 * el alta masiva de una temporada—, y `Application` no puede depender de
 * `Infrastructure` sin invertir la direccion de las dependencias. Un ayudante en
 * `Shared/Infrastructure/Telemetry/` habria obligado a esa arista o a dejar dos
 * de las siete copias sin consolidar. Aqui no depende de nadie: solo de la
 * **API** de OpenTelemetry, que es una abstraccion —no el SDK— y que las capas
 * `Application` ya tienen concedida.
 */
final class SpanScope
{
    private function __construct(
        private readonly ?SpanInterface $span,
        /** Solo cuando el span se abrio con {@see self::startActive()}. */
        private ?ScopeInterface $scope,
    ) {}

    /**
     * Abre un span colgado del contexto activo, que es el que trae el
     * `traceparent` que el middleware de propagacion extrajo de la peticion: asi
     * el span cuelga del que abrio el navegador del quiosco o del portal (§8.1).
     *
     * **No lo activa.** El span mide el acto; no se convierte en el contexto en
     * curso. Es el comportamiento de seis de las siete telemetrias.
     *
     * @param  non-empty-string  $tracer  `kronoqr.<modulo>`.
     * @param  non-empty-string  $name
     * @param  SpanKind::KIND_*  $kind
     * @param  array<string, scalar|null>  $attributes  Solo identificadores publicos: un
     *                                                  atributo de traza acaba en el mismo sitio que un log (regla dura 21).
     */
    public static function start(
        string $tracer,
        string $name,
        int $kind = SpanKind::KIND_SERVER,
        array $attributes = [],
    ): self {
        return new self(self::open($tracer, $name, $kind, $attributes), null);
    }

    /**
     * Igual que {@see self::start()}, pero ademas **activa** el span mientras
     * dura el acto.
     *
     * Existe por un caso concreto y documentado:
     * `Identity\Application\Support\PortalAccessTelemetry` escribe sus tres
     * apuntes —aceptado, rechazado, bloqueado— desde metodos publicos que el caso
     * de uso invoca **dentro** del acto medido, donde no tiene a mano el span. Su
     * unica forma de fechar esos apuntes era leer {@see self::currentTraceId()},
     * y eso daba una respuesta distinta de la de las otras seis cuando la
     * peticion llega sin `traceparent`: el span propio abre entonces una traza
     * nueva que el contexto ambiente no conoce, y el log se quedaba sin
     * identificador.
     *
     * Activando el span, el contexto en curso **es** el span propio: las dos
     * lecturas devuelven el mismo identificador siempre, y la divergencia deja de
     * existir en lugar de quedar documentada. {@see self::end()} lo suelta.
     *
     * @param  non-empty-string  $tracer
     * @param  non-empty-string  $name
     * @param  SpanKind::KIND_*  $kind
     * @param  array<string, scalar|null>  $attributes
     */
    public static function startActive(
        string $tracer,
        string $name,
        int $kind = SpanKind::KIND_SERVER,
        array $attributes = [],
    ): self {
        $span = self::open($tracer, $name, $kind, $attributes);

        if (! $span instanceof SpanInterface) {
            return new self(null, null);
        }

        try {
            return new self($span, $span->activate());
        } catch (Throwable) {
            // Sin activar, pero con span: mejor una traza sin contexto en curso
            // que ninguna.
            return new self($span, null);
        }
    }

    /**
     * Cierra el span, con los atributos que solo se conocen al final —el
     * desenlace, el recuento de filas—.
     *
     * Es idempotente en la practica: llamarlo dos veces no rompe nada porque
     * todo esta envuelto.
     *
     * @param  array<string, scalar|null>  $attributes
     */
    public function end(array $attributes = []): void
    {
        try {
            $this->scope?->detach();
            $this->scope = null;

            if (! $this->span instanceof SpanInterface) {
                return;
            }

            foreach ($attributes as $key => $value) {
                // Un atributo sin nombre no es un atributo, y la API de
                // OpenTelemetry exige una clave no vacia: se salta en lugar de
                // dejar caer con el toda la medicion.
                if ($key !== '') {
                    $this->span->setAttribute($key, $value);
                }
            }

            $this->span->end();
        } catch (Throwable) {
            // Ver el docblock de la clase: medir no puede romper lo que se mide.
        }
    }

    /**
     * El `trace_id` del span propio, listo para el campo `trace_id` del log
     * estructurado (§8.1).
     */
    public function traceId(): ?string
    {
        if (! $this->span instanceof SpanInterface) {
            return null;
        }

        try {
            return self::significant($this->span->getContext()->getTraceId());
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * El `trace_id` del contexto en curso, para quien escribe un apunte sin tener
     * el span a mano. Ver {@see self::startActive()}.
     */
    public static function currentTraceId(): ?string
    {
        try {
            return self::significant(Span::getCurrent()->getContext()->getTraceId());
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  non-empty-string  $tracer
     * @param  non-empty-string  $name
     * @param  SpanKind::KIND_*  $kind
     * @param  array<string, scalar|null>  $attributes
     */
    private static function open(string $tracer, string $name, int $kind, array $attributes): ?SpanInterface
    {
        try {
            $builder = Globals::tracerProvider()
                ->getTracer($tracer)
                ->spanBuilder($name)
                ->setSpanKind($kind)
                ->setParent(Context::getCurrent());

            foreach ($attributes as $key => $value) {
                if ($key !== '') {
                    $builder->setAttribute($key, $value);
                }
            }

            return $builder->startSpan();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Un `trace_id` a ceros es el que devuelve un span inerte: escribirlo seria
     * peor que no escribir nada, porque **parece** un identificador y nadie lo
     * buscaria dos veces.
     */
    private static function significant(string $traceId): ?string
    {
        return trim($traceId, '0') === '' ? null : $traceId;
    }
}
