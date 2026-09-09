<?php

declare(strict_types=1);

use App\Modules\Product\Application\UseCase\RunDoctorHandler;
use App\Modules\Product\Domain\ValueObject\DoctorCheck;
use App\Modules\Product\Domain\ValueObject\DoctorStatus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `product:doctor` avisa cuando el historico de errores se acerca a su techo
 * (RF-PD-13, RF-PD-15, decision 14).
 *
 * ## Por que hace falta esta sonda
 *
 * El techo por origen degrada **en silencio desde la pantalla**: al llegar, los
 * errores nuevos de ese origen dejan de abrir fila y se cuentan juntos en un
 * grupo de desbordamiento. El panel sigue listando grupos y nada dice que se
 * este perdiendo detalle. `doctor` es donde el IT del cliente mira cuando algo
 * va raro, y aqui se lo dice con la cifra y con que hacer.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    WorkforceFixtures::site();
});

/** `$cuantos` grupos abiertos de un origen. */
function gruposAbiertosDe(string $source, int $cuantos): void
{
    $ahora = now()->toDateTimeString('microsecond');
    $filas = [];

    foreach (range(1, $cuantos) as $indice) {
        $filas[] = [
            'fingerprint' => bin2hex(random_bytes(32)),
            'level' => 'error',
            'source' => $source,
            'message' => 'grupo abierto',
            'context' => '{}',
            'app_version' => '2.2.0',
            'occurrences' => 1,
            'first_seen_at' => $ahora,
            'last_seen_at' => $ahora,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ];
    }

    DB::table('error_events')->insert($filas);
}

/** La comprobacion del historico dentro del informe de `doctor`. */
function comprobacionDelHistorico(): DoctorCheck
{
    $informe = app(RunDoctorHandler::class)->handle('es');

    $encontrada = array_values(array_filter(
        $informe->checks,
        static fn (DoctorCheck $check): bool => $check->id === 'app.error_history',
    ));

    expect($encontrada)->not->toBeEmpty('`doctor` no incluye la comprobacion del historico de errores.');

    return $encontrada[0];
}

it('sale en verde con la tabla vacia y dice el tamano', function (): void {
    $comprobacion = comprobacionDelHistorico();

    expect($comprobacion->status)->toBe(DoctorStatus::Ok)
        ->and($comprobacion->summary)->toContain('tamano normal');
})->group('RF-PD-15', 'RF-PD-13');

it('avisa al acercarse al techo, con el origen y la cifra', function (): void {
    Config::set('product.errors_max_open_groups_per_source', 10);

    gruposAbiertosDe('kiosk', 8);

    $comprobacion = comprobacionDelHistorico();

    expect($comprobacion->status)->toBe(DoctorStatus::Warning)
        ->and($comprobacion->summary)->toContain('kiosk')
        ->and($comprobacion->summary)->toContain('8')
        // Y el «que hacer», que es lo que evita la llamada de telefono
        // (ADR-016): el fabricante no tiene acceso a este servidor.
        ->and($comprobacion->fix)->toContain('product:errors');
})->group('RF-PD-15', 'RF-PD-13');

it('falla al llegar al techo, porque a partir de ahi se pierde detalle', function (): void {
    Config::set('product.errors_max_open_groups_per_source', 5);

    gruposAbiertosDe('worker', 5);

    $comprobacion = comprobacionDelHistorico();

    expect($comprobacion->status)->toBe(DoctorStatus::Failure)
        ->and($comprobacion->summary)->toContain('worker')
        ->and($comprobacion->fix)->toContain('PRODUCT_ERRORS_MAX_OPEN_GROUPS_PER_SOURCE');
})->group('RF-PD-15', 'RF-PD-13');

it('el techo se mira por origen y no en total', function (): void {
    // Cuatro origenes con la mitad del techo cada uno no son un problema: lo que
    // degrada es que UNO llegue, porque el desbordamiento es por origen.
    Config::set('product.errors_max_open_groups_per_source', 10);

    foreach (['api', 'worker', 'kiosk', 'admin'] as $source) {
        gruposAbiertosDe($source, 4);
    }

    expect(comprobacionDelHistorico()->status)->toBe(DoctorStatus::Ok);
})->group('RF-PD-15', 'RF-PD-13');

it('el techo desactivado no produce ni aviso ni fallo', function (): void {
    // A 0 se desactiva, y entonces no hay nada de lo que avisar por este eje.
    Config::set('product.errors_max_open_groups_per_source', 0);

    gruposAbiertosDe('kiosk', 40);

    expect(comprobacionDelHistorico()->status)->toBe(DoctorStatus::Ok);
})->group('RF-PD-15', 'RF-PD-13');
