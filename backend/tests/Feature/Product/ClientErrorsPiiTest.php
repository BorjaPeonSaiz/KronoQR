<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Identity\PortalLogins;
use Tests\Support\Product\SeededPersonalData;

/*
 * `POST /api/v1/client-errors` NO DEJA ENTRAR NI UN DATO PERSONAL en
 * `error_events` (ADR-048; RF-PD-15, RL-19, regla dura 21).
 *
 * Se siembran nombres, apellidos, correos, documentos, NAF, cuentas, tarjetas,
 * telefonos y codigos de empleado en todas sus formas —en el mensaje, en el
 * valor de cada clave de texto admitida, en claves que no estan en la lista,
 * en valores anidados, en `employee_uuid` y `device_id` del cuerpo y en
 * `app_version`—, con sesion de gestion y con sesion de portal, y se busca
 * cada uno en la tabla y en `GET /api/v1/diagnostics/errors`.
 *
 * Y el control positivo, sin el cual la prueba pasaria tambien si no se
 * escribiera nada: los grupos estan, con su codigo, y el mensaje tecnico sale
 * legible.
 */

uses(RefreshDatabase::class);

/** Los codigos del catalogo web que se van alternando. */
const CLIENT_ERRORS_PII_CODES = ['web.unhandled_error', 'web.unhandled_rejection', 'web.vue_error'];

/**
 * @return array{0: string, 1: string}
 */
function clientErrorsPiiSessions(): array
{
    $quiosco = AttendanceFixtures::scenario();

    return [
        ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN)),
        PortalLogins::open($quiosco['employee']),
    ];
}

it('no guarda ni un dato sembrado, entre por la sesion que entre', function (string $sesion): void {
    [$gestion, $portal] = clientErrorsPiiSessions();
    $token = $sesion === 'gestion' ? $gestion : $portal;

    Api::as($token)
        ->post('/api/v1/client-errors', ['errors' => SeededPersonalData::clientReports(CLIENT_ERRORS_PII_CODES)])
        ->assertStatus(202);

    $filas = DB::table('error_events')->get();
    $texto = SeededPersonalData::textOfRows($filas);

    expect(SeededPersonalData::allLeaksIn($texto))->toBe([])
        // Ni las claves de fuera de la lista ni las anidadas: no entran.
        ->and($texto)->not->toContain('first_name')
        ->and($texto)->not->toContain('meta');

    // Control positivo: hay grupos, de los tres codigos, y el texto tecnico se lee.
    expect($filas->count())->toBeGreaterThan(1)
        ->and($filas->pluck('code')->unique()->sort()->values()->all())->toBe(CLIENT_ERRORS_PII_CODES)
        ->and($filas->pluck('source')->unique()->values()->all())->toBe([$sesion === 'gestion' ? 'admin' : 'portal'])
        ->and($texto)->toContain(SeededPersonalData::TECHNICAL);
})->with(['gestion', 'portal'])->group('RF-PD-15', 'RL-19');

it('tampoco aparece ninguno en el listado del panel', function (): void {
    [$gestion, $portal] = clientErrorsPiiSessions();

    Api::as($portal)
        ->post('/api/v1/client-errors', ['errors' => SeededPersonalData::clientReports(CLIENT_ERRORS_PII_CODES)])
        ->assertStatus(202);

    $listado = Api::as($gestion)->get('/api/v1/diagnostics/errors?per_page=100')->assertOk();

    /** @var list<array<string, mixed>> $grupos */
    $grupos = $listado->json('data');
    $texto = SeededPersonalData::textOfRows(array_map(static fn (array $grupo): object => (object) $grupo, $grupos));

    expect($grupos)->not->toBeEmpty()
        ->and(SeededPersonalData::allLeaksIn($texto))->toBe([])
        ->and($texto)->toContain(SeededPersonalData::TECHNICAL);
})->group('RF-PD-15', 'RL-19');

it('agrupa en uno el mismo fallo de dos personas distintas', function (): void {
    [$gestion] = clientErrorsPiiSessions();

    $informe = static fn (string $persona): array => [
        'code' => 'web.vue_error',
        'occurred_at' => '2026-10-03T09:00:00Z',
        'app_version' => '2.2.0',
        'context' => ['message' => 'TypeError: Cannot set properties of undefined para '.$persona],
    ];

    Api::as($gestion)
        ->post('/api/v1/client-errors', ['errors' => [$informe('Rosa Ficticiana'), $informe('Luz Inventadez')]])
        ->assertStatus(202);

    $fila = DB::table('error_events')->sole();

    expect((int) $fila->occurrences)->toBe(2)
        ->and($fila->message)->toBe('TypeError: Cannot set properties of undefined para …');
})->group('RF-PD-15', 'RL-19');
