<?php

declare(strict_types=1);

use App\Modules\Shared\Application\Port\Clock;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Quality\Support\Commands;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Product\DataExports;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Time\FixedClock;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `php artisan product:export-all` (RF-PD-14, RL-20, Anexo C).
 *
 * ## Por que la consola tiene su propia prueba
 *
 * Porque es el camino que sigue funcionando cuando el panel no. Tres casos
 * reales: el fichero es demasiado grande para descargarlo por el navegador,
 * nadie puede entrar al panel (contraseña perdida, segundo factor con el movil
 * roto), o el cliente quiere automatizar una copia trimestral desde su `cron`.
 * RL-20 no puede depender de que la puerta de delante este abierta.
 *
 * ## Lo que se comprueba
 *
 * Que **registra su fila igual que el panel** —con `requested_via = console` y
 * sin usuario—, que imprime lo que quien esta delante de la terminal necesita
 * —ruta, tamaño, huella, recuentos y como sacar el fichero del contenedor—, que
 * `--purge` purga y no genera, y que dos ejecuciones simultaneas no producen dos
 * exportaciones.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(Clock::class, FixedClock::at('2026-09-08 10:00:00'));
    WorkforceFixtures::site();
    LicenseKeys::install();

    DataExports::useTemporaryPath();
});

afterEach(function (): void {
    DataExports::cleanUpTemporaryPath();
});

it('genera el ZIP, registra su fila y dice como sacarlo del contenedor', function (): void {
    [$codigo, $salida] = Commands::run('product:export-all');

    expect($codigo)->toBe(0, $salida);

    $fila = DB::table('data_exports')->orderByDesc('id')->first();

    expect($fila?->status)->toBe('completed')
        // Sin usuario: por consola no hay sesion que atribuir, y `requested_via`
        // es lo que dice la verdad de por donde se pidio.
        ->and($fila?->requested_via)->toBe('console')
        ->and($fila?->requested_by_user_id)->toBeNull()
        ->and($fila?->sha256)->toBeString()
        ->and($fila?->expires_at)->not->toBeNull()
        ->and(is_file((string) $fila?->file_path))->toBeTrue();

    // Lo que quien esta delante de la terminal necesita para terminar el
    // trabajo. El fabricante no tiene acceso a este servidor (ADR-016): si el
    // texto no dice que hacer, la unica salida es una llamada de telefono.
    expect($salida)->toContain('Exportacion integra generada')
        ->and($salida)->toContain((string) $fila?->file_path)
        ->and($salida)->toContain((string) $fila?->sha256)
        ->and($salida)->toContain('docker compose cp app:')
        ->and($salida)->toContain('sha256sum')
        // Y el aviso: el fichero lleva todos los datos personales de la
        // plantilla.
        ->and($salida)->toContain('TODOS los datos personales');

    // Los recuentos por fichero, que es lo que permite comprobar de un vistazo
    // que la copia esta completa.
    expect($salida)->toContain('employees')
        ->and($salida)->toContain('shift_entries')
        ->and($salida)->toContain('audit_log');
})->group('RF-PD-14', 'RL-20');

it('la exportacion de consola aparece en la lista del panel', function (): void {
    // No es un fichero paralelo que nadie ve: se registra igual y se puede
    // descargar desde el panel, que es lo que hace que las dos vias sean la
    // misma funcionalidad.
    Commands::run('product:export-all');

    $fila = DB::table('data_exports')->orderByDesc('id')->first();

    expect($fila)->not->toBeNull();

    // Y deja sus dos asientos, como cualquier otra (RS-05).
    expect(DB::table('audit_log')->where('action', 'data_export.requested')->count())->toBe(1)
        ->and(DB::table('audit_log')->where('action', 'data_export.generated')->count())->toBe(1);
})->group('RF-PD-14', 'RS-05');

it('sale con 2 y no genera nada si ya hay una en curso', function (): void {
    // Codigo propio y no `1`: «espera» y «algo ha fallado» son dos cosas
    // distintas para quien escribe un script alrededor del comando.
    DataExports::inProgress('running');

    [$codigo, $salida] = Commands::run('product:export-all');

    expect($codigo)->toBe(2)
        ->and($salida)->toContain('Ya hay una exportacion integra en curso')
        // Y no se ha creado ninguna fila de mas.
        ->and(DB::table('data_exports')->count())->toBe(1);
})->group('RF-PD-14');

