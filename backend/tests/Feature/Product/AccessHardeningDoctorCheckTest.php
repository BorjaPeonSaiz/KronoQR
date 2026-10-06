<?php

declare(strict_types=1);

use App\Modules\Product\Application\UseCase\RunDoctorHandler;
use App\Modules\Product\Domain\ValueObject\DoctorCheck;
use App\Modules\Product\Domain\ValueObject\DoctorStatus;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Workforce\EmployeePins;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `product:doctor` dice como de cerrada esta la puerta del portal frente a la
 * red que el cliente ha decidido (ADR-050 §1 y §3, RF-ID-09, RS-12).
 *
 * ## Que se fija
 *
 *  - Portal accesible desde internet con PIN de 6 cifras = AVISO que recomienda
 *    8. Nunca fallo: abrir el portal y elegir la longitud son decisiones del
 *    cliente, y un `2` abortaria `update.sh`.
 *  - Con el ajuste en 8, el NUMERO de personas en alta que conservan un PIN de
 *    6, para que RRHH sepa cuantos restablecimientos faltan. Nunca quienes
 *    (regla dura 21): el informe viaja en el paquete de diagnostico.
 *
 * Depende de `employees.pin_length` (migracion de ADR-050).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    WorkforceFixtures::site();
    Config::set('security.edge_networks.portal_internal', '10.20.0.0/16');
});

/** Fija `IDENTITY_PIN_LENGTH` como lo dejaria el panel. */
function accesoConLongitudDePin(string $longitud): void
{
    DB::table('installation_settings')->updateOrInsert(
        ['key' => 'IDENTITY_PIN_LENGTH'],
        ['value' => json_encode($longitud), 'updated_at' => '2026-10-06 00:00:00+00'],
    );
}

/** Una comprobacion `access.*` dentro del informe. */
function comprobacionDeAcceso(string $id, string $idioma = 'es'): DoctorCheck
{
    $informe = app(RunDoctorHandler::class)->handle($idioma);

    $encontrada = array_values(array_filter(
        $informe->checks,
        static fn (DoctorCheck $check): bool => $check->id === $id,
    ));

    expect($encontrada)->not->toBeEmpty("`doctor` no incluye la comprobacion {$id}.");

    return $encontrada[0];
}

/** Una persona en alta (o de baja) con un PIN de esa longitud. */
function personaConPin(string $pin, string $estado = 'active'): string
{
    $uuid = WorkforceFixtures::employee(WorkforceFixtures::onlySiteId(), status: $estado);
    EmployeePins::issue($uuid, $pin);

    return $uuid;
}

it('avisa del portal abierto a internet con PIN de 6 cifras y recomienda 8', function (string $red): void {
    Config::set('security.edge_networks.portal_internal', $red);

    $comprobacion = comprobacionDeAcceso('access.pin_length');

    expect($comprobacion->status)->toBe(DoctorStatus::Warning)
        ->and($comprobacion->summary)->toContain('accesible desde internet')
        ->and($comprobacion->fix)->toContain('8 cifras')
        ->and($comprobacion->fix)->toContain('Longitud del PIN')
        ->and($comprobacion->details)->toBe(['pin_length' => 6, 'portal_exposed' => true]);
})->with([
    'abierto' => '0.0.0.0/0',
    'publico' => '203.0.113.0/24',
])->group('RF-PD-13', 'RF-ID-09', 'RS-12');

it('sale en verde con el portal abierto y los PIN de 8 cifras', function (): void {
    Config::set('security.edge_networks.portal_internal', '0.0.0.0/0');
    accesoConLongitudDePin('8');

    $comprobacion = comprobacionDeAcceso('access.pin_length');

    expect($comprobacion->status)->toBe(DoctorStatus::Ok)
        ->and($comprobacion->summary)->toContain('8 cifras');
})->group('RF-PD-13', 'RF-ID-09');

