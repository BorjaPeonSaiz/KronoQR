<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Support;

use App\Modules\Shared\Domain\Exception\BusinessRejection;
use OpenTelemetry\API\Trace\SpanKind;
use Throwable;

/**
 * **La medida comun de todo caso de uso** (doc 02 §8): un span `INTERNAL` y una
 * observacion de `use_case_duration_seconds{use_case,outcome}`.
 *
 * ## Por que un ayudante y no un decorador
 *
 * No hay bus de comandos y los casos de uso son clases `final` sin interfaz:
 * no hay donde colgar un decorador sin cambiar como se resuelve cada uno. El
 * patron es el mismo en todos:
 *
 *     public function handle(Command $command): Result
 *     {
 *         return UseCaseInstrumentation::run('workforce.register_employee', fn () => $this->doHandle($command));
 *     }
 *
 * El nombre es un **literal** a proposito: `UseCaseInstrumentationTest` lo lee
 * del fichero, comprueba su forma (`<modulo>.<accion_snake>`) y que no se
 * repite. Un nombre calculado no se podria comprobar sin ejecutar el caso.
 *
 * ## Tres desenlaces
 *
 * - `ok`: el cuerpo devolvio.
 * - `rejected`: el cuerpo lanzo una excepcion **de negocio** —una regla que dice
 *   que no, un recurso que no existe, unas credenciales que no valen—. Es lo
 *   esperado de un producto que valida; no es una averia.
 * - `error`: cualquier otra excepcion. Esto si es una averia.
 *
 * Que es «de negocio» lo decide {@see self::isRejection()}: la excepcion lo
 * declara implementando {@see BusinessRejection}. Lo que no lo declara es una
 * averia, aunque viva en una carpeta de excepciones de un modulo: el disco
 * lleno de una exportacion o el SMTP caido no son reglas que dicen que no
 * (V21-1-AR-1). `BusinessRejectionClassificationTest` exige que cada excepcion
 * del producto este en un lado o en otro a proposito.
 *
 * ## El span: activo, y marcado como error cuando es una averia
 *
 * El span del caso de uso se **activa** mientras dura: las consultas, los
 * trabajos que encola y cada linea de log que escribe cuelgan de el, y quien
 * escribe un apunte dentro lee su `trace_id` con
 * {@see SpanScope::currentTraceId()} sin tener el span a mano.
 *
 * Una averia cierra el span con `status=error` y un evento `exception` con
 * `exception.type` = la clase, **sin el mensaje ni la traza** (V21-4-BE-2, regla
 * dura 21): el mensaje de una `QueryException` lleva el SQL con sus valores y el
 * host. Un rechazo lleva el mismo evento sin `status=error`: no es una averia, y
 * buscar `status=error` en Tempo tiene que dar solo averias.
 *
 * ## Medir no puede romper lo que se mide
 *
 * Igual que {@see SpanScope}: si el puerto de metricas o el span fallan, el
 * caso de uso sigue y devuelve lo mismo. La unica excepcion que sale de aqui es
 * la del propio cuerpo, **la misma instancia**, sin envolver.
 *
 * ## Que NO lleva
 *
 * Ni un atributo que salga de la peticion. El span lleva el nombre del caso de
 * uso y su desenlace; un caso de uso puede añadir al cierre atributos que
 * describen su RESULTADO —un recuento, un desenlace detallado—, nunca un dato de
 * una persona (regla dura 21).
 */
final class UseCaseInstrumentation
{
    public const string OUTCOME_OK = 'ok';

    public const string OUTCOME_REJECTED = 'rejected';

    public const string OUTCOME_ERROR = 'error';

    private function __construct() {}

    /**
     * @template T
     *
     * @param  non-empty-string  $useCase  `<modulo>.<accion_snake>`; el modulo da el tracer `kronoqr.<modulo>`.
     * @param  callable(): T  $body
     * @param  (callable(T): array<non-empty-string, scalar|null>)|null  $describe  Atributos de cierre a partir
     *                                                                              del resultado: identificadores de un catalogo cerrado y recuentos, nunca datos de personas.
     * @return T
     */
    public static function run(string $useCase, callable $body, ?callable $describe = null): mixed
    {
        $span = SpanScope::startActive(self::tracerOf($useCase), $useCase, SpanKind::KIND_INTERNAL);
        $startedAt = self::nanoseconds();

        try {
            $result = $body();
        } catch (Throwable $failure) {
            $outcome = self::isRejection($failure) ? self::OUTCOME_REJECTED : self::OUTCOME_ERROR;

            self::finish($span, $useCase, $outcome, $startedAt, [], $failure);

            throw $failure;
        }

        $attributes = [];

        if ($describe !== null) {
            try {
                $attributes = $describe($result);
            } catch (Throwable) {
                // Describir es accesorio: sin atributos, el span se cierra igual.
            }
        }

        self::finish($span, $useCase, self::OUTCOME_OK, $startedAt, $attributes);

        return $result;
    }

    /**
     * Un rechazo de negocio: la excepcion lo declara con {@see BusinessRejection}.
     * Cualquier otra es una averia.
     */
    public static function isRejection(Throwable $failure): bool
    {
        return $failure instanceof BusinessRejection;
    }

    /**
     * @param  array<string, scalar|null>  $attributes
     */
    private static function finish(SpanScope $span, string $useCase, string $outcome, float $startedAt, array $attributes, ?Throwable $failure = null): void
    {
        try {
            UseCaseMetricsRegistry::current()?->observe(
                $useCase,
                $outcome,
                max(0.0, (self::nanoseconds() - $startedAt) / 1e9),
            );
        } catch (Throwable) {
            // Ver el docblock de la clase: medir no puede romper lo que se mide.
        }

        if ($failure instanceof Throwable) {
            // Ver el docblock de la clase: la clase, nunca el mensaje.
            $span->recordFailure($failure, $outcome === self::OUTCOME_ERROR);
        }

        // `end()` ya es a prueba de fallos, y va despues de la metrica para que
        // un puerto que lance no deje el span abierto. `outcome` va el ultimo:
        // un atributo de cierre no puede pisarlo.
        $span->end([...$attributes, 'outcome' => $outcome]);
    }

    /**
     * @return non-empty-string
     */
    private static function tracerOf(string $useCase): string
    {
        $module = strstr($useCase, '.', true);

        return 'kronoqr.'.($module === false || $module === '' ? 'application' : $module);
    }

    /**
     * Reloj monotono: un ajuste del reloj del sistema durante el caso de uso no
     * puede producir una duracion negativa.
     */
    private static function nanoseconds(): float
    {
        return (float) hrtime(true);
    }
}
