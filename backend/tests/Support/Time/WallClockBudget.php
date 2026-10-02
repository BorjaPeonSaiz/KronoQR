<?php

declare(strict_types=1);

namespace Tests\Support\Time;

/**
 * Presupuesto de reloj de pared de una prueba: «esto tarda menos de N s».
 *
 * Solo vale cuando la prueba corre SIN instrumentacion. El job de cobertura de
 * la CI (`make coverage`) ejecuta la suite entera con Xdebug en modo
 * `coverage`, que registra cada linea de PHP ejecutada y multiplica el tiempo
 * del codigo que mide la prueba. El 02-10-2026 el run programado 36991871603
 * de `main` fallo en `PeriodReportVolumeTest` con 5,88 s contra un presupuesto
 * de 5 s (RNF-P-05), una hora despues de pasar con el mismo arbol: lo que se
 * midio fue Xdebug, no el informe (CI-COB-01).
 *
 * Por eso el presupuesto se AFIRMA cuando no hay un driver de cobertura activo
 * —el job ④ de Integracion, la pasada de Pest del ③ (Unit, Contract, Feature)
 * y cualquier `make test` local— y se ANUNCIA, sin afirmarlo, en `make
 * coverage` y en la mutacion (`make mutate`, `make mutate-changed`), que
 * corren con Xdebug en modo `coverage`. La prueba sigue comprobando su
 * resultado y solo deja de comprobar el reloj. No es un `skip`: la puerta
 * `INTEGRATION_MAX_SKIPPED` cuenta omitidas y la prueba no lo es.
 *
 * El anuncio sale por la salida de error con un prefijo fijo para que se pueda
 * buscar en el registro del job:
 *
 *     [presupuesto-de-reloj] RNF-P-05 sin afirmar bajo cobertura (xdebug): ...
 *
 * Lo que NO pasa por aqui: las comparaciones RELATIVAS entre dos medidas de la
 * misma prueba (tiempo constante de RS-03), que la instrumentacion encarece por
 * igual en los dos lados, y los techos de una espera de red (sonda de
 * disponibilidad, colector caido), donde el tiempo es de la red y no del PHP.
 */
final class WallClockBudget
{
    public const string NOTICE_PREFIX = '[presupuesto-de-reloj]';

    /**
     * Afirma `$seconds < $budget`, salvo bajo cobertura, donde lo anuncia.
     */
    public static function expectBelow(float $seconds, float $budget, string $requirement): void
    {
        self::announce(self::check($seconds, $budget, $requirement, self::coverageDriver()));
    }

    /**
     * Lo mismo en milisegundos, para los microbenchmarks: en segundos, una
     * media de 0,05 ms se escribiria «0.000 s» y el mensaje no diria nada.
     */
    public static function expectBelowMilliseconds(float $milliseconds, float $budget, string $requirement): void
    {
        self::announce(self::check($milliseconds, $budget, $requirement, self::coverageDriver(), 'ms'));
    }

    /**
     * La decision, con el driver explicito para poder probarla sin depender
     * de con que se lance la suite. Devuelve el anuncio cuando NO ha afirmado.
     */
    public static function check(
        float $measured,
        float $budget,
        string $requirement,
        ?string $coverageDriver,
        string $unit = 's',
    ): ?string {
        if ($coverageDriver === null) {
            expect($measured)->toBeLessThan(
                $budget,
                \sprintf('%s: %.3f %s medidos, presupuesto %.1f %s.', $requirement, $measured, $unit, $budget, $unit),
            );

            return null;
        }

        // Una prueba cuya unica asercion es el reloj —un microbenchmark— se
        // quedaria sin ninguna y PHPUnit la marcaria «risky». Que la medida sea
        // un tiempo y no basura si se puede comprobar con cualquier driver.
        expect($measured)->toBeGreaterThanOrEqual(0.0);

        return \sprintf(
            '%s %s sin afirmar bajo cobertura (%s): %.3f %s medidos, presupuesto %.1f %s. '
            .'Se afirma en las pasadas sin cobertura ni mutacion (jobs ③ y ④ de la CI y local).',
            self::NOTICE_PREFIX,
            $requirement,
            $coverageDriver,
            $measured,
            $unit,
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

    private static function announce(?string $notice): void
    {
        if ($notice !== null) {
            fwrite(STDERR, $notice."\n");
        }
    }
}
