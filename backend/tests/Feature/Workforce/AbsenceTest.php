<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * El CRUD de `/api/v1/absences` (**RF-GP-04**, tarea 3.10), extremo a extremo y
 * contra el contrato de la API.
 *
 * Lo que estas pruebas defienden:
 *
 *   - **Regla dura 5 y RN-13**: corregir no sobrescribe, crea una version nueva
 *     y la anterior se queda con su tipo, sus fechas y su autor.
 *   - **Anular es un hecho**, no un `DELETE`: la fila sigue ahi con autor,
 *     momento y motivo.
 *   - **Regla dura 6**: las tres operaciones quedan en `audit_log` con su accion
 *     propia, y **sin la nota en el payload** (regla dura 21).
 *   - La invariante del esquema —`absences_no_overlap`— se traduce a `409` y no
 *     a un `500`.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

/**
 * @return array{token: string, employee: string}
 */
function contextoDeAusencia(): array
{
    $site = WorkforceFixtures::site('Hotel de ausencias');

    return [
        'token' => ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)),
        'employee' => WorkforceFixtures::employee($site, WorkforceFixtures::department($site, 'Cocina')),
    ];
}

/**
 * Registra una ausencia y devuelve su `uuid`.
 *
 * @param  array<string, mixed>  $overrides
 */
function registrarAusencia(string $token, string $employeeUuid, array $overrides = []): string
{
    $respuesta = Api::as($token)
        ->post('/api/v1/absences', [
            'employee_uuid' => $employeeUuid,
            'type' => 'vacation',
            'starts_on' => '2026-03-02',
            'ends_on' => '2026-03-06',
            ...$overrides,
        ])
        ->assertValidRequest()
        ->assertValidResponse(201);

    /** @var string $uuid */
    $uuid = $respuesta->json('uuid');

    return $uuid;
}

it('registra una ausencia con sus dias contados de extremo a extremo', function (): void {
    $contexto = contextoDeAusencia();

    Api::as($contexto['token'])
        ->post('/api/v1/absences', [
            'employee_uuid' => $contexto['employee'],
            'type' => 'sick_leave',
            'starts_on' => '2026-03-02',
            'ends_on' => '2026-03-06',
        ])
        ->assertValidRequest()
        ->assertValidResponse(201)
        ->assertJsonPath('employee_uuid', $contexto['employee'])
        ->assertJsonPath('type', 'sick_leave')
        ->assertJsonPath('starts_on', '2026-03-02')
        ->assertJsonPath('ends_on', '2026-03-06')
        // Los dos extremos entran: del 2 al 6 son cinco dias.
        ->assertJsonPath('days', 5)
        ->assertJsonPath('status', 'active')
        ->assertJsonPath('version', 1)
        ->assertJsonPath('supersedes_uuid', null)
        ->assertJsonPath('superseded_by_uuid', null)
        ->assertJsonPath('change_reason', null)
        ->assertJsonPath('voided_at', null);
})->group('RF-GP-04');

it('rechaza con 409 una ausencia que pisa otra activa de la misma persona', function (): void {
    // La invariante la hace cumplir PostgreSQL, no una consulta previa. `409` y
    // no `422`: el cuerpo es valido, lo que no encaja es el estado.
    $contexto = contextoDeAusencia();

    registrarAusencia($contexto['token'], $contexto['employee']);

    Api::as($contexto['token'])
        ->post('/api/v1/absences', [
            'employee_uuid' => $contexto['employee'],
            'type' => 'leave',
            'starts_on' => '2026-03-06',
            'ends_on' => '2026-03-09',
        ])
        ->assertStatus(409)
        ->assertJsonPath('type', 'urn:kronoqr:problem:conflict');

    expect(DB::table('absences')->count())->toBe(1);
})->group('RF-GP-04');

