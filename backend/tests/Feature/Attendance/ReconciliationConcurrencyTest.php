<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Command\ReconcileDailyTotalsCommand;
use App\Modules\Attendance\Application\Port\CredentialResolver;
use App\Modules\Attendance\Application\Port\ScanMetrics;
use App\Modules\Attendance\Application\Port\WorkDayLedger;
use App\Modules\Attendance\Application\UseCase\ReconcileDailyTotals;
use App\Modules\Attendance\Application\UseCase\ReconciliationReport;
use App\Modules\Shared\Infrastructure\Persistence\Row;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Quality\Support\Commands;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Attendance\FakeCredentialResolver;
use Tests\Support\Attendance\RacingWorkDayLedger;
use Tests\Support\Attendance\RecordingScanMetrics;
use Tests\Support\Concurrency\ParallelRequests;
use Tests\Support\Database\CommittedDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **La reconciliacion nocturna cruzandose con el turno de noche** (RF-PR-02,
 * RN-06, RQ-03).
 *
 * La pasada de `attendance:reconcile` corre a las 03:50 UTC, que en un hotel no
 * es una hora muerta: es cuando el turno de noche va y viene de la pausa y
 * cuando empiezan los madrugadores de cocina. La prueba de carga de la tarea 3.6
 * destapo lo que eso producia: tres escaneos confirmados a las 14:57:50,7-50,8,
 * la reconciliacion escribiendo a las 14:57:50,99 y, como resultado, un empleado
 * con el tramo cerrado y su jornada en `total_minutes = 0` con
 * `has_open_shift = true`, y otros dos con el tramo abierto y `shift_count = 0`
 * con `first_in_at` a nulo. El registro legal —`shift_entries`— estaba intacto
 * (regla dura 7, ADR-007); lo que mentia hasta la noche siguiente eran la vista
 * de cumplimiento, el informe y el portal del empleado.
 *
 * ## Por que la carrera se provoca y no se espera
 *
 * La ventana esta entre las dos lecturas de la inspeccion —los tramos vigentes
 * primero, las filas de la proyeccion despues, sin instantanea comun— y dura lo
 * que tarde una consulta. Esperar a que el planificador del sistema operativo
 * intercale los procesos justo ahi daria una prueba que falla una vez de cada
 * treinta, que es lo mismo que no tener prueba. Por eso las dos primeras la
 * fuerzan con {@see RacingWorkDayLedger}: el fichaje —real, en otro proceso y
 * confirmado de verdad— ocurre exactamente entre las dos lecturas. **Esas dos son
 * la guarda de la regresion**: con el codigo anterior fallan siempre, y fallan
 * enseñando la fila corrompida.
 *
 * La tercera es la rafaga tal cual, sin intercalado provocado: ocho personas
 * fichando mientras la pasada da vueltas. Vale por lo que afirma del desenlace
 * —ninguna jornada corregida, ninguna divergencia, el dia entero cuadrado—, no
 * por reproducir la carrera: la ventana dura lo que una consulta y el defecto
 * podia pasar inadvertido en una ejecucion concreta. Si algun dia esta falla, es
 * que hay una carrera **distinta** de la que arreglo la 3.6.
 *
 * ## Los dos sentidos de la misma carrera
 *
 * Son dos casos y no uno, y se corrompen de forma distinta:
 *
 *   · **Fichaje que abre jornada.** La lectura del registro horario no ve nada y
 *     la de la proyeccion ya ve la fila: la pasada cree que hay una fila
 *     huerfana y la pone a cero. El tramo abierto se queda sin `shift_count` ni
 *     `first_in_at`.
 *   · **Fichaje que cierra jornada.** La lectura del registro horario ve el
 *     tramo abierto y la de la proyeccion ya ve el dia cerrado: la pasada
 *     «corrige» el total a cero y vuelve a marcar la jornada como abierta.
 *
 * En los dos, la proyeccion buena la habia escrito el fichaje en su propia
 * transaccion y la reconciliacion la piso con una lectura vieja.
 */

