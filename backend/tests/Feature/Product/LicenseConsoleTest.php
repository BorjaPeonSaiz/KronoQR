<?php

declare(strict_types=1);

use App\Modules\Shared\Application\Port\Clock;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Time\FixedClock;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `license:show` y `license:activate` (Anexo C del doc 01, RF-PD-04, ADR-028).
 *
 * Es lo que ejecuta la persona de informatica del hotel a las nueve de la
 * mañana, con un aviso en pantalla, y lo que ejecutara `doctor` en la tarea 5.9.
 * Lo que se prueba aqui no es el formato bonito: es que **diga que hacer**, que
 * **no imprima la clave**, que **nunca sea un error fatal** y que su codigo de
 * salida sea util para una tarea programada.
 *
 * **Se usa `Artisan::call()` y no `$this->artisan()`** porque hay que afirmar
 * sobre la salida COMPLETA —que una cadena no aparezca en ninguna parte, por
 * ejemplo— y no linea a linea.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    WorkforceFixtures::site();
    LicenseKeys::install();
    app()->instance(Clock::class, FixedClock::at('2026-06-15 09:00:00'));
});

/**
 * Ejecuta un comando y devuelve su codigo de salida y su salida completa.
 *
 * @param  array<string, mixed>  $parameters
 * @return array{code: int, output: string}
 */
function runLicenseCommand(string $command, array $parameters = []): array
{
    $code = Artisan::call($command, $parameters);

    return ['code' => $code, 'output' => Artisan::output()];
}

it('license:show sin clave sale degradado y NUNCA con un error fatal', function (): void {
    // La verificacion literal de la ficha: «sin clave: estado degradado, nunca
    // error fatal».
    $result = runLicenseCommand('license:show');

    expect($result['output'])->toContain('SIN LICENCIA (el sistema sigue funcionando)')
        // La seccion que importa a las nueve de la mañana.
        ->and($result['output'])->toContain('Lo que NUNCA depende de la licencia')
        ->and($result['output'])->toContain('exportacion para la Inspeccion')
        // Y que hacer.
        ->and($result['output'])->toContain('php artisan license:activate')
        // `1` significa «hay algo que mirar», no «esto se ha roto».
        ->and($result['code'])->toBe(1);
})->group('RF-PD-04');

it('license:show imprime contratado frente a real para las dos magnitudes', function (): void {
    // ADR-028: la cifra que el fabricante pide en una revision comercial. Son
    // dos y no tres porque `max_sites` no existe (ADR-040).
    expect(runLicenseCommand('license:activate', [
        'key' => LicenseKeys::current()->issue(['max_employees' => 2, 'max_devices' => 1]),
    ])['code'])->toBe(0);

    $siteId = WorkforceFixtures::onlySiteId();
    WorkforceFixtures::employee($siteId);
    WorkforceFixtures::employee($siteId);
    WorkforceFixtures::employee($siteId);

    $result = runLicenseCommand('license:show');

    expect($result['output'])->toContain('Personas en plantilla')
        ->and($result['output'])->toContain('Quioscos activos')
        ->and($result['output'])->toContain('contratado: 2')
        ->and($result['output'])->toContain('real: 3')
        ->and($result['output'])->toContain('SUPERADO en 1')
        // Y lo que el exceso NO significa, dicho con todas las letras.
        ->and($result['output'])->toContain('NO ha impedido ningun alta')
        ->and($result['code'])->toBe(1);
})->group('RF-PD-04', 'RF-PD-05');

it('license:show sale 0 solo con la licencia vigente y sin excesos', function (): void {
    runLicenseCommand('license:activate', ['key' => LicenseKeys::current()->issue()]);

    $result = runLicenseCommand('license:show');

    expect($result['output'])->toContain('VIGENTE')
        ->and($result['output'])->toContain('Hotel de Pruebas, S.L.')
        ->and($result['code'])->toBe(0);
})->group('RF-PD-04');

