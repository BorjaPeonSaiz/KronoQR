<?php

declare(strict_types=1);

use App\Modules\Attendance\Infrastructure\Metrics\RedisAnomalyMetrics;
use App\Modules\Shared\Application\Port\Clock;
use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Time\FixedClock;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `anomalous_patterns_detected_total{pattern}` publicado por la revision
 * nocturna (doc 02 §8.2, tarea 3.1).
 *
 * ## Lo unico que esta prueba no deja pasar
 *
 * Que la serie de Grafana y la salida de `attendance:detect-incidents`
 * discrepen. Son dos formas de contar lo mismo, escritas en dos sitios
 * distintos, y en cuanto dejen de coincidir la conversacion del lunes por la
 * mañana se convierte en «pues el panel dice otra cosa». Por eso se afirma la
 * IGUALDAD entre lo que el comando reporta y lo que queda en Redis, y no un
 * numero literal: asi la prueba sigue valiendo cuando la deteccion mejore y
 * encuentre mas cosas.
 *
 * ## Sin doble de `AnomalyMetrics`
 *
 * El adaptador real **se traga sus propios fallos** —medir no puede romper una
 * revision que ya escribio sus incidencias y sus asientos de auditoria—, asi que
 * un doble no distinguiria «conto» de «lo intento y no pudo». Se lee el hash que
 * `/metrics` publica.
 *
 * ## El reloj esta detenido (regla dura 2)
 *
 * «Un turno abierto desde hace trece horas» no se puede afirmar contra un reloj
 * que avanza: la prueba pasaria o fallaria segun la hora a la que se ejecute.
 */

uses(RefreshDatabase::class);

/** Las 19:00 UTC del 14 de marzo, el «ahora» de todo este fichero. */
const AHORA_DE_LA_REVISION = '2026-03-14 19:00:00';

beforeEach(function (): void {
    // Contador acumulado en Redis: la base de datos si se rehace entre pruebas,
    // esto no.
    app(Redis::class)->connection()->command('DEL', [RedisAnomalyMetrics::ANOMALIES_TOTAL]);
});

afterEach(function (): void {
    app(Redis::class)->connection()->command('DEL', [RedisAnomalyMetrics::ANOMALIES_TOTAL]);
});

/**
 * Un tramo escrito directamente en la tabla: la revision necesita estados que el
 * fichaje tardaria trece horas de reloj en producir.
 */
function tramoDeRevision(string $employeeUuid, int $siteId, string $clockedInAt, ?string $clockedOutAt = null): void
{
    DB::table('shift_entries')->insert([
        'uuid' => Str::uuid7()->toString(),
        'employee_id' => AttendanceFixtures::employeeIdOf($employeeUuid),
        'site_id' => $siteId,
        'work_date' => substr($clockedInAt, 0, 10),
        'clocked_in_at' => $clockedInAt,
        'clocked_out_at' => $clockedOutAt,
        'duration_minutes' => $clockedOutAt === null
            ? null
            : intdiv(strtotime($clockedOutAt) - strtotime($clockedInAt), 60),
        'status' => $clockedOutAt === null ? 'open' : 'closed',
        'clock_in_source' => 'qr_kiosk',
        'clock_out_source' => $clockedOutAt === null ? null : 'qr_kiosk',
        'version' => 1,
        'created_at' => $clockedInAt,
        'updated_at' => $clockedInAt,
    ]);
}

/**
 * Ejecuta la revision nocturna con el reloj detenido y devuelve el recuento por
 * tipo **tal y como lo reporto el comando**.
 *
 * El comando imprime una linea `  <tipo>: <n>` por tipo encontrado, que es lo
 * que lee el responsable en el registro del planificador. Comparar eso con la
 * serie es comparar las dos caras del mismo numero.
 *
 * @return array<string, int>
 */
function revisionNocturna(): array
{
    app()->instance(Clock::class, FixedClock::at(AHORA_DE_LA_REVISION));

    expect(Artisan::call('attendance:detect-incidents'))->toBe(0);

    preg_match_all('/^ {2}([a-z_]+): (\d+)$/m', Artisan::output(), $lineas, PREG_SET_ORDER);

    $reportado = [];

    foreach ($lineas as $linea) {
        $reportado[$linea[1]] = (int) $linea[2];
    }

    return $reportado;
}

