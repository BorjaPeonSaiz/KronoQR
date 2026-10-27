<?php

declare(strict_types=1);

use Tests\Architecture\Support\ModuleTree;
use Tests\Architecture\Support\SystemClockCalls;

/*
 * Ni el dominio ni la capa de aplicacion leen el reloj del sistema: la hora
 * entra por el puerto `Clock` (regla dura 2, ADR-002 «Verificacion», ADR-021;
 * V1-AR-1, V1-BE-3 y R1-AR-05 de la verificacion de la 2.2.0).
 *
 * `DomainPurityTest` cubria seis funciones y solo en `Domain/`. Esta prueba
 * extiende la vigilancia a `Application/`, que es donde el caso de uso resuelve
 * el instante antes de pasarselo al agregado, y a las formas que aquella no
 * veia: `gmdate()`, `date_create()`, `CarbonImmutable::now()`,
 * `new DateTimeImmutable('today')`... La lista y la deteccion estan en
 * {@see SystemClockCalls}.
 *
 * LA UNICA EXCEPCION ES `hrtime()`, Y ES UNA LISTA CERRADA DE FICHEROS
 * ({@see SystemClockCalls::MONOTONIC_TIMERS}): mide duraciones para la
 * telemetria y el suelo de tiempo constante de los rechazos (RS-03). Es
 * monotono —un ajuste de NTP no lo hace retroceder, que es por lo que sustituye
 * a `microtime(true)`— y no da una hora que pueda acabar en el registro.
 */

/**
 * Las lecturas del reloj en una capa, como «fichero:linea llama a x()».
 *
 * @return list<string>
 */
function systemClockReadsIn(string $layer): array
{
    $offenders = [];

    foreach (ModuleTree::filesInLayer(ModuleTree::MODULES, $layer) as $file) {
        $relative = ModuleTree::relative($file);
        $monotonicAllowed = \in_array($relative, SystemClockCalls::MONOTONIC_TIMERS, true);

        $offenders[] = array_map(
            static fn (array $read): string => $relative.':'.$read['line'].' llama a '.$read['call'],
            SystemClockCalls::in((string) file_get_contents($file), $monotonicAllowed),
        );
    }

    return array_merge(...$offenders);
}

/** Una sonda de aplicacion con `$codigo` como cuerpo de un metodo, en la linea 4. */
function systemClockProbe(string $codigo, string $imports = ''): string
{
    return "<?php\nnamespace App\\Modules\\Attendance\\Application\\UseCase;\n".$imports."\nfinal class Sonda { public function f(): mixed { return ".$codigo."; } }\n";
}

it('no lee el reloj del sistema desde el dominio', function (): void {
    expect(systemClockReadsIn('Domain'))->toBe([]);
})->group('RNF-M-03');

it('no lee el reloj del sistema desde la capa de aplicacion', function (): void {
    expect(systemClockReadsIn('Application'))->toBe([]);
})->group('RNF-M-03');

it('solo admite hrtime en ficheros que existen y lo usan', function (): void {
    // Una excepcion que se queda en la lista cuando el fichero desaparece o deja
    // de medir es un agujero esperando a alguien: la lista tiene que ser la de
    // hoy, no la de un dia.
    $stale = [];

    foreach (SystemClockCalls::MONOTONIC_TIMERS as $relative) {
        $file = ModuleTree::root().'/'.$relative;
        $reads = is_file($file) ? SystemClockCalls::in((string) file_get_contents($file)) : [];

        if (array_column($reads, 'call') === [] || array_unique(array_column($reads, 'call')) !== ['hrtime()']) {
            $stale[] = $relative;
        }
    }

    expect($stale)->toBe([]);
})->group('RNF-M-03');

