<?php

declare(strict_types=1);

use Tests\Architecture\Support\ModuleTree;

/*
 * En las suites que arrancan el framework, el reloj del contenedor se congela
 * con `FrozenTime`, nunca a mano.
 *
 * POR QUE EXISTE. Una prueba de Feature, Integration o Contract tiene dos
 * relojes: el puerto `Clock` del dominio (ADR-021, regla dura 2) y el de
 * Carbon, que leen Sanctum, Eloquent y el limitador de peticiones. El
 * 13-09-2026 `main` se puso en rojo sin que nadie hubiera tocado nada:
 * `PlanLimitsDoNotBlockTest` instalaba un `FixedClock` de junio, emitia con el
 * un token de quiosco (caducidad = junio + 90 dias) y fichaba; Sanctum
 * comparaba esa caducidad con el reloj REAL. Tres meses en verde por
 * calendario. `FrozenTime` detiene los dos relojes en el mismo instante, y esta
 * prueba impide volver a instalar el reloj del contenedor por otra via.
 *
 * QUE COMPRUEBA. Ningun fichero de esas tres suites vincula `Clock::class` en
 * el contenedor (`instance`, `bind`, `singleton`, `scoped`). Un `FixedClock`
 * pasado a mano a un servicio construido fuera del contenedor sigue estando
 * permitido: no toca el reloj que ve el framework.
 *
 * Recorre con `scandir` (`ModuleTree::phpFilesUnder`) por la trampa del bind
 * mount que documenta `SourceDiscoveryTest`.
 */

/**
 * @return list<string>
 */
function frameworkBackedTestFiles(): array
{
    $files = [];

    foreach (['Feature', 'Integration', 'Contract'] as $suite) {
        $files = [...$files, ...ModuleTree::phpFilesUnder(dirname(__DIR__).'/'.$suite)];
    }

    return $files;
}

it('no instala el reloj del contenedor a mano en las suites con framework', function (): void {
    $offenders = [];

    foreach (frameworkBackedTestFiles() as $file) {
        $source = file_get_contents($file);
        expect($source)->not->toBeFalse();
        assert($source !== false);

        if (preg_match('/->(instance|bind|singleton|scoped)\(\s*Clock::class/', $source) === 1) {
            $offenders[] = ModuleTree::relative($file, dirname(__DIR__));
        }
    }

    expect($offenders)->toBe(
        [],
        'Estas pruebas vinculan Clock::class a mano y dejan el reloj de Carbon en la hora real; '
        .'usa FrozenTime::at() para detener los dos: '.implode(', ', $offenders),
    );
})->group('RQ-13');

it('el helper existe y lo usa al menos una prueba de cada suite con framework', function (): void {
    expect(file_exists(dirname(__DIR__).'/Support/Time/FrozenTime.php'))->toBeTrue();

    foreach (['Feature', 'Integration'] as $suite) {
        $users = array_filter(
            ModuleTree::phpFilesUnder(dirname(__DIR__).'/'.$suite),
            static fn (string $file): bool => str_contains((string) file_get_contents($file), 'FrozenTime::'),
        );

        expect($users)->not->toBe([], "Ninguna prueba de {$suite} usa FrozenTime: la guarda de arriba se ha quedado sin sujeto.");
    }
})->group('RQ-13');