it('rechaza con 422 lo que es una errata del formulario', function (array $cuerpo, string $campo): void {
    $contexto = contextoDeAusencia();

    Api::as($contexto['token'])
        ->post('/api/v1/absences', ['employee_uuid' => $contexto['employee'], ...$cuerpo])
        ->assertStatus(422)
        ->assertJsonPath('type', 'urn:kronoqr:problem:validation-failed')
        ->assertJsonPath('errors.'.$campo.'.0', fn (mixed $mensaje): bool => is_string($mensaje));
})->with([
    'periodo invertido' => [
        ['type' => 'vacation', 'starts_on' => '2026-03-10', 'ends_on' => '2026-03-01'],
        'ends_on',
    ],
    'tipo inventado' => [
        ['type' => 'excedencia', 'starts_on' => '2026-03-02', 'ends_on' => '2026-03-06'],
        'type',
    ],
    'fecha que no existe' => [
        ['type' => 'vacation', 'starts_on' => '2026-02-31', 'ends_on' => '2026-03-06'],
        'starts_on',
    ],
    // `other` sin nota: sin texto no describe nada.
    'otro sin nota' => [
        ['type' => 'other', 'starts_on' => '2026-03-02', 'ends_on' => '2026-03-06'],
        'note',
    ],
])->group('RF-GP-04');

it('rechaza con 422 y no con 404 la ausencia de alguien que no existe', function (): void {
    // El identificador va en el CUERPO, asi que hay un campo que corregir en el
    // formulario. Un `404` diria que la ruta no lleva a ninguna parte, y la ruta
    // esta bien.
    $contexto = contextoDeAusencia();

    Api::as($contexto['token'])
        ->post('/api/v1/absences', [
            'employee_uuid' => '0199f0c2-1f4a-7c3e-9b21-000000000000',
            'type' => 'vacation',
            'starts_on' => '2026-03-02',
            'ends_on' => '2026-03-06',
        ])
        ->assertStatus(422)
        ->assertJsonPath('type', 'urn:kronoqr:problem:validation-failed');
})->group('RF-GP-04');

it('rechaza una ausencia que no toca ningun dia de la relacion laboral', function (): void {
    // Decision 2 de la ficha: esos dias no eran dias de trabajo. El alta de la
    // semilla es el 2026-01-01.
    $contexto = contextoDeAusencia();

    Api::as($contexto['token'])
        ->post('/api/v1/absences', [
            'employee_uuid' => $contexto['employee'],
            'type' => 'vacation',
            'starts_on' => '2025-06-01',
            'ends_on' => '2025-06-05',
        ])
        ->assertStatus(422);
})->group('RF-GP-04');

it('corregir crea una version nueva y conserva la anterior con autor y motivo', function (): void {
    // **RN-13 y regla dura 5.** La respuesta lleva un `uuid` NUEVO, y la fila
    // anterior sigue en la tabla con su tipo y sus fechas originales.
    $contexto = contextoDeAusencia();

    $original = registrarAusencia($contexto['token'], $contexto['employee'], ['type' => 'sick_leave']);

    $respuesta = Api::as($contexto['token'])
        ->patch('/api/v1/absences/'.$original, [
            'ends_on' => '2026-03-13',
            'reason' => 'El parte de baja se prorrogo una semana.',
        ])
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('version', 2)
        ->assertJsonPath('supersedes_uuid', $original)
        ->assertJsonPath('ends_on', '2026-03-13')
        // Lo omitido conserva su valor.
        ->assertJsonPath('type', 'sick_leave')
        ->assertJsonPath('starts_on', '2026-03-02')
        ->assertJsonPath('change_reason', 'El parte de baja se prorrogo una semana.')
        ->assertJsonPath('status', 'active');

    /** @var string $nueva */
    $nueva = $respuesta->json('uuid');

    expect($nueva)->not->toBe($original);

    // LA ANTERIOR SIGUE AHI, intacta y con el puntero a la que la sustituye.
    $anterior = DB::table('absences')->where('uuid', $original)->first();

    expect($anterior)->not->toBeNull();
    expect($anterior?->status)->toBe('superseded');
    expect($anterior?->ends_on)->toBe('2026-03-06');
    // Y con su autor, que es el dato que RN-13 exige conservar en la propia fila
    // ademas de en el trail.
    expect($anterior?->created_by_user_id)->not->toBeNull();

    // El detalle la devuelve como historial, de la mas antigua a la mas reciente.
    Api::as($contexto['token'])
        ->get('/api/v1/absences/'.$nueva)
        ->assertValidResponse(200)
        ->assertJsonPath('absence.uuid', $nueva)
        ->assertJsonCount(1, 'history')
        ->assertJsonPath('history.0.uuid', $original)
        ->assertJsonPath('history.0.status', 'superseded')
        ->assertJsonPath('history.0.ends_on', '2026-03-06');
})->group('RF-GP-04', 'RN-13');

