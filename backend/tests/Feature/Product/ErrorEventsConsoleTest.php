<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Quality\Support\Commands;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `php artisan product:errors` y `product:errors:prune` (Anexo C, RF-PD-15,
 * decision 10 de la ficha 5.12).
 *
 * ## Por que la consola tiene su propia prueba
 *
 * Por lo mismo que `product:doctor` y `product:export-all`: **el panel puede ser
 * justamente lo que no funciona**. Un producto cuyo unico diagnostico esta
 * detras de la aplicacion que falla no sirve de nada el dia que hace falta.
 *
 * ## Los codigos de salida son la parte contractual
 *
 * `0` ninguno, `1` hay `error`, `2` hay `critical`. Son los de `doctor` a
 * proposito, para que un script de monitorizacion del cliente que ya sabe
 * interpretar «0 bien, 1 mirar, 2 actuar» no tenga que aprender una segunda
 * tabla.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    WorkforceFixtures::site();
});

/** Un grupo abierto en el historico. */
function grupoEnConsola(string $level = 'error', string $source = 'api', ?string $lastSeenAt = null): void
{
    $ahora = now()->toDateTimeString('microsecond');

    DB::table('error_events')->insert([
        'fingerprint' => bin2hex(random_bytes(32)),
        'level' => $level,
        'source' => $source,
        'module' => 'product',
        'message' => 'fallo de '.$source,
        'exception_class' => 'RuntimeException',
        'file' => 'app/Foo.php',
        'line' => 10,
        'context' => '{}',
        'trace_id' => str_repeat('b', 32),
        'app_version' => '2.2.0',
        'occurrences' => 4,
        'first_seen_at' => $lastSeenAt ?? $ahora,
        'last_seen_at' => $lastSeenAt ?? $ahora,
        'created_at' => $ahora,
        'updated_at' => $ahora,
    ]);
}

it('sale 0 y lo dice cuando no hay nada abierto', function (): void {
    [$codigo, $salida] = Commands::run('product:errors --since=24h --lang=es');

    expect($codigo)->toBe(0)
        ->and($salida)->toContain('No hay ningun error abierto');
})->group('RF-PD-15');

it('sale 1 cuando hay errores y 2 cuando hay alguno critico', function (): void {
    grupoEnConsola('error');

    [$soloErrores] = Commands::run('product:errors --since=24h');

    expect($soloErrores)->toBe(1);

    grupoEnConsola('critical', 'scheduler');

    [$conCritico, $salida] = Commands::run('product:errors --since=24h --lang=es');

    expect($conCritico)->toBe(2)
        // Los criticos primero: hacer recorrer treinta lineas de avisos hasta el
        // que impide fichar es una forma de esconderlo.
        ->and(strpos($salida, 'CRITICO'))->toBeLessThan((int) strpos($salida, 'fallo de api'))
        ->and($salida)->toContain('Traza:')
        ->and($salida)->toContain('Que hacer:');
})->group('RF-PD-15');

it('sale 2 aunque el critico este mas alla de la primera pagina', function (): void {
    /*
     * EL DEFECTO QUE LA REVISION ENCONTRO. El comando enseña como mucho cien
     * grupos, y el codigo de salida se deducia de esas cien filas: una
     * instalacion con ciento veinte grupos de nivel `error` y **un critico en la
     * posicion ciento uno** salia `1`, y el script de monitorizacion del cliente
     * no se enteraba de lo unico que tenia que mirar.
     *
     * El critico se pone con el `last_seen_at` MAS ANTIGUO para que caiga al
     * final del orden descendente, que es donde no llega la pagina.
     */
    foreach (range(1, 105) as $indice) {
        grupoEnConsola('error', 'api', now()->subMinutes($indice)->toDateTimeString('microsecond'));
    }

    grupoEnConsola('critical', 'worker', now()->subHours(3)->toDateTimeString('microsecond'));

    [$codigo] = Commands::run('product:errors --since=24h');

    expect($codigo)->toBe(2);
})->group('RF-PD-15');

