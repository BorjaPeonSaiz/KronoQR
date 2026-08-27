<?php

declare(strict_types=1);

use App\Modules\Compliance\Http\Policy\LegalExportPolicy;
use App\Modules\Identity\Domain\ValueObject\TokenAbility;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;

/*
 * Regla dura 18 y RQ-07: `GET /api/v1/reports/legal-export` con su prueba de
 * autorizacion negativa **por cada rol no autorizado**, sin excepciones.
 *
 * Es el endpoint que entrega **la lista nominal de la plantilla con sus horas**.
 * Quien pueda llamarlo se lleva, en un fichero, el registro horario de todas las
 * personas de la instalacion. Por eso se prueba rol a rol y no con un caso
 * representativo.
 *
 * Se comprueban los DOS controles del doc 02 §7.3, y por separado:
 *
 *   - **El ambito del token** —`reports:legal` o `reports:*`—, que verifica el
 *     middleware `ability`. Es lo que deja fuera a un token de quiosco y al del
 *     portal del empleado.
 *   - **La policy**, que verifica el ROL. Es lo que dejaria fuera a una cuenta
 *     que tuviera el ambito sin el rol.
 *
 * Que los dos devuelvan 403 es correcto: desde fuera no se distingue por que se
 * ha denegado, y no hay motivo para decirlo.
 */

uses(RefreshDatabase::class);

/**
 * @return array<string, mixed>
 */
function peticionDeExportacionLegal(): array
{
    return ['from' => '2026-01-01', 'to' => '2026-01-31'];
}

it('no deja exportar sin token', function (): void {
    // La mitad de la regla dura 18 que mas se olvida: comprobar tambien que sin
    // credenciales no se entra.
    Api::guest()
        ->get('/api/v1/reports/legal-export', peticionDeExportacionLegal())
        ->assertStatus(401);

    expect(DB::table('audit_log')->where('action', 'legal_export.generated')->count())->toBe(0);
})->group('RF-ID-03', 'RL-06', 'RS-04');

it('no deja exportar a un empleado', function (): void {
    // Un empleado consulta LO SUYO por el portal, con `self:read` (ADR-015). La
    // exportacion legal completa es otra cosa: es la plantilla entera.
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::EMPLEADO));

    Api::as($token)
        ->get('/api/v1/reports/legal-export', peticionDeExportacionLegal())
        ->assertStatus(403);

    // Y no ha quedado rastro: un 403 que ya hubiera generado el fichero seria
    // peor que un 200.
    expect(DB::table('audit_log')->where('action', 'legal_export.generated')->count())->toBe(0);
})->group('RF-ID-03', 'RL-06');

it('no deja exportar a un quiosco ni a un responsable de departamento', function (string $role): void {
    // El quiosco porque su token vive colgado de una pared (RS-04). El
    // responsable de departamento porque su alcance es su departamento
    // (RF-ID-03) y este endpoint no tiene alcance parcial: darle acceso hoy
    // seria darle el registro horario de toda la instalacion.
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::from($role)));

    Api::as($token)
        ->get('/api/v1/reports/legal-export', peticionDeExportacionLegal())
        ->assertStatus(403);

    expect(DB::table('audit_log')->where('action', 'legal_export.generated')->count())->toBe(0);
})->with([
    'quiosco' => UserRole::KIOSK->value,
    'responsable de departamento' => UserRole::RESPONSABLE_DEPARTAMENTO->value,
])->group('RF-ID-03', 'RL-06', 'RS-04');

it('no deja exportar con un token de gestion al que no se le concedio el ambito', function (): void {
    // El rol es correcto y aun asi no pasa: es la mitad que el §7.3 llama
    // «ambito». Sin ella, cualquier token emitido a una cuenta de RRHH —incluido
    // uno acotado a otra cosa— serviria para descargar la plantilla entera.
    $user = ManagementUsers::withRole(UserRole::RRHH);
    $token = $user->createToken('Solo plantilla', [TokenAbility::EMPLOYEES_ALL->value])->plainTextToken;

    Api::as($token)
        ->get('/api/v1/reports/legal-export', peticionDeExportacionLegal())
        ->assertStatus(403);
})->group('RS-04', 'RF-ID-03');

it('deja exportar a los tres roles del Anexo B', function (string $role): void {
    // El complemento de las negativas: si nadie comprobara el caso positivo, una
    // policy que devolviera `false` siempre pasaria todas las pruebas de arriba.
    //
    // `auditor` esta desde el principio y no desde la tarea 2.1: el catalogo de
    // permisos ya le concede `reports:legal`, asi que omitirlo en la policy
    // habria dejado a las dos mitades de la autorizacion diciendo cosas
    // distintas.
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::from($role)));

    Api::as($token)
        ->get('/api/v1/reports/legal-export', peticionDeExportacionLegal())
        ->assertOk();
})->with([
    'administrador' => UserRole::ADMIN->value,
    'rrhh' => UserRole::RRHH->value,
    'auditor' => UserRole::AUDITOR->value,
])->group('RF-IN-05', 'RF-ID-03');

it('la policy nombra exactamente los tres roles y ninguno mas', function (): void {
    // La policy, aparte del endpoint. Un cambio que le añadiera un rol pasaria
    // desapercibido entre las pruebas de arriba —solo se veria como un 403 que
    // deja de serlo— y este endpoint entrega la plantilla entera.
    $policy = new LegalExportPolicy;

    foreach ([UserRole::ADMIN, UserRole::RRHH, UserRole::AUDITOR] as $role) {
        expect($policy->generate(ManagementUsers::withRole($role)))->toBeTrue($role->value.' deberia poder exportar.');
    }

    foreach ([UserRole::RESPONSABLE_DEPARTAMENTO, UserRole::EMPLEADO, UserRole::KIOSK] as $role) {
        expect($policy->generate(ManagementUsers::withRole($role)))->toBeFalse($role->value.' no deberia poder exportar.');
    }
})->group('RF-ID-03', 'RL-06');
