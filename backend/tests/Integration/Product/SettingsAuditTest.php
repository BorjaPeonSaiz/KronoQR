<?php

declare(strict_types=1);

use App\Modules\Compliance\Application\UseCase\VerifyAuditChain;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;

/*
 * Todo cambio de configuracion deja asiento en `audit_log` (RF-PD-01, RL-04,
 * regla dura 6).
 *
 * Doc 01 §5, nota de `installation_settings`: *«todo cambio queda auditado,
 * porque algunos afectan al calculo de horas»*. Lo que estas pruebas fijan es
 * que el asiento sea **reconstruible** —quien, cuando, que clave, de que a que—
 * y que la cadena de hash siga intacta despues de varios cambios, porque un
 * `audit_log` que se rompe con una operacion normal deja de valer como prueba.
 *
 * POR QUE NO PODIA SER UNITARIA: la cadena de hash, el encadenado bajo candado y
 * el `jsonb` del payload viven en PostgreSQL. Un doble en memoria daria los tres
 * por buenos sin haberlos comprobado nunca.
 */

uses(RefreshDatabase::class);

/**
 * Los asientos de cambio de configuracion, del mas antiguo al mas reciente.
 *
 * @return list<object{actor_type: string, actor_id: int|null, subject_type: string|null, payload: string}>
 */
function settingAuditEntries(): array
{
    /** @var list<object{actor_type: string, actor_id: int|null, subject_type: string|null, payload: string}> $rows */
    $rows = DB::table('audit_log')
        ->where('action', 'calculation_setting.changed')
        ->orderBy('id')
        ->get()
        ->all();

    return $rows;
}

/**
 * El payload del asiento, con las claves ordenadas.
 *
 * **PostgreSQL no conserva el orden de las claves de un `jsonb`** —las almacena
 * por longitud y despues por bytes—, asi que lo que devuelve la base de datos no
 * viene en el orden en que se escribio. Se ordena aqui para poder comparar el
 * mapa entero de una vez, que es lo que hace que una clave de mas o de menos en
 * el asiento salte a la vista.
 *
 * @param  object{payload: string}  $entry
 * @return array<string, mixed>
 */
function auditPayload(object $entry): array
{
    /** @var array<string, mixed> $payload */
    $payload = json_decode($entry->payload, true, 512, JSON_THROW_ON_ERROR);

    ksort($payload, SORT_STRING);

    return $payload;
}

it('deja un asiento por clave cambiada, con el valor anterior y el posterior', function (): void {
    $admin = ManagementUsers::withRole(UserRole::ADMIN);

    Api::as(ManagementUsers::tokenFor($admin))
        ->patch('/api/v1/settings', [
            'settings' => [
                'ATTENDANCE_DEBOUNCE_SECONDS' => 90,
                'BRANDING_APP_NAME' => 'Hotel Marina',
            ],
        ])
        ->assertStatus(200);

    $entries = settingAuditEntries();

    // Dos claves, dos asientos. Uno por peticion obligaria a decidir un unico
    // `affects_worked_hours` para un conjunto mixto, y ese booleano perderia el
    // matiz para el que existe.
    expect($entries)->toHaveCount(2);

    $byKey = [];

    foreach ($entries as $entry) {
        $payload = auditPayload($entry);
        $key = $payload['key'] ?? null;

        expect($key)->toBeString()
            ->and($entry->actor_type)->toBe('user')
            ->and($entry->actor_id)->toBe($admin->id)
            ->and($entry->subject_type)->toBe('installation_setting');

        if (is_string($key)) {
            $byKey[$key] = $payload;
        }
    }

    // El umbral que SI mueve minutos (RF-AT-06). `was_product_default` es
    // `true` porque una instalacion limpia no tiene ninguna fila: desde la tarea
    // 5.1 el valor de serie vive en el catalogo, y la siembra de la 1.3 —que
    // existia solo porque el adaptador antiguo exigia filas— la retira la
    // migracion de contraccion. El asiento dice la verdad: antes regia el valor
    // del producto.
    expect($byKey['ATTENDANCE_DEBOUNCE_SECONDS'])->toBe([
        'affects_worked_hours' => true,
        'impact' => 'worked_hours',
        'key' => 'ATTENDANCE_DEBOUNCE_SECONDS',
        'new_value' => 90,
        'previous_value' => 60,
        'was_product_default' => true,
    ]);

    // Y la marca, que no mueve ni un minuto y nunca se habia configurado.
    expect($byKey['BRANDING_APP_NAME'])->toBe([
        'affects_worked_hours' => false,
        'impact' => 'presentation',
        'key' => 'BRANDING_APP_NAME',
        'new_value' => 'Hotel Marina',
        'previous_value' => 'KronoQR',
        'was_product_default' => true,
    ]);
})->group('RF-PD-01', 'RL-04');

it('no deja asiento cuando el PATCH no cambia nada', function (): void {
    // Abrir la pantalla y pulsar «guardar» no puede ensuciar el trail: la señal
    // que importa —«alguien cambio el anti-rebote»— quedaria enterrada entre
    // entradas que solo dicen «alguien miro la configuracion».
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

    // 60 es exactamente el valor de serie y no hay fila: no cambia nada.
    Api::as($token)
        ->patch('/api/v1/settings', ['settings' => ['ATTENDANCE_DEBOUNCE_SECONDS' => 60]])
        ->assertStatus(200);

    expect(settingAuditEntries())->toBe([]);
    expect(DB::table('installation_settings')->where('key', 'ATTENDANCE_DEBOUNCE_SECONDS')->exists())
        ->toBeFalse();
})->group('RF-PD-01', 'RL-04');

it('no deja asiento ni fila cuando el cambio se rechaza', function (): void {
    // El asiento y la fila estan en la misma transaccion: o los dos o ninguno.
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

    Api::as($token)
        ->patch('/api/v1/settings', ['settings' => ['LOCALE_AVAILABLE' => ['en']]])
        ->assertStatus(422);

    expect(settingAuditEntries())->toBe([])
        ->and(DB::table('installation_settings')->where('key', 'LOCALE_AVAILABLE')->exists())->toBeFalse();
})->group('RF-PD-01', 'RL-04');

it('mantiene la cadena de auditoria intacta despues de varios cambios', function (): void {
    // Escenario ineludible del §9.4. Una cadena que se rompe con una operacion
    // normal deja de valer como prueba, y el verificador nocturno la denunciaria
    // todos los dias hasta que alguien lo silenciara.
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

    foreach ([10, 11, 12, 9] as $hours) {
        Api::as($token)
            ->patch('/api/v1/settings', ['settings' => ['ATTENDANCE_MAX_SHIFT_HOURS' => $hours]])
            ->assertStatus(200);
    }

    Api::as($token)
        ->patch('/api/v1/settings', [
            'settings' => [
                'BRANDING_ACCENT_COLOR' => '#0f172a',
                'LOCALE_DEFAULT' => 'en',
                'LOCALE_AVAILABLE' => ['es', 'en'],
            ],
        ])
        ->assertStatus(200);

    // Cuatro del umbral (el ultimo cambia de 12 a 9; el tercero vuelve a 12 y
    // tambien cuenta) y dos de la segunda peticion: `LOCALE_AVAILABLE` no cambia
    // nada y por eso no deja asiento.
    expect(settingAuditEntries())->toHaveCount(6);

    expect(app(VerifyAuditChain::class)->handle()->isIntact())->toBeTrue();
})->group('RF-PD-01', 'RL-04', 'RS-07');