it('license:show explica que esta degradado y desde cuando', function (): void {
    expect(runLicenseCommand('license:activate', ['key' => LicenseKeys::current()->issue([
        'valid_from' => '2025-01-01T00:00:00Z',
        'valid_until' => '2025-12-31T23:59:59Z',
    ])])['code'])->toBe(1);

    $result = runLicenseCommand('license:show');

    expect($result['output'])->toContain('CADUCADA (el sistema sigue funcionando)')
        ->and($result['output'])->toContain('Caduco hace 165 dia(s).')
        ->and($result['output'])->toContain('Informes por periodo')
        ->and($result['output'])->toContain('no disponible por licencia caducada (desde el 31/12/2025)')
        // La degradacion parcial de ADR-011: la presencia no se apaga, sondea.
        ->and($result['output'])->toContain('pasa a actualizarse por sondeo')
        ->and($result['output'])->toContain('Pide la renovacion al proveedor')
        ->and($result['code'])->toBe(1);
})->group('RF-PD-04', 'RF-PD-05');

it('license:show no imprime la clave, solo su huella', function (): void {
    // §3.5, secretos: la clave no es un secreto pero lleva el nombre del cliente
    // y quien ejecuta esto suele estar pegando la salida en un ticket.
    $key = LicenseKeys::current()->issue();
    runLicenseCommand('license:activate', ['key' => $key]);

    $output = runLicenseCommand('license:show')['output'];

    expect($output)->not->toContain($key)
        ->and($output)->not->toContain('KQL1.')
        ->and($output)->toMatch('/huella [0-9a-f]{12}/');
})->group('RF-PD-04');

it('license:activate sin clave ni entorno no activa nada y dice como se usa', function (): void {
    $result = runLicenseCommand('license:activate');

    expect($result['output'])->toContain('LICENSE_KEY esta vacia')
        ->and($result['output'])->toContain('php artisan license:activate "KQL1...."')
        ->and($result['code'])->toBe(2)
        ->and(DB::table('license')->count())->toBe(0);
})->group('RF-PD-04');

it('license:activate toma la clave del entorno cuando no se le pasa', function (): void {
    // Es la unica linea del producto que lee `LICENSE_KEY`, y es como la
    // llamara el instalador de la tarea 5.4 (decision de la 5.1: manda la base
    // de datos).
    config()->set('license.bootstrap_key', LicenseKeys::current()->issue());

    expect(runLicenseCommand('license:activate')['code'])->toBe(0)
        ->and(DB::table('license')->count())->toBe(1);
})->group('RF-PD-04');

it('license:activate distingue no activar de activar algo no vigente', function (string $key, int $exitCode, string $fragment): void {
    // `2` es «no se activo nada, consigue otra clave»; `1` es «la clave es
    // autentica y hay que hablar de fechas». La accion siguiente es distinta.
    $result = runLicenseCommand('license:activate', ['key' => $key]);

    expect($result['output'])->toContain($fragment)
        ->and($result['code'])->toBe($exitCode);
})->with([
    'clave a medias' => ['KQL1.rota', 2, 'La clave esta incompleta o cortada'],
    'otro emisor' => [fn () => LicenseKeys::mint()->issue(), 2, 'no la emitio el fabricante'],
    'sin clave publica' => [
        function () {
            config()->set('license.public_key', '');

            return LicenseKeys::current()->issue();
        },
        2,
        'no lleva la clave publica del fabricante',
    ],
    'clave caducada: se guarda igual' => [
        fn () => LicenseKeys::current()->issue([
            'valid_from' => '2025-01-01T00:00:00Z',
            'valid_until' => '2025-12-31T23:59:59Z',
        ]),
        1,
        'YA ESTA CADUCADA',
    ],
])->group('RF-PD-04');

it('license:activate no imprime la clave ni al rechazarla', function (): void {
    $key = LicenseKeys::mint()->issue();

    $result = runLicenseCommand('license:activate', ['key' => $key]);

    expect($result['output'])->not->toContain($key)
        ->and($result['code'])->toBe(2);
})->group('RF-PD-04');

it('una clave rechazada deja la anterior intacta y lo dice', function (): void {
    runLicenseCommand('license:activate', ['key' => LicenseKeys::current()->issue(['customer_name' => 'La buena'])]);

    $result = runLicenseCommand('license:activate', ['key' => LicenseKeys::mint()->issue()]);

    expect($result['output'])->toContain('La licencia anterior sigue como estaba')
        ->and($result['output'])->toContain('Nada de esto afecta al fichaje')
        ->and($result['code'])->toBe(2)
        ->and(DB::table('license')->value('customer_name'))->toBe('La buena');
})->group('RF-PD-04');
