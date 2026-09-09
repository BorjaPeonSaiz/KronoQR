<?php

declare(strict_types=1);

use App\Modules\Product\Application\Port\ErrorEventRepository;
use App\Modules\Product\Application\UseCase\RecordErrorEvent;
use App\Modules\Product\Domain\ValueObject\ErrorFingerprint;
use App\Modules\Product\Infrastructure\Metrics\RedisErrorMetrics;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\ErrorEventSink;
use App\Modules\Shared\Domain\ValueObject\ErrorLevel;
use App\Modules\Shared\Domain\ValueObject\ErrorReport;
use App\Modules\Shared\Domain\ValueObject\ErrorSource;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\ErrorHistoryConnection;
use Tests\Support\Time\FixedClock;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Los dos limites de la escritura contra PostgreSQL de verdad: **el reloj del
 * cliente y el techo de grupos por origen** (RF-PD-15, decisiones 6 y 14,
 * revision).
 *
 * ## Por que son de integracion y no unitarias
 *
 * Las dos garantias las da el `INSERT … ON CONFLICT`: `GREATEST`, `LEAST` y el
 * `CASE` que decide la reapertura son SQL. Un doble en memoria las daria por
 * buenas sin haberlas comprobado, y son las que impiden que un reloj mal puesto
 * borre un fallo que esta ocurriendo hoy.
 *
 * EL RELOJ ESTA FIJO (regla dura 2): sin eso, «hace un ano» seria una cifra que
 * cambia segun el dia en que corra la suite.
 */

uses(RefreshDatabase::class);

const ERROR_CLOCK_NOW = '2026-09-09 08:00:00';

beforeEach(function (): void {
    app()->instance(Clock::class, FixedClock::at(ERROR_CLOCK_NOW));

    WorkforceFixtures::site();
    ErrorHistoryConnection::shareTestTransaction();
});

/*
 * El puente se deshace al terminar. En `Integration` no hay enganche global —lo
 * hay solo en `Feature`, ver `tests/Pest.php`—, y sin esto la conexion se queda
 * agarrada al PDO de esta prueba y contamina a las que usan `CommittedDatabase`
 * despues, con un `relation "error_events" does not exist` a varios ficheros de
 * distancia de la causa.
 */
afterEach(function (): void {
    ErrorHistoryConnection::release();
});

/**
 * Un informe con el instante y el mensaje que decida quien llama.
 */
function informeCon(string $mensaje, string $occurredAt, ErrorSource $source = ErrorSource::Kiosk): ErrorReport
{
    return new ErrorReport(
        source: $source,
        level: ErrorLevel::Error,
        message: $mensaje,
        occurredAt: new DateTimeImmutable($occurredAt, new DateTimeZone('UTC')),
        appVersion: '2.2.0',
        context: ['reason' => 'timeout'],
        code: $source === ErrorSource::Kiosk ? 'kiosk.heartbeat.failed' : null,
        exceptionClass: $source === ErrorSource::Kiosk ? null : 'RuntimeException',
        file: $source === ErrorSource::Kiosk ? null : 'app/Foo.php',
        line: $source === ErrorSource::Kiosk ? null : 10,
    );
}

/**
 * La fila del unico grupo que hay.
 *
 * @return object{
 *     id: int,
 *     occurrences: int,
 *     first_seen_at: string,
 *     last_seen_at: string,
 *     resolved_at: string|null,
 *     resolved_by_user_id: int|null,
 *     code: string|null,
 *     exception_class: string|null,
 *     file: string|null,
 *     module: string|null,
 *     app_version: string,
 * }
 */
function unicoGrupo(): object
{
    $fila = DB::table('error_events')->first();

    expect($fila)->not->toBeNull();

    /** @var object{id: int, occurrences: int, first_seen_at: string, last_seen_at: string, resolved_at: string|null, resolved_by_user_id: int|null, code: string|null, exception_class: string|null, file: string|null, module: string|null, app_version: string} $fila */
    return $fila;
}

