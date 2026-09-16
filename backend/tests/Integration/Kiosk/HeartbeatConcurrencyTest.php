<?php

declare(strict_types=1);

use App\Modules\Kiosk\Infrastructure\Metrics\RedisKioskMetrics;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\DB;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Concurrency\ParallelRequests;
use Tests\Support\Database\CommittedDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Time\FrozenTime;

/*
 * **Dos latidos del mismo quiosco a la vez dejan una fila coherente**
 * (**RF-PA-07**, **RS-04**, tarea 3.3, decision 4; doc 02 §9.4).
 *
 * ## Por que esta carrera existe de verdad
 *
 * El planificador del latido de la PWA dispara cada 60 s, pero la tablet tambien
 * late al recuperar la red y al volver del segundo plano. Con la red justa de un
 * hotel, el latido lento y el nuevo se solapan: dos peticiones del **mismo**
 * dispositivo, con el token **del mismo** dispositivo, escribiendo las **mismas
 * cinco columnas** de la **misma** fila.
 *
 * ## Lo que no puede pasar, y es lo unico que esta prueba afirma
 *
 * Que la fila quede **mezclada**: la bateria de un latido con la cola del otro.
 * El panel de salud leeria «al 9 % y con 41 pendientes desde hace siete horas» de
 * una tablet que nunca dijo eso, y el IT del cliente saldria a buscar una averia
 * que no existe. La garantia la da que `DbDeviceFleet::recordHeartbeat()` sea
 * **una sola sentencia `UPDATE`**: PostgreSQL serializa las dos y la segunda
 * sobrescribe entera a la primera. Un `SELECT` previo seguido de un `UPDATE` por
 * columnas —la implementacion que parece natural el dia que alguien quiera
 * «conservar la bateria si el latido no la trae»— produciria justo la mezcla, y
 * esta prueba es lo que lo impediria.
 *
 * ## Procesos de verdad, no un bucle
 *
 * Dos llamadas seguidas en el mismo proceso pasarian igual con la
 * implementacion prohibida. Por eso {@see ParallelRequests} y
 * {@see CommittedDatabase}: los hijos abren su propia conexion y no verian una
 * transaccion sin confirmar.
 *
 * ## Y por eso tambien se suelta Redis antes de bifurcar
 *
 * `fork()` duplica **todos** los descriptores, no solo el de PostgreSQL que
 * `ParallelRequests` ya cierra. Dos procesos escribiendo en el mismo socket de
 * Redis corrompen el protocolo, y `RedisKioskMetrics` **se traga cualquier
 * `Throwable` a proposito** —medir no puede tumbar un latido—, asi que el
 * sintoma no seria un error sino una metrica que a veces no se escribe: una
 * prueba intermitente, que es lo unico que aqui no se tolera.
 */

uses(CommittedDatabase::class);

/*
 * Las dos instantaneas, distintas en las cinco columnas, y su fila esperada.
 *
 * El cuerpo y la fila van escritos por separado a proposito: `oldest_pending_at`
 * **se omite** cuando la cola esta vacia —el contrato lo declara opcional y no
 * anulable, y la PWA no lo envia— pero la columna se escribe a `NULL`
 * igualmente, porque dejar el valor anterior haria que una cola ya drenada
 * siguiera diciendo «el mas antiguo es de hace siete horas». Derivar una forma
 * de la otra con un `if` dentro de la prueba esconderia justo esa asimetria.
 */
const LATIDO_A = [
    'app_version' => '2.2.0',
    'pending_queue_size' => 0,
    'battery_level' => 92,
    'battery_charging' => true,
];

const FILA_A = [
    'app_version' => '2.2.0',
    'pending_queue_size' => 0,
    'oldest_pending_at' => null,
    'battery_level' => 92,
    'battery_charging' => true,
];

const LATIDO_B = [
    'app_version' => '2.3.1',
    'pending_queue_size' => 41,
    'oldest_pending_at' => '2026-09-16T05:12:44Z',
    'battery_level' => 9,
    'battery_charging' => false,
];

const FILA_B = [
    'app_version' => '2.3.1',
    'pending_queue_size' => 41,
    'oldest_pending_at' => '2026-09-16T05:12:44Z',
    'battery_level' => 9,
    'battery_charging' => false,
];

/** Las tres series del latido, vaciadas y con el socket suelto antes de bifurcar. */
function seriesDelLatidoEnLimpio(): void
{
    $redis = app(RedisManager::class);

    foreach ([RedisKioskMetrics::LAST_SEEN, RedisKioskMetrics::QUEUE_SIZE, RedisKioskMetrics::BATTERY_LEVEL] as $key) {
        $redis->connection()->command('DEL', [$key]);
    }

    // Ver el docblock: nadie puede heredar un socket a Redis.
    $redis->purge();
}

/**
 * La telemetria persistida de un quiosco, en la misma forma que FILA_A y FILA_B.
 *
 * @return array<string, mixed>
 */
function telemetriaPersistidaDe(int $deviceId): array
{
    $device = DB::table('devices')->where('id', $deviceId)->sole();

    return [
        'app_version' => $device->app_version,
        'pending_queue_size' => $device->pending_queue_size,
        'oldest_pending_at' => $device->oldest_pending_at === null
            ? null
            : (new DateTimeImmutable((string) $device->oldest_pending_at))->format('Y-m-d\TH:i:s\Z'),
        'battery_level' => $device->battery_level,
        'battery_charging' => $device->battery_charging,
    ];
}

