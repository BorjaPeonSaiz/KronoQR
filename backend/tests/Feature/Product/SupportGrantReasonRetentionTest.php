<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Product\SupportGrants;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * El motivo de una concesion de soporte (tarea 11.3 C; RGPD art. 5.1.c y
 * 5.1.e, RL-11, RL-15, ADR-020):
 *
 *   - no admite datos personales con forma (correo, DNI o NIE, telefono): `422`
 *     en `errors.reason` por la API y salida 1 por consola, sin crear nada;
 *   - se vacia de la fila pasados `PRODUCT_SUPPORT_GRANT_REASON_RETENTION_DAYS`
 *     (90, minimo 30) desde que la concesion se cerro, y la concesion se sigue
 *     listando con `reason: null` y su `reason_purged_at`;
 *   - el asiento `support_grant.granted` conserva el motivo.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
    WorkforceFixtures::site();
    LicenseKeys::install();
    FrozenTime::at('2026-10-21 03:45:00');
});

/** Una concesion cerrada hace `$dias` dias: caducada, o revocada si se pide. */
function concesionCerradaHace(int $dias, bool $revocada = false, string $motivo = 'Incidencia #123'): string
{
    $uuid = SupportGrants::issue(reason: $motivo)->grant->uuid;
    $cierre = now()->subDays($dias);

    DB::table('support_grants')->where('uuid', $uuid)->update($revocada
        ? ['granted_at' => $cierre->copy()->subHour(), 'expires_at' => $cierre->copy()->addHours(23), 'revoked_at' => $cierre]
        : ['granted_at' => $cierre->copy()->subDay(), 'expires_at' => $cierre]);

    return $uuid;
}

function motivoDe(string $uuid): ?string
{
    $motivo = DB::table('support_grants')->where('uuid', $uuid)->value('reason');

    return \is_string($motivo) ? $motivo : null;
}

it('rechaza por la API un motivo con un dato personal, en su campo y sin crear nada', function (string $motivo): void {
    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN)))
        ->post('/api/v1/support/grants', ['reason' => $motivo])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);

    expect(DB::table('support_grants')->count())->toBe(0);
})->with([
    'correo' => ['Revisar a lucia.ferrer@hotel.example'],
    'DNI' => ['El de 12345678Z no ficha'],
    'NIE' => ['La persona X1234567L no aparece'],
    'telefono' => ['Llamar al 612 345 678'],
])->group('RL-15', 'RF-PD-11');

it('admite un motivo que señala a la persona por su uuid', function (): void {
    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN)))
        ->post('/api/v1/support/grants', ['reason' => 'Incidencia #7: 0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90 no ficha'])
        ->assertValidResponse(201);
})->group('RL-15', 'RF-PD-11');

it('rechaza por consola un motivo con un dato personal, dice por que y recuerda la pauta', function (): void {
    ManagementUsers::withRole(UserRole::ADMIN);
    app()->setLocale('es');

    $code = Artisan::call('support:grant', ['--reason' => 'Llamar al 612345678']);
    $salida = Artisan::output();

    expect($code)->toBe(1)
        ->and($salida)->toContain('parece llevar un dato personal')
        ->and($salida)->toContain('usa su uuid')
        ->and(DB::table('support_grants')->count())->toBe(0);
})->group('RL-15', 'RF-PD-11');

