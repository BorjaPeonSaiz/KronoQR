<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Persistence\Device;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concurrency\ParallelRequests;
use Tests\Support\Database\CommittedDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\FixedClock;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **El codigo de emparejamiento es de un solo uso bajo concurrencia**
 * (RF-PD-06, ficha de la tarea 5.6, doc 02 §9.4).
 *
 * Dos escenarios, y los dos son carreras de verdad:
 *
 *   1. **Dos `confirm` simultaneos con el mismo codigo -> un solo dispositivo.**
 *      Es el escenario del hotel donde dos personas del turno de mañana teclean
 *      el codigo a la vez, cada una en su ordenador.
 *   2. **Dos `claim` simultaneos sobre la misma solicitud -> un solo token.** Es
 *      el escenario real de una tablet con la red intermitente: el sondeo se
 *      reintenta antes de que llegue la respuesta del anterior.
 *
 * **Procesos de verdad y no un bucle.** Dos llamadas seguidas en el mismo proceso
 * pasarian igual con la implementacion prohibida —un `SELECT` previo seguido de
 * un `UPDATE`—, que es exactamente la que este producto no puede permitirse.
 * Quien arbitra es PostgreSQL con `UPDATE ... WHERE status = ?`, y solo se ve
 * desde dos transacciones concurrentes. Por eso este fichero usa
 * {@see CommittedDatabase} y no `RefreshDatabase`: los hijos abren su propia
 * conexion y no pueden ver una transaccion sin confirmar.
 */

uses(CommittedDatabase::class);

const CONFIRMACIONES_PARALELAS = 4;

it('deja un solo dispositivo aunque dos personas confirmen el mismo codigo a la vez', function (): void {
    WorkforceFixtures::site();
    app()->instance(Clock::class, FixedClock::at('2026-09-07 10:00:00'));

    /** @var array{code: string} $ticket */
    $ticket = Api::guest()->post('/api/v1/kiosk/pair', ['app_version' => '1.4.2'])->json();

    // Cuatro cuentas distintas, que es como pasa: cada persona en su sesion.
    $tokens = [];

    for ($i = 0; $i < CONFIRMACIONES_PARALELAS; $i++) {
        $tokens[] = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));
    }

    $respuestas = ParallelRequests::run(
        CONFIRMACIONES_PARALELAS,
        // **Nombres distintos a proposito.** Con el mismo nombre, el ganador
        // seria el UNIQUE de `devices(site_id, name)` y esta prueba no diria
        // nada del `UPDATE` condicional: pasaria igual con un `SELECT` previo.
        // Con nombres distintos, lo unico que puede impedir cuatro dispositivos
        // es que la solicitud solo se pueda confirmar una vez.
        static fn (int $indice): mixed => Api::as($tokens[$indice])
            ->post('/api/v1/kiosk/pair/confirm', [
                'code' => $ticket['code'],
                'name' => 'Quiosco '.$indice,
            ]),
    );

    $codigos = array_map(static fn (array $r): int => $r['status'], $respuestas);

    // Uno gana y los demas reciben el rechazo generico de siempre: quien pierde
    // la carrera no tiene por que enterarse de que la hubo (regla dura 17).
    expect(array_count_values($codigos)[200] ?? 0)->toBe(1)
        ->and(array_count_values($codigos)[422] ?? 0)->toBe(CONFIRMACIONES_PARALELAS - 1);

    $tiposRechazados = array_values(array_unique(array_map(
        static function (array $r): string {
            /** @var array{type: string} $cuerpo */
            $cuerpo = $r['body'];

            return $cuerpo['type'];
        },
        array_filter($respuestas, static fn (array $r): bool => $r['status'] === 422),
    )));

    expect($tiposRechazados)->toBe(['urn:kronoqr:problem:pairing-code-rejected']);

    // **Un solo dispositivo.** Es lo que importa: cuatro filas de `devices`
    // serian cuatro quioscos fantasma en el panel de salud y cuatro tokens que
    // nadie sabe donde estan.
    expect(DB::table('devices')->count())->toBe(1)
        ->and(DB::table('device_pairing_requests')->where('status', 'confirmed')->count())->toBe(1);
})->group('RF-PD-06', 'RQ-03');

