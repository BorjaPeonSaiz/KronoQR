<?php

declare(strict_types=1);

use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Workforce\Infrastructure\Persistence\Department;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **`access.denied` bajo control: la unica escritura de `audit_log` que provoca
 * quien NO esta autorizado** (RF-ID-03, RS-05, ADR-010, ADR-037).
 *
 * Todas las demas entradas del trail las produce un acto de gestion. Esta la
 * produce una peticion **denegada**, asi que un bucle de peticiones denegadas era
 * un bucle de escrituras, y cada una toma el `pg_advisory_xact_lock` **global** de
 * ADR-010 — el mismo por el que pasa cada fichaje. Un responsable recorriendo UUID
 * ajenos metia escrituras serializadas en el camino critico del cambio de turno y
 * llenaba de ruido cuatro años de la tabla que se enseña en una inspeccion.
 *
 * **Lo que estas pruebas fijan es el equilibrio, no la supresion.** El escenario
 * «Aislamiento por departamento» del doc 01 §11 exige por escrito que *«el intento
 * queda registrado»*: la primera denegacion de cada ventana **siempre** se
 * escribe, y `DepartmentScopeTest` lo comprueba. Aqui se comprueba lo otro: que la
 * repeticion se agrupa, que la agrupacion no cruza actores ni conjuntos de datos,
 * y que las denegaciones agrupadas se cuentan en lugar de desaparecer.
 *
 * El mecanismo es el que **nombra ADR-037** para la contencion de este candado:
 * agrupar por frecuencia, entero detras del puerto, sin tocar a ninguno de sus
 * llamantes.
 */

uses(RefreshDatabase::class);

/**
 * Un responsable de Cocina, con gente ajena en Recepcion a la que no alcanza.
 *
 * @return array{token: string, ajenos: list<string>}
 */
function jefeDeCocinaConAjenos(int $cuantos = 3): array
{
    $site = WorkforceFixtures::site();
    $jefe = ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO);

    $cocina = WorkforceFixtures::department($site, 'Cocina');
    Department::query()->whereKey($cocina)->update(['manager_user_id' => $jefe->id]);

    $recepcion = WorkforceFixtures::department($site, 'Recepcion');

    $ajenos = [];

    for ($i = 0; $i < $cuantos; $i++) {
        $ajenos[] = WorkforceFixtures::employee($site, $recepcion);
    }

    return ['token' => ManagementUsers::tokenFor($jefe), 'ajenos' => $ajenos];
}

function denegacionesRegistradas(): int
{
    return DB::table('audit_log')->where('action', AuditAction::AccessDenied->value)->count();
}

beforeEach(function (): void {
    config()->set('identity.two_factor.required_roles', []);

    // El techo de peticiones no puede ser quien corte estas pruebas: lo que se
    // mide aqui son las FILAS que escribe el trail, no las peticiones que acepta
    // el proceso. Son dos controles distintos y cada uno tiene su prueba.
    config()->set('identity.management.rate_limit_per_minute', 1000);
});

it('agrupa en un solo asiento las denegaciones repetidas del mismo actor sobre el mismo dataset', function (): void {
    config()->set('compliance.authorization_denial_window_seconds', 60);

    ['token' => $token, 'ajenos' => $ajenos] = jefeDeCocinaConAjenos(3);

    foreach ($ajenos as $ajeno) {
        Api::as($token)->get('/api/v1/employees/'.$ajeno)->assertStatus(403);
    }

    // Tres `403`, un asiento. El `403` se sigue devolviendo siempre: lo que se
    // agrupa es la fila, no la denegacion.
    expect(denegacionesRegistradas())->toBe(1);

    $asiento = DB::table('audit_log')
        ->where('action', AuditAction::AccessDenied->value)
        ->orderByDesc('id')
        ->first();

    $payload = (string) json_encode($asiento);

    // El primero representa una sola denegacion —la suya—, y sigue apuntando a
    // quien se fue a buscar.
    expect($payload)->toContain('repeated_since_last_entry\": 1')
        ->and($payload)->toContain($ajenos[0])
        ->and($payload)->toContain('employee_profile');
})->group('RF-ID-03', 'RS-05', 'RQ-07');

