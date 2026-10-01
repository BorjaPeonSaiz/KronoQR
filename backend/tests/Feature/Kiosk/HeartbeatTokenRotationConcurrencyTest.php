<?php

declare(strict_types=1);

use App\Modules\Identity\Application\Command\IssueDeviceTokenCommand;
use App\Modules\Identity\Application\UseCase\IssueDeviceToken;
use App\Modules\Identity\Domain\ValueObject\IssuedAccessToken;
use App\Modules\Identity\Infrastructure\Persistence\Device;
use App\Modules\Kiosk\Application\Port\KioskMetrics;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Concurrency\ParallelRequests;
use Tests\Support\Database\CommittedDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Kiosk\RecordingKioskMetrics;
use Tests\Support\Time\FrozenTime;

/*
 * **Dos latidos simultaneos firmados con el MISMO token que toca rotar dejan un
 * solo relevo vigente** (RF-ID-04, RS-04, RF-KI-04; ADR-044; doc 02 §9.5:
 * escritura del quiosco -> idempotencia concurrente).
 *
 * ## Por que esta carrera existe de verdad
 *
 * La tablet late cada 60 s, pero tambien al recuperar la red y al volver del
 * segundo plano: con la wifi justa de un hotel, el latido lento y el nuevo se
 * solapan. Si los dos llegan el dia 72 —pasado el 80 % de la vida del token—,
 * los dos piden rotar.
 *
 * ## Lo que no puede pasar
 *
 * Que los dos lean «un solo token vivo», decidan los dos ROTAR y dejen **tres**
 * tokens vivos: el relevado en solape y dos relevos de 90 dias. ADR-044 promete
 * que como mucho conviven dos —el vigente y el relevado— y nunca una cadena, y
 * revocar una tablet con un relevo de mas que nadie sabe donde esta es justo lo
 * que RS-04 prohibe. Lo impide el `SELECT ... FOR UPDATE` sobre la fila del
 * dispositivo en `SanctumDeviceTokenIssuer::lockedTokensOf()`: el segundo
 * latido espera, ve el relevo del primero y lo trata como una respuesta perdida
 * (reentrega: retira ese relevo y emite otro).
 *
 * ## Procesos de verdad, no un bucle
 *
 * Dos latidos seguidos en el mismo proceso pasarian igual sin el bloqueo. Por
 * eso {@see ParallelRequests} y {@see CommittedDatabase}. Las metricas van a un
 * doble en memoria para que ningun hijo herede el socket de Redis.
 */

uses(CommittedDatabase::class);

/**
 * Un quiosco emparejado de verdad, con su token en el dia 80 de 90.
 *
 * @return array{deviceId: int, token: string}
 */
function quioscoConTokenPorRotarEnParalelo(): array
{
    $escenario = AttendanceFixtures::scenario();
    app()->instance(KioskMetrics::class, new RecordingKioskMetrics);

    $token = app(IssueDeviceToken::class)->handle(new IssueDeviceTokenCommand($escenario['deviceUuid']));

    if (! $token instanceof IssuedAccessToken) {
        throw new RuntimeException('No se ha emitido token para el quiosco.');
    }

    DB::table('personal_access_tokens')->where('tokenable_id', $escenario['device'])->update([
        'created_at' => '2026-03-13 08:00:00',
        'expires_at' => '2026-06-11 08:00:00',
    ]);

    return ['deviceId' => $escenario['device'], 'token' => $token->plainTextToken];
}

/** El hash que Sanctum guarda: SHA-256 de la mitad secreta de `<id>|<secreto>`. */
function hashDelTokenEnParalelo(string $plainTextToken): string
{
    return hash('sha256', mb_substr($plainTextToken, mb_strpos($plainTextToken, '|') + 1));
}

/** El valor de `rotated_token` de un cuerpo de respuesta, o `null` si no lo trae. */
function relevoEntregadoEnParalelo(mixed $cuerpo): ?string
{
    $valor = is_array($cuerpo) && is_array($cuerpo['rotated_token'] ?? null) ? ($cuerpo['rotated_token']['value'] ?? null) : null;

    return is_string($valor) ? $valor : null;
}

/**
 * Los hashes de los tokens vivos del quiosco.
 *
 * @return list<string>
 */
function hashesVivosDelQuioscoEnParalelo(int $deviceId): array
{
    /** @var list<string> $hashes */
    $hashes = DB::table('personal_access_tokens')
        ->where('tokenable_type', Device::class)
        ->where('tokenable_id', $deviceId)
        ->orderBy('id')
        ->pluck('token')
        ->all();

    return $hashes;
}

