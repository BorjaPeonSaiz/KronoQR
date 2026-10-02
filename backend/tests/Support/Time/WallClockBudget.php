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
 * —el job ④ de Integracion, el ③ de Unitarias y cualquier `make test` local—
 * y se ANUNCIA, sin afirmarlo, cuando lo hay: la prueba sigue comprobando su
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
        $notice = self::check($seconds, $budget, $requirement, self::coverageDriver());

        if ($notice !== null) {
            fwrite(STDERR, $notice."\n");
        }
    }

    /**
     * La decision, con el driver explicito para poder probarla sin depender
     * de con que se lance la suite. Devuelve el anuncio cuando NO ha afirmado.
     */
    public static function check(float $seconds, float $budget, string $requirement, ?string $coverageDriver): ?string
    {
        if ($coverageDriver === null) {
            expect($seconds)->toBeLessThan(
                $budget,
                \sprintf('%s: %.3f s medidos, presupuesto %.1f s.', $requirement, $seconds, $budget),
            );

            return null;
        }

        return \sprintf(
            '%s %s sin afirmar bajo cobertura (%s): %.3f s medidos, presupuesto %.1f s. '
            .'Se afirma en las suites sin instrumentacion (job ④ de la CI y local).',
            self::NOTICE_PREFIX,
            $requirement,
            $coverageDriver,
            $seconds,
            $budget,
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
}