it('vacia el motivo solo pasado el plazo desde el cierre, y conserva el resto', function (): void {
    $vencida = concesionCerradaHace(91);
    $revocadaVencida = concesionCerradaHace(100, revocada: true);
    $dentroDelPlazo = concesionCerradaHace(89);
    $vigente = SupportGrants::issue()->grant->uuid;

    expect(Artisan::call('product:support-grants:prune-reasons'))->toBe(0)
        ->and(Artisan::output())->toContain('2');

    expect(motivoDe($vencida))->toBeNull()
        ->and(motivoDe($revocadaVencida))->toBeNull()
        ->and(motivoDe($dentroDelPlazo))->toBe('Incidencia #123')
        ->and(motivoDe($vigente))->toBe('Incidencia #123 de prueba')
        ->and(DB::table('support_grants')->where('uuid', $vencida)->value('reason_purged_at'))->not->toBeNull()
        ->and(DB::table('support_grants')->where('uuid', $dentroDelPlazo)->value('reason_purged_at'))->toBeNull()
        // Nada se borra (regla dura 5): las cuatro siguen ahi.
        ->and(DB::table('support_grants')->count())->toBe(4);

    // Repetirlo no toca nada mas.
    expect(Artisan::call('product:support-grants:prune-reasons'))->toBe(0)
        ->and(DB::table('support_grants')->whereNull('reason')->count())->toBe(2);
})->group('RL-11', 'RF-PD-11');

it('no baja nunca de 30 dias aunque el plazo configurado sea menor', function (): void {
    Config::set('product.support_grant_reason_retention_days', 5);

    $hace10 = concesionCerradaHace(10);
    $hace31 = concesionCerradaHace(31);

    Artisan::call('product:support-grants:prune-reasons');

    expect(motivoDe($hace10))->toBe('Incidencia #123')
        ->and(motivoDe($hace31))->toBeNull();
})->group('RL-11', 'RF-PD-11');

it('la simulacion cuenta y no vacia nada', function (): void {
    $vencida = concesionCerradaHace(91, motivo: 'Incidencia secreta #9');

    expect(Artisan::call('product:support-grants:prune-reasons', ['--dry-run' => true]))->toBe(0);

    $salida = Artisan::output();

    expect($salida)->toContain('1')
        ->and($salida)->not->toContain('secreta')
        ->and(motivoDe($vencida))->toBe('Incidencia secreta #9');
})->group('RL-11', 'RF-PD-11');

it('la lista sigue enseñando la concesion vaciada, con la forma del contrato', function (): void {
    $vencida = concesionCerradaHace(91);
    Artisan::call('product:support-grants:prune-reasons');

    $respuesta = Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN)))
        ->get('/api/v1/support/grants')
        ->assertValidResponse(200);

    $fila = collect((array) $respuesta->json('data'))->firstWhere('uuid', $vencida);

    expect($fila)->toBeArray()
        ->and($fila)->toHaveKey('reason')
        ->and($fila['reason'])->toBeNull()
        ->and($fila['reason_purged_at'] ?? null)->toBe('2026-10-21T03:45:00.000000Z');
})->group('RL-11', 'RF-PD-11', 'RS-05');

it('el asiento de la concesion conserva el motivo vaciado de la fila', function (): void {
    $vencida = concesionCerradaHace(91, motivo: 'Incidencia #4242');
    Artisan::call('product:support-grants:prune-reasons');

    expect(motivoDe($vencida))->toBeNull()
        ->and(DB::table('audit_log')->where('action', 'support_grant.granted')->where('payload->reason', 'Incidencia #4242')->count())
        ->toBe(1);
})->group('RL-11', 'RL-04', 'RF-PD-11');

it('deniega listar las concesiones a quien no es admin', function (UserRole $rol): void {
    Auth::forgetGuards();

    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole($rol)))
        ->get('/api/v1/support/grants')
        ->assertStatus(403);
})->with([
    'rrhh' => [UserRole::RRHH],
    'auditor' => [UserRole::AUDITOR],
    'responsable' => [UserRole::RESPONSABLE_DEPARTAMENTO],
])->group('RS-05', 'RF-PD-11');

it('programa el vaciado cada dia a las 03:45 UTC, en segundo plano y sin solaparse', function (): void {
    $eventos = array_values(array_filter(
        app(Schedule::class)->events(),
        static fn (Event $event): bool => str_contains((string) $event->command, 'product:support-grants:prune-reasons'),
    ));

    expect($eventos)->toHaveCount(1)
        ->and($eventos[0]->expression)->toBe('45 3 * * *')
        ->and($eventos[0]->withoutOverlapping)->toBeTrue()
        ->and($eventos[0]->runInBackground)->toBeTrue();
})->group('RL-11');