it('no pide 8 cifras con el portal en la red del hotel', function (): void {
    // 6 con el bloqueo por empleado basta en la red interna (ADR-015): avisar
    // aqui enseñaria a ignorar el aviso que si importa.
    $comprobacion = comprobacionDeAcceso('access.pin_length');

    expect($comprobacion->status)->toBe(DoctorStatus::Ok)
        ->and($comprobacion->summary)->toContain('6 cifras');
})->group('RF-PD-13', 'RF-ID-09');

it('cuenta las personas en alta que conservan el PIN de 6 con el ajuste en 8, sin decir quienes', function (): void {
    accesoConLongitudDePin('8');
    personaConPin('482913');
    personaConPin('739104');
    personaConPin('48291375');
    // De baja: no puede entrar al portal y no cuenta.
    personaConPin('583920', 'terminated');
    // Sin PIN: no hay nada que restablecer.
    WorkforceFixtures::employee(WorkforceFixtures::onlySiteId(), firstName: 'Nombrevisible', lastName: 'Apellidovisible');

    $comprobacion = comprobacionDeAcceso('access.short_pins');
    $publico = json_encode($comprobacion->details).$comprobacion->summary.($comprobacion->fix ?? '');

    expect($comprobacion->status)->toBe(DoctorStatus::Warning)
        ->and($comprobacion->summary)->toStartWith('2 persona(s)')
        ->and($comprobacion->details)->toBe(['short_pins' => 2])
        ->and($comprobacion->fix)->toContain('Restablecer el PIN')
        ->and($publico)->not->toContain('Persona')
        ->and($publico)->not->toContain('Nombrevisible')
        ->and($publico)->not->toMatch('/[0-9a-f]{8}-[0-9a-f]{4}-/');
})->group('RF-PD-13', 'RF-ID-09', 'RS-05');

it('sale en verde cuando ya no queda ningun PIN de 6 con el ajuste en 8', function (): void {
    accesoConLongitudDePin('8');
    personaConPin('48291375');

    expect(comprobacionDeAcceso('access.short_pins')->status)->toBe(DoctorStatus::Ok);
})->group('RF-PD-13', 'RF-ID-09');

it('no cuenta PIN cortos mientras los PIN se emitan de 6', function (): void {
    personaConPin('482913');

    $comprobacion = comprobacionDeAcceso('access.short_pins');

    expect($comprobacion->status)->toBe(DoctorStatus::Ok)
        ->and($comprobacion->summary)->toContain('se emiten de 6 cifras');
})->group('RF-PD-13', 'RF-ID-09');

it('avisa del PIN de 6 tambien en ingles', function (): void {
    Config::set('security.edge_networks.portal_internal', '0.0.0.0/0');

    expect(comprobacionDeAcceso('access.pin_length', 'en')->summary)
        ->toContain('reachable from the internet')
        ->toContain('6 digits');
})->group('RF-PD-13', 'RF-ID-09');

it('cuenta los PIN cortos tambien en ingles', function (): void {
    // En una prueba aparte: la resolucion de ajustes se memoriza por proceso,
    // asi que el ajuste se fija antes del primer informe.
    accesoConLongitudDePin('8');
    personaConPin('482913');

    expect(comprobacionDeAcceso('access.short_pins', 'en')->summary)
        ->toContain('1 active person(s)');
})->group('RF-PD-13', 'RF-ID-09');

/*
 * Segundo factor de los roles de gestion (RS-06, ADR-050 §5 y M1 de su
 * revision). `IDENTITY_2FA_REQUIRED_ROLES` puede acortarse —es configuracion,
 * regla dura 13— pero tiene que verse; y tras actualizar, las cuentas de roles
 * obligados sin TOTP tienen abierta la ventana de auto-alta hasta su primer
 * acceso, asi que se cuentan. Solo el numero.
 */

/** Fija la lista de roles obligados como la dejaria el `.env`. */
function accesoConRolesObligados(string $lista): void
{
    Config::set('identity.two_factor.required_roles', array_values(array_filter(
        array_map('trim', explode(',', $lista)),
        static fn (string $rol): bool => $rol !== '',
    )));
}