it('respeta los filtros al calcular el codigo de salida', function (): void {
    // Quien ejecuta `--source=worker --since=1h` esta preguntando por eso: un
    // codigo que ignorara los filtros haria inutil el comando dentro de un
    // script.
    grupoEnConsola('critical', 'scheduler');

    [$conFiltro] = Commands::run('product:errors --since=24h --source=worker');
    [$sinFiltro] = Commands::run('product:errors --since=24h');

    expect($conFiltro)->toBe(0)
        ->and($sinFiltro)->toBe(2);
})->group('RF-PD-15');

it('el periodo acota de verdad', function (): void {
    grupoEnConsola('critical', 'worker', now()->subDays(10)->toDateTimeString('microsecond'));

    [$ultimoDia] = Commands::run('product:errors --since=24h');
    [$ultimoMes] = Commands::run('product:errors --since=4w');

    expect($ultimoDia)->toBe(0)
        ->and($ultimoMes)->toBe(2);
})->group('RF-PD-15');

it('devuelve JSON para que lo lea un script, con el mismo codigo de salida', function (): void {
    grupoEnConsola('critical', 'worker');

    [$codigo, $salida] = Commands::run('product:errors --since=24h --json');

    /** @var array{open_critical: int, total: int, since: string, groups: list<array<string, mixed>>} $documento */
    $documento = json_decode(trim($salida), true, 512, JSON_THROW_ON_ERROR);

    expect($codigo)->toBe(2)
        ->and($documento['open_critical'])->toBe(1)
        ->and($documento['total'])->toBe(1)
        ->and($documento['groups'][0]['level'])->toBe('critical')
        ->and($documento['groups'][0]['occurrences'])->toBe(4)
        // UTC siempre en la consola (regla dura 3): es como esta almacenado y
        // como esta el log tecnico contra el que se compara.
        ->and($documento['since'])->toEndWith('Z');
})->group('RF-PD-15');

it('traduce el informe al idioma pedido', function (): void {
    [, $ingles] = Commands::run('product:errors --since=24h --lang=en');

    expect($ingles)->toContain('No open errors in this period.');
})->group('RF-PD-15');

it('sale 2 y explica que hacer cuando el periodo no se entiende', function (): void {
    // `2` y no `1`: una peticion que no se entiende no puede confundirse con «no
    // hay errores», que es lo que un `0` le diria a un script.
    [$codigo, $salida] = Commands::run('product:errors --since=24 --lang=es');

    expect($codigo)->toBe(2)
        ->and($salida)->toContain('30m, 24h, 7d o 2w');
})->group('RF-PD-15');

it('rechaza un nivel o un origen que no existen y enumera los que si', function (): void {
    [$nivel, $salidaNivel] = Commands::run('product:errors --level=urgente --lang=es');
    [$origen, $salidaOrigen] = Commands::run('product:errors --source=nube --lang=es');

    expect($nivel)->toBe(2)
        ->and($salidaNivel)->toContain('error, critical')
        ->and($origen)->toBe(2)
        ->and($salidaOrigen)->toContain('api, worker, scheduler, console, kiosk, admin, portal');
})->group('RF-PD-15');

it('la purga cuenta en seco y borra por la ultima vez que se vio', function (): void {
    Config::set('compliance.retention.error_history_days', 90);

    grupoEnConsola('error', 'api', now()->subDays(120)->toDateTimeString('microsecond'));
    grupoEnConsola('error', 'api', now()->subDays(30)->toDateTimeString('microsecond'));

    [$seco, $salidaSeco] = Commands::run('product:errors:prune --dry-run --lang=es');

    expect($seco)->toBe(0)
        ->and($salidaSeco)->toContain('Se borrarian 1 grupo')
        // Un ensayo que no borra no puede haber borrado nada.
        ->and(DB::table('error_events')->count())->toBe(2);

    [$real, $salidaReal] = Commands::run('product:errors:prune --lang=es');

    expect($real)->toBe(0)
        ->and($salidaReal)->toContain('Borrados 1 grupo')
        ->and(DB::table('error_events')->count())->toBe(1);
})->group('RF-PD-15', 'RL-11');

it('la purga sale 0 cuando no hay nada que borrar', function (): void {
    // Una tarea programada que saliera distinto de cero por no tener trabajo
    // llenaria el monitor del cliente de falsas alarmas cada madrugada.
    [$codigo] = Commands::run('product:errors:prune');

    expect($codigo)->toBe(0);
})->group('RF-PD-15', 'RL-11');