uses(CommittedDatabase::class);

/**
 * El directorio de señales de la rafaga, uno por prueba.
 *
 * Los hijos que fichan dejan ahi un fichero al confirmar y el que reconcilia los
 * cuenta para saber cuando parar: es una espera por condicion y no por reloj.
 * Se limpia en `afterEach` —no al final de la prueba— para que una asercion que
 * falle no deje basura en `/tmp` del contenedor.
 */
function racedSignalDirectory(): string
{
    $directory = sys_get_temp_dir().'/kronoqr-reconcile-race-'.bin2hex(random_bytes(6));

    if (! mkdir($directory, 0o700, true) && ! is_dir($directory)) {
        throw new RuntimeException('No se ha podido crear el directorio de señales '.$directory.'.');
    }

    return $directory;
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/kronoqr-reconcile-race-*') ?: [] as $directory) {
        array_map(unlink(...), glob($directory.'/*') ?: []);
        rmdir($directory);
    }
});

/** La jornada sobre la que se reconcilia, en la zona del centro. */
function racedWorkDate(): string
{
    return '2026-03-14';
}

/**
 * Centro, quiosco y N tarjetas listas para fichar.
 *
 * @return array{site: int, token: string, cards: list<string>, employees: list<string>}
 */
function racedKiosk(int $employees): array
{
    $site = WorkforceFixtures::site('Hotel de reconciliacion concurrente', 'Europe/Madrid');
    $department = WorkforceFixtures::department($site);
    $device = AttendanceFixtures::device($site, 'Entrada de personal');
    $token = AttendanceFixtures::tokenFor($device['id']);

    $credentials = FakeCredentialResolver::new();
    $cards = [];
    $uuids = [];

    for ($index = 0; $index < $employees; $index++) {
        $card = 'FH1.a3.reconcilia'.str_pad((string) $index, 12, '0', STR_PAD_LEFT).'.firma'.$index;
        $uuid = WorkforceFixtures::employee($site, $department);

        $credentials->resolving($card, $uuid);

        $cards[] = $card;
        $uuids[] = $uuid;
    }

    app()->instance(ScanMetrics::class, new RecordingScanMetrics);
    app()->instance(CredentialResolver::class, $credentials);

    return ['site' => $site, 'token' => $token, 'cards' => $cards, 'employees' => $uuids];
}

/**
 * Un escaneo por el endpoint real, con su `Idempotency-Key` como en el quiosco.
 */
function racedScan(string $token, string $card, string $occurredAt): int
{
    $scanId = Str::uuid7()->toString();

    return Api::as($token)
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan', [
            'scan_id' => $scanId,
            'occurred_at' => $occurredAt,
            'qr_payload' => $card,
        ])
        ->getStatusCode();
}

/**
 * El mismo escaneo, **en otro proceso y confirmado** antes de volver.
 *
 * Hace falta que sea otro proceso: lo que se quiere demostrar es que la
 * proyeccion sobrevive a una transaccion ajena que confirma en mitad de la
 * pasada, y una llamada en el mismo proceso compartiria conexion y transaccion
 * con ella.
 */
function racedScanInAnotherProcess(string $token, string $card, string $occurredAt): void
{
    $responses = ParallelRequests::run(1, static function (int $index) use ($token, $card, $occurredAt): TestResponse {
        $scanId = Str::uuid7()->toString();

        return Api::as($token)
            ->withHeaders(['Idempotency-Key' => $scanId])
            ->post('/api/v1/scan', [
                'scan_id' => $scanId,
                'occurred_at' => $occurredAt,
                'qr_payload' => $card,
            ]);
    });

    expect($responses[0]['status'])->toBe(200);
}

/**
 * Deja instalado el ledger que confirma un fichaje entre las dos lecturas de la
 * inspeccion. Lo comparten el caso de uso y el comando.
 */
function installRacingLedger(callable $duringBlockRead): void
{
    $real = app(WorkDayLedger::class);

    app()->instance(WorkDayLedger::class, new RacingWorkDayLedger($real, $duringBlockRead));
}

