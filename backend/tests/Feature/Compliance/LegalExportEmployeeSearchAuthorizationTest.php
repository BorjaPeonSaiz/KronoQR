<?php

declare(strict_types=1);

use App\Modules\Compliance\Http\Policy\LegalExportPolicy;
use App\Modules\Identity\Domain\ValueObject\TokenAbility;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Spectator\Spectator;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Identity\PortalLogins;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Regla dura 18 y RQ-07: `POST /api/v1/reports/legal-export/employees/search`
 * con su prueba de autorizacion negativa **por cada rol no autorizado**.
 *
 * Es la ruta que contesta «¿existe en esta instalacion alguien con este DNI?».
 * Por eso no basta con el `403`: tiene que ser **identico exista o no el
 * documento**, y no dejar asiento, porque una diferencia en cualquiera de las
 * dos cosas ya responderia a la pregunta. Los dos controles del doc 02 §7.3 se
 * prueban por separado: el ambito del token y la policy del rol.
 */

uses(RefreshDatabase::class);

const LEGAL_EXPORT_EMPLOYEE_SEARCH_AUTHZ_URI = '/api/v1/reports/legal-export/employees/search';

/** Un DNI que existe en la plantilla de la prueba. */
const LEGAL_EXPORT_EMPLOYEE_SEARCH_AUTHZ_DNI = '12345678Z';

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

/**
 * Una persona con DNI en la plantilla; devuelve su `uuid`.
 */
function personaConDniParaAutorizacion(): string
{
    $site = WorkforceFixtures::site();
    $uuid = WorkforceFixtures::employee($site, null, 'active', 'José', 'Pérez Amrani');

    DB::update(
        'UPDATE employees SET national_id_hash = digest(?, ?) WHERE uuid = ?',
        [LEGAL_EXPORT_EMPLOYEE_SEARCH_AUTHZ_DNI, 'sha256', $uuid],
    );

    return $uuid;
}

function asientosDeBusquedaLegalParaAutorizacion(): int
{
    return DB::table('audit_log')
        ->where('action', 'personal_data.accessed')
        ->whereRaw("payload->>'dataset' = ?", ['legal_export_employee_search'])
        ->count();
}

/**
 * Los portadores que NO pueden buscar, cada uno con su token.
 *
 * @return array<string, array{0: Closure(string): string}>
 */
function portadoresSinBusquedaLegal(): array
{
    return [
        // Su alcance es su departamento (RF-ID-03) y la exportacion no tiene
        // alcance parcial: buscar a cualquiera de la instalacion seria salirse.
        'responsable de departamento' => [static fn (string $employee): string => ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO))],
        // Una cuenta de gestion con el rol `empleado`: tiene rol pero no ambito.
        'empleado (cuenta)' => [static fn (string $employee): string => ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::EMPLEADO))],
        // La sesion real del portal, con `self:read`: consulta lo suyo y nada mas.
        'empleado (portal)' => [static fn (string $employee): string => PortalLogins::open($employee)],
        // Un token colgado de una pared no pregunta por DNI (RS-04).
        'quiosco (cuenta)' => [static fn (string $employee): string => ManagementUsers::kioskToken()],
        'quiosco (dispositivo)' => [static fn (string $employee): string => AttendanceFixtures::tokenFor(
            AttendanceFixtures::device(WorkforceFixtures::onlySiteId())['id'],
        )],
        // Quien no ha terminado de entrar no tiene todavia ningun ambito (RS-06).
        'segundo factor pendiente' => [static fn (string $employee): string => ManagementUsers::pendingTokenFor(ManagementUsers::withRole(UserRole::ADMIN))],
        // El rol es correcto y aun asi no pasa: le falta el ambito.
        'rrhh con un token sin el ambito' => [static fn (string $employee): string => ManagementUsers::withRole(UserRole::RRHH)
            ->createToken('Solo plantilla', [TokenAbility::EMPLOYEES_ALL->value])->plainTextToken],
        // Y al reves: el ambito sin el rol, que es lo que para la policy.
        'empleado con el ambito reports:legal' => [static fn (string $employee): string => ManagementUsers::withRole(UserRole::EMPLEADO)
            ->createToken('Ambito sin rol', [TokenAbility::REPORTS_LEGAL->value])->plainTextToken],
    ];
}

it('no deja buscar sin sesion', function (): void {
    personaConDniParaAutorizacion();

    Api::guest()
        ->post(LEGAL_EXPORT_EMPLOYEE_SEARCH_AUTHZ_URI, ['national_id' => LEGAL_EXPORT_EMPLOYEE_SEARCH_AUTHZ_DNI])
        ->assertValidResponse(401);

    expect(asientosDeBusquedaLegalParaAutorizacion())->toBe(0);
})->group('RF-IN-05', 'RF-ID-02', 'RS-05');

