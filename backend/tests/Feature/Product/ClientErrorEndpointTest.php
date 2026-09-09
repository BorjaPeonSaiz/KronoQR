<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Identity\PortalLogins;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `POST /api/v1/client-errors` — el panel y el portal vacian su buffer
 * (RF-PD-15, decision 7 de la ficha 5.12).
 *
 * Las tres afirmaciones que sostienen este endpoint:
 *
 *   1. **El servidor decide el origen por el token**, nunca el cuerpo.
 *   2. **El servidor sanea otra vez**, aunque el cliente diga que ya lo hizo.
 *   3. **Nunca devuelve el historico**: consultar es otra potestad y otra ruta.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    WorkforceFixtures::site();
    // Los limites de esta zona se prueban en su propio caso; el resto no puede
    // depender de cuantas peticiones lleve el fichero.
    RateLimiter::clear('client-errors');
});

/**
 * Un error **con la forma exacta que emite
 * `packages/web-kit/src/clientErrors.ts`**.
 *
 * El contexto por omision es el literal de `installWebErrorReporting` para un
 * error de Vue: `message`, `component` y `hook`. No es un detalle de estilo —la
 * revision lo pidio con todas las letras—: con un payload inventado, estas
 * pruebas pasaban mientras el producto guardaba filas sin mensaje y con el
 * contexto vacio en la instalacion de un cliente.
 *
 * @param  array<string, scalar>|null  $context
 * @return array<string, mixed>
 */
function errorDePanel(string $code = 'web.vue_error', ?array $context = null): array
{
    return [
        'code' => $code,
        'occurred_at' => now()->toIso8601ZuluString('microsecond'),
        'app_version' => '2.2.0',
        'context' => $context ?? [
            'message' => 'TypeError: Cannot read properties of undefined (reading id)',
            'component' => 'WorkdaysView',
            'hook' => 'setup function',
        ],
    ];
}

it('acepta el buffer de una sesion de gestion de cualquier rol', function (UserRole $role): void {
    // Quien sufre el error es quien lo reporta: un `empleado` con el panel
    // abierto sufre errores igual que un `admin`.
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole($role));

    Api::as($token)
        ->post('/api/v1/client-errors', ['errors' => [errorDePanel()]])
        ->assertStatus(202)
        ->assertExactJson(['accepted' => 1]);

    expect(DB::table('error_events')->where('source', 'admin')->count())->toBe(1);
})->with([
    'admin' => [UserRole::ADMIN],
    'rrhh' => [UserRole::RRHH],
    'auditor' => [UserRole::AUDITOR],
    'responsable de departamento' => [UserRole::RESPONSABLE_DEPARTAMENTO],
])->group('RF-PD-15');

it('acepta el buffer de una sesion de portal y la marca como portal', function (): void {
    $siteId = WorkforceFixtures::onlySiteId();
    $uuid = WorkforceFixtures::employee($siteId);

    Api::as(PortalLogins::open($uuid))
        ->post('/api/v1/client-errors', ['errors' => [errorDePanel()]])
        ->assertStatus(202)
        ->assertExactJson(['accepted' => 1]);

    $fila = DB::table('error_events')->first();

    expect($fila?->source)->toBe('portal')
        // Solo el uuid, nunca el nombre (regla dura 21). Dice desde que portal
        // falla algo, que es lo que distingue «le pasa a todo el mundo» de «le
        // pasa a esta persona con su movil».
        ->and($fila?->employee_uuid)->toBe($uuid);
})->group('RF-PD-15', 'RF-ID-07');

it('el origen lo decide el token aunque el cuerpo intente decir otra cosa', function (): void {
    // El contrato no declara un campo `source`, asi que enviarlo es un `422`: no
    // se ignora en silencio, se dice que no. Es la unica forma de que quien lo
    // intento no se vaya convencido de haberlo conseguido.
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

    Api::as($token)->post('/api/v1/client-errors', [
        'errors' => [array_merge(errorDePanel(), ['source' => 'scheduler'])],
    ])->assertStatus(422);

    Api::as($token)->post('/api/v1/client-errors', [
        'errors' => [errorDePanel()],
        'app' => 'kiosk',
    ])->assertStatus(422);

    expect(DB::table('error_events')->count())->toBe(0);
})->group('RF-PD-15');

it('rechaza un codigo de quiosco desde una sesion de gestion', function (): void {
    /*
     * LA CORRECCION DE LA REVISION DE SEGURIDAD, y esta prueba fijaba antes lo
     * contrario. Con solo el patron del contrato, una sesion de gestion o de
     * portal podia enviar `kiosk.camera.unavailable` y **fabricar una fila
     * `critical`** que dispara la alerta al IT del cliente de madrugada, por un
     * error de camara que ningun quiosco ha tenido.
     *
     * Ahora hay dos controles y basta uno: el codigo no esta en el catalogo de
     * su origen —`422`— y, aunque llegara, `ErrorLevel::forClientCode()` solo
     * devuelve `critical` con origen `kiosk`.
     */
    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN)))
        ->post('/api/v1/client-errors', ['errors' => [errorDePanel('kiosk.camera.unavailable')]])
        ->assertStatus(422);

    expect(DB::table('error_events')->count())->toBe(0);
})->group('RF-PD-15', 'RS-04');

