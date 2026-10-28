<?php

declare(strict_types=1);

use Tests\Architecture\Support\ClockTampering;
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
 * QUE COMPRUEBA. Ningun fichero de esas tres suites toca uno solo de los dos
 * relojes: ni vincula `Clock` en el contenedor (por su nombre corto, completo o
 * un alias), ni mueve solo Carbon (`setTestNow`, `travelTo`, `freezeTime`...).
 * La lista completa y lo que no ve estan en `ClockTampering`. Un `FixedClock`
 * pasado a mano a un servicio construido fuera del contenedor sigue estando
 * permitido: no toca el reloj que ve el framework.
 *
 * La primera version solo buscaba `->instance(Clock::class`, y
 * `ScopeDenialAuditThrottlingTest` movia Carbon dos minutos sin el `Clock` del
 * dominio sin que nadie lo viera (Q5 de la verificacion de la 2.1.0). Por eso
 * el detector tiene aqui sus propias pruebas: una guarda que no puede fallar
 * no vigila nada.
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

it('no toca uno solo de los dos relojes en las suites con framework', function (): void {
    $offenders = [];

    foreach (frameworkBackedTestFiles() as $file) {
        $source = file_get_contents($file);
        expect($source)->not->toBeFalse();
        assert($source !== false);

        foreach (ClockTampering::in($source) as $offence) {
            $offenders[] = ModuleTree::relative($file, dirname(__DIR__)).' '.$offence;
        }
    }

    expect($offenders)->toBe(
        [],
        "Estas pruebas mueven uno solo de los dos relojes (el Clock del dominio o Carbon) y dejan el otro en la hora real;\n"
        ."usa FrozenTime::at() para detener los dos:\n- ".implode("\n- ", $offenders),
    );
})->group('RQ-13');

it('reconoce cada forma de tocar un solo reloj', function (string $source): void {
    expect(ClockTampering::in($source))->toHaveCount(1);
})->with([
    'Clock por su nombre corto' => '<?php app()->instance(Clock::class, FixedClock::at("2026-06-15 10:00:00"));',
    'Clock por su nombre completo' => '<?php $this->app->singleton(\App\Modules\Shared\Application\Port\Clock::class, fn () => $reloj);',
    'Clock por un alias' => '<?php use App\Modules\Shared\Application\Port\Clock as Reloj; app()->bind(Reloj::class, fn () => $reloj);',
    'Clock por la facade App' => '<?php App::scoped(Clock::class, fn () => $reloj);',
    'Carbon::setTestNow' => '<?php Carbon::setTestNow(Carbon::now()->addMinutes(2));',
    'CarbonImmutable::setTestNow' => '<?php CarbonImmutable::setTestNow("2026-06-15 10:00:00");',
    'la facade Date' => '<?php Date::setTestNow("2026-06-15 10:00:00");',
    'travelTo de Laravel' => '<?php $this->travelTo(now()->addDay());',
    'travel de Pest' => '<?php travel(5)->minutes();',
    'freezeTime' => '<?php $this->freezeTime();',
])->group('RQ-13');

it('no confunde con tocar el reloj lo que solo lo lee o lo explica', function (string $source): void {
    expect(ClockTampering::in($source))->toBe([]);
})->with([
    'FrozenTime::at' => '<?php FrozenTime::at("2026-06-15 10:00:00");',
    'leer el Clock del contenedor' => '<?php $ahora = app(Clock::class)->now();',
    'un FixedClock pasado a mano' => '<?php new DoctorCheck(clock: FixedClock::at("2026-06-15 10:00:00"));',
    'un comentario que lo nombra' => "<?php\n// Aqui no se usa Carbon::setTestNow() ni ->instance(Clock::class\n\$x = 1;",
    'otro metodo cuyo nombre contiene travel' => '<?php $ruta->unravelTo(3);',
    'otra clase vinculada' => '<?php app()->instance(ClockSkewPolicy::class, $politica);',
])->group('RQ-13');

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