/**
 * El valor de un campo de una serie de Redis.
 *
 * `command()` devuelve `mixed` —el cliente de Redis no promete el tipo— y lo
 * que aqui se compara es un contador: la conversion va en un sitio, y un campo
 * que todavia no existe es un cero, no un fallo.
 */
function contadorDe(Connection $redis, string $serie, string $etiqueta): int
{
    $valor = $redis->command('HGET', [$serie, $etiqueta]);

    return is_numeric($valor) ? (int) $valor : 0;
}

it('una tablet con la hora un ano atrasada no arrastra un grupo vivo hacia la purga', function (): void {
    /*
     * EL FALLO QUE ESTA PRUEBA CIERRA. `last_seen_at` es la columna por la que
     * envejece la purga. Con la asignacion directa que habia antes, una tablet
     * con el reloj un ano atrasado escribia `last_seen_at = 2025` en un grupo
     * que estaba ocurriendo HOY, y la pasada de las 03:35 se lo llevaba: se
     * borraba un fallo vivo por un reloj mal puesto.
     *
     * Ahora la ocurrencia se acota por abajo a «recepcion menos la retencion» y
     * la escritura usa `GREATEST`, asi que el instante no puede retroceder.
     */
    $sumidero = app(ErrorEventSink::class);

    $sumidero->record(informeCon('el latido no llega', ERROR_CLOCK_NOW));
    $sumidero->record(informeCon('el latido no llega', '2025-09-09T08:00:00Z'));

    $grupo = unicoGrupo();

    expect((int) $grupo->occurrences)->toBe(2)
        // Sigue viva: el instante no ha retrocedido un ano.
        ->and($grupo->last_seen_at)->toContain('2026-09-09')
        // Y la primera aparicion tampoco baja de la cota inferior, que son los
        // 90 dias de retencion: junio de 2026, no septiembre de 2025.
        ->and($grupo->first_seen_at)->toContain('2026-06-11');
})->group('RF-PD-15');

it('una tablet con la hora adelantada no deja el grupo clavado en el futuro', function (): void {
    app(ErrorEventSink::class)->record(informeCon('camara perdida', '2027-01-01T00:00:00Z'));

    // Acotado por arriba al reloj del servidor: si no, el grupo se quedaria en
    // lo alto del listado durante meses y su purga se retrasaria otro tanto.
    expect(unicoGrupo()->last_seen_at)->toContain('2026-09-09');
})->group('RF-PD-15');

it('first_seen_at nunca queda por delante de last_seen_at', function (string $primero, string $segundo): void {
    // La invariante que hacen ciertas `LEAST` y `GREATEST` juntas, en los dos
    // ordenes de llegada posibles.
    $sumidero = app(ErrorEventSink::class);

    $sumidero->record(informeCon('la cola no responde', $primero));
    $sumidero->record(informeCon('la cola no responde', $segundo));

    $grupo = unicoGrupo();

    expect(strcmp((string) $grupo->first_seen_at, (string) $grupo->last_seen_at))->toBeLessThanOrEqual(0);
})->with([
    'en orden' => ['2026-09-08T08:00:00Z', '2026-09-09T07:00:00Z'],
    'al reves' => ['2026-09-09T07:00:00Z', '2026-09-08T08:00:00Z'],
    'del futuro y del pasado' => ['2027-01-01T00:00:00Z', '2025-01-01T00:00:00Z'],
])->group('RF-PD-15');