it('responde 403 a quien no puede exportar, sin asiento', function (Closure $token): void {
    $employee = personaConDniParaAutorizacion();

    Api::as($token($employee))
        ->post(LEGAL_EXPORT_EMPLOYEE_SEARCH_AUTHZ_URI, ['q' => 'amrani'])
        ->assertValidResponse(403);

    expect(asientosDeBusquedaLegalParaAutorizacion())->toBe(0);
})->with(portadoresSinBusquedaLegal())->group('RF-IN-05', 'RF-ID-02', 'RS-04', 'RS-05');

it('responde el mismo 403 exista o no el DNI', function (Closure $token): void {
    $employee = personaConDniParaAutorizacion();
    $bearer = $token($employee);

    $existe = Api::as($bearer)->post(LEGAL_EXPORT_EMPLOYEE_SEARCH_AUTHZ_URI, ['national_id' => LEGAL_EXPORT_EMPLOYEE_SEARCH_AUTHZ_DNI]);
    $noExiste = Api::as($bearer)->post(LEGAL_EXPORT_EMPLOYEE_SEARCH_AUTHZ_URI, ['national_id' => '99999999R']);
    // Ni siquiera un cuerpo invalido cambia la respuesta: la autorizacion va
    // antes que la validacion, y un 422 aqui ya diria algo.
    $invalido = Api::as($bearer)->post(LEGAL_EXPORT_EMPLOYEE_SEARCH_AUTHZ_URI, ['national_id' => '1']);

    expect($existe->status())->toBe(403)
        ->and($noExiste->status())->toBe(403)
        ->and($invalido->status())->toBe(403)
        ->and($noExiste->getContent())->toBe($existe->getContent())
        ->and($invalido->getContent())->toBe($existe->getContent())
        ->and(asientosDeBusquedaLegalParaAutorizacion())->toBe(0);
})->with(portadoresSinBusquedaLegal())->group('RF-IN-05', 'RS-05', 'RL-08');

it('deja buscar a los tres roles que pueden exportar', function (string $role): void {
    // El control positivo: una policy que dijera que no a todo pasaria todas
    // las negativas de arriba.
    $employee = personaConDniParaAutorizacion();
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::from($role)));

    // Con la ruta literal: es la validacion de contrato que cuenta
    // `ContractResponseCoverageTest` para esta operacion.
    $response = Api::as($token)
        ->post('/api/v1/reports/legal-export/employees/search', ['national_id' => LEGAL_EXPORT_EMPLOYEE_SEARCH_AUTHZ_DNI])
        ->assertValidResponse(200);

    expect($response->json('data.0.uuid'))->toBe($employee)
        ->and(asientosDeBusquedaLegalParaAutorizacion())->toBe(1);
})->with([
    'administrador' => UserRole::ADMIN->value,
    'rrhh' => UserRole::RRHH->value,
    'auditor' => UserRole::AUDITOR->value,
])->group('RF-IN-05', 'RF-ID-02');

it('deja buscar al auditor con un token emitido antes de la 2.2.1', function (): void {
    // El token conserva `attendance:read` y `audit:read`, pero lleva tambien
    // `reports:legal`: la busqueda tiene que seguir funcionando para el.
    $employee = personaConDniParaAutorizacion();
    $token = ManagementUsers::legacyAuditorTokenFor(ManagementUsers::withRole(UserRole::AUDITOR));

    Api::as($token)
        ->post(LEGAL_EXPORT_EMPLOYEE_SEARCH_AUTHZ_URI, ['q' => 'amrani'])
        ->assertValidResponse(200)
        ->assertJsonPath('data.0.uuid', $employee);
})->group('RF-IN-05', 'RF-ID-02');

it('la policy de la busqueda nombra exactamente los mismos roles que la de la exportacion', function (): void {
    $policy = new LegalExportPolicy;

    foreach (UserRole::cases() as $role) {
        $actor = ManagementUsers::withRole($role);

        expect($policy->searchEmployees($actor))->toBe(
            $policy->generate($actor),
            $role->value.': buscar y exportar tienen que decir lo mismo.',
        );
    }

    expect($policy->searchEmployees(ManagementUsers::withRole(UserRole::AUDITOR)))->toBeTrue()
        ->and($policy->searchEmployees(ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO)))->toBeFalse();
})->group('RF-IN-05', 'RF-ID-02');
