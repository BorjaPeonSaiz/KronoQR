<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\CredentialResolver;
use App\Modules\Kiosk\Application\Port\KioskMetrics;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spectator\Spectator;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Attendance\FakeCredentialResolver;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Kiosk\RecordingKioskMetrics;
use Tests\Support\Time\FixedClock;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * El emparejamiento de una tablet, de punta a punta y contra el contrato
 * (**RF-PD-06**, tarea 5.6).
 *
 * Los tres pasos, en el orden en que ocurren de verdad:
 *
 *   1. `POST /api/v1/kiosk/pair` — la tablet pide y recibe el codigo que muestra.
 *   2. `POST /api/v1/kiosk/pair/confirm` — un `admin` lo teclea en el panel.
 *   3. `POST /api/v1/kiosk/pair/claim` — la tablet recoge su token y ficha.
 *
 * **Cada respuesta se valida contra `openapi.yaml`** con Spectator (RQ-06,
 * ADR-013). No es ceremonia: el cliente TypeScript de las tres SPA se genera de
 * ese fichero, asi que una desviacion aqui rompe los tres frontends a la vez.
 *
 * La otra mitad —que los tres rechazos del `claim` son indistinguibles en
 * respuesta y en tiempo— vive en `PairingRejectionTest`, porque es RS-03 y se
 * mide distinto.
 */

uses(RefreshDatabase::class);

const MOMENTO_DEL_EMPAREJAMIENTO = '2026-09-07 10:00:00';

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
    app()->instance(Clock::class, FixedClock::at(MOMENTO_DEL_EMPAREJAMIENTO));
});

/**
 * Token de una cuenta de gestion con el rol indicado.
 */
function panelDe(UserRole $role = UserRole::ADMIN): string
{
    return ManagementUsers::tokenFor(ManagementUsers::withRole($role));
}

/**
 * Paso 1: la tablet pide un codigo.
 *
 * @return TestResponse<Response>
 */
function pedirCodigo(string $appVersion = '1.4.2'): TestResponse
{
    return Api::guest()->post('/api/v1/kiosk/pair', ['app_version' => $appVersion]);
}

/**
 * Paso 1, ya tipado: el ticket que devuelve la tablet.
 *
 * Existe porque `->json()` devuelve `mixed` y PHPStan 9 no deja acceder a un
 * `mixed` por indice. Un solo sitio con la forma escrita, en lugar de una
 * anotacion repetida en cada prueba.
 *
 * @return array{pairing_id: string, pairing_secret: string, code: string, expires_at: string, poll_interval_seconds: int}
 */
function ticketNuevo(string $appVersion = '1.4.2'): array
{
    /** @var array{pairing_id: string, pairing_secret: string, code: string, expires_at: string, poll_interval_seconds: int} $ticket */
    $ticket = pedirCodigo($appVersion)->json();

    return $ticket;
}

/**
 * Paso 3: la tablet sondea.
 *
 * @return TestResponse<Response>
 */
function sondear(string $pairingId, string $secret): TestResponse
{
    return Api::guest()->post('/api/v1/kiosk/pair/claim', [
        'pairing_id' => $pairingId,
        'pairing_secret' => $secret,
    ]);
}

// --- El camino completo ------------------------------------------------------