/**
 * Reconcilia la jornada de la prueba, con el fichaje intercalado entre las dos
 * lecturas de la inspeccion.
 */
function reconcileWithAScanInBetween(callable $duringBlockRead): ReconciliationReport
{
    installRacingLedger($duringBlockRead);

    return app(ReconcileDailyTotals::class)->handle(
        new ReconcileDailyTotalsCommand(racedWorkDate(), racedWorkDate()),
    );
}

/** La fila de `daily_totals` de esa persona, leida con tipos. */
function racedRow(string $employeeUuid): Row
{
    $row = DB::table('daily_totals')
        ->where('employee_id', AttendanceFixtures::employeeIdOf($employeeUuid))
        ->where('work_date', racedWorkDate())
        ->first();

    return Row::of(is_object($row) ? $row : throw new RuntimeException(
        'La jornada de '.$employeeUuid.' ha desaparecido de daily_totals.'
    ));
}

it('no pisa el fichaje que abre jornada mientras inspecciona el dia', function (): void {
    $kiosk = racedKiosk(1);
    $employee = $kiosk['employees'][0];

    FrozenTime::at('2026-03-14 06:00:00');

    $report = reconcileWithAScanInBetween(static function () use ($kiosk): void {
        racedScanInAnotherProcess($kiosk['token'], $kiosk['cards'][0], '2026-03-14T06:00:00Z');
    });

    // --- La jornada del empleado sigue siendo la que el fichaje escribio -----

    // Se afirma **primero el estado** y despues los recuentos: es lo que la
    // carga destapo —`shift_count = 0` y `first_in_at` a nulo sobre un tramo
    // abierto— y es lo que tiene que leerse en el fallo si esto vuelve a
    // romperse.
    $row = racedRow($employee);

    expect($row->int('shift_count'))->toBe(1)
        ->and($row->bool('has_open_shift'))->toBeTrue()
        ->and($row->nullableInstant('first_in_at'))->not->toBeNull()
        // Un tramo abierto aporta cero al total legal: el cero de aqui es el
        // bueno, y se distingue del cero de la corrupcion por los otros tres
        // campos.
        ->and($row->int('total_minutes'))->toBe(0);

    expect(DB::table('shift_entries')->count())->toBe(1)
        ->and(AttendanceFixtures::projectionDivergences())->toBe([]);

    // --- Y la pasada no ha «corregido» nada ---------------------------------

    // La sospecha existio —la inspeccion vio una fila sin jornada detras— pero
    // al releerla con la fila bloqueada ya cuadraba. Eso no es una divergencia:
    // si contara, la alerta critica de integridad sonaria cada madrugada.
    expect($report->divergences)->toBe(0)
        ->and($report->corrected)->toBe(0)
        ->and($report->failures)->toBe(0)
        ->and($report->selfResolved)->toBe(1)
        ->and($report->isClean())->toBeTrue();

    // Nada que corregir es nada que auditar: un asiento aqui significaria que la
    // pasada reescribio una fila que estaba bien (regla dura 6).
    expect(DB::table('audit_log')->where('subject_type', 'daily_totals')->count())->toBe(0);
})->group('RN-06', 'RF-PR-02');