it('cuenta en el asiento siguiente las denegaciones que agrupo, en vez de perderlas', function (): void {
    // La agrupacion no puede convertirse en «no consta»: quien lea el trail de un
    // incidente tiene que poder decir cuantas veces lo intento esa cuenta. Un
    // numero en un apunte responde mejor que 341 filas identicas.
    config()->set('compliance.authorization_denial_window_seconds', 60);

    ['token' => $token, 'ajenos' => $ajenos] = jefeDeCocinaConAjenos(4);

    foreach ([$ajenos[0], $ajenos[1], $ajenos[2]] as $ajeno) {
        Api::as($token)->get('/api/v1/employees/'.$ajeno)->assertStatus(403);
    }

    expect(denegacionesRegistradas())->toBe(1);

    // Se agota la ventana. El contador de agrupadas vive mas que ella a
    // proposito: si caducara a la vez, la cuenta se perderia justo en el hueco
    // entre una ventana y la siguiente.
    //
    // Se mueve el reloj de Carbon y no se espera: la caducidad de la cache lo
    // consulta (`InteractsWithTime::currentTime()`), asi que dos minutos de viaje
    // son dos minutos de cache sin `sleep()` en la suite.
    Carbon::setTestNow(Carbon::now()->addMinutes(2));

    Api::as($token)->get('/api/v1/employees/'.$ajenos[3])->assertStatus(403);

    expect(denegacionesRegistradas())->toBe(2);

    $ultimo = DB::table('audit_log')
        ->where('action', AuditAction::AccessDenied->value)
        ->orderByDesc('id')
        ->first();

    // Las dos que se agruparon mas esta: tres.
    expect((string) json_encode($ultimo))->toContain('repeated_since_last_entry\": 3');
})->group('RF-ID-03', 'RS-05');

it('no agrupa entre conjuntos de datos distintos', function (): void {
    // La ficha y el registro horario de una persona son dos hechos distintos y el
    // trail tiene que poder responderlos por separado: agrupar por actor a secas
    // haria que ir a por las horas de alguien quedara tapado por haber ido antes a
    // por su ficha.
    config()->set('compliance.authorization_denial_window_seconds', 60);

    ['token' => $token, 'ajenos' => $ajenos] = jefeDeCocinaConAjenos(1);

    Api::as($token)->get('/api/v1/employees/'.$ajenos[0])->assertStatus(403);
    Api::as($token)->get('/api/v1/employees/'.$ajenos[0].'/workdays')->assertStatus(403);

    expect(denegacionesRegistradas())->toBe(2);

    $datasets = (string) json_encode(
        DB::table('audit_log')
            ->where('action', AuditAction::AccessDenied->value)
            ->pluck('payload')
            ->all()
    );

    expect($datasets)
        ->toContain('employee_profile')
        ->toContain('employee_workdays');
})->group('RF-ID-03', 'RS-05');

it('no deja que un actor silencie el asiento de otro', function (): void {
    // Si la ventana no llevara el actor en su clave, bastaria con provocar una
    // denegacion propia para que la de otra cuenta —la que de verdad importa— no
    // llegara a escribirse. Seria un borrado de traza al alcance de cualquiera con
    // una sesion.
    config()->set('compliance.authorization_denial_window_seconds', 60);

    $primero = jefeDeCocinaConAjenos(1);
    $segundo = jefeDeCocinaConAjenos(1);

    Api::as($primero['token'])->get('/api/v1/employees/'.$primero['ajenos'][0])->assertStatus(403);
    Api::as($segundo['token'])->get('/api/v1/employees/'.$segundo['ajenos'][0])->assertStatus(403);

    expect(denegacionesRegistradas())->toBe(2);
})->group('RF-ID-03', 'RS-05');

it('escribe un asiento por denegacion cuando la ventana se desactiva', function (): void {
    // La salida para un cliente que prefiera la fila a la contencion (regla dura
    // 13). Y el control negativo de las pruebas de arriba: sin el, un fallo que
    // dejara de escribir SIEMPRE las pasaria todas.
    config()->set('compliance.authorization_denial_window_seconds', 0);

    ['token' => $token, 'ajenos' => $ajenos] = jefeDeCocinaConAjenos(3);

    foreach ($ajenos as $ajeno) {
        Api::as($token)->get('/api/v1/employees/'.$ajeno)->assertStatus(403);
    }

    expect(denegacionesRegistradas())->toBe(3);
})->group('RF-ID-03', 'RS-05');