it('un reporte atrasado NO reabre un grupo que ya se dio por resuelto despues', function (): void {
    /*
     * El caso real: la cola offline de una tablet que estuvo el fin de semana
     * sin cobertura. Lo que llega el lunes describe algo que paso el viernes, y
     * alguien lo arreglo el sabado. Reabrir con eso le diria a quien lo arreglo
     * que su arreglo no funciono, cuando lo que ha llegado es historia.
     */
    $sumidero = app(ErrorEventSink::class);
    $sumidero->record(informeCon('el padron no descifra', '2026-09-05T08:00:00Z'));

    $usuario = ManagementUsers::withRole(UserRole::ADMIN);
    $id = (int) unicoGrupo()->id;

    app(ErrorEventRepository::class)->resolve($id, $usuario->id, new DateTimeImmutable('2026-09-06T10:00:00Z'));

    expect(unicoGrupo()->resolved_at)->not->toBeNull();

    // Llega tarde, de antes de la resolucion: cuenta, pero no reabre.
    $sumidero->record(informeCon('el padron no descifra', '2026-09-05T09:00:00Z'));

    $grupo = unicoGrupo();

    expect($grupo->resolved_at)->not->toBeNull()
        ->and($grupo->resolved_by_user_id)->toBe($usuario->id)
        ->and((int) $grupo->occurrences)->toBe(2);
})->group('RF-PD-15');

it('un reporte posterior a la resolucion SI reabre el grupo', function (): void {
    // La otra mitad: que un fallo dado por arreglado vuelva a ocurrir es
    // exactamente lo que IT tiene que ver.
    $sumidero = app(ErrorEventSink::class);
    $sumidero->record(informeCon('el padron no descifra', '2026-09-05T08:00:00Z'));

    $usuario = ManagementUsers::withRole(UserRole::ADMIN);

    app(ErrorEventRepository::class)->resolve(
        (int) unicoGrupo()->id,
        $usuario->id,
        new DateTimeImmutable('2026-09-06T10:00:00Z'),
    );

    $sumidero->record(informeCon('el padron no descifra', '2026-09-07T11:00:00Z'));

    $grupo = unicoGrupo();

    expect($grupo->resolved_at)->toBeNull()
        ->and($grupo->resolved_by_user_id)->toBeNull()
        // Y **conserva el recuento**: la reapertura no reinicia nada, son las
        // dos apariciones del mismo fallo.
        ->and((int) $grupo->occurrences)->toBe(2);
})->group('RF-PD-15');

it('por encima del techo, las huellas nuevas se cuentan en el grupo de desbordamiento', function (): void {
    /*
     * Decision 14. El limitador acota peticiones, no filas: con cincuenta
     * errores por envio y doce envios por minuto, una sesion podia crear
     * seiscientos grupos nuevos por minuto y dejarlos noventa dias.
     */
    Config::set('product.errors_max_open_groups_per_source', 3);

    $sumidero = app(ErrorEventSink::class);

    /*
     * Mensajes que se diferencian EN PALABRAS y no en un numero: la
     * normalizacion sustituye las cifras por `<n>` a proposito, asi que «fallo
     * 1» y «fallo 2» son —correctamente— el mismo grupo. Para probar el techo
     * hacen falta huellas de verdad distintas.
     */
    foreach (['camara', 'escaner', 'padron', 'almacen', 'reloj', 'padron cifrado'] as $que) {
        $sumidero->record(informeCon('no responde el subsistema de '.$que, ERROR_CLOCK_NOW));
    }

    // Tres grupos de verdad mas el de desbordamiento, con las tres apariciones
    // que no llegaron a abrir fila.
    expect(DB::table('error_events')->count())->toBe(4);

    $desbordamiento = DB::table('error_events')
        ->where('code', RecordErrorEvent::OVERFLOW_CODE)
        ->first();

    expect($desbordamiento)->not->toBeNull()
        ->and((int) ($desbordamiento->occurrences ?? 0))->toBe(3)
        ->and($desbordamiento?->fingerprint)->toBe(ErrorFingerprint::overflowFor(ErrorSource::Kiosk)->value)
        ->and($desbordamiento?->message)->toContain('techo de grupos')
        // Sin nada variable dentro: el grupo que contiene la entropia no puede
        // reintroducirla.
        ->and($desbordamiento?->context)->toBe('{}');
})->group('RF-PD-15');

