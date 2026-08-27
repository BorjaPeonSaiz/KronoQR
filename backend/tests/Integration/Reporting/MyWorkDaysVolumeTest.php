<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\WorkDayRepository;
use App\Modules\Attendance\Domain\Event\DailyTotalsRecalculated;
use App\Modules\Attendance\Domain\Model\WorkDay;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Attendance\Infrastructure\Projection\DailyTotalsProjector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Factory\ClockingPolicyFactory;
use Tests\Support\Http\Api;
use Tests\Support\Identity\PortalLogins;
use Tests\Support\Time\Instants;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * El portal con volumen: **90 dias de jornadas** (doc 02 §9.5 y §10.2, tarea
 * 1.11).
 *
 * Lo que esta prueba defiende no es el contenido de la respuesta —eso lo hace la
 * de feature— sino dos propiedades que solo se ven con datos suficientes:
 *
 *   1. **Que las consultas del adaptador son planas y no un N+1.** Con 90
 *      jornadas, una consulta por dia serian noventa viajes a la base para
 *      pintar una pantalla que alguien abre desde datos moviles. Se cuentan las
 *      sentencias, que es lo unico que lo detecta antes de que lo detecte una
 *      persona con un mes malo de cobertura.
 *   2. **Que el CSV completo sigue cuadrando**: la suma de las duraciones de las
 *      filas es exactamente la suma de los totales de las jornadas (RN-06).
 *
 * Corre en la suite de integracion porque necesita PostgreSQL de verdad: las
 * cuatro consultas del adaptador usan funciones de ventana y `DISTINCT ON`, que
 * no existen en SQLite.
 */

uses(RefreshDatabase::class);

/**
 * Noventa jornadas consecutivas de ocho horas, con su proyeccion recalculada.
 *
 * @return array{token: string, employee: string, from: string, to: string, days: int}
 */
function noventaJornadas(): array
{
    config()->set('identity.portal.rate_limit_per_minute', 10_000);

    $site = WorkforceFixtures::site('Hotel con historico');
    $employee = WorkforceFixtures::employee($site);
    $token = PortalLogins::open($employee);

    $repositorio = app(WorkDayRepository::class);
    $proyector = app(DailyTotalsProjector::class);

    $primero = new DateTimeImmutable('2026-01-01', new DateTimeZone('UTC'));

    for ($dia = 0; $dia < 90; $dia++) {
        $fecha = $primero->modify('+'.$dia.' days')->format('Y-m-d');

        $jornada = WorkDay::start($employee, $site, WorkDate::fromIsoDate($fecha, Instants::madrid()));
        $jornada->clockIn(Str::uuid7()->toString(), Instants::inMadrid($fecha.' 08:00'), ScanOrigin::QR_KIOSK);
        $jornada->clockOut(Instants::inMadrid($fecha.' 16:00'), ScanOrigin::QR_KIOSK, ClockingPolicyFactory::standard());

        $repositorio->save($jornada);

        foreach ($jornada->releaseEvents() as $evento) {
            if ($evento instanceof DailyTotalsRecalculated) {
                $proyector->handle($evento);
            }
        }
    }

    return [
        'token' => $token,
        'employee' => $employee,
        'from' => '2026-01-01',
        'to' => $primero->modify('+89 days')->format('Y-m-d'),
        'days' => 90,
    ];
}

it('sirve noventa jornadas propias sin una consulta por dia', function (): void {
    $contexto = noventaJornadas();

    DB::flushQueryLog();
    DB::enableQueryLog();

    $respuesta = Api::as($contexto['token'])->get('/api/v1/me/workdays', [
        'from' => $contexto['from'],
        'to' => $contexto['to'],
    ]);

    $sentencias = \count(DB::getRawQueryLog());
    DB::disableQueryLog();

    $respuesta->assertStatus(200)
        ->assertJsonPath('meta.total', $contexto['days'])
        ->assertJsonPath('employee_uuid', $contexto['employee']);

    // El techo es generoso a proposito: ademas de las cuatro consultas del
    // adaptador estan la del token de Sanctum, la del empleado y la de la zona
    // del centro. Lo que esta prueba tiene que cazar es el orden de magnitud —una
    // consulta por jornada serian noventa— y no fijar un numero exacto que
    // convertiria cualquier refactor en un fallo.
    expect($sentencias)->toBeLessThan(20);
})->group('RF-ID-05', 'RNF-P-01');

it('cuadra la suma del CSV completo con los totales de las jornadas', function (): void {
    // RN-06 visto desde el fichero que la persona se lleva. Si el CSV sumara
    // distinto que la pantalla, el numero que alguien lleva a una reunion no
    // seria el que el sistema defiende.
    $contexto = noventaJornadas();

    $csv = Api::as($contexto['token'])->get('/api/v1/me/export', [
        'from' => $contexto['from'],
        'to' => $contexto['to'],
    ])->streamedContent();

    $minutos = 0;

    foreach (explode("\r\n", $csv) as $linea) {
        if (! str_starts_with($linea, 'TRAMO;')) {
            continue;
        }

        $columnas = explode(';', $linea);

        // La columna `duration` es la sexta del orden declarado en
        // `PersonalRecordCsv::COLUMNS`, y va como `HH:MM`.
        [$horas, $resto] = explode(':', $columnas[5]);

        $minutos += ((int) $horas * 60) + (int) $resto;
    }

    // Noventa jornadas de ocho horas, escritas como numero y no calculadas por
    // la prueba a partir de lo mismo que esta comprobando.
    expect($minutos)->toBe(43_200);
})->group('RF-ID-05', 'RN-06', 'RL-05');