it('no reabre la jornada que se cerro mientras inspeccionaba el dia', function (): void {
    $kiosk = racedKiosk(1);
    $employee = $kiosk['employees'][0];

    // La entrada, ya confirmada y con su proyeccion correcta.
    FrozenTime::at('2026-03-14 06:00:00');
    expect(racedScan($kiosk['token'], $kiosk['cards'][0], '2026-03-14T06:00:00Z'))->toBe(200);

    // Diez minutos despues corre la pasada, y la salida confirma entre sus dos
    // lecturas. Diez minutos y no dos: la ventana anti-rebote de RF-AT-06 son
    // sesenta segundos, y por debajo de ella el segundo escaneo no cerraria
    // nada.
    FrozenTime::at('2026-03-14 06:10:00');

    $report = reconcileWithAScanInBetween(static function () use ($kiosk): void {
        racedScanInAnotherProcess($kiosk['token'], $kiosk['cards'][0], '2026-03-14T06:10:00Z');
    });

    // El turno esta cerrado y el dia vale diez minutos: lo que el fichaje
    // escribio. La corrupcion de la carga dejaba aqui `total_minutes = 0` con
    // `has_open_shift = true` sobre un tramo ya cerrado.
    $row = racedRow($employee);

    expect($row->int('total_minutes'))->toBe(10)
        ->and($row->int('shift_count'))->toBe(1)
        ->and($row->bool('has_open_shift'))->toBeFalse()
        ->and($row->nullableInstant('last_out_at'))->not->toBeNull();

    // La comprobacion de integridad de RN-06: la proyeccion y la suma de los
    // tramos vigentes tienen que decir lo mismo.
    expect(AttendanceFixtures::projectionDivergences())->toBe([])
        ->and(DB::table('audit_log')->where('subject_type', 'daily_totals')->count())->toBe(0);

    expect($report->divergences)->toBe(0)
        ->and($report->corrected)->toBe(0)
        ->and($report->failures)->toBe(0)
        ->and($report->selfResolved)->toBe(1);
})->group('RN-06', 'RF-PR-02');

it('termina en verde y lo dice cuando la jornada se resolvio sola', function (): void {
    // Lo mismo que la primera, **por el comando**: lo que el operador ve es la
    // salida de `attendance:reconcile` y su codigo de salida, y de los dos
    // depende que el planificador registre un fallo nocturno (`onFailure` de
    // `routes/console.php`) y que alguien acuda al runbook a las cuatro de la
    // manana sin que haya nada roto.
    $kiosk = racedKiosk(1);
    $employee = $kiosk['employees'][0];

    FrozenTime::at('2026-03-14 06:00:00');

    installRacingLedger(static function () use ($kiosk): void {
        racedScanInAnotherProcess($kiosk['token'], $kiosk['cards'][0], '2026-03-14T06:00:00Z');
    });

    [$exitCode, $output] = Commands::run('attendance:reconcile --from='.racedWorkDate().' --to='.racedWorkDate());

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('1 jornada(s) se resolvieron solas')
        ->and($output)->toContain('Sin divergencias')
        // Y **no** el aviso de divergencia: es el que remite al runbook y el que
        // no debe aparecer por que alguien fiche durante la pasada.
        ->and($output)->not->toContain('divergencia(s)')
        ->and($output)->not->toContain('no se pudieron reconciliar');

    // Recuentos, nunca personas (regla dura 21): ni el nombre ni siquiera el
    // UUID salen por pantalla. El detalle vive en el log.
    expect($output)->not->toContain($employee);

    $row = racedRow($employee);

    expect($row->int('shift_count'))->toBe(1)
        ->and($row->bool('has_open_shift'))->toBeTrue()
        ->and(DB::table('audit_log')->where('subject_type', 'daily_totals')->count())->toBe(0);
})->group('RN-06', 'RF-PR-02');