it('vincula una tablet en tres pasos y la deja fichando', function (): void {
    // El recorrido entero de RF-PD-06, y el unico que demuestra que el resultado
    // sirve para algo: **el token recien obtenido ficha**. Sin la ultima parte,
    // todo lo demas podria estar bien y la tablet seguir sin poder trabajar.
    $site = WorkforceFixtures::site('Hotel del emparejamiento');
    $department = WorkforceFixtures::department($site);
    $employee = WorkforceFixtures::employee($site, $department);

    // 1. La tablet pide. Es el unico caso que valida tambien la PETICION contra
    // el contrato: los demas ya dan por bueno el cuerpo que envia esta.
    pedirCodigo()->assertStatus(201)->assertValidRequest()->assertValidResponse();

    $ticket = ticketNuevo();

    expect($ticket['code'])->toMatch('/^[0-9]{6}$/')
        // Diez minutos de serie, contados desde el reloj del servidor.
        ->and($ticket['expires_at'])->toStartWith('2026-09-07T10:10:00')
        ->and($ticket['poll_interval_seconds'])->toBe(5);

    // 2. Mientras nadie confirme, el sondeo dice `pending` y nada mas.
    $espera = sondear($ticket['pairing_id'], $ticket['pairing_secret']);
    $espera->assertOk()->assertValidRequest()->assertValidResponse()
        ->assertExactJson(['status' => 'pending']);

    // 3. El administrador teclea el codigo.
    $confirmacion = Api::as(panelDe())->post('/api/v1/kiosk/pair/confirm', [
        'code' => $ticket['code'],
        'name' => 'Recepcion',
    ]);

    $confirmacion->assertOk()->assertValidRequest()->assertValidResponse()
        ->assertJsonPath('device.name', 'Recepcion')
        ->assertJsonPath('device.status', 'active')
        // Quiosco nuevo, no reactivacion.
        ->assertJsonPath('device.reactivated', false)
        // **De que solicitud vino.** Es lo que permite al administrador
        // contrastar con la tablet que tiene delante: un digito mal tecleado
        // confirma OTRA tablet, y sin esto se descubre semanas despues.
        ->assertJsonPath('request.app_version', '1.4.2');

    expect($confirmacion->json('request.requested_at'))->toStartWith('2026-09-07T10:00:00');

    // **La respuesta del panel no lleva el token**: quien lo necesita es la
    // tablet, no el navegador del administrador.
    expect($confirmacion->json())->not->toHaveKey('token');

    // 4. La tablet lo recoge en su siguiente sondeo.
    $recogida = sondear($ticket['pairing_id'], $ticket['pairing_secret']);
    $recogida->assertOk()->assertValidResponse()
        ->assertJsonPath('status', 'paired')
        ->assertJsonPath('device.name', 'Recepcion');

    /** @var string $token */
    $token = $recogida->json('token.value');

    // 5. Y con ese token ficha, que es lo unico que demuestra que sirve.
    Auth::forgetGuards();

    app()->instance(
        CredentialResolver::class,
        FakeCredentialResolver::new()->resolving('FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa', $employee),
    );

    $scanId = Str::uuid7()->toString();

    Api::as($token)
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan', [
            'scan_id' => $scanId,
            'occurred_at' => '2026-09-07T10:02:31Z',
            'qr_payload' => 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa',
        ])
        ->assertOk();

    expect(DB::table('shift_entries')->count())->toBe(1);
})->group('RF-PD-06', 'RQ-06');

// --- Un solo uso -------------------------------------------------------------

it('no entrega el token dos veces con la misma solicitud', function (): void {
    // El «un solo uso» del contrato. El segundo sondeo no es un reenvio
    // idempotente como el del fichaje: alli el mismo `scan_id` devuelve la misma
    // respuesta porque el hecho es el mismo, y aqui el token ya se entrego y no
    // se puede volver a enseñar.
    WorkforceFixtures::site();

    $ticket = ticketNuevo();

    Api::as(panelDe())->post('/api/v1/kiosk/pair/confirm', [
        'code' => $ticket['code'],
        'name' => 'Recepcion',
    ])->assertOk();

    sondear($ticket['pairing_id'], $ticket['pairing_secret'])->assertOk();

    sondear($ticket['pairing_id'], $ticket['pairing_secret'])
        ->assertStatus(422)
        ->assertValidResponse()
        ->assertJsonPath('type', 'urn:kronoqr:problem:pairing-rejected');
})->group('RF-PD-06', 'RS-03');

