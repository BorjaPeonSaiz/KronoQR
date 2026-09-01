<?php

declare(strict_types=1);

use App\Modules\Compliance\Application\UseCase\VerifyAuditChain;
use App\Modules\Product\Application\Command\ActivateLicenseCommand;
use App\Modules\Product\Application\UseCase\ActivateLicenseHandler;
use App\Modules\Product\Application\UseCase\DescribeLicenseHandler;
use App\Modules\Product\Domain\Exception\LicenseKeyRejected;
use App\Modules\Product\Domain\ValueObject\LicenseState;
use App\Modules\Product\Domain\ValueObject\LicenseStatus;
use App\Modules\Shared\Application\Port\Clock;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Time\FixedClock;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * La persistencia de la licencia y el asiento de su activacion (RF-PD-04,
 * RL-04, regla dura 6).
 *
 * POR QUE NO PODIA SER UNITARIA: la fila unica de `license`, sus `CHECK`, el
 * `jsonb` de `features` y la cadena de hash de `audit_log` viven en PostgreSQL.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    WorkforceFixtures::site();
    LicenseKeys::install();
    app()->instance(Clock::class, FixedClock::at('2026-06-15T09:00:00'));
});

function activateKey(string $key, ?int $actorUserId = null): LicenseStatus
{
    return app(ActivateLicenseHandler::class)->handle(new ActivateLicenseCommand($key, $actorUserId));
}

/**
 * @return list<object{action: string, actor_type: string, subject_type: string|null, payload: string}>
 */
function licenseAuditEntries(string $action): array
{
    /** @var list<object{action: string, actor_type: string, subject_type: string|null, payload: string}> $rows */
    $rows = DB::table('audit_log')->where('action', $action)->orderBy('id')->get()->all();

    return $rows;
}

it('guarda la clave y su proyeccion legible', function (): void {
    activateKey(LicenseKeys::current()->issue());

    /** @var object{signed_key: string, customer_name: string, plan: string, max_employees: int, max_devices: int, features: string, last_verified_at: string|null} $row */
    $row = DB::table('license')->first();

    expect($row->customer_name)->toBe('Hotel de Pruebas, S.L.')
        ->and($row->plan)->toBe('estandar')
        ->and((int) $row->max_employees)->toBe(50)
        ->and((int) $row->max_devices)->toBe(3)
        ->and(json_decode((string) $row->features, true))->toBe(['advanced_reports', 'realtime_presence'])
        // La clave firmada entera se guarda: es la afirmacion con valor, y es lo
        // unico que permite volver a verificar tras un despliegue o una
        // restauracion de copia.
        ->and($row->signed_key)->toStartWith('KQL1.')
        // ADR-018: se anota cuando se verifico.
        ->and($row->last_verified_at)->not->toBeNull();
})->group('RF-PD-04', 'RL-04');

it('mantiene una sola fila aunque se active muchas veces', function (): void {
    // Una licencia por instalacion, como un centro (ADR-040). Lo garantiza el
    // indice unico sobre una expresion constante, no la buena voluntad del
    // repositorio.
    activateKey(LicenseKeys::current()->issue(['customer_name' => 'Primero']));
    activateKey(LicenseKeys::current()->issue(['customer_name' => 'Segundo']));
    activateKey(LicenseKeys::current()->issue(['customer_name' => 'Tercero']));

    expect(DB::table('license')->count())->toBe(1)
        ->and(DB::table('license')->value('customer_name'))->toBe('Tercero')
        // Y la historia no se pierde: esta en `audit_log`, que si es solo
        // apendice y encadenado.
        ->and(licenseAuditEntries('license.activated'))->toHaveCount(3);
})->group('RF-PD-04', 'RL-04');

it('deja asiento de la activacion con el plan, los limites y la vigencia', function (): void {
    activateKey(LicenseKeys::current()->issue());

    $entries = licenseAuditEntries('license.activated');
    expect($entries)->toHaveCount(1);

    /** @var array<string, mixed> $payload */
    $payload = json_decode($entries[0]->payload, true);

    expect($entries[0]->subject_type)->toBe('license')
        ->and($payload['customer_name'])->toBe('Hotel de Pruebas, S.L.')
        ->and($payload['plan'])->toBe('estandar')
        ->and($payload['max_employees'])->toBe(50)
        ->and($payload['max_devices'])->toBe(3)
        ->and($payload['features'])->toBe(['advanced_reports', 'realtime_presence'])
        ->and($payload['valid_until'])->toStartWith('2026-12-31')
        ->and($payload['resulting_state'])->toBe('valid')
        // La huella y NO la clave: el asiento acaba en el trail y en su
        // exportacion, y no hay razon para difundir ahi la clave entera.
        ->and($payload['key_fingerprint'])->toMatch('/^[0-9a-f]{12}$/')
        ->and($entries[0]->payload)->not->toContain('KQL1.');
})->group('RF-PD-04', 'RL-04');