it('rechaza un codigo de quiosco desde una sesion de portal', function (): void {
    $uuid = WorkforceFixtures::employee(WorkforceFixtures::onlySiteId());

    Api::as(PortalLogins::open($uuid))
        ->post('/api/v1/client-errors', ['errors' => [errorDePanel('kiosk.camera.unavailable')]])
        ->assertStatus(422);

    expect(DB::table('error_events')->count())->toBe(0);
})->group('RF-PD-15', 'RS-04');

it('ningun error del panel o del portal puede quedar como critico', function (): void {
    // La otra mitad: los tres codigos del catalogo de web se guardan, y ninguno
    // sube de `error`. Un panel no decide que es critico en esta instalacion.
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

    Api::as($token)->post('/api/v1/client-errors', [
        'errors' => [
            errorDePanel('web.vue_error'),
            errorDePanel('web.unhandled_error', ['message' => 'ReferenceError: x is not defined', 'source' => '/app.js:12']),
            errorDePanel('web.unhandled_rejection', ['message' => 'Error: fetch failed']),
        ],
    ])->assertStatus(202)->assertExactJson(['accepted' => 3]);

    expect(DB::table('error_events')->where('level', 'critical')->count())->toBe(0)
        ->and(DB::table('error_events')->where('level', 'error')->count())->toBe(3);
})->group('RF-PD-15');

it('rechaza un codigo que no esta en ningun catalogo', function (): void {
    // Un codigo libre daba una huella nueva por envio, sin techo: seiscientas
    // filas por minuto y actor. El catalogo corta la entropia en el origen.
    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN)))
        ->post('/api/v1/client-errors', ['errors' => [errorDePanel('web.inventado_por_alguien')]])
        ->assertStatus(422);

    expect(DB::table('error_events')->count())->toBe(0);
})->group('RF-PD-15');

it('guarda el texto del error del navegador como mensaje, y no lo repite en el contexto', function (): void {
    /*
     * El defecto que corrige la revision: la fila decia literalmente
     * `web.vue_error` y nada mas, y —peor— **todos los `web.vue_error` de la
     * instalacion colapsaban en un unico grupo**, porque la huella se calcula
     * sobre el mensaje.
     */
    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN)))
        ->post('/api/v1/client-errors', ['errors' => [errorDePanel()]])
        ->assertStatus(202);

    $fila = DB::table('error_events')->first();

    /** @var array<string, mixed> $contexto */
    $contexto = json_decode((string) ($fila->context ?? '{}'), true, 512, JSON_THROW_ON_ERROR);

    expect($fila?->message)->toContain('TypeError')
        ->and($fila?->message)->toContain('Cannot read properties of undefined')
        /*
         * El contexto llega entero —eran las claves que la lista no tenia— y sin
         * `message`, que ha ascendido a columna.
         *
         * El orden es el que impone `jsonb` de PostgreSQL (por longitud de
         * clave y despues alfabetico), no el de la lista ni el del cliente: el
         * tipo no conserva el orden de insercion. Se afirma tal cual para que
         * esta prueba no mienta sobre lo que la base de datos garantiza.
         */
        ->and($contexto)->toBe(['hook' => 'setup function', 'component' => 'WorkdaysView']);
})->group('RF-PD-15');

it('dos errores de Vue con textos distintos son dos grupos', function (): void {
    // La consecuencia visible de lo anterior: sin el mensaje, la huella era la
    // misma para todos y el panel enseñaba una sola linea con miles de
    // repeticiones de cosas que no tienen nada que ver.
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

    Api::as($token)->post('/api/v1/client-errors', [
        'errors' => [
            errorDePanel('web.vue_error', [
                'message' => 'TypeError: Cannot read properties of undefined (reading id)',
                'component' => 'WorkdaysView',
                'hook' => 'setup function',
            ]),
            errorDePanel('web.vue_error', [
                'message' => 'RangeError: Maximum call stack size exceeded',
                'component' => 'ReportsView',
                'hook' => 'render function',
            ]),
        ],
    ])->assertStatus(202)->assertExactJson(['accepted' => 2]);

    expect(DB::table('error_events')->count())->toBe(2);
})->group('RF-PD-15');

