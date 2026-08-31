<?php

declare(strict_types=1);

use App\Modules\Compliance\Application\Port\RetentionPolicyProvider;
use App\Modules\Compliance\Application\UseCase\VerifyAuditChain;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Todo cambio del perfil de cumplimiento deja asiento en `audit_log`
 * (RF-PD-07, RL-04, regla dura 6).
 *
 * Es el asiento que contesta a la pregunta que trae una inspeccion: «¿por que
 * esta jornada de marzo, con once horas de descanso, no genero alerta?». Sin el,
 * la respuesta honesta es «no lo sabemos».
 *
 * POR QUE NO PODIA SER UNITARIA: la cadena de hash, el encadenado bajo candado y
 * el `jsonb` del payload viven en PostgreSQL.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    WorkforceFixtures::site();
});

/**
 * Los asientos de cambio de perfil, del mas antiguo al mas reciente.
 *
 * @return list<object{actor_type: string, actor_id: int|null, subject_type: string|null, subject_id: int|null, payload: string}>
 */
function profileAuditEntries(): array
{
    /** @var list<object{actor_type: string, actor_id: int|null, subject_type: string|null, subject_id: int|null, payload: string}> $rows */
    $rows = DB::table('audit_log')
        ->where('action', 'calculation_setting.changed')
        ->where('subject_type', 'compliance_profile')
        ->orderBy('id')
        ->get()
        ->all();

    return $rows;
}

/**
 * @param  object{payload: string}  $entry
 * @return array<string, mixed>
 */
function profileAuditPayload(object $entry): array
{
    /** @var array<string, mixed> $payload */
    $payload = json_decode($entry->payload, true, 512, JSON_THROW_ON_ERROR);

    ksort($payload, SORT_STRING);

    return $payload;
}

it('deja un asiento por campo cambiado, con el valor anterior y el posterior', function (): void {
    $admin = ManagementUsers::withRole(UserRole::ADMIN);

    Api::as(ManagementUsers::tokenFor($admin))
        ->patch('/api/v1/compliance-profile', [
            'min_rest_hours' => 10,
            'retention_years' => 6,
            'name' => 'Convenio de hosteleria de Cantabria',
        ])
        ->assertStatus(200);

    $entries = profileAuditEntries();

    // Tres campos, tres asientos. Uno por peticion obligaria a decidir un unico
    // `affects_incident_detection` para un conjunto mixto.
    expect($entries)->toHaveCount(3);

    $profileId = DB::table('compliance_profiles')->where('is_default', true)->value('id');
    $byField = [];

    foreach ($entries as $entry) {
        $payload = profileAuditPayload($entry);
        $field = $payload['field'] ?? null;

        expect($field)->toBeString()
            ->and($entry->actor_type)->toBe('user')
            ->and($entry->actor_id)->toBe($admin->id)
            ->and($entry->subject_type)->toBe('compliance_profile')
            // Al contrario que la configuracion de instalacion, aqui el sujeto ES
            // una fila con identificador estable.
            ->and($entry->subject_id)->toBe($profileId);

        if (is_string($field)) {
            $byField[$field] = $payload;
        }
    }

    // El umbral que mueve la deteccion de incidencias (RN-10).
    expect($byField['min_rest_hours'])->toBe([
        'affects_incident_detection' => true,
        'affects_retention' => false,
        // La decision de retroactividad, escrita en el propio asiento.
        'applies_from' => 'change_forward_only',
        'detection_suspended' => false,
        'field' => 'min_rest_hours',
        'new_value' => 10,
        'previous_value' => 12,
    ]);

    // El unico campo cuyo error se paga con datos que no vuelven (RL-02).
    expect($byField['retention_years'])->toBe([
        'affects_incident_detection' => false,
        'affects_retention' => true,
        'applies_from' => 'change_forward_only',
        'detection_suspended' => false,
        'field' => 'retention_years',
        'new_value' => 6,
        'previous_value' => 4,
    ]);

    // Y el nombre del convenio, que no mueve ni una alerta ni un dia de purga.
    expect($byField['name'])->toBe([
        'affects_incident_detection' => false,
        'affects_retention' => false,
        'applies_from' => 'change_forward_only',
        'detection_suspended' => false,
        'field' => 'name',
        'new_value' => 'Convenio de hosteleria de Cantabria',
        'previous_value' => 'ES-hosteleria',
    ]);
})->group('RF-PD-07', 'RL-04');

it('no deja asiento cuando el PATCH no cambia nada', function (): void {
    // Abrir la pantalla y pulsar «guardar» no puede ensuciar el trail: la señal
    // que importa —«alguien movio el descanso minimo»— quedaria enterrada.
    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN)))
        ->patch('/api/v1/compliance-profile', ['min_rest_hours' => 12, 'max_daily_hours' => 9])
        ->assertStatus(200);

    expect(profileAuditEntries())->toBe([]);
})->group('RF-PD-07', 'RL-04');

it('mantiene intacta la cadena de auditoria despues de varios cambios', function (): void {
    // Un `audit_log` que se rompe con una operacion normal deja de valer como
    // prueba, que es justo lo que este asiento existe para ser.
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

    foreach ([11, 10, 13] as $hours) {
        Api::as($token)->patch('/api/v1/compliance-profile', ['min_rest_hours' => $hours])->assertStatus(200);
    }

    expect(profileAuditEntries())->toHaveCount(3)
        ->and(app(VerifyAuditChain::class)->handle()->isIntact())->toBeTrue();
})->group('RF-PD-07', 'RL-04');

