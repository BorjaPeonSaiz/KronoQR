<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Support;

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
 * ## Medir no puede romper lo que se mide
 *
 * Igual que {@see SpanScope}: si el puerto de metricas o el span fallan, el
 * caso de uso sigue y devuelve lo mismo. La unica excepcion que sale de aqui es
 * la del propio cuerpo, **la misma instancia**, sin envolver.
 *
 * ## Que NO lleva
 *
 * Ni un atributo que salga de la peticion. El span lleva el nombre del caso de
 * uso y su desenlace; los identificadores publicos que un acto quiera añadir
 * siguen en su telemetria propia, que es donde se decide que se puede escribir
 * (regla dura 21).
 */
final class UseCaseInstrumentation
{
    public const string OUTCOME_OK = 'ok';

    public const string OUTCOME_ERROR = 'error';

    private function __construct() {}

    /**
     * @template T
     *
     * @param  non-empty-string  $useCase  `<modulo>.<accion_snake>`; el modulo da el tracer `kronoqr.<modulo>`.
     * @param  callable(): T  $body
     * @return T
     */
    public static function run(string $useCase, callable $body): mixed
    {
        $span = SpanScope::start(self::tracerOf($useCase), $useCase, SpanKind::KIND_INTERNAL);
        $startedAt = self::nanoseconds();

        try {
            $result = $body();
        } catch (Throwable $failure) {
            self::finish($span, $useCase, self::OUTCOME_ERROR, $startedAt);

            throw $failure;
        }

        self::finish($span, $useCase, self::OUTCOME_OK, $startedAt);

        return $result;
    }

    private static function finish(SpanScope $span, string $useCase, string $outcome, float $startedAt): void
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
        // un puerto que lance no deje el span abierto.
        $span->end(['outcome' => $outcome]);
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