it('no admite confirmar dos veces el mismo codigo', function (): void {
    // Dos administradores tecleando el mismo codigo no dan de alta dos quioscos.
    // La version concurrente de esto esta en
    // `tests/Integration/Kiosk/PairingConcurrencyTest.php`.
    WorkforceFixtures::site();

    $ticket = ticketNuevo();
    $token = panelDe();

    Api::as($token)->post('/api/v1/kiosk/pair/confirm', [
        'code' => $ticket['code'],
        'name' => 'Recepcion',
    ])->assertOk();

    Api::as($token)->post('/api/v1/kiosk/pair/confirm', [
        'code' => $ticket['code'],
        'name' => 'Otro nombre',
    ])
        ->assertStatus(422)
        ->assertValidResponse()
        ->assertJsonPath('type', 'urn:kronoqr:problem:pairing-code-rejected');

    expect(DB::table('devices')->count())->toBe(1);
})->group('RF-PD-06');

// --- Caducidad ---------------------------------------------------------------

it('rechaza un codigo caducado y deja pedir otro sin intervencion', function (): void {
    // Regla dura 19: la tablet nunca queda atrapada. Un codigo caducado se
    // rechaza y la PWA pide otro; ninguna de las dos cosas exige a nadie tocar
    // la tablet ni entrar por consola.
    WorkforceFixtures::site();

    $ticket = ticketNuevo();

    // Diez minutos y un segundo despues.
    app()->instance(Clock::class, FixedClock::at('2026-09-07 10:10:01'));

    Api::as(panelDe())->post('/api/v1/kiosk/pair/confirm', [
        'code' => $ticket['code'],
        'name' => 'Recepcion',
    ])->assertStatus(422)->assertJsonPath('type', 'urn:kronoqr:problem:pairing-code-rejected');

    // El sondeo tambien lo rechaza, con SU respuesta generica.
    sondear($ticket['pairing_id'], $ticket['pairing_secret'])
        ->assertStatus(422)
        ->assertJsonPath('type', 'urn:kronoqr:problem:pairing-rejected');

    // Y pedir otro codigo funciona, que es la salida del callejon.
    pedirCodigo()->assertStatus(201)->assertValidResponse();
})->group('RF-PD-06');

it('entrega el token de una solicitud ya confirmada aunque el codigo haya caducado', function (): void {
    // **La caducidad deja de aplicar despues del `confirm`.** La fila de
    // `devices` ya existe: negar la recogida dejaria un quiosco dado de alta que
    // ninguna tablet puede usar, y eso solo se arregla por consola — justo lo que
    // RF-PD-06 existe para evitar.
    WorkforceFixtures::site();

    $ticket = ticketNuevo();

    Api::as(panelDe())->post('/api/v1/kiosk/pair/confirm', [
        'code' => $ticket['code'],
        'name' => 'Recepcion',
    ])->assertOk();

    // Media hora despues de que el codigo hubiera caducado.
    app()->instance(Clock::class, FixedClock::at('2026-09-07 10:40:00'));

    sondear($ticket['pairing_id'], $ticket['pairing_secret'])
        ->assertOk()
        ->assertValidResponse()
        ->assertJsonPath('status', 'paired');
})->group('RF-PD-06');

// --- Reactivacion (ADR-028) --------------------------------------------------

it('reactiva la misma fila y el mismo uuid al sustituir una tablet averiada', function (): void {
    // El escenario de ADR-028: se avería el quiosco de Recepcion y se cuelga otra
    // tablet en el mismo sitio. La fila es «el quiosco de Recepcion» y sobrevive
    // al aparato, asi que su historial no se parte en dos.
    WorkforceFixtures::site();
    $token = panelDe();

    $primero = ticketNuevo();
    $alta = Api::as($token)->post('/api/v1/kiosk/pair/confirm', [
        'code' => $primero['code'],
        'name' => 'Recepcion',
    ])->assertOk();

    /** @var string $uuid */
    $uuid = $alta->json('device.uuid');

    // Se desvincula la averiada.
    Api::as($token)->post('/api/v1/devices/'.$uuid.'/unpair')->assertOk();

    // Y se vincula la nueva con el mismo nombre.
    $segundo = ticketNuevo();
    $sustitucion = Api::as($token)->post('/api/v1/kiosk/pair/confirm', [
        'code' => $segundo['code'],
        'name' => 'Recepcion',
    ]);

    $sustitucion->assertOk()->assertValidResponse()
        ->assertJsonPath('device.uuid', $uuid)
        ->assertJsonPath('device.reactivated', true)
        ->assertJsonPath('device.status', 'active');

    // Una sola fila: nada se ha duplicado y nada se ha borrado (regla dura 5).
    expect(DB::table('devices')->count())->toBe(1);
})->group('RF-PD-06');