/**
 * @return array<string, string>
 */
function patronesDetectados(): array
{
    /** @var array<string, string> $fields */
    $fields = app(Redis::class)->connection()->command('HGETALL', [RedisAnomalyMetrics::ANOMALIES_TOTAL]);

    return $fields;
}

/**
 * Centro en Madrid con un empleado dentro.
 *
 * @return array{site: int, employee: string}
 */
function centroDeRevision(): array
{
    $site = WorkforceFixtures::site('Hotel de revision nocturna', 'Europe/Madrid');

    return ['site' => $site, 'employee' => WorkforceFixtures::employee($site, WorkforceFixtures::department($site))];
}

it('publica el turno olvidado con el mismo recuento que reporta el comando', function (): void {
    // Escenario «Turno olvidado» del doc 01 §11: un tramo abierto desde hace
    // trece horas. La revision lo encuentra, abre incidencia y **no cierra el
    // tramo** (RN-08); lo que aqui se comprueba es la tercera cosa que hace, que
    // es dejar constancia de cuantos encontro.
    ['site' => $site, 'employee' => $employee] = centroDeRevision();

    tramoDeRevision($employee, $site, '2026-03-14 06:00:00+00');

    $reportado = revisionNocturna();

    expect($reportado)->not->toBe([])
        ->and(patronesDetectados())->toBe(
            array_combine(
                array_map(static fn (string $tipo): string => 'pattern='.$tipo, array_keys($reportado)),
                array_map(static fn (int $n): string => (string) $n, $reportado),
            ),
        );
})->group('RF-PR-01', 'RF-PR-06');

it('reparte los hallazgos por tipo y no en un solo cubo', function (): void {
    // Dos anomalias distintas en la misma pasada: un turno abierto de trece
    // horas y una jornada desmesurada ya cerrada. La serie tiene que
    // distinguirlas, porque las dos alertas del §9.3 que las miran son
    // distintas y van a destinatarios distintos.
    ['site' => $site, 'employee' => $employee] = centroDeRevision();
    $otro = WorkforceFixtures::employee($site);

    tramoDeRevision($employee, $site, '2026-03-14 06:00:00+00');
    tramoDeRevision($otro, $site, '2026-03-13 04:00:00+00', '2026-03-13 21:00:00+00');

    $reportado = revisionNocturna();

    expect(array_keys($reportado))->toHaveCount(2)
        ->and(patronesDetectados())->toHaveCount(2);
})->group('RF-PR-01', 'RF-PR-06');

it('una noche tranquila no escribe ninguna serie', function (): void {
    // Lo normal, y lo que tiene que verse como ausencia y no como ceros:
    // escribir un cero por tipo llenaria la serie de valores que no aportan y
    // haria creer que la revision encontro algo.
    ['site' => $site, 'employee' => $employee] = centroDeRevision();

    tramoDeRevision($employee, $site, '2026-03-14 06:00:00+00', '2026-03-14 14:00:00+00');

    expect(revisionNocturna())->toBe([])
        ->and(patronesDetectados())->toBe([]);
})->group('RF-PR-01', 'RF-PR-06');

it('el reparto por patron sale por /metrics', function (): void {
    config(['observability.metrics.allow_cidr' => '10.91.0.0/24']);

    ['site' => $site, 'employee' => $employee] = centroDeRevision();

    tramoDeRevision($employee, $site, '2026-03-14 06:00:00+00');

    $reportado = revisionNocturna();
    $patron = (string) array_key_first($reportado);

    $cuerpo = (string) Api::guest()->fromIp('10.91.0.5')->get('/metrics')->getContent();

    expect($cuerpo)
        ->toContain('# TYPE anomalous_patterns_detected_total counter')
        ->toContain('anomalous_patterns_detected_total{pattern="'.$patron.'"} '.$reportado[$patron]);
})->group('RF-PR-01', 'RF-PR-06', 'RS-09');
