<?php

declare(strict_types=1);

namespace Tests\Support\Time;

use LogicException;
use Pest\TestSuite;

/**
 * Presupuesto de reloj de pared de una prueba: «esto tarda menos de N».
 *
 * ## Solo en la suite nocturna de rendimiento (D4 de la 2.3.0)
 *
 * Un presupuesto de reloj dentro de una suite funcional falla cuando la
 * maquina va cargada, no cuando el producto empeora: `PeriodReportVolumeTest`
 * puso `main` en rojo tres veces con 5,000977 s, 5,61 s y 5,88 s frente a 5 s
 * (Q2, V21-2-QA-2), y una prueba que falla a veces no se reintenta, se arregla.
 * Decision del propietario (D4): la AFIRMACION FUNCIONAL se queda en su suite y
 * la de TIEMPO pasa a la suite de rendimiento nocturna, con la mediana de
 * varias pasadas.
 *
 * Esa suite es el grupo {@see self::GROUP}: `phpunit.xml` lo excluye de toda
 * pasada normal (`make test`, `make test-integration`, los jobs ③ y ④, la
 * cobertura y la mutacion) y `make test-performance` —el job nocturno
 * `performance` de ci.yml— lo ejecuta solo. Las pruebas con presupuesto siguen
 * en el fichero de lo que miden, junto a sus datos de prueba, con
 * `->group('performance')`. Los metodos que AFIRMAN un tiempo lo comprueban:
 * fuera de ese grupo lanzan, para que nadie vuelva a meter un reloj en una
 * suite funcional por descuido.
 *
 * ## La mediana, y la pasada de calentamiento
 *
 * {@see self::samples()} descarta {@see self::WARMUP_PASSES} pasada —la que
 * carga clases, llena caches y calienta el plan de PostgreSQL— y devuelve
 * {@see self::PASSES}; se juzga la mediana. Un pico aislado de un runner
 * compartido no decide el resultado; una regresion real mueve la mediana. El
 * presupuesto es el del requisito, sin margen añadido: el margen esta en no
 * juzgar una sola muestra.
 *
 * ## Bajo cobertura se anuncia y no se afirma
 *
 * El grupo no corre con Xdebug —la cobertura y la mutacion lo excluyen—, pero si
 * alguien lo lanza con cobertura, lo medido seria Xdebug (5,88 s el 02-10-2026,
 * CI-COB-01) y no el producto. Entonces se ANUNCIA por la salida de error con un
 * prefijo fijo, sin afirmar:
 *
 *     [presupuesto-de-reloj] RNF-P-05 sin afirmar bajo cobertura (xdebug): ...
 */
final class WallClockBudget
{
    public const string NOTICE_PREFIX = '[presupuesto-de-reloj]';

    /** El grupo de Pest de la suite nocturna de rendimiento. */
    public const string GROUP = 'performance';

    /** Pasadas que se juzgan por su mediana. Impar: la mediana es una medida. */
    public const int PASSES = 5;

    /** Pasadas previas que no se cuentan: carga de clases, caches, plan en frio. */
    public const int WARMUP_PASSES = 1;

    /**
     * Segundos que tarda `$work`, con el reloj monotono.
     *
     * @param  callable(): mixed  $work
     */
    public static function seconds(callable $work): float
    {
        $startedAt = hrtime(true);
        $work();

        return (hrtime(true) - $startedAt) / 1e9;
    }

    /**
     * {@see self::PASSES} medidas de `$measure`, tras {@see self::WARMUP_PASSES}
     * que no se cuentan. `$measure` devuelve la medida de UNA pasada, en la
     * unidad que se vaya a juzgar.
     *
     * @param  callable(): float  $measure
     * @return list<float>
     */
    public static function samples(callable $measure, int $passes = self::PASSES): array
    {
        for ($pass = 0; $pass < self::WARMUP_PASSES; $pass++) {
            $measure();
        }

        $samples = [];

        for ($pass = 0; $pass < $passes; $pass++) {
            $samples[] = $measure();
        }

        return $samples;
    }

    /**
     * La mediana; con un numero par de muestras, la media de las dos centrales.
     * La misma de las pruebas de tiempo constante ({@see Samples}).
     *
     * @param  list<float>  $samples
     */
    public static function median(array $samples): float
    {
        return Samples::median($samples);
    }