it('detecta cada forma de leer el reloj del sistema', function (string $codigo, string $imports, string $lectura): void {
    expect(array_column(SystemClockCalls::in(systemClockProbe($codigo, $imports)), 'call'))->toBe([$lectura]);
})->with([
    'time' => ['time()', '', 'time()'],
    'date' => ["date('Y-m-d')", '', 'date()'],
    'gmdate con barra' => ["\\gmdate('H:i')", '', 'gmdate()'],
    'strtotime' => ["strtotime('tomorrow')", '', 'strtotime()'],
    'microtime' => ['microtime(true)', '', 'microtime()'],
    'hrtime fuera de la lista' => ['hrtime(true)', '', 'hrtime()'],
    'date_create' => ['date_create()', '', 'date_create()'],
    'date_create_immutable' => ["date_create_immutable('now')", '', 'date_create_immutable()'],
    'uniqid' => ['uniqid()', '', 'uniqid()'],
    'Carbon::now importado' => ['CarbonImmutable::now()', "use Carbon\\CarbonImmutable;\n", 'CarbonImmutable::now()'],
    'Carbon::today calificado' => ['\\Carbon\\Carbon::today()', '', '\\Carbon\\Carbon::today()'],
    'Carbon de Laravel' => ['\\Illuminate\\Support\\Carbon::now()', '', '\\Illuminate\\Support\\Carbon::now()'],
    'Carbon con alias' => ['Instante::now()', "use Carbon\\CarbonImmutable as Instante;\n", 'Instante::now()'],
    'facade Date' => ['\\Date::now()', '', '\\Date::now()'],
    'new sin argumentos' => ['new \\DateTimeImmutable()', '', 'new \\DateTimeImmutable()'],
    'new sin parentesis' => ['new DateTime', '', 'new DateTime'],
    'new solo con la zona' => ["new DateTimeImmutable(timezone: new \\DateTimeZone('UTC'))", '', 'new DateTimeImmutable(timezone: …)'],
    "new con 'now'" => ["new DateTimeImmutable('now', \$utc)", '', "new DateTimeImmutable('now')"],
    "new con 'today'" => ["new \\DateTimeImmutable('today')", '', "new \\DateTimeImmutable('today')"],
    "new con 'midnight'" => ["new DateTime('midnight')", '', "new DateTime('midnight')"],
    'new con una hora sin fecha' => ["new DateTimeImmutable('08:00')", '', "new DateTimeImmutable('08:00')"],
    'new con un desplazamiento' => ["new DateTimeImmutable('+1 day')", '', "new DateTimeImmutable('+1 day')"],
    'new Carbon' => ['new Carbon()', "use Carbon\\Carbon;\n", 'new Carbon()'],
])->group('RNF-M-03');

it('no confunde un instante escrito ni un metodo propio con leer el reloj', function (string $codigo): void {
    // Los falsos positivos son los que acaban con una prueba desactivada.
    expect(SystemClockCalls::in(systemClockProbe($codigo)))->toBe([]);
})->with([
    'el puerto Clock' => ['$this->clock->now()'],
    'un metodo date propio' => ['$this->date()'],
    'un estatico propio' => ['WorkDate::today($zona)'],
    'fecha completa' => ["new DateTimeImmutable('2026-03-29 00:00:00', \$utc)"],
    'segundos Unix' => ["new DateTimeImmutable('@1700000000')"],
    'una variable' => ['new DateTimeImmutable($instante)'],
    'createFromFormat con !' => ["DateTimeImmutable::createFromFormat('!Y-m-d', \$fecha)"],
    'en un comentario' => ['/* time() */ 1'],
    'en una cadena' => ["'date()'"],
    'una funcion propia con espacio de nombres' => ['\\App\\Support\\time()'],
])->group('RNF-M-03');

it('admite hrtime solo donde la lista lo permite', function (): void {
    $source = systemClockProbe('hrtime(true) - microtime(true)');

    expect(array_column(SystemClockCalls::in($source, monotonicAllowed: true), 'call'))->toBe(['microtime()'])
        ->and(array_column(SystemClockCalls::in($source), 'call'))->toBe(['hrtime()', 'microtime()']);
})->group('RNF-M-03');