it('corrige una divergencia real con un fichaje esperando, sin abrazo mortal', function (): void {
    // **EL ABRAZO MORTAL ABBA, que es el defecto que esta prueba fija.**
    //
    // El camino del fichaje toma el candado consultivo GLOBAL de la cadena de
    // `audit_log` —el agregado registra `EmployeeClockedIn` antes de
    // `DailyTotalsRecalculated`, y el asiento lo escribe un listener sincrono—
    // y DESPUES la fila de `daily_totals` (el `UPSERT` del proyector). La
    // correccion tomaba las dos al reves: primero la fila con `FOR UPDATE` y
    // despues el candado, al publicar el asiento.
    //
    // Eso no es una carrera que se resuelva reintentando: es un ciclo, y
    // PostgreSQL lo rompe matando a una de las dos transacciones a
    // `deadlock_timeout` (1 s). Si la victima es el fichaje, un empleado recibe
    // un error al pasar la tarjeta porque una tarea de mantenimiento estaba
    // reconciliando — lo que la regla dura 19 prohibe.
    //
    // Para que el ciclo pueda existir hace falta una divergencia **real y
    // persistente** (que la relectura confirme) y un fichaje **en vuelo** (no
    // uno ya confirmado): por eso la fila se manipula a mano y por eso los dos
    // actores son dos procesos hijos que se citan con un fichero, en lugar de
    // un escaneo que termina antes de devolver el control.
    $kiosk = racedKiosk(1);
    $employee = $kiosk['employees'][0];

    FrozenTime::at('2026-03-14 06:00:00');

    // La jornada existe de verdad: entrada fichada y proyeccion correcta.
    expect(racedScan($kiosk['token'], $kiosk['cards'][0], '2026-03-14T06:00:00Z'))->toBe(200);

    // Y ahora la proyeccion miente, por fuera de la aplicacion: es la unica
    // forma en que esto ocurre de verdad (regla dura 7). Un tramo abierto vale
    // cero minutos, asi que la divergencia NO se deshace al releer.
    DB::table('daily_totals')
        ->where('employee_id', AttendanceFixtures::employeeIdOf($employee))
        ->where('work_date', racedWorkDate())
        ->update(['total_minutes' => 999]);

    // La salida llega diez minutos despues: por encima de la ventana
    // anti-rebote de RF-AT-06, asi que cierra el turno de verdad.
    FrozenTime::at('2026-03-14 06:10:00');

    $signals = racedSignalDirectory();

    $results = ParallelRequests::runTasks(2, static function (int $index) use ($kiosk, $signals): mixed {
        if ($index === 0) {
            // El fichaje. No arranca hasta que la correccion tiene la fila
            // tomada: es la cita que hace que el choque de candados sea el de
            // produccion y no uno cualquiera.
            racedWaitFor($signals.'/row-locked');

            return ['status' => racedScan($kiosk['token'], $kiosk['cards'][0], '2026-03-14T06:10:00Z')];
        }

        return racedReconcileWaitingForTheScan($signals);
    });

    $scan = $results[0] ?? null;
    $reconciliation = $results[1] ?? null;

    // --- Los dos terminan, y el empleado no se entera de nada ---------------

    // Con el orden invertido, aqui aparecia un 500 (si la victima del abrazo era
    // el fichaje) o una correccion perdida (si era la reconciliacion).
    expect(racedCount($scan, 'status'))->toBe(200);

    // La cita ocurrio de verdad: si el fichaje no hubiera llegado a esperar por
    // ningun candado, esta prueba no habria ejercitado ningun choque y no
    // significaria nada.
    expect(racedCount($reconciliation, 'blocked'))->toBe(1);

    // --- La correccion se hizo, y es una de verdad --------------------------

    expect(racedCount($reconciliation, 'divergences'))->toBe(1)
        ->and(racedCount($reconciliation, 'corrected'))->toBe(1)
        ->and(racedCount($reconciliation, 'failures'))->toBe(0)
        ->and(racedCount($reconciliation, 'self_resolved'))->toBe(0);

    // Y dejo asiento: la unica copia de lo que la fila afirmaba (regla dura 6).
    expect(DB::table('audit_log')->where('subject_type', 'daily_totals')->count())->toBe(1);

    // --- El estado final es el del fichaje, que es el ultimo en escribir ----

    $row = racedRow($employee);

    expect($row->int('total_minutes'))->toBe(10)
        ->and($row->int('shift_count'))->toBe(1)
        ->and($row->bool('has_open_shift'))->toBeFalse()
        ->and(AttendanceFixtures::projectionDivergences())->toBe([]);
})->group('RN-06', 'RF-PR-02');