it('deja la cadena de auditoria integra', function (): void {
    activateKey(LicenseKeys::current()->issue());
    activateKey(LicenseKeys::current()->issue(['plan' => 'grande']));

    expect(app(VerifyAuditChain::class)->handle(1000)->isIntact())->toBeTrue();
})->group('RF-PD-04', 'RL-04');

it('activa una clave ya caducada y lo deja escrito', function (): void {
    // Un hotel que renueva con retraso recibe una clave cuya vigencia empezo el
    // dia 1. Rechazarla le obligaria a pedir otra por una diferencia de
    // calendario.
    $status = activateKey(LicenseKeys::current()->issue([
        'valid_from' => '2025-01-01T00:00:00Z',
        'valid_until' => '2025-12-31T23:59:59Z',
    ]));

    expect($status->state)->toBe(LicenseState::Expired)
        ->and(DB::table('license')->count())->toBe(1);

    /** @var array<string, mixed> $payload */
    $payload = json_decode(licenseAuditEntries('license.activated')[0]->payload, true);

    // Que conste que se activo asi, y no que caduco despues.
    expect($payload['resulting_state'])->toBe('expired');
})->group('RF-PD-04', 'RL-04');

it('una clave rechazada no toca la fila ni deja asiento', function (): void {
    activateKey(LicenseKeys::current()->issue(['customer_name' => 'La buena']));

    $other = LicenseKeys::mint();

    expect(static fn () => activateKey($other->issue(['customer_name' => 'La mala'])))
        ->toThrow(LicenseKeyRejected::class);

    expect(DB::table('license')->value('customer_name'))->toBe('La buena')
        ->and(licenseAuditEntries('license.activated'))->toHaveCount(1);
})->group('RF-PD-04', 'RL-04');

it('normaliza los espacios y los saltos de linea de una clave pegada de un correo', function (): void {
    $key = LicenseKeys::current()->issue();
    $mangled = '  '.substr($key, 0, 40)."\n  ".substr($key, 40)."\r\n";

    $status = activateKey($mangled);

    expect($status->state)->toBe(LicenseState::Valid)
        ->and(DB::table('license')->value('signed_key'))->toBe($key);
})->group('RF-PD-04');

it('anota la ultima verificacion correcta al consultar el estado', function (): void {
    activateKey(LicenseKeys::current()->issue());

    DB::table('license')->update(['last_verified_at' => null]);

    app(DescribeLicenseHandler::class)->handle();

    expect(DB::table('license')->value('last_verified_at'))->not->toBeNull();
})->group('RF-PD-04');

it('sin fila, el estado es «sin licencia» y nada falla', function (): void {
    $overview = app(DescribeLicenseHandler::class)->handle();

    expect($overview->status->state)->toBe(LicenseState::Absent)
        ->and($overview->status->license)->toBeNull()
        // Las cifras reales se enseñan igual: son utiles por si solas.
        ->and($overview->usage[0]->contracted)->toBeNull()
        ->and($overview->usage[0]->actual)->toBeGreaterThanOrEqual(0);
})->group('RF-PD-04');

it('una fila manipulada a mano degrada, no rompe', function (): void {
    // Editar `valid_until` con `psql` deja la firma sin cuadrar. El estado pasa
    // a `unverifiable` y el sistema sigue funcionando: es el comportamiento
    // correcto para un control COMERCIAL (doc 01 §8.1), que no se refuerza a
    // costa de bloquear el registro.
    activateKey(LicenseKeys::current()->issue());

    DB::table('license')->update(['signed_key' => 'KQL1.manipulada.aqui']);

    $overview = app(DescribeLicenseHandler::class)->handle();

    expect($overview->status->state)->toBe(LicenseState::Unverifiable)
        ->and($overview->status->rejection?->value)->toBe('malformed');
})->group('RF-PD-04');

it('la huella del asiento y la del recurso son la misma', function (): void {
    // Es el valor con el que alguien confirma POR TELEFONO que la clave activada
    // es la que se envio: si el asiento y la pantalla dieran huellas distintas,
    // esa conversacion no llevaria a ninguna parte. Se calcula en un solo sitio
    // —`StoredLicense::fingerprintOf()`— y esto lo ata.
    $key = LicenseKeys::current()->issue();
    activateKey($key);

    /** @var array<string, mixed> $payload */
    $payload = json_decode(licenseAuditEntries('license.activated')[0]->payload, true);

    $overview = app(DescribeLicenseHandler::class)->handle();

    expect($payload['key_fingerprint'])
        ->toBe($overview->stored?->fingerprint())
        ->toBe(substr(hash('sha256', $key), 0, 12));
})->group('RF-PD-04', 'RL-04');