it('deja la fila con uno de los dos latidos entero, nunca con las columnas mezcladas', function (): void {
    FrozenTime::at('2026-09-16 12:00:00');

    $escenario = AttendanceFixtures::scenario();
    $latidos = [LATIDO_A, LATIDO_B];

    seriesDelLatidoEnLimpio();

    $respuestas = ParallelRequests::run(
        2,
        static fn (int $indice): mixed => Api::as($escenario['token'])
            ->post('/api/v1/kiosk/heartbeat', $latidos[$indice]),
    );

    // **Los dos ganan.** Un latido no compite por nada: no hay invariante que
    // arbitrar y perder uno apagaria la unica senal que dice que la tablet vive.
    expect(array_map(static fn (array $r): int => $r['status'], $respuestas))->toBe([200, 200]);

    // **La afirmacion que importa**: el juego COMPLETO de uno de los dos, no una
    // combinacion de los dos. Cual de ellos gane es indiferente y no se afirma:
    // el orden de dos peticiones simultaneas no es una promesa del producto.
    expect(telemetriaPersistidaDe($escenario['device']))->toBeIn([FILA_A, FILA_B]);

    // Y el instante lo pone el servidor con su reloj, no la tablet: si viniera
    // del dispositivo, un reloj averiado dejaria un quiosco «visto» en 2031 y la
    // alerta de latido no volveria a saltar jamas.
    /** @var string $lastSeenAt */
    $lastSeenAt = DB::table('devices')->where('id', $escenario['device'])->value('last_seen_at');

    expect((new DateTimeImmutable($lastSeenAt))->format('Y-m-d H:i:s'))->toBe('2026-09-16 12:00:00');
})->group('RF-PA-07', 'RS-04');

it('publica en cada gauge un valor que alguno de los dos latidos declaro', function (): void {
    // Las tres series son gauges INDEPENDIENTES —tres `HSET` sueltos— y no
    // pretenden contar la misma instantanea: lo que responden es «cuanta bateria
    // hay AHORA» y «cuanto hay en la cola AHORA». Lo que si tiene que cumplirse,
    // y es lo que se afirma, es que cada una contenga un valor **que un quiosco
    // dijo de verdad**. Un valor inventado en la serie saldria por `/metrics` a
    // Prometheus, y de ahi al cuadro de mando y a la alerta.
    FrozenTime::at('2026-09-16 12:00:00');

    $escenario = AttendanceFixtures::scenario();
    $latidos = [LATIDO_A, LATIDO_B];

    seriesDelLatidoEnLimpio();

    $respuestas = ParallelRequests::run(
        2,
        static fn (int $indice): mixed => Api::as($escenario['token'])
            ->post('/api/v1/kiosk/heartbeat', $latidos[$indice]),
    );

    // Sin esto, las tres aserciones de abajo pasarian con un solo latido
    // atendido: un rechazo silencioso dejaria la prueba verde sin haber medido
    // ninguna concurrencia.
    expect(array_map(static fn (array $r): int => $r['status'], $respuestas))->toBe([200, 200]);

    $etiqueta = 'device='.$escenario['deviceUuid'];
    $lectura = static function (string $key) use ($etiqueta): int {
        /** @var string $valor */
        $valor = app(RedisManager::class)->connection()->command('HGET', [$key, $etiqueta]);

        return (int) $valor;
    };

    expect($lectura(RedisKioskMetrics::QUEUE_SIZE))->toBeIn([0, 41])
        ->and($lectura(RedisKioskMetrics::BATTERY_LEVEL))->toBeIn([92, 9])
        // El instante del ultimo latido es el del reloj detenido en los dos
        // casos: 2026-09-16 12:00:00 UTC.
        ->and($lectura(RedisKioskMetrics::LAST_SEEN))->toBe(1789560000);
})->group('RF-PA-07', 'RS-04');

it('escribe las cinco columnas de telemetria en una sola sentencia UPDATE', function (): void {
    // **Esta es la version determinista de la prueba de arriba.** Aquella
    // demuestra que hoy, con dos procesos de verdad, la fila no sale mezclada;
    // esta demuestra POR QUE no puede salir mezclada nunca: una sola sentencia,
    // con las cinco columnas dentro, sin leer antes para escribir despues.
    //
    // La carrera de dos procesos es una observacion; el numero de sentencias es
    // la garantia. Quien parta este `UPDATE` en dos, o meta un `SELECT` delante
    // para «conservar la bateria si el latido no la trae», rompe esta prueba en
    // el acto y no cuando el planificador de una tablet se solape en produccion.
    FrozenTime::at('2026-09-16 12:00:00');

    $escenario = AttendanceFixtures::scenario();
    $sentencias = [];

    DB::listen(static function (QueryExecuted $query) use (&$sentencias): void {
        $sentencias[] = $query->sql;
    });

    Api::as($escenario['token'])->post('/api/v1/kiosk/heartbeat', LATIDO_B)->assertOk();

    // Se recogen todas y se filtran despues, en vez de filtrar dentro del
    // oyente: una condicion dentro del oyente es una rama de la prueba que nadie
    // prueba, y aqui la lista completa deja ver en el fallo que mas se ejecuto.
    $escrituras = array_values(array_filter(
        $sentencias,
        static fn (string $sql): bool => str_starts_with($sql, 'update "devices"'),
    ));

    expect($escrituras)->toHaveCount(1)
        ->and($escrituras[0])->toContain(
            '"last_seen_at"',
            '"app_version"',
            '"pending_queue_size"',
            '"oldest_pending_at"',
            '"battery_level"',
            '"battery_charging"',
        );
})->group('RF-PA-07', 'RS-04');