it('rechaza el nombre de un quiosco ACTIVO con un 422 sobre el campo', function (): void {
    // No es un rechazo del codigo —que sigue siendo valido— sino del formulario:
    // al administrador le cambia lo que tiene que hacer, que es cambiar el nombre
    // y no pedir otro codigo. Por eso son dos respuestas distintas del contrato.
    WorkforceFixtures::site();
    $token = panelDe();

    $primero = ticketNuevo();
    Api::as($token)->post('/api/v1/kiosk/pair/confirm', [
        'code' => $primero['code'],
        'name' => 'Recepcion',
    ])->assertOk();

    $segundo = ticketNuevo();
    Api::as($token)->post('/api/v1/kiosk/pair/confirm', [
        'code' => $segundo['code'],
        'name' => 'Recepcion',
    ])
        ->assertStatus(422)
        ->assertValidResponse()
        ->assertJsonPath('type', 'urn:kronoqr:problem:validation-failed')
        ->assertJsonStructure(['errors' => ['name']]);

    // Y el codigo NO se ha consumido: se puede volver a usar con otro nombre.
    Api::as($token)->post('/api/v1/kiosk/pair/confirm', [
        'code' => $segundo['code'],
        'name' => 'Cocina',
    ])->assertOk();
})->group('RF-PD-06');

// --- Lo que no se guarda en claro --------------------------------------------

it('no guarda en claro ni el codigo ni el secreto', function (): void {
    // Doc 01 §5: como `devices.token_hash` y `credentials.secret_hash`. Un codigo
    // en claro en la base de datos es una credencial de alta de quiosco al
    // alcance de cualquier volcado.
    WorkforceFixtures::site();

    $ticket = ticketNuevo();

    /** @var object{code_hash: string, secret_hash: string} $fila */
    $fila = DB::table('device_pairing_requests')->where('uuid', $ticket['pairing_id'])->firstOrFail();

    expect($fila->code_hash)->not->toBe($ticket['code'])
        ->and($fila->code_hash)->toBe(hash('sha256', $ticket['code']))
        ->and($fila->secret_hash)->not->toBe($ticket['pairing_secret'])
        ->and($fila->secret_hash)->toBe(hash('sha256', $ticket['pairing_secret']));
})->group('RF-PD-06', 'RS-03');

it('purga las solicitudes viejas al crear una nueva', function (): void {
    // Higiene, no retencion legal: aqui no hay ni un dato personal. Es perezosa
    // y no programada, porque un barrido en el scheduler que dejara de correr no
    // purgaria nada en silencio.
    WorkforceFixtures::site();

    $vieja = ticketNuevo();

    // Veinticinco horas despues.
    app()->instance(Clock::class, FixedClock::at('2026-09-08 11:00:00'));

    pedirCodigo();

    expect(DB::table('device_pairing_requests')->where('uuid', $vieja['pairing_id'])->count())->toBe(0)
        ->and(DB::table('device_pairing_requests')->count())->toBe(1);
})->group('RF-PD-06');

// --- La flota ----------------------------------------------------------------

it('lista los quioscos con lo que hace falta para detectar uno averiado', function (): void {
    WorkforceFixtures::site();
    $token = panelDe();

    $ticket = ticketNuevo();
    Api::as($token)->post('/api/v1/kiosk/pair/confirm', [
        'code' => $ticket['code'],
        'name' => 'Recepcion',
    ])->assertOk();

    $listado = Api::as($token)->get('/api/v1/devices');

    $listado->assertOk()->assertValidResponse()
        ->assertJsonPath('devices.0.name', 'Recepcion')
        ->assertJsonPath('devices.0.status', 'active')
        // Recien vinculado: no ha latido nunca, y eso NO es lo mismo que estar al
        // dia. `last_seen_at` nulo lo dice.
        ->assertJsonPath('devices.0.last_seen_at', null)
        ->assertJsonPath('devices.0.pending_queue_size', 0);

    expect($listado->json('devices.0.paired_at'))->toStartWith('2026-09-07T10:00:00');

    // Y nunca el token ni su hash.
    expect($listado->json('devices.0'))->not->toHaveKey('token_hash');
})->group('RF-PD-06', 'RF-PA-07');