it('entrega un solo token aunque la tablet sondee cuatro veces a la vez', function (): void {
    // El escenario real de una tablet con la red intermitente: el sondeo se
    // reintenta antes de que llegue la respuesta del anterior.
    //
    // La garantia se comprueba en las respuestas Y **en lo que queda escrito**:
    // exactamente un sondeo se lleva un token, la fila queda `claimed` una sola
    // vez y en `personal_access_tokens` hay **un** token para ese dispositivo.
    // Esa ultima afirmacion es la que importa: dos tokens vivos para la misma
    // tablet significarian que revocar el visible deja el otro funcionando.
    WorkforceFixtures::site();
    app()->instance(Clock::class, FixedClock::at('2026-09-07 10:00:00'));

    /** @var array{pairing_id: string, pairing_secret: string, code: string} $ticket */
    $ticket = Api::guest()->post('/api/v1/kiosk/pair', ['app_version' => '1.4.2'])->json();

    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN)))
        ->post('/api/v1/kiosk/pair/confirm', ['code' => $ticket['code'], 'name' => 'Recepcion'])
        ->assertOk();

    $respuestas = ParallelRequests::run(
        CONFIRMACIONES_PARALELAS,
        static fn (): mixed => Api::guest()->post('/api/v1/kiosk/pair/claim', [
            'pairing_id' => $ticket['pairing_id'],
            'pairing_secret' => $ticket['pairing_secret'],
        ]),
    );

    $codigos = array_map(static fn (array $r): int => $r['status'], $respuestas);

    // Uno recoge y los otros tres reciben el rechazo generico: quien pierde la
    // carrera no puede distinguirlo de un secreto equivocado (regla dura 17).
    expect(array_count_values($codigos)[200] ?? 0)->toBe(1)
        ->and(array_count_values($codigos)[422] ?? 0)->toBe(CONFIRMACIONES_PARALELAS - 1);

    // **Un solo token entregado**, que es lo que ve la tablet.
    $conToken = array_filter(
        $respuestas,
        static fn (array $r): bool => is_array($r['body']) && isset($r['body']['token']),
    );

    expect($conToken)->toHaveCount(1);

    // Y el mismo rechazo generico para los tres que pierden: quien llega tarde no
    // puede distinguirlo de un secreto equivocado (regla dura 17).
    $tiposRechazados = array_values(array_unique(array_map(
        static function (array $r): string {
            /** @var array{type: string} $cuerpo */
            $cuerpo = $r['body'];

            return $cuerpo['type'];
        },
        array_filter($respuestas, static fn (array $r): bool => $r['status'] === 422),
    )));

    expect($tiposRechazados)->toBe(['urn:kronoqr:problem:pairing-rejected']);

    // La solicitud queda consumida una sola vez: es el `UPDATE ... WHERE
    // status = 'confirmed'` haciendo de arbitro.
    expect(DB::table('device_pairing_requests')->where('status', 'claimed')->count())->toBe(1)
        ->and(DB::table('devices')->count())->toBe(1);

    // **Y un solo token PERSISTIDO.** Afirmar solo sobre las respuestas dejaria
    // pasar el caso peor de todos: cuatro tokens emitidos y tres respuestas
    // perdidas. Se cuenta la fila de Sanctum y se coteja `devices.token_hash`,
    // que son las dos mitades de la misma escritura.
    $device = DB::table('devices')->sole();

    /** @var array{token: array{value: string}} $entregado */
    $entregado = array_values($conToken)[0]['body'];
    $secreto = mb_substr($entregado['token']['value'], mb_strpos($entregado['token']['value'], '|') + 1);

    expect(DB::table('personal_access_tokens')
        ->where('tokenable_type', Device::class)
        ->where('tokenable_id', $device->id)
        ->count())->toBe(1)
        // El hash guardado es el del token que se entrego, no «alguno»: si la
        // escritura se perdiera y quedara la de otro sondeo, la tablet tendria un
        // token que el servidor no reconoce.
        ->and($device->token_hash)->toBe(hash('sha256', $secreto));
})->group('RF-PD-06', 'RQ-03');

it('deja un solo quiosco aunque dos personas confirmen a la vez con el MISMO nombre nuevo', function (): void {
    // **La carrera que el `SELECT ... FOR UPDATE` de `provision()` no cubre.**
    // Aquel bloquea una fila que EXISTE —el caso de la reactivacion—; cuando el
    // nombre es nuevo no hay nada que bloquear, asi que las dos confirmaciones
    // llegan al `INSERT` y una choca contra `devices_site_id_name_unique`.
    //
    // Lo que se comprueba es que el perdedor recibe un `422` sobre `name` y no un
    // `500`: quien lo recibe tiene que cambiar el nombre, y eso es lo que esa
    // respuesta le dice. Un `500` seria ademas un error del producto en un camino
    // que el producto puede prever.
    //
    // Son DOS solicitudes distintas a proposito: con el mismo codigo, el ganador
    // lo decidiria el `UPDATE` condicional y esta prueba no diria nada del indice.
    WorkforceFixtures::site();
    app()->instance(Clock::class, FixedClock::at('2026-09-07 10:00:00'));

    $tickets = [];
    $tokens = [];

    for ($i = 0; $i < 2; $i++) {
        /** @var array{code: string} $ticket */
        $ticket = Api::guest()->post('/api/v1/kiosk/pair', ['app_version' => '1.4.2'])->json();
        $tickets[] = $ticket;
        $tokens[] = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));
    }

    $respuestas = ParallelRequests::run(
        2,
        static fn (int $indice): mixed => Api::as($tokens[$indice])
            ->post('/api/v1/kiosk/pair/confirm', [
                'code' => $tickets[$indice]['code'],
                'name' => 'Recepcion',
            ]),
    );

    $codigos = array_map(static fn (array $r): int => $r['status'], $respuestas);

    expect(array_count_values($codigos)[200] ?? 0)->toBe(1)
        ->and(array_count_values($codigos)[422] ?? 0)->toBe(1)
        // **Nunca un 500**: es la mitad que de verdad se estaba rompiendo.
        ->and(array_count_values($codigos)[500] ?? 0)->toBe(0);

    foreach ($respuestas as $respuesta) {
        if ($respuesta['status'] === 422) {
            /** @var array{type: string, errors?: array<string, mixed>} $cuerpo */
            $cuerpo = $respuesta['body'];

            expect($cuerpo['type'])->toBe('urn:kronoqr:problem:validation-failed')
                ->and($cuerpo['errors'] ?? [])->toHaveKey('name');
        }
    }

    // Un solo quiosco con ese nombre, que es lo que el indice existe para
    // garantizar: dos «Recepcion» convierten cualquier diagnostico en una
    // adivinanza.
    expect(DB::table('devices')->count())->toBe(1);
})->group('RF-PD-06', 'RQ-03');