it('responde 409 al corregir una version que ya no es la vigente', function (): void {
    // ADR-035: el `uuid` identifica una VERSION. Dos personas mirando la misma
    // pantalla no pueden crear dos ramas del historial.
    $contexto = contextoDeAusencia();

    $original = registrarAusencia($contexto['token'], $contexto['employee']);

    Api::as($contexto['token'])
        ->patch('/api/v1/absences/'.$original, ['reason' => 'Primera correccion.'])
        ->assertValidResponse(200);

    Api::as($contexto['token'])
        ->patch('/api/v1/absences/'.$original, ['reason' => 'Segunda, sobre la version vieja.'])
        ->assertStatus(409)
        ->assertJsonPath('type', 'urn:kronoqr:problem:conflict');
})->group('RF-GP-04', 'RN-13');

it('anular conserva el hecho, no crea version y libera los dias', function (): void {
    // Regla dura 5: nada se borra. Y `version` no sube, porque de un hecho que no
    // paso no hay version posterior.
    $contexto = contextoDeAusencia();

    $uuid = registrarAusencia($contexto['token'], $contexto['employee']);

    Api::as($contexto['token'])
        ->post('/api/v1/absences/'.$uuid.'/void', [
            'reason' => 'Se registro a la persona equivocada.',
        ])
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('uuid', $uuid)
        ->assertJsonPath('status', 'voided')
        ->assertJsonPath('version', 1)
        ->assertJsonPath('superseded_by_uuid', null)
        ->assertJsonPath('void_reason', 'Se registro a la persona equivocada.');

    // Sigue en la tabla con su autor de anulacion.
    $fila = DB::table('absences')->where('uuid', $uuid)->first();

    expect($fila)->not->toBeNull();
    expect($fila?->voided_by_user_id)->not->toBeNull();

    // Y los dias quedan libres: se puede registrar otra que los cubra.
    registrarAusencia($contexto['token'], $contexto['employee'], ['type' => 'leave']);

    expect(DB::table('absences')->count())->toBe(2);
})->group('RF-GP-04');

it('responde 409 al anular algo que ya no es la version vigente', function (): void {
    $contexto = contextoDeAusencia();

    $uuid = registrarAusencia($contexto['token'], $contexto['employee']);

    Api::as($contexto['token'])
        ->post('/api/v1/absences/'.$uuid.'/void', ['reason' => 'Ya no procede.'])
        ->assertValidResponse(200);

    Api::as($contexto['token'])
        ->post('/api/v1/absences/'.$uuid.'/void', ['reason' => 'Otra vez.'])
        ->assertStatus(409);
})->group('RF-GP-04');

it('responde 404 sobre una ausencia que no existe', function (): void {
    $contexto = contextoDeAusencia();

    Api::as($contexto['token'])
        ->get('/api/v1/absences/0199f4a1-6c22-7e10-9b40-000000000000')
        ->assertStatus(404);

    Api::as($contexto['token'])
        ->patch('/api/v1/absences/0199f4a1-6c22-7e10-9b40-000000000000', ['reason' => 'No existe.'])
        ->assertStatus(404);

    Api::as($contexto['token'])
        ->post('/api/v1/absences/0199f4a1-6c22-7e10-9b40-000000000000/void', ['reason' => 'No existe.'])
        ->assertStatus(404);
})->group('RF-GP-04');

it('lista solo las activas por omision y devuelve el historico con status=all', function (): void {
    $contexto = contextoDeAusencia();

    $anulada = registrarAusencia($contexto['token'], $contexto['employee']);

    Api::as($contexto['token'])
        ->post('/api/v1/absences/'.$anulada.'/void', ['reason' => 'Se registro por error.'])
        ->assertValidResponse(200);

    registrarAusencia($contexto['token'], $contexto['employee'], [
        'type' => 'leave',
        'starts_on' => '2026-03-10',
        'ends_on' => '2026-03-11',
    ]);

    Api::as($contexto['token'])
        ->get('/api/v1/absences?from=2026-03-01&to=2026-03-31')
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'leave')
        ->assertJsonPath('meta.total', 1);

    Api::as($contexto['token'])
        ->get('/api/v1/absences?from=2026-03-01&to=2026-03-31&status=all')
        ->assertValidResponse(200)
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 2);
})->group('RF-GP-04');

