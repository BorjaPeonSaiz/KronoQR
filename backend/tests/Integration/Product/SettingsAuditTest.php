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

it('deja constancia de que el codigo de servicio cambio, y nunca del codigo', function (): void {
    // RF-KI-08, tarea 3.3, decision 6. `KIOSK_SERVICE_CODE` es un secreto
    // compartido con cada tablet del hotel y `audit_log` se enseña en una
    // inspeccion: RL-04 exige saber QUIEN lo cambio y CUANDO —eso sigue— pero el
    // valor no aporta nada a esa pregunta y si abriria una via para leerlo.
    //
    // No se redacta con `'***'` ni con la longitud: un asiento que dijera «pasa
    // de 8 a 10 cifras» seria informacion sobre el secreto escrita en la tabla
    // que se enseña. O esta el valor, o esta la marca de que no esta.
    $admin = ManagementUsers::withRole(UserRole::ADMIN);

    Api::as(ManagementUsers::tokenFor($admin))
        ->patch('/api/v1/settings', ['settings' => ['KIOSK_SERVICE_CODE' => '48392017']])
        ->assertStatus(200);

    $entries = settingAuditEntries();

    expect($entries)->toHaveCount(1);

    $payload = auditPayload($entries[0]);

    expect($payload)->toBe([
        'affects_worked_hours' => false,
        'impact' => 'presentation',
        'key' => 'KIOSK_SERVICE_CODE',
        // La marca, y NI `previous_value` NI `new_value`.
        'value_redacted' => true,
        'was_product_default' => true,
    ])
        // Quien lo hizo sigue estando, que es la mitad del asiento que importa.
        ->and($entries[0]->actor_id)->toBe($admin->id)
        ->and($entries[0]->actor_type)->toBe('user')
        // Y el codigo no esta en ninguna parte del asiento, ni por descuido.
        ->and($entries[0]->payload)->not->toContain('48392017');
})->group('RF-PD-01', 'RF-KI-08', 'RL-04');

it('mantiene el antes y el despues en las claves que no son confidenciales', function (): void {
    // El guarda del guarda: si la redaccion se aplicara de mas, el trail de los
    // umbrales operativos se convertiria en un «alguien cambio algo» inservible
    // para investigar una discrepancia de nomina seis meses despues.
    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN)))
        ->patch('/api/v1/settings', ['settings' => ['ATTENDANCE_DEBOUNCE_SECONDS' => 90]])
        ->assertStatus(200);

    $payload = auditPayload(settingAuditEntries()[0]);

    expect($payload)->toHaveKeys(['previous_value', 'new_value'])
        ->and($payload)->not->toHaveKey('value_redacted');
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

it('deja asiento de impacto de cumplimiento al activar el fichaje de pausa', function (): void {
    // RF-AT-12 y decision 8 de la ficha 3.5. Activarlo no mueve ni un minuto del
    // calculo —por eso `affects_worked_hours` es `false`— pero **reactiva RN-12**
    // y desde la noche siguiente empiezan a abrirse incidencias `missing_break`.
    // El asiento tiene que decirlo: quien lea el registro seis meses despues
    // necesita poder explicar por que aquel dia aparecieron cuarenta avisos.
    $admin = ManagementUsers::withRole(UserRole::ADMIN);

    Api::as(ManagementUsers::tokenFor($admin))
        ->patch('/api/v1/settings', ['settings' => ['ATTENDANCE_BREAK_CLOCKING' => 'enabled']])
        ->assertStatus(200);

    $entries = settingAuditEntries();

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->actor_type)->toBe('user')
        ->and($entries[0]->actor_id)->toBe($admin->id)
        ->and($entries[0]->subject_type)->toBe('installation_setting')
        ->and(auditPayload($entries[0]))->toBe([
            'affects_worked_hours' => false,
            'impact' => 'compliance_review',
            'key' => 'ATTENDANCE_BREAK_CLOCKING',
            'new_value' => 'enabled',
            'previous_value' => 'disabled',
            'was_product_default' => true,
        ]);

    // Y la cadena sigue intacta: un asiento nuevo no la rompe (regla dura 6).
    expect(app(VerifyAuditChain::class)->handle()->isIntact())->toBeTrue();
})->group('RF-PD-01', 'RF-AT-12', 'RL-04');

it('separa en el asiento el interruptor del resumen semanal de un cambio de marca', function (): void {
    // Tarea 3.12, decision 14 de la segunda vuelta. `WEEKLY_SUMMARY_EMAIL` es el
    // cambio con mas consecuencias en privacidad que un administrador puede
    // hacer desde el panel: encenderlo saca cada lunes nombres y horas de la
    // plantilla por SMTP, hacia un buzon cuyo plazo de conservacion no controla
    // el producto. Con `impact: presentation` —como estaba— ese asiento quedaba
    // en el trail indistinguible de un cambio de color, y quien audita
    // proteccion de datos tiene que poder separarlos con una sola consulta.
    //
    // Y `affects_worked_hours` sigue siendo `false`: no mueve ni un minuto del
    // registro. Las dos cosas a la vez son justamente lo que el enumerado de
    // cuatro casos permite decir y un booleano no.
    $admin = ManagementUsers::withRole(UserRole::ADMIN);

    Api::as(ManagementUsers::tokenFor($admin))
        ->patch('/api/v1/settings', ['settings' => ['WEEKLY_SUMMARY_EMAIL' => 'enabled']])
        ->assertStatus(200);

    $entries = settingAuditEntries();

    expect($entries)->toHaveCount(1)
        ->and(auditPayload($entries[0]))->toBe([
            'affects_worked_hours' => false,
            'impact' => 'data_disclosure',
            'key' => 'WEEKLY_SUMMARY_EMAIL',
            'new_value' => 'enabled',
            'previous_value' => 'disabled',
            'was_product_default' => true,
        ]);
})->group('RF-PD-01', 'RF-PR-05', 'RL-04');