it('el plazo de retencion sale del perfil y cambiarlo mueve el corte de la purga', function (): void {
    // La tension con la tarea 2.10, escrita como prueba: los cuatro años de
    // RL-02 no son una constante de PHP ni una variable de entorno, salen de
    // `compliance_profiles.retention_years`. Purgar de mas es irreversible sobre
    // datos con obligacion legal de conservacion, asi que el camino tiene que
    // estar atado en los dos sentidos: con el perfil de serie el plazo es 4, y
    // con el perfil cambiado es el que diga el perfil.
    $retention = app(RetentionPolicyProvider::class);

    expect($retention->forInstallation()->legalRecordYears)->toBe(4);

    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN)))
        ->patch('/api/v1/compliance-profile', ['retention_years' => 6])
        ->assertStatus(200);

    // Instancias nuevas: tanto el proveedor de retencion como el de perfiles
    // memoizan **por peticion** a proposito, y lo que se comprueba aqui es que la
    // fuente sea la fila y no una constante. `forgetScopedInstances()` es lo que
    // hace el framework entre una peticion y la siguiente.
    app()->forgetScopedInstances();
    app()->forgetInstance(RetentionPolicyProvider::class);

    expect(app(RetentionPolicyProvider::class)->forInstallation()->legalRecordYears)->toBe(6);
})->group('RF-PD-07', 'RL-02', 'RL-11');

it('dice la verdad sobre el umbral de la pausa, cuya regla esta suspendida', function (): void {
    // **El asiento que estaba mintiendo.** `break_required_after_hours` gobierna
    // RN-12, y RN-12 se evalua pero no abre incidencias hasta que el quiosco
    // registre la pausa declarada. El asiento decia
    // `affects_incident_detection: true` sobre un registro con valor legal, y era
    // falso: cambiar ese umbral no altera ni una incidencia.
    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN)))
        ->patch('/api/v1/compliance-profile', ['break_required_after_hours' => 5])
        ->assertStatus(200);

    $entries = profileAuditEntries();

    expect($entries)->toHaveCount(1);

    expect(profileAuditPayload($entries[0]))->toBe([
        // La verdad de hoy.
        'affects_incident_detection' => false,
        'affects_retention' => false,
        'applies_from' => 'change_forward_only',
        // Y el matiz que explica ese `false`: no es que el campo no gobierne
        // nada, es que lo que gobierna esta suspendido. Sin esto, este asiento
        // seria indistinguible del de un cambio de nombre del convenio.
        'detection_suspended' => true,
        'field' => 'break_required_after_hours',
        'new_value' => 5,
        'previous_value' => 6,
    ]);
})->group('RF-PD-07', 'RN-12', 'RL-04');

it('anota en la fila quien cambio el perfil y cuando', function (): void {
    // Lo que `audit_log` no contesta barato: «¿esta fila es la de serie o alguien
    // la ha ajustado?». Es lo primero que mira quien abre la pantalla y lo que
    // permitira a `doctor` avisar de una instalacion que nunca reviso su convenio.
    $admin = ManagementUsers::withRole(UserRole::ADMIN);

    $antes = DB::table('compliance_profiles')->where('is_default', true)->first();

    // Recien instalado: nadie lo ha tocado, y eso es una afirmacion, no un hueco.
    expect($antes?->updated_at)->toBeNull()
        ->and($antes?->updated_by_user_id)->toBeNull();

    Api::as(ManagementUsers::tokenFor($admin))
        ->patch('/api/v1/compliance-profile', ['min_rest_hours' => 11])
        ->assertStatus(200);

    $despues = DB::table('compliance_profiles')->where('is_default', true)->first();

    expect($despues?->updated_at)->not->toBeNull()
        ->and($despues?->updated_by_user_id)->toBe($admin->id);
})->group('RF-PD-07', 'RL-04');

it('devuelve 422 y no 500 al renombrar el perfil a uno que ya existe', function (): void {
    // El `UNIQUE` de la columna es la garantia; sin traducirlo, el intento salia
    // como una averia del servidor y quien lo hacia no sabia que el problema era
    // el nombre.
    DB::table('compliance_profiles')->insert([
        'name' => 'Convenio de Cantabria',
        'jurisdiction' => 'ES',
        'retention_years' => 4,
        'min_rest_hours' => 12,
        'max_daily_hours' => 9,
        'max_weekly_hours' => 40,
        'break_required_after_hours' => 6,
        'week_starts_on' => 1,
        'holiday_calendar' => '[]',
        'is_default' => false,
    ]);

    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN)))
        ->patch('/api/v1/compliance-profile', ['name' => 'Convenio de Cantabria'])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['name']]);

    // Y no se ha escrito nada: ni la fila ni el asiento.
    expect(profileAuditEntries())->toBe([])
        ->and(DB::table('compliance_profiles')->where('is_default', true)->value('name'))
        ->toBe('ES-hosteleria');
})->group('RF-PD-07');