it('sin fechas devuelve el mes en curso del centro, con el reloj inyectado', function (): void {
    /*
     * **Regla dura 2, y el hallazgo de la revision.** Antes, «el mes en curso» lo
     * decidia un `new DateTimeImmutable('now', …)` dentro del `FormRequest`: el
     * unico punto del producto fuera de `SystemClock` que leia el reloj del
     * proceso. `FrozenTime` no lo detenia, asi que el rango por omision no se
     * podia probar y cambiaba solo el dia 1 de cada mes.
     *
     * La zona del centro es `Europe/Madrid`, y el instante congelado es el 1 de
     * marzo a las 00:30 **en esa zona** —el 28 de febrero a las 23:30 en UTC—:
     * es el caso que distingue leer el reloj del servidor de leerlo en la zona
     * del hotel. Con la conversion mal hecha, el rango empezaria en febrero.
     */
    FrozenTime::at('2026-02-28 23:30:00');

    $contexto = contextoDeAusencia();

    // Del 1 de marzo (por omision) al 28 de febrero de 2027: +364 dias.
    registrarAusencia($contexto['token'], $contexto['employee'], [
        'starts_on' => '2026-03-01',
        'ends_on' => '2026-03-01',
    ]);

    // Y una de febrero, que queda fuera del mes en curso.
    registrarAusencia($contexto['token'], $contexto['employee'], [
        'type' => 'leave',
        'starts_on' => '2026-02-10',
        'ends_on' => '2026-02-11',
    ]);

    Api::as($contexto['token'])
        ->get('/api/v1/absences')
        ->assertValidResponse(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.starts_on', '2026-03-01');
})->group('RF-GP-04');

it('recorta a dos años un periodo mas largo en lugar de rechazarlo', function (): void {
    // El techo no es una regla de negocio: es lo que impide que una URL
    // manipulada pida diez años de historico a una consulta paginada. Se recorta
    // porque un enlace guardado con un rango enorme tiene que seguir devolviendo
    // algo util; el `422` se reserva para lo que no tiene sentido.
    FrozenTime::at('2026-03-01 09:00:00');

    $contexto = contextoDeAusencia();

    // Dentro del techo (a 730 dias del 1 de enero de 2026) y fuera de el.
    registrarAusencia($contexto['token'], $contexto['employee'], [
        'starts_on' => '2027-06-01',
        'ends_on' => '2027-06-02',
    ]);

    registrarAusencia($contexto['token'], $contexto['employee'], [
        'type' => 'leave',
        'starts_on' => '2028-06-01',
        'ends_on' => '2028-06-02',
    ]);

    Api::as($contexto['token'])
        ->get('/api/v1/absences?from=2026-01-01&to=2029-01-01')
        ->assertValidResponse(200)
        // Solo la que cae dentro de los dos años: la de 2028 se queda fuera del
        // rango recortado, y la peticion sigue siendo un `200`.
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.starts_on', '2027-06-01');
})->group('RF-GP-04');

it('devuelve las ausencias que tocan el periodo, no solo las que empiezan dentro', function (): void {
    // Una baja del 28 de febrero al 3 de marzo tiene que aparecer preguntando por
    // marzo: lo contrario obligaria a quien mira un mes a adivinar que hay algo
    // colgando del anterior.
    $contexto = contextoDeAusencia();

    registrarAusencia($contexto['token'], $contexto['employee'], [
        'type' => 'sick_leave',
        'starts_on' => '2026-02-26',
        'ends_on' => '2026-03-03',
    ]);

    Api::as($contexto['token'])
        ->get('/api/v1/absences?from=2026-03-01&to=2026-03-31')
        ->assertValidResponse(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.starts_on', '2026-02-26');

    // Y no aparece en un periodo que no toca.
    Api::as($contexto['token'])
        ->get('/api/v1/absences?from=2026-04-01&to=2026-04-30')
        ->assertValidResponse(200)
        ->assertJsonCount(0, 'data');
})->group('RF-GP-04');

/**
 * Registra, corrige y anula una ausencia con nota, y devuelve los tres asientos
 * en orden.
 *
 * **Existe para que las dos pruebas de auditoria no compartan un solo caso.**
 * Juntas pasaban del limite de complejidad ciclomatica del §3.5, y ademas son
 * dos afirmaciones distintas: «el asiento describe el hecho» y «el asiento no
 * lleva la nota». Si se rompiera una, el mensaje tiene que decir cual.
 *
 * @return array{asientos: list<object>, uuid: string, empleado: string}
 */
function trasLasTresOperaciones(): array
{
    $contexto = contextoDeAusencia();

    $uuid = registrarAusencia($contexto['token'], $contexto['employee'], [
        'type' => 'other',
        'note' => 'Permiso por mudanza, sin mas detalle.',
    ]);

    $respuesta = Api::as($contexto['token'])
        ->patch('/api/v1/absences/'.$uuid, [
            'ends_on' => '2026-03-09',
            'reason' => 'Se alargo un dia mas.',
        ])
        ->assertValidResponse(200);

    /** @var string $nueva */
    $nueva = $respuesta->json('uuid');

    Api::as($contexto['token'])
        ->post('/api/v1/absences/'.$nueva.'/void', ['reason' => 'Al final no la cogio.'])
        ->assertValidResponse(200);

    /** @var list<object> $asientos */
    $asientos = DB::table('audit_log')
        ->whereIn('action', ['absence.registered', 'absence.corrected', 'absence.voided'])
        ->orderBy('id')
        ->get()
        ->all();

    return ['asientos' => $asientos, 'uuid' => $uuid, 'empleado' => $contexto['employee']];
}

/**
 * El payload de un asiento, ya decodificado.
 *
 * @return array<string, mixed>
 */
function payloadDelAsiento(object $asiento): array
{
    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) ($asiento->payload ?? '{}'), true, 512, JSON_THROW_ON_ERROR);

    return $payload;
}