it('sale en verde con los cuatro roles de gestion obligados a llevar segundo factor', function (): void {
    accesoConRolesObligados('admin,rrhh,auditor,responsable_departamento');

    $comprobacion = comprobacionDeAcceso('access.two_factor_roles');

    expect($comprobacion->status)->toBe(DoctorStatus::Ok)
        ->and($comprobacion->details)->toBe(['missing_roles' => []]);
})->group('RF-PD-13', 'RS-06');

it('avisa, sin fallar, de cada rol de gestion que falta en la lista', function (): void {
    // La lista de la 2.1.0: sin el responsable, que corrige jornadas.
    accesoConRolesObligados('admin, rrhh,auditor,no_es_un_rol');

    $comprobacion = comprobacionDeAcceso('access.two_factor_roles');

    expect($comprobacion->status)->toBe(DoctorStatus::Warning)
        ->and($comprobacion->summary)->toContain('responsable_departamento')
        ->and($comprobacion->details)->toBe(['missing_roles' => ['responsable_departamento']])
        ->and($comprobacion->fix)->toContain('IDENTITY_2FA_REQUIRED_ROLES=admin,rrhh,auditor,responsable_departamento');

    accesoConRolesObligados('');

    expect(comprobacionDeAcceso('access.two_factor_roles')->details)
        ->toBe(['missing_roles' => ['admin', 'rrhh', 'auditor', 'responsable_departamento']]);
})->group('RF-PD-13', 'RS-06');

it('cuenta las cuentas activas de roles obligados sin segundo factor, sin decir cuales', function (): void {
    accesoConRolesObligados('admin,rrhh,auditor,responsable_departamento');

    ManagementUsers::withActiveSecondFactor(ManagementUsers::withRole(UserRole::ADMIN));
    ManagementUsers::withRole(UserRole::RRHH, 'sin-segundo-factor@kronoqr.test');
    $responsable = ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO);
    // Un alta a medias (secreto sin confirmar) no cierra la ventana.
    $responsable->two_factor_secret = ManagementUsers::TOTP_SECRET;
    $responsable->save();
    // Desactivada: no puede entrar, no cuenta.
    $baja = ManagementUsers::withRole(UserRole::AUDITOR);
    $baja->is_active = false;
    $baja->save();

    $comprobacion = comprobacionDeAcceso('access.two_factor_pending');
    $publico = json_encode($comprobacion->details).$comprobacion->summary.($comprobacion->fix ?? '');

    expect($comprobacion->status)->toBe(DoctorStatus::Warning)
        ->and($comprobacion->summary)->toStartWith('2 cuenta(s)')
        ->and($comprobacion->details)->toBe(['pending_accounts' => 2])
        ->and($comprobacion->fix)->toContain('auth.two_factor_enabled')
        ->and($comprobacion->fix)->toContain('identity:2fa-reset')
        ->and($publico)->not->toContain('sin-segundo-factor@')
        ->and($publico)->not->toContain('Cuenta de prueba')
        ->and($publico)->not->toContain($responsable->uuid);
})->group('RF-PD-13', 'RS-06', 'RS-05');

it('solo cuenta los roles que la instalacion obliga', function (): void {
    accesoConRolesObligados('admin,rrhh,auditor');
    ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO);

    expect(comprobacionDeAcceso('access.two_factor_pending')->status)->toBe(DoctorStatus::Ok);
})->group('RF-PD-13', 'RS-06');

it('avisa del segundo factor tambien en ingles', function (): void {
    accesoConRolesObligados('admin,rrhh,auditor');
    ManagementUsers::withRole(UserRole::RRHH);

    expect(comprobacionDeAcceso('access.two_factor_roles', 'en')->summary)
        ->toContain('does not include responsable_departamento');
    expect(comprobacionDeAcceso('access.two_factor_pending', 'en')->summary)
        ->toStartWith('1 active account(s)');
})->group('RF-PD-13', 'RS-06');