it('con --purge borra los ficheros caducados, marca las filas y no genera nada', function (): void {
    $vencida = DataExports::completed(expiresAt: new DateTimeImmutable('2026-09-01T00:00:00Z'));
    $vigente = DataExports::completed(expiresAt: new DateTimeImmutable('2036-01-01T00:00:00Z'));

    [$codigo, $salida] = Commands::run('product:export-all --purge');

    expect($codigo)->toBe(0)
        ->and($salida)->toContain('Purgadas 1 exportaciones integras caducadas')
        ->and(is_file((string) $vencida->filePath))->toBeFalse()
        ->and(is_file((string) $vigente->filePath))->toBeTrue()
        // NO GENERA NADA: siguen siendo dos filas, no tres.
        ->and(DB::table('data_exports')->count())->toBe(2)
        ->and(DB::table('data_exports')->where('uuid', $vencida->uuid)->value('status'))->toBe('purged');
})->group('RF-PD-14', 'RN-13');

it('con --purge y nada que purgar lo dice y sale con 0', function (): void {
    // Es lo que corre cada hora en el planificador: el caso normal es que no haya
    // nada, y ese caso no puede parecer un error.
    DataExports::completed(expiresAt: new DateTimeImmutable('2036-01-01T00:00:00Z'));

    [$codigo, $salida] = Commands::run('product:export-all --purge');

    expect($codigo)->toBe(0)
        ->and($salida)->toContain('No hay ninguna exportacion integra caducada')
        ->and(DB::table('data_exports')->count())->toBe(1);
})->group('RF-PD-14');

it('con --purge libera tambien las exportaciones que se quedaron a medias', function (): void {
    /*
     * La pasada horaria es la red de debajo del bloqueante: si el servidor se
     * paro durante una generacion —lo que hace `docker compose down`, el paso 1
     * de cualquier actualizacion—, nadie llamo a `failed()` ni al caso de uso, y
     * la fila quedo `running` ocupando el turno.
     *
     * Aqui se comprueba que el producto se desbloquea solo, sin que nadie entre
     * por `psql`, y que **lo dice en voz alta**: si eso se repite cada hora, lo
     * que hay que mirar es el trabajador de cola.
     */
    $atascada = DataExports::inProgress('running');

    DB::table('data_exports')->where('uuid', $atascada->uuid)->update([
        'requested_at' => '2026-09-08 08:00:00+00',
        'started_at' => '2026-09-08 08:00:02+00',
    ]);

    // El reloj del `beforeEach` son las 10:00: dos horas despues, por encima del
    // umbral de una hora.
    [$codigo, $salida] = Commands::run('product:export-all --purge');

    expect($codigo)->toBe(0)
        ->and($salida)->toContain('Liberadas 1 exportaciones que se quedaron a medias')
        ->and($salida)->toContain('stale');

    $fila = DB::table('data_exports')->where('uuid', $atascada->uuid)->first();

    expect($fila?->status)->toBe('failed')
        ->and($fila?->failure_reason)->toBe('stale')
        // Nada se borra (regla dura 5): la fila queda con su historia.
        ->and(DB::table('data_exports')->count())->toBe(1);
})->group('RF-PD-14', 'RL-20');

it('con --purge no toca una exportacion que sigue dentro de su plazo', function (): void {
    // La otra mitad: la pasada horaria no puede matar una exportacion grande que
    // lleva veinte minutos escribiendo.
    $enCurso = DataExports::inProgress('running');

    DB::table('data_exports')->where('uuid', $enCurso->uuid)->update([
        'requested_at' => '2026-09-08 09:40:00+00',
        'started_at' => '2026-09-08 09:40:02+00',
    ]);

    [$codigo, $salida] = Commands::run('product:export-all --purge');

    expect($codigo)->toBe(0)
        ->and($salida)->not->toContain('Liberadas')
        ->and(DB::table('data_exports')->where('uuid', $enCurso->uuid)->value('status'))->toBe('running');
})->group('RF-PD-14');