/**
 * Una clave del payload de un asiento, o `null` si no esta.
 *
 * **Existe para que las pruebas no acumulen `??`**: cada uno es un punto de
 * decision y media docena seguidos pasan del limite de complejidad del §3.5. El
 * respaldo vive aqui, una vez, en lugar de en cada asercion.
 */
function claveDelAsiento(object $asiento, string $clave): mixed
{
    return payloadDelAsiento($asiento)[$clave] ?? null;
}

it('deja las tres operaciones en audit_log, cada una con su accion y su antes', function (): void {
    // **Regla dura 6.** El asiento tiene que describir el hecho: el tipo entra,
    // porque sin el «hubo una ausencia de cinco dias» no dice nada, y al corregir
    // entra el ANTES completo, que es lo que lo hace reconstruible sin recorrer
    // la cadena de versiones desde la primera.
    $resultado = trasLasTresOperaciones();
    $asientos = $resultado['asientos'];

    expect(array_column($asientos, 'action'))
        ->toBe(['absence.registered', 'absence.corrected', 'absence.voided']);

    expect(claveDelAsiento($asientos[0], 'absence_uuid'))->toBe($resultado['uuid']);
    expect(claveDelAsiento($asientos[0], 'employee_uuid'))->toBe($resultado['empleado']);
    expect(claveDelAsiento($asientos[0], 'type'))->toBe('other');
    expect(claveDelAsiento($asientos[0], 'version'))->toBe(1);
    expect(claveDelAsiento($asientos[0], 'source'))->toBe('manual');

    expect(claveDelAsiento($asientos[1], 'supersedes_uuid'))->toBe($resultado['uuid']);
    expect(claveDelAsiento($asientos[1], 'previous_ends_on'))->toBe('2026-03-06');
    expect(claveDelAsiento($asientos[1], 'ends_on'))->toBe('2026-03-09');
    expect(claveDelAsiento($asientos[1], 'reason'))->toBe('Se alargo un dia mas.');

    expect(claveDelAsiento($asientos[2], 'reason'))->toBe('Al final no la cogio.');
})->group('RF-GP-04', 'RS-05');

it('no mete la nota en ningun asiento, solo si la habia', function (): void {
    // **Regla dura 21.** La nota puede llevar un diagnostico, y `audit_log` se
    // conserva cuatro años y se enseña en una inspeccion. De ella consta
    // `has_note`, que es lo que permite explicar por que una ausencia `other`
    // era valida, y nada mas.
    $asientos = trasLasTresOperaciones()['asientos'];

    expect(claveDelAsiento($asientos[0], 'has_note'))->toBeTrue();

    foreach ($asientos as $asiento) {
        expect((string) ($asiento->payload ?? ''))->not->toContain('mudanza');
    }
})->group('RF-GP-04', 'RS-05');