it('deja un solo relevo vigente cuando dos latidos con el mismo token piden rotar a la vez', function (): void {
    FrozenTime::at('2026-06-01 08:00:00');
    $quiosco = quioscoConTokenPorRotarEnParalelo();

    $respuestas = ParallelRequests::run(
        2,
        static fn (): mixed => Api::as($quiosco['token'])->post('/api/v1/kiosk/heartbeat', [
            'app_version' => '2.2.0',
            'pending_queue_size' => 0,
        ]),
    );

    // Ninguno de los dos se tumba (regla dura 19).
    expect(array_column($respuestas, 'status'))->toBe([200, 200]);

    // Los dos traen relevo: el primero rota y el segundo, que espera al bloqueo y
    // ya ve ese relevo, lo reentrega. Un latido sin relevo aqui seria una
    // rotacion que fallo y que `DeviceTokenRenewal` se trago en silencio.
    $relevos = array_map(relevoEntregadoEnParalelo(...), array_column($respuestas, 'body'));

    expect($relevos)->each->toBeString();

    /** @var list<string> $relevos */
    $hashesEntregados = array_map(hashDelTokenEnParalelo(...), $relevos);
    $vivos = hashesVivosDelQuioscoEnParalelo($quiosco['deviceId']);
    $entregadosVivos = array_values(array_intersect($hashesEntregados, $vivos));

    // **La afirmacion que importa: dos tokens vivos, no tres.** El relevado en
    // solape y UN relevo; el otro relevo entregado ya esta retirado.
    expect($vivos)->toHaveCount(2)
        ->and($vivos[0])->toBe(hashDelTokenEnParalelo($quiosco['token']))
        ->and($entregadosVivos)->toHaveCount(1)
        ->and($vivos[1])->toBe($entregadosVivos[0]);

    // `devices.token_hash` es la otra mitad de la misma escritura: tiene que
    // apuntar al relevo vigente y no al que se retiro.
    expect(DB::table('devices')->where('id', $quiosco['deviceId'])->value('token_hash'))->toBe($entregadosVivos[0]);

    // El solape del relevado no se alarga en la reentrega: 24 h desde el latido.
    expect(DB::table('personal_access_tokens')->orderBy('id')->where('tokenable_id', $quiosco['deviceId'])->value('expires_at'))
        ->toStartWith('2026-06-02 08:00:00');

    // Emparejamiento, rotacion y reentrega: cada relevo deja su asiento.
    expect(DB::table('audit_log')->where('action', 'device.paired')->count())->toBe(3);
})->group('RF-ID-04', 'RS-04', 'RF-KI-04');

it('lee los tokens del quiosco con la fila del dispositivo bloqueada antes de emitir el relevo', function (): void {
    // **La version determinista de la prueba de arriba.** Aquella observa que
    // hoy, con dos procesos, no quedan tres tokens; esta fija POR QUE: el
    // `SELECT ... FOR UPDATE` sobre `devices` ocurre antes del `INSERT` del
    // relevo. Quien quite el bloqueo rompe esta prueba en el acto, no el dia que
    // dos latidos coincidan en produccion.
    FrozenTime::at('2026-06-01 08:00:00');
    $quiosco = quioscoConTokenPorRotarEnParalelo();
    $sentencias = [];

    DB::listen(static function (QueryExecuted $query) use (&$sentencias): void {
        $sentencias[] = $query->sql;
    });

    Api::as($quiosco['token'])->post('/api/v1/kiosk/heartbeat', [
        'app_version' => '2.2.0',
        'pending_queue_size' => 0,
    ])->assertOk()->assertJsonPath('rotated_token.expires_at', '2026-08-30T08:00:00Z');

    // Una sola lectura bloqueante y una sola emision, en ese orden. Si el
    // bloqueo desaparece, o llega despues del `INSERT`, la lista cambia.
    $orden = array_values(array_filter(array_map(
        static fn (string $sql): ?string => match (true) {
            str_starts_with($sql, 'select * from "devices"') && str_ends_with($sql, 'for update') => 'bloqueo del dispositivo',
            str_starts_with($sql, 'insert into "personal_access_tokens"') => 'emision del relevo',
            default => null,
        },
        $sentencias,
    )));

    expect($orden)->toBe(['bloqueo del dispositivo', 'emision del relevo']);
})->group('RF-ID-04', 'RS-04', 'RF-KI-04');
