<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Support;

use DomainException;
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
 * Que es «de negocio» lo decide {@see self::isRejection()}: no hay una clase
 * base comun en los modulos —unos heredan de `DomainException` y otros de
 * `RuntimeException`—, y el marcador que si comparten todos es donde viven.
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

    /**
     * Los espacios de nombres de las excepciones de negocio de los modulos. Las
     * de `Application\Exception` incluyen dos que no lo son —una dependencia que
     * no responde, un tope de capacidad—, y por eso se excluyen por su sufijo.
     */
    private const string REJECTION_NAMESPACE = '/^App\\\\Modules\\\\[A-Za-z]+\\\\(?:Domain|Application)\\\\Exception\\\\/';

    private const string NOT_A_REJECTION_SUFFIX = '/(?:Unavailable|Busy)$/';

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
        $span = SpanScope::start(self::tracerOf($useCase), $useCase, SpanKind::KIND_INTERNAL);
        $startedAt = self::nanoseconds();

        try {
            $result = $body();
        } catch (Throwable $failure) {
            self::finish($span, $useCase, self::isRejection($failure) ? self::OUTCOME_REJECTED : self::OUTCOME_ERROR, $startedAt, []);

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
     * Una excepcion de negocio: una `DomainException` o una clase de las
     * carpetas de excepciones de un modulo, salvo las de indisponibilidad.
     */
    public static function isRejection(Throwable $failure): bool
    {
        if ($failure instanceof DomainException) {
            return true;
        }

        if (preg_match(self::REJECTION_NAMESPACE, $failure::class) !== 1) {
            return false;
        }

        // La clase y sus padres: `PairingCapacityExhausted` es una
        // `PairingUnavailable` aunque su nombre no lo diga.
        return array_all([$failure::class, ...array_values(class_parents($failure))], static fn (string $class): bool => preg_match(self::NOT_A_REJECTION_SUFFIX, $class) !== 1);
    }

    /**
     * @param  array<string, scalar|null>  $attributes
     */
    private static function finish(SpanScope $span, string $useCase, string $outcome, float $startedAt, array $attributes): void
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
