<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Support;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ScopeInterface;
use Throwable;

/**
 * El andamiaje de un span: abrirlo sin que abrirlo pueda romper nada, cerrarlo
 * igual, y responder cual es el `trace_id` que va al log (doc 02 §8.1).
 *
 * ## Quien abre spans, y quien no
 *
 * Solo dos clases de consumidores: {@see UseCaseInstrumentation}, que abre el
 * span de cada caso de uso, de cada consulta y de cada acto medido, y el
 * armazon (abajo). Las telemetrias de los modulos **no abren spans**: añaden
 * sus atributos al span en curso con {@see self::annotateCurrent()} y fechan su
 * log con {@see self::currentTraceId()}. `UseCaseInstrumentationTest` lo exige.
 * Lo que las diferencia de verdad —sus atributos, su nivel de log y sus reglas
 * de minimizacion de datos personales— sigue en cada una: eso es dominio de
 * cada acto y no debe fusionarse.
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
 * Porque la usan los casos de uso, por {@see UseCaseInstrumentation}, y
 * `Application` no puede depender de `Infrastructure` sin invertir la direccion
 * de las dependencias. Aqui no depende de nadie: solo de la
 * **API** de OpenTelemetry, que es una abstraccion —no el SDK— y que las capas
 * `Application` ya tienen concedida.
 *
 * ## Y tambien la usa el ARMAZON, fuera de los modulos
 *
 * La instrumentacion transversal de la tarea 3.1 —el span de servidor de
 * `App\Http\Middleware\PropagateTraceContext`, el span por consulta de
 * `App\Support\Observability\Tracing\DatabaseSpans` y el del planificador—
 * necesita exactamente este andamiaje, y durante un tiempo tuvo su propia
 * copia (`SafeSpan`) linea por linea. Dos copias del mismo `try/catch` son dos
 * sitios donde arreglar el mismo fallo, y la segunda solo existia porque
 * `App\*` no podia depender de un modulo.
 *
 * Se resolvio abriendo UNA arista nombrada en Deptrac —`AppFramework` ->
 * `SharedTracingSupport`, la capa que es exactamente este directorio— en lugar
 * de duplicar. La arista es estrecha a proposito: alcanza este andamiaje y
 * {@see TraceparentHeader}, no `Shared\Application` entera ni ningun otro
 * modulo.
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
     * curso. Es lo que necesita el armazon: el span de una consulta o de una
     * tarea programada se cierra fuera de la pila en la que se abrio.
     *
     * @param  non-empty-string  $tracer  `kronoqr.<modulo>`.
     * @param  non-empty-string  $name
     * @param  SpanKind::KIND_*  $kind
     * @param  array<string, scalar|null>  $attributes  Solo identificadores publicos: un
     *                                                  atributo de traza acaba en el mismo sitio que un log (regla dura 21).
     * @param  int|null  $startEpochNanos  Marca de inicio RETROACTIVA. Existe por lo
     *                                     que solo se puede medir cuando ya ha ocurrido: `QueryExecuted` llega
     *                                     con la consulta terminada y su duracion, y sin poder fechar el
     *                                     principio el span duraria cero. `null` —el caso normal— es «ahora».
     */
    public static function start(
        string $tracer,
        string $name,
        int $kind = SpanKind::KIND_SERVER,
        array $attributes = [],
        ?int $startEpochNanos = null,
    ): self {
        return new self(self::open($tracer, $name, $kind, $attributes, $startEpochNanos), null);
    }

    /**
     * Igual que {@see self::start()}, pero ademas **activa** el span mientras
     * dura el acto.
     *
     * Es lo que usan {@see UseCaseInstrumentation} y el span de servidor: lo que
     * ocurre dentro —un apunte del rastro de autenticacion, una consulta, otro
     * caso de uso— se fecha leyendo {@see self::currentTraceId()} sin el span a
     * mano. Sin activarlo, una peticion sin `traceparent` abriria una traza nueva
     * que el contexto ambiente no conoce, y el log se quedaria sin identificador.
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
        $span = self::open($tracer, $name, $kind, $attributes, null);

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
     * Deja constancia de que el acto fallo, antes de {@see self::end()}
     * (V21-4-BE-2).
     *
     * Un evento `exception` con `exception.type` = la clase, y **nada mas**. No
     * es `recordException()` a proposito: ese metodo del SDK añade por su
     * cuenta `exception.message` y `exception.stacktrace`, y el mensaje de una
     * `QueryException` lleva el SQL con los valores enlazados y el host. Un
     * atributo de traza acaba en el mismo sitio que un log (regla dura 21).
     *
     * Con `$isError`, ademas `status=error`, que es lo que se busca en Tempo
     * para encontrar averias. Un rechazo de negocio no lo lleva.
     */
    public function recordFailure(Throwable $failure, bool $isError): void
    {
        try {
            if (! $this->span instanceof SpanInterface) {
                return;
            }

            $this->span->addEvent('exception', ['exception.type' => $failure::class]);

            if ($isError) {
                // La descripcion es la clase: el mensaje no sale de aqui.
                $this->span->setStatus(StatusCode::STATUS_ERROR, $failure::class);
            }
        } catch (Throwable) {
            // Ver el docblock de la clase: medir no puede romper lo que se mide.
        }
    }

    /**
     * Añade atributos al span **en curso** —el de servidor de la peticion, o el
     * del caso de uso activo— sin abrir uno nuevo.
     *
     * Es lo que hacen las telemetrias del borde HTTP desde que el span de cada
     * acto lo abre `UseCaseInstrumentation`: el `device.id` y el `scan.id` de un
     * fichaje son de la peticion, no del caso de uso, y van al span de la
     * peticion. Solo identificadores publicos (regla dura 21).
     *
     * @param  array<string, scalar|null>  $attributes
     */
    public static function annotateCurrent(array $attributes): void
    {
        try {
            $span = Span::getCurrent();

            foreach ($attributes as $key => $value) {
                if ($key !== '') {
                    $span->setAttribute($key, $value);
                }
            }
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
    private static function open(
        string $tracer,
        string $name,
        int $kind,
        array $attributes,
        ?int $startEpochNanos,
    ): ?SpanInterface {
        try {
            $builder = Globals::tracerProvider()
                ->getTracer($tracer)
                ->spanBuilder($name)
                ->setSpanKind($kind)
                ->setParent(Context::getCurrent());

            if ($startEpochNanos !== null) {
                $builder->setStartTimestamp($startEpochNanos);
            }

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
     * Una sola normalizacion del identificador «significativo» en todo el
     * producto. Ver {@see TraceparentHeader::significant()}.
     */
    private static function significant(string $traceId): ?string
    {
        return TraceparentHeader::significant($traceId);
    }
}