    /**
     * Afirma que la MEDIANA de `$samples` queda por debajo de `$budget`.
     *
     * @param  list<float>  $samples
     */
    public static function expectMedianBelow(array $samples, float $budget, string $requirement, string $unit = 's'): void
    {
        self::guardPerformanceGroup($requirement);
        self::announce(self::check(self::median($samples), $budget, $requirement, self::coverageDriver(), $unit, $samples));
    }

    /**
     * Una medida que ya es un agregado de muchas muestras —la media de 500
     * vueltas de un microbenchmark, la dispersion entre medianas de un rechazo
     * de tiempo constante—: no tiene sentido sacarle otra mediana.
     */
    public static function expectBelow(float $measured, float $budget, string $requirement, string $unit = 's'): void
    {
        self::guardPerformanceGroup($requirement);
        self::announce(self::check($measured, $budget, $requirement, self::coverageDriver(), $unit));
    }

    /**
     * Una RAZON entre dos medidas de la misma prueba por debajo de un techo: el
     * tiempo constante de RS-03 (V4-QA-1 de la 2.2.1).
     */
    public static function expectRatioBelow(float $ratio, float $maxRatio, string $requirement): void
    {
        self::expectBelow($ratio, $maxRatio, $requirement, 'x');
    }

    /**
     * La decision, con el driver explicito para poder probarla sin depender
     * de con que se lance la suite. Devuelve el anuncio cuando NO ha afirmado.
     *
     * @param  list<float>  $samples  Las muestras de las que sale `$measured`, para el mensaje.
     */
    public static function check(
        float $measured,
        float $budget,
        string $requirement,
        ?string $coverageDriver,
        string $unit = 's',
        array $samples = [],
    ): ?string {
        $detail = $samples === []
            ? ''
            : \sprintf(' (mediana de %s)', implode(', ', array_map(
                static fn (float $sample): string => \sprintf('%.3f', $sample),
                $samples,
            )));

        if ($coverageDriver === null) {
            expect($measured)->toBeLessThan(
                $budget,
                \sprintf('%s: %.3f %s medidos%s, presupuesto %.1f %s.', $requirement, $measured, $unit, $detail, $budget, $unit),
            );

            return null;
        }

        // Una prueba cuya unica asercion es el reloj se quedaria sin ninguna y
        // PHPUnit la marcaria «risky». Que la medida sea un tiempo y no basura
        // si se puede comprobar con cualquier driver.
        expect($measured)->toBeGreaterThanOrEqual(0.0);

        return \sprintf(
            '%s %s sin afirmar bajo cobertura (%s): %.3f %s medidos%s, presupuesto %.1f %s. '
            .'Se afirma en la suite nocturna de rendimiento, sin cobertura (make test-performance).',
            self::NOTICE_PREFIX,
            $requirement,
            $coverageDriver,
            $measured,
            $unit,
            $detail,
            $budget,
            $unit,
        );
    }

    /**
     * `pcov`, `xdebug` o `null`. Xdebug solo cuenta en modo `coverage`: con
     * `xdebug.mode=off` —el de la imagen de desarrollo— esta cargado y no mide.
     */
    public static function coverageDriver(): ?string
    {
        if (\extension_loaded('pcov') && filter_var(\ini_get('pcov.enabled'), FILTER_VALIDATE_BOOL)) {
            return 'pcov';
        }

        if (\function_exists('xdebug_info') && \in_array('coverage', xdebug_info('mode'), true)) {
            return 'xdebug';
        }

        return null;
    }

    /**
     * Un presupuesto de reloj fuera de la suite de rendimiento es justo lo que
     * D4 retiro de las suites funcionales: se para aqui, con el remedio.
     *
     * @param  list<string>|null  $groups  Los grupos de la prueba; por omision, los de la que se esta ejecutando.
     */
    public static function guardPerformanceGroup(string $requirement, ?array $groups = null): void
    {
        $groups ??= TestSuite::getInstance()->test?->groups() ?? [];

        if (! \in_array(self::GROUP, $groups, true)) {
            throw new LogicException(\sprintf(
                'Presupuesto de reloj de %s fuera de la suite de rendimiento: la prueba tiene que llevar '
                ."->group('%s') (D4 de la 2.3.0). La afirmacion funcional va en otra prueba, sin reloj.",
                $requirement,
                self::GROUP,
            ));
        }
    }

    private static function announce(?string $notice): void
    {
        if ($notice !== null) {
            fwrite(STDERR, $notice."\n");
        }
    }
}