it('sobrevive a una rafaga de fichajes con la reconciliacion dando vueltas', function (): void {
    // La forma que tenia el fallo en la prueba de carga: varias personas
    // fichando a la vez y la pasada ejecutandose encima. Aqui no se fuerza
    // ningun intercalado: se lanzan los dos a la vez y se exige que el desenlace
    // sea siempre el mismo.
    $people = 8;
    $kiosk = racedKiosk($people);

    FrozenTime::at('2026-03-14 06:00:00');

    $signals = racedSignalDirectory();

    $results = ParallelRequests::runTasks(
        $people + 1,
        static function (int $index) use ($kiosk, $people, $signals): mixed {
            if ($index < $people) {
                $status = racedScan($kiosk['token'], $kiosk['cards'][$index], '2026-03-14T06:00:00Z');

                // La señal se deja **despues** de que la respuesta vuelva, es
                // decir, con la transaccion ya confirmada: es lo que permite a la
                // pasada saber cuando ha terminado la rafaga sin esperar por
                // reloj.
                touch($signals.'/done-'.$index);

                return ['status' => $status];
            }

            return reconcileDuringTheBurst($signals, $people);
        },
    );

    $statuses = [];

    for ($index = 0; $index < $people; $index++) {
        $statuses[] = racedCount($results[$index] ?? null, 'status');
    }

    $reconciliation = $results[$people] ?? null;

    expect(array_unique($statuses))->toBe([200]);

    // **Al menos una pasada, y eso es lo unico que se puede exigir.** El
    // reconciliador es el noveno `fork`: si el sistema operativo lo planifica
    // tarde y los ocho fichajes ya han dejado su señal, el bucle no da ninguna
    // vuelta «durante» la rafaga y solo queda la final. Exigir mas de una seria
    // exigirle al planificador un orden que nadie promete, que es como se
    // escriben las pruebas que fallan una vez de cada treinta en la CI. Las que
    // garantizan el intercalado son las dos primeras de este fichero.
    expect(racedCount($reconciliation, 'passes'))->toBeGreaterThanOrEqual(1)
        // Y lo que no puede pasar: que la reconciliacion «corrija» una jornada
        // que el fichaje acababa de dejar bien.
        ->and(racedCount($reconciliation, 'corrected'))->toBe(0)
        ->and(racedCount($reconciliation, 'divergences'))->toBe(0)
        ->and(racedCount($reconciliation, 'failures'))->toBe(0);

    // --- El estado final, que es lo que ve el panel y el portal --------------

    expect(DB::table('shift_entries')->count())->toBe($people)
        ->and(DB::table('daily_totals')->count())->toBe($people)
        ->and(DB::table('daily_totals')->where('has_open_shift', true)->count())->toBe($people)
        ->and(DB::table('daily_totals')->where('shift_count', 1)->count())->toBe($people)
        ->and(DB::table('daily_totals')->whereNull('first_in_at')->count())->toBe(0)
        ->and(AttendanceFixtures::projectionDivergences())->toBe([]);
})->group('RN-06', 'RF-PR-02');

/**
 * Espera a que aparezca un fichero, sin pasarse de tiempo.
 *
 * Es una espera **por condicion**: el fichero lo deja el otro proceso cuando ha
 * llegado al punto que interesa. El tope existe para que un hijo que nunca
 * llegue no deje a este girando para siempre, no como sincronizacion.
 */
function racedWaitFor(string $file, int $attempts = 4000): void
{
    for ($attempt = 0; $attempt < $attempts && ! is_file($file); $attempt++) {
        usleep(2_000);
    }
}

/**
 * Reconcilia la jornada **citandose con un fichaje en vuelo**: deja la señal en
 * cuanto tiene la fila tomada y no sigue hasta ver al otro proceso esperando un
 * candado.
 *
 * Devuelve los recuentos de la pasada mas si llego a ver esa espera, que es lo
 * que distingue «no hubo abrazo» de «no hubo choque que abrazar».
 *
 * @return array<string, int>
 */