it('desvincula un quiosco y devuelve la fila ya revocada', function (): void {
    WorkforceFixtures::site();
    $token = panelDe();

    $ticket = ticketNuevo();
    /** @var string $uuid */
    $uuid = Api::as($token)->post('/api/v1/kiosk/pair/confirm', [
        'code' => $ticket['code'],
        'name' => 'Recepcion',
    ])->json('device.uuid');

    Api::as($token)->post('/api/v1/devices/'.$uuid.'/unpair')
        ->assertOk()
        ->assertValidResponse()
        // El estado de DESPUES, para que el panel repinte la fila sin volver a
        // pedir la lista.
        ->assertJsonPath('status', 'revoked')
        ->assertJsonPath('uuid', $uuid);

    // Nada se borra (regla dura 5).
    expect(DB::table('devices')->where('uuid', $uuid)->count())->toBe(1);
})->group('RF-PD-06');

it('desvincula de forma idempotente', function (): void {
    // La segunda pulsacion de un boton no es un error para quien la da.
    WorkforceFixtures::site();
    $token = panelDe();

    $ticket = ticketNuevo();
    /** @var string $uuid */
    $uuid = Api::as($token)->post('/api/v1/kiosk/pair/confirm', [
        'code' => $ticket['code'],
        'name' => 'Recepcion',
    ])->json('device.uuid');

    Api::as($token)->post('/api/v1/devices/'.$uuid.'/unpair')->assertOk();
    Api::as($token)->post('/api/v1/devices/'.$uuid.'/unpair')
        ->assertOk()
        ->assertJsonPath('status', 'revoked');
})->group('RF-PD-06');

it('responde 404 al desvincular un quiosco que no existe', function (): void {
    WorkforceFixtures::site();

    Api::as(panelDe())
        ->post('/api/v1/devices/0199f3c9-1b7d-7a44-8e02-3c4d5e6f7a81/unpair')
        ->assertStatus(404)
        ->assertValidResponse();
})->group('RF-PD-06');

// --- Cuando la instalacion no puede atender (RS-02) ---------------------------

it('responde 503 cuando hay demasiadas solicitudes vivas', function (): void {
    // **La cota que el limitador por IP no puede poner.** Aquel frena a UN
    // origen; quien reparta el trafico entre direcciones lo esquiva, y diez mil
    // solicitudes vivas estrechan el espacio de sorteo hasta que el alta legitima
    // de un quiosco empieza a fallar — la unica forma de negar ese alta desde
    // fuera.
    config()->set('kiosk.pairing.max_live_pending', 2);

    WorkforceFixtures::site();

    pedirCodigo()->assertStatus(201);
    pedirCodigo()->assertStatus(201);

    pedirCodigo()
        ->assertStatus(503)
        ->assertValidResponse()
        ->assertJsonPath('type', 'urn:kronoqr:problem:service-unavailable');
})->group('RF-PD-06', 'RS-02', 'RQ-06');

it('vuelve a admitir solicitudes en cuanto las vivas caducan', function (): void {
    // **Regla dura 19: el hueco aparece solo.** La cota protege la tabla, pero no
    // puede convertirse en la razon por la que un quiosco legitimo se queda sin
    // dar de alta. Nadie tiene que intervenir: cada peticion purga antes las
    // pendientes ya caducadas.
    config()->set('kiosk.pairing.max_live_pending', 1);

    WorkforceFixtures::site();

    pedirCodigo()->assertStatus(201);
    pedirCodigo()->assertStatus(503);

    // Diez minutos y un segundo despues, la primera ya no esta viva.
    app()->instance(Clock::class, FixedClock::at('2026-09-07 10:10:01'));

    pedirCodigo()->assertStatus(201);
})->group('RF-PD-06', 'RS-02');

