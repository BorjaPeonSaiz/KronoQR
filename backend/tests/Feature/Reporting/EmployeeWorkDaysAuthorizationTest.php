<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\ValueObject\TokenAbility;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `GET /api/v1/employees/{uuid}/workdays` con su prueba de autorizacion negativa
 * **por cada rol no autorizado** (regla dura 18, RQ-07, doc 02 §9.5).
 *
 * Este endpoint es la primera pantalla del producto desde la que alguien con
 * responsabilidad de gestion puede ver el registro horario de OTRA persona. Lo
 * que protege no es una lista de nombres: son las horas a las que cada uno entro
 * y salio cada dia, que es el dato personal mas sensible que este sistema guarda
 * de nadie (RS-05).
 *
 * Se comprueban los DOS controles del doc 02 §7.3, y por separado:
 *
 *   - **El ambito del token** (`attendance:read`), que verifica el middleware
 *     `ability`. Es lo que deja fuera al token de quiosco y a una sesion de
 *     portal.
 *   - **La policy**, que verifica el ROL. Es lo que deja fuera al `auditor` y al
 *     `responsable_departamento`, que **si** llevan `attendance:read` en su token
 *     y aun asi no son «manager+» en esta fase.
 *
 * Que los dos devuelvan 403 es correcto: desde fuera no se distingue por que se
 * ha denegado, y no hay motivo para decirlo.
 */

uses(RefreshDatabase::class);

/**
 * Un empleado real al que intentar mirarle las jornadas, para que lo que falle
 * sea la autorizacion y no la busqueda del recurso.
 */
function empleadoConsultable(): string
{
    return WorkforceFixtures::employee(WorkforceFixtures::site('Hotel de permisos de lectura'));
}

it('no deja consultar el registro horario sin token', function (): void {
    // La mitad de la regla dura 18 que mas se olvida: comprobar tambien que sin
    // credenciales no se entra.
    Api::guest()
        ->get('/api/v1/employees/'.empleadoConsultable().'/workdays')
        ->assertStatus(401)
        ->assertJsonPath('type', 'urn:kronoqr:problem:unauthenticated');
})->group('RF-PA-03', 'RQ-07', 'RS-04');

it('no deja consultar el registro horario de nadie a un quiosco', function (): void {
    // RS-04 y §7.3: el token de una tablet vive colgado de una pared. Sus tres
    // ambitos son `scan:write`, `roster:read` y `heartbeat:write`; ninguno abre
    // esta puerta, y si la abriera, un quiosco robado se llevaria el registro
    // horario completo de la plantilla.
    Api::as(ManagementUsers::kioskToken())
        ->get('/api/v1/employees/'.empleadoConsultable().'/workdays')
        ->assertStatus(403);
})->group('RF-PA-03', 'RQ-07', 'RS-04', 'RF-ID-04');

it('no deja consultar el registro horario de otro a una cuenta de empleado', function (): void {
    // RF-ID-07 y ADR-015: el empleado consulta LO SUYO en el portal, con codigo y
    // PIN y ambito `self:read`. El `self` del Anexo B se sirve en
    // `GET /api/v1/me/workdays`, no aqui: esta ruta es la de gestion.
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::EMPLEADO));

    Api::as($token)
        ->get('/api/v1/employees/'.empleadoConsultable().'/workdays')
        ->assertStatus(403);
})->group('RF-PA-03', 'RQ-07', 'RF-ID-07');

it('no deja consultar el registro horario a un auditor ni a un responsable de departamento', function (string $role): void {
    // LOS DOS TIENEN `attendance:read` EN EL TOKEN, y aun asi reciben 403. No es
    // una contradiccion: es exactamente para lo que sirven las dos
    // comprobaciones del §7.3.
    //
    //   - `auditor` no esta en «manager+» (Anexo B). Lo que ese rol necesita —el
    //     registro para un requerimiento— se sirve por
    //     `GET /api/v1/reports/legal-export`, que es suyo y queda auditado como
    //     exportacion legal (tarea 1.17).
    //   - `responsable_departamento` no adquiere alcance propio hasta RF-ID-03
    //     (tarea 2.1): darle acceso hoy seria darle el registro horario de TODA
    //     la instalacion, que es justo lo que ese requisito viene a impedir.
    //
    // Cuando la 2.1 cambie el alcance, este caso cambiara con ella y no en
    // silencio.
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::from($role)));

    Api::as($token)
        ->get('/api/v1/employees/'.empleadoConsultable().'/workdays')
        ->assertStatus(403);
})->with([
    'auditor' => UserRole::AUDITOR->value,
    'responsable de departamento' => UserRole::RESPONSABLE_DEPARTAMENTO->value,
])->group('RF-PA-03', 'RQ-07', 'RF-ID-03');

it('deniega el ambito aunque el rol sea el correcto', function (): void {
    // La mitad de la autorizacion que no es la policy (§7.3). Un token de RRHH
    // emitido SIN `attendance:read` —una sesion antigua, o uno recortado a
    // proposito— no alcanza este endpoint aunque su portadora pueda consultar.
    $usuaria = ManagementUsers::withRole(UserRole::RRHH);
    $token = $usuaria->createToken('Sesion sin el ambito', [
        TokenAbility::EMPLOYEES_ALL->value,
    ])->plainTextToken;

    Api::as($token)
        ->get('/api/v1/employees/'.empleadoConsultable().'/workdays')
        ->assertStatus(403);
})->group('RF-PA-03', 'RQ-07', 'RS-04');

it('deja consultar a admin y a rrhh, que son «manager+» en esta fase', function (string $role): void {
    // El contrapunto de las pruebas negativas: sin esto, una policy que denegara
    // a todo el mundo pasaria las cinco de arriba.
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::from($role)));

    Api::as($token)
        ->get('/api/v1/employees/'.empleadoConsultable().'/workdays')
        ->assertStatus(200);
})->with([
    'admin' => UserRole::ADMIN->value,
    'rrhh' => UserRole::RRHH->value,
])->group('RF-PA-03', 'RQ-07');

it('no deja rastro de auditoria cuando se deniega el acceso', function (): void {
    // RS-05 registra DIVULGACIONES, no intentos. Un 403 no divulgo nada, asi que
    // no puede escribir un asiento que diga que alguien accedio a datos
    // personales: `audit_log` es lo que se enseña en una inspeccion y no puede
    // contar accesos que no ocurrieron.
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::AUDITOR));

    Api::as($token)
        ->get('/api/v1/employees/'.empleadoConsultable().'/workdays')
        ->assertStatus(403);

    expect(DB::table('audit_log')->where('action', 'personal_data.accessed')->count())->toBe(0);
})->group('RF-PA-03', 'RS-05');