it('sanea en el servidor aunque el cliente envie datos personales', function (): void {
    // El saneado del cliente es una cortesia, no una garantia: un cliente es
    // codigo que corre en un dispositivo del cliente. Este historico viaja al
    // fabricante dentro del paquete de diagnostico (regla dura 21, RL-19).
    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN)))
        ->post('/api/v1/client-errors', [
            'errors' => [errorDePanel('web.vue_error', [
                // Por el camino real: el texto del error viene en `message` y
                // asciende a la columna, asi que lo que se sanea es lo que el
                // navegador escribio de verdad.
                'message' => "Error: no se pudo cargar a 'Ana Ruiz' (ana.ruiz@hotel.es, 12345678Z, 600123456) "
                    .'del turno de las 22:15',
                'cause' => 'ana.ruiz@hotel.es no existe',
                'component' => 'WorkdaysView',
                'employee_name' => 'Ana Ruiz',
                'pin' => '4321',
            ])],
        ])
        ->assertStatus(202);

    $fila = DB::table('error_events')->first();
    $todo = json_encode((array) $fila, JSON_UNESCAPED_UNICODE);

    expect($todo)->not->toContain('Ana Ruiz')
        ->and($todo)->not->toContain('ana.ruiz@hotel.es')
        ->and($todo)->not->toContain('12345678Z')
        ->and($todo)->not->toContain('600123456')
        ->and($todo)->not->toContain('22:15')
        ->and($todo)->not->toContain('4321')
        // Y las claves que no estan en la lista de permitidos ni siquiera
        // aparecen.
        ->and($todo)->not->toContain('employee_name');
})->group('RF-PD-15', 'RL-19');

it('el quiosco recibe 403: tiene su canal dentro del latido', function (): void {
    // Abrirle un segundo canal competiria con la cola de fichajes por la misma
    // red que ya le esta fallando (regla dura 19).
    $escenario = AttendanceFixtures::scenario();

    Api::as($escenario['token'])
        ->post('/api/v1/client-errors', ['errors' => [errorDePanel('kiosk.camera.unavailable')]])
        ->assertStatus(403);

    expect(DB::table('error_events')->count())->toBe(0);
})->group('RF-PD-15', 'RS-04');

it('no hay canal anonimo', function (): void {
    // Una superficie publica que escribe filas en la base de datos seria un
    // vector de denegacion de servicio contra la misma base por la que pasa cada
    // fichaje (ADR-010).
    Api::guest()->post('/api/v1/client-errors', ['errors' => [errorDePanel()]])->assertStatus(401);
})->group('RF-PD-15');

it('nunca devuelve el historico, solo el recuento aceptado', function (): void {
    // Que cualquier sesion pueda escribir aqui no puede convertirse en que pueda
    // leer lo que escriben las demas.
    $respuesta = Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)))
        ->post('/api/v1/client-errors', ['errors' => [errorDePanel()]])
        ->assertStatus(202);

    expect(array_keys((array) $respuesta->json()))->toBe(['accepted']);
})->group('RF-PD-15');

it('admite cincuenta errores por envio y rechaza cincuenta y uno', function (): void {
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

    $cincuenta = array_map(
        static fn (int $i): array => errorDePanel('web.vue_error', ['component' => 'Vista'.$i]),
        range(1, 50),
    );

    Api::as($token)->post('/api/v1/client-errors', ['errors' => $cincuenta])
        ->assertStatus(202)
        ->assertExactJson(['accepted' => 50]);

    Api::as($token)->post('/api/v1/client-errors', ['errors' => [...$cincuenta, errorDePanel()]])
        ->assertStatus(422);
})->group('RF-PD-15');

it('agrupa por huella: cincuenta veces el mismo error son una fila', function (): void {
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

    $mismo = array_fill(0, 50, errorDePanel('web.unhandled_rejection', ['cause' => 'timeout']));

    Api::as($token)->post('/api/v1/client-errors', ['errors' => $mismo])->assertStatus(202);

    expect(DB::table('error_events')->count())->toBe(1)
        ->and(DB::table('error_events')->where('occurrences', 50)->count())->toBe(1);
})->group('RF-PD-15');

it('rechaza un cuerpo vacio y un codigo que no sigue el catalogo', function (): void {
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

    Api::as($token)->post('/api/v1/client-errors', ['errors' => []])->assertStatus(422);
    Api::as($token)->post('/api/v1/client-errors', [
        'errors' => [errorDePanel('Se rompio al cargar la ficha de Ana')],
    ])->assertStatus(422);
})->group('RF-PD-15');

it('tiene su propia zona de limite, por token', function (): void {
    // 12 por minuto y token de serie: ni los 120 de la gestion -cada peticion
    // puede traer cincuenta errores- ni los 3 del diagnostico, que cortarian el
    // drenaje normal del buffer.
    Config::set('product.client_errors_rate_limit_per_minute', 2);

    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

    Api::as($token)->post('/api/v1/client-errors', ['errors' => [errorDePanel()]])->assertStatus(202);
    Api::as($token)->post('/api/v1/client-errors', ['errors' => [errorDePanel()]])->assertStatus(202);
    Api::as($token)->post('/api/v1/client-errors', ['errors' => [errorDePanel()]])->assertStatus(429);
})->group('RF-PD-15');