it('libera el codigo de una pendiente caducada en la peticion siguiente', function (): void {
    // El indice unico parcial no sabe de relojes: sin la purga inmediata, una
    // pendiente caducada seguiria reservando su `code_hash` las 24 h que tarda el
    // barrido por antiguedad. Con diez minutos de vida, eso es un goteo que
    // estrecha el sorteo sin que nadie lo vea.
    WorkforceFixtures::site();

    $caducada = ticketNuevo();

    app()->instance(Clock::class, FixedClock::at('2026-09-07 10:10:01'));

    pedirCodigo()->assertStatus(201);

    expect(DB::table('device_pairing_requests')->where('uuid', $caducada['pairing_id'])->count())->toBe(0)
        // Y no se ha llevado por delante a las que si valen.
        ->and(DB::table('device_pairing_requests')->count())->toBe(1);
})->group('RF-PD-06');

it('no purga una pendiente que todavia esta viva', function (): void {
    // El control negativo de la purga: si borrara de mas, la tablet que esta
    // mostrando su codigo lo perderia a mitad del emparejamiento.
    WorkforceFixtures::site();

    $viva = ticketNuevo();

    // Nueve minutos despues: dentro de plazo.
    app()->instance(Clock::class, FixedClock::at('2026-09-07 10:09:00'));

    pedirCodigo()->assertStatus(201);

    expect(DB::table('device_pairing_requests')->where('uuid', $viva['pairing_id'])->count())->toBe(1);

    // Y sigue sirviendo.
    sondear($viva['pairing_id'], $viva['pairing_secret'])
        ->assertOk()
        ->assertJsonPath('status', 'pending');
})->group('RF-PD-06');

// --- Instrumentacion (DoD §10.3) ---------------------------------------------

it('cuenta cada desenlace del emparejamiento con su motivo, que nunca sale en la respuesta', function (): void {
    // Lo que puede romperse sin que nadie lo note es que alguien anada un camino
    // de rechazo y se olvide de contarlo: entonces un pico de intentos fallidos
    // no aparece en ningun panel y «alguien esta probando secretos» se confunde
    // con «hoy se dan de alta cuatro quioscos».
    //
    // El motivo va en la metrica —que `/metrics` sirve solo a la red interna— y
    // JAMAS en la respuesta (regla dura 17): eso lo comprueba
    // `PairingRejectionTest`, que exige los tres cuerpos identicos byte a byte.
    $metricas = new RecordingKioskMetrics;
    app()->instance(KioskMetrics::class, $metricas);

    WorkforceFixtures::site();

    $ticket = ticketNuevo();

    // Un sondeo con el secreto equivocado.
    sondear($ticket['pairing_id'], str_repeat('z', 43))->assertStatus(422);

    // Y una solicitud que no existe.
    sondear('0199aaaa-bbbb-7ccc-8ddd-eeeeffff0000', str_repeat('z', 43))->assertStatus(422);

    Api::as(panelDe())->post('/api/v1/kiosk/pair/confirm', [
        'code' => $ticket['code'],
        'name' => 'Recepcion',
    ])->assertOk();

    sondear($ticket['pairing_id'], $ticket['pairing_secret'])->assertOk();

    expect($metricas->requested)->toBe(1)
        ->and($metricas->confirmed)->toBe(1)
        ->and($metricas->claimed)->toBe(1)
        // Con el motivo real, que es lo que hace util la metrica.
        ->and($metricas->rejected)->toBe(['secret', 'unknown']);
})->group('RF-PD-06');

it('cuenta tambien el rechazo por cota de solicitudes vivas', function (): void {
    config()->set('kiosk.pairing.max_live_pending', 1);

    $metricas = new RecordingKioskMetrics;
    app()->instance(KioskMetrics::class, $metricas);

    WorkforceFixtures::site();

    pedirCodigo()->assertStatus(201);
    pedirCodigo()->assertStatus(503);

    expect($metricas->rejected)->toBe(['capacity']);
})->group('RF-PD-06', 'RS-02');