function racedReconcileWaitingForTheScan(string $signals): array
{
    $ledger = app(WorkDayLedger::class);
    $blocked = 0;

    app()->instance(WorkDayLedger::class, new RacingWorkDayLedger(
        $ledger,
        // La rafaga no interesa aqui: lo que se provoca es el choque de
        // candados, no la lectura a medias.
        static fn (): null => null,
        static function () use ($signals, &$blocked): void {
            // La fila ya esta tomada con `FOR UPDATE`: que arranque el fichaje.
            touch($signals.'/row-locked');

            // Y no se sigue hasta verlo esperar por un candado. Con el orden
            // corregido espera por el **consultivo de la cadena**; con el
            // invertido esperaba por esta misma fila, que es el ciclo. Se
            // pregunta a `pg_locks` en lugar de dormir un rato fijo: lo que hace
            // falta es la condicion, no el tiempo.
            for ($attempt = 0; $attempt < 1_500; $attempt++) {
                if (racedWaitingBackends() > 0) {
                    $blocked = 1;

                    return;
                }

                usleep(2_000);
            }
        },
    ));

    $report = app(ReconcileDailyTotals::class)->handle(
        new ReconcileDailyTotalsCommand(racedWorkDate(), racedWorkDate()),
    );

    return [
        'blocked' => $blocked,
        'divergences' => $report->divergences,
        'corrected' => $report->corrected,
        'failures' => $report->failures,
        'self_resolved' => $report->selfResolved,
    ];
}

/**
 * Cuantas sesiones de PostgreSQL estan esperando un candado ahora mismo.
 *
 * Sirve para las dos formas del choque —fila y candado consultivo— sin
 * distinguirlas: lo que se quiere saber es si el otro proceso ya llego al punto
 * de bloquearse.
 */
function racedWaitingBackends(): int
{
    /** @var list<object{waiting: int|string}> $rows */
    $rows = DB::select('SELECT count(*) AS waiting FROM pg_locks WHERE NOT granted');

    $waiting = $rows[0]->waiting ?? 0;

    return is_numeric($waiting) ? (int) $waiting : 0;
}

/**
 * Un recuento de los que vuelven de un proceso hijo, ya tipado.
 *
 * El viaje por JSON deja `mixed` a los dos lados, y un molde silencioso haria
 * que un resultado ausente se leyera como cero — es decir, que la prueba pasara
 * justo cuando el hijo no hizo su trabajo. Con `-1`, la asercion falla y dice
 * cual.
 */
function racedCount(mixed $payload, string $key): int
{
    if (! is_array($payload) || ! isset($payload[$key]) || ! is_int($payload[$key])) {
        return -1;
    }

    return $payload[$key];
}

/**
 * Reconcilia en bucle mientras dure la rafaga y devuelve lo que encontro.
 *
 * **La condicion de parada son las señales de los hijos que fichan, no un
 * `sleep`**: una espera por reloj daria una prueba que pasa en el portatil y
 * falla en la CI, o al reves. El tope de vueltas existe para que un hijo que se
 * quede sin escribir su señal no deje este proceso girando para siempre.
 *
 * @return array<string, int>
 */
function reconcileDuringTheBurst(string $signals, int $people): array
{
    $reconcile = app(ReconcileDailyTotals::class);
    $command = new ReconcileDailyTotalsCommand(racedWorkDate(), racedWorkDate());

    $passes = 0;
    $divergences = 0;
    $corrected = 0;
    $failures = 0;
    $selfResolved = 0;

    $pass = function () use ($reconcile, $command, &$passes, &$divergences, &$corrected, &$failures, &$selfResolved): void {
        $report = $reconcile->handle($command);

        $passes++;
        $divergences += $report->divergences;
        $corrected += $report->corrected;
        $failures += $report->failures;
        $selfResolved += $report->selfResolved;
    };

    while (\count(glob($signals.'/done-*') ?: []) < $people && $passes < 400) {
        $pass();
    }

    // Una vuelta mas con la rafaga ya terminada: esta ve el dia entero y tiene
    // que encontrarlo cuadrado. Si alguna de las anteriores hubiera corrompido
    // una jornada, esta seria la que lo denunciaria.
    $pass();

    return [
        'passes' => $passes,
        'divergences' => $divergences,
        'corrected' => $corrected,
        'failures' => $failures,
        'self_resolved' => $selfResolved,
    ];
}