it('en el techo, un grupo que YA existe sigue contando sus apariciones', function (): void {
    // El techo frena grupos nuevos, no el registro de lo que ya se estaba
    // viendo: si no, el fallo importante dejaria de contar justo cuando la
    // instalacion esta peor.
    Config::set('product.errors_max_open_groups_per_source', 2);

    $sumidero = app(ErrorEventSink::class);
    $sumidero->record(informeCon('el primero, que existe desde antes', ERROR_CLOCK_NOW));
    $sumidero->record(informeCon('el segundo', ERROR_CLOCK_NOW));

    // Ya en el techo: este abre desbordamiento.
    $sumidero->record(informeCon('el tercero, que ya no cabe', ERROR_CLOCK_NOW));

    // Y el primero sigue subiendo.
    $sumidero->record(informeCon('el primero, que existe desde antes', ERROR_CLOCK_NOW));

    $primero = DB::table('error_events')->where('message', 'el primero, que existe desde antes')->first();

    expect((int) ($primero->occurrences ?? 0))->toBe(2)
        ->and(DB::table('error_events')->where('code', RecordErrorEvent::OVERFLOW_CODE)->count())->toBe(1);
})->group('RF-PD-15');

it('el techo es POR ORIGEN: un panel ruidoso no deja sin registrar al quiosco', function (): void {
    // Que el panel de un cliente tenga un problema no puede dejar sin registrar
    // los errores del quiosco, que son los que impiden fichar.
    Config::set('product.errors_max_open_groups_per_source', 2);

    $sumidero = app(ErrorEventSink::class);

    // En palabras, no en un numero: ver la prueba anterior.
    foreach (['grafico', 'tabla', 'filtro', 'exportacion'] as $que) {
        $sumidero->record(informeCon('el panel no pinta el '.$que, ERROR_CLOCK_NOW, ErrorSource::Admin));
    }

    $sumidero->record(informeCon('la camara no arranca', ERROR_CLOCK_NOW, ErrorSource::Kiosk));

    expect(DB::table('error_events')->where('source', 'kiosk')->where('message', 'la camara no arranca')->count())
        ->toBe(1)
        ->and(DB::table('error_events')->where('source', 'admin')->count())->toBe(3);
})->group('RF-PD-15');

it('los grupos RESUELTOS no cuentan para el techo', function (): void {
    // El techo protege de la entropia viva, no del historico: un origen con
    // grupos ya atendidos no tiene por que dejar de registrar el de hoy.
    Config::set('product.errors_max_open_groups_per_source', 2);

    $sumidero = app(ErrorEventSink::class);
    $sumidero->record(informeCon('uno', ERROR_CLOCK_NOW));
    $sumidero->record(informeCon('dos', ERROR_CLOCK_NOW));

    $usuario = ManagementUsers::withRole(UserRole::ADMIN);
    $repositorio = app(ErrorEventRepository::class);

    foreach (DB::table('error_events')->get(['id']) as $fila) {
        /** @var object{id: int} $fila */
        $repositorio->resolve($fila->id, $usuario->id, new DateTimeImmutable(ERROR_CLOCK_NOW));
    }

    $sumidero->record(informeCon('tres, que si cabe', ERROR_CLOCK_NOW));

    expect(DB::table('error_events')->where('message', 'tres, que si cabe')->count())->toBe(1)
        ->and(DB::table('error_events')->where('code', RecordErrorEvent::OVERFLOW_CODE)->count())->toBe(0);
})->group('RF-PD-15');

it('cuenta ocurrencias siempre y grupos abiertos solo al crear o reabrir', function (): void {
    /*
     * Decision 14, y es lo que decide **cuando suena la alerta**.
     *
     * `application_errors_total` mide ocurrencias; con esa serie, una camara de
     * quiosco rota que emite el mismo error cada pocos segundos mantendria
     * `ErroresCriticosNuevos` encendida durante dias por un problema que el IT
     * del cliente ya conoce — y una alerta que no se apaga deja de leerse.
     *
     * `application_error_groups_opened_total` sube solo cuando aparece algo
     * nuevo o cuando algo dado por arreglado vuelve, que son los dos momentos
     * en los que hay que mirar.
     */
    $redis = app(Redis::class)->connection();
    $redis->command('DEL', [RedisErrorMetrics::ERRORS_TOTAL]);
    $redis->command('DEL', [RedisErrorMetrics::GROUPS_OPENED_TOTAL]);

    $sumidero = app(ErrorEventSink::class);
    $etiqueta = 'source=kiosk,level=error';

    // Uno nuevo: sube en las dos.
    $sumidero->record(informeCon('el latido no llega', ERROR_CLOCK_NOW));
    // Y otras dos apariciones del mismo: solo en la de ocurrencias.
    $sumidero->record(informeCon('el latido no llega', ERROR_CLOCK_NOW));
    $sumidero->record(informeCon('el latido no llega', ERROR_CLOCK_NOW));

    expect(contadorDe($redis, RedisErrorMetrics::ERRORS_TOTAL, $etiqueta))->toBe(3)
        ->and(contadorDe($redis, RedisErrorMetrics::GROUPS_OPENED_TOTAL, $etiqueta))->toBe(1);

    /*
     * Resuelto y vuelto a ocurrir DESPUES: eso si es una apertura.
     *
     * Los dos instantes van por detras del reloj fijo de la prueba (08:00 del
     * dia 9) a proposito: una ocurrencia posterior al reloj del servidor se
     * acota a ese reloj, y entonces no seria posterior a la resolucion y no
     * reabriria. Es la interaccion entre las dos reglas de la decision 6, y
     * conviene que la prueba la respete en vez de rodearla.
     */
    app(ErrorEventRepository::class)->resolve(
        (int) unicoGrupo()->id,
        ManagementUsers::withRole(UserRole::ADMIN)->id,
        new DateTimeImmutable('2026-09-08T09:00:00Z'),
    );

    $sumidero->record(informeCon('el latido no llega', '2026-09-08T10:00:00Z'));

    expect(contadorDe($redis, RedisErrorMetrics::ERRORS_TOTAL, $etiqueta))->toBe(4)
        ->and(contadorDe($redis, RedisErrorMetrics::GROUPS_OPENED_TOTAL, $etiqueta))->toBe(2);
})->group('RF-PD-15');

it('recorta code, exception_class y file a la anchura de su columna', function (): void {
    /*
     * Decision 14. **Un valor largo no puede hacer fallar el `INSERT` y perder
     * el error**: PostgreSQL rechazaria la fila entera con un `22001` y el
     * sumidero, que no lanza, lo convertiria en una linea de log que nadie mira.
     * Y seria justo el fallo mas raro —el que mas cuesta diagnosticar— el que no
     * se guarda.
     */
    app(ErrorEventSink::class)->record(new ErrorReport(
        source: ErrorSource::Api,
        level: ErrorLevel::Error,
        message: 'desbordado',
        occurredAt: new DateTimeImmutable(ERROR_CLOCK_NOW, new DateTimeZone('UTC')),
        appVersion: str_repeat('9', 80),
        context: [],
        code: str_repeat('c', 300),
        exceptionClass: str_repeat('E', 400),
        file: str_repeat('f', 400),
        line: 10,
        module: str_repeat('m', 100),
    ));

    $grupo = unicoGrupo();

    expect(mb_strlen((string) $grupo->code))->toBe(80)
        ->and(mb_strlen((string) $grupo->exception_class))->toBe(255)
        ->and(mb_strlen((string) $grupo->file))->toBe(255)
        ->and(mb_strlen((string) $grupo->app_version))->toBe(32)
        ->and(mb_strlen((string) $grupo->module))->toBe(40);
})->group('RF-PD-15');
