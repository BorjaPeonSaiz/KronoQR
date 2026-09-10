<?php

declare(strict_types=1);

use App\Modules\Reporting\Application\Port\WorkDayCompletionReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `workdays_complete_ratio{site}` — el recuento que lo sostiene, contra
 * PostgreSQL de verdad (RF-IN-08, doc 02 §8.2, tarea 3.1).
 *
 * POR QUE INTEGRACION Y NO UNIDAD. Aqui no hay ninguna regla que probar en
 * memoria: lo que se comprueba **es la consulta**. La doble agrupacion que
 * convierte tramos en jornadas, el `FILTER` que decide que es completa y el
 * predicado que deja fuera lo anulado y lo sustituido viven en SQL, y un doble
 * del puerto los daria por buenos sin haberlos ejecutado nunca. El caso de uso
 * que decide QUE DIA se mide si es unitario, y esta en
 * `tests/Unit/Reporting/AdoptionMetricsTest.php`.
 *
 * LO QUE ESTA METRICA PROMETE es «cuantas jornadas quedaron bien registradas».
 * Un error aqui no rompe nada visible: publica un numero creible y equivocado en
 * el cuadro que sostiene la renovacion de la licencia. Por eso se prueba contra
 * el motor y con los estados que el producto produce de verdad.
 *
 * Los tramos se escriben con el constructor de consultas y no fichando: la
 * mitad de estos estados —un turno de noche ya cerrado, un tramo sustituido por
 * una correccion— costaria ocho horas de reloj producirlos por el camino real.
 */

uses(RefreshDatabase::class);

/**
 * Un tramo tal cual queda en la tabla, con la jornada a la que pertenece.
 *
 * `$clockedOutAt` a `null` es el tramo abierto: entrada fichada y salida
 * pendiente, que es justo lo que descalifica una jornada.
 */
function tramoDeJornada(
    string $employeeUuid,
    int $siteId,
    string $workDate,
    string $clockedInAt,
    ?string $clockedOutAt = null,
    string $status = 'closed',
): void {
    DB::table('shift_entries')->insert([
        'uuid' => Str::uuid7()->toString(),
        'employee_id' => AttendanceFixtures::employeeIdOf($employeeUuid),
        'site_id' => $siteId,
        'work_date' => $workDate,
        'clocked_in_at' => $clockedInAt,
        'clocked_out_at' => $clockedOutAt,
        'duration_minutes' => $clockedOutAt === null
            ? null
            : intdiv(strtotime($clockedOutAt) - strtotime($clockedInAt), 60),
        'status' => $status,
        'clock_in_source' => 'qr_kiosk',
        'clock_out_source' => $clockedOutAt === null ? null : 'qr_kiosk',
        'version' => 1,
        'created_at' => $clockedInAt,
        'updated_at' => $clockedInAt,
    ]);
}

/**
 * @return array<int, array{complete: int, total: int}>
 */
function jornadasCompletasEn(string $workDate): array
{
    return app(WorkDayCompletionReader::class)->completionOn($workDate);
}

/**
 * Centro de la instalacion y un empleado nuevo dentro.
 *
 * @return array{site: int, employee: string}
 */
function centroConEmpleado(): array
{
    $site = WorkforceFixtures::site('Hotel de adopcion', 'Europe/Madrid');

    return ['site' => $site, 'employee' => WorkforceFixtures::employee($site)];
}

it('cuenta como completa la jornada que no dejo ningun tramo abierto', function (): void {
    ['site' => $site, 'employee' => $employee] = centroConEmpleado();

    tramoDeJornada($employee, $site, '2026-03-14', '2026-03-14 06:00:00+00', '2026-03-14 14:00:00+00');

    expect(jornadasCompletasEn('2026-03-14'))->toBe([$site => ['complete' => 1, 'total' => 1]]);
})->group('RF-IN-08');

it('cuenta una sola jornada por empleado aunque tenga cuatro tramos', function (): void {
    // La jornada partida de un hotel: entrada, pausa de comida y vuelta. Sin la
    // primera agrupacion de la consulta esto sumaria dos jornadas, y un turno
    // partido pesaria el doble que uno seguido en el mismo indicador.
    ['site' => $site, 'employee' => $employee] = centroConEmpleado();

    tramoDeJornada($employee, $site, '2026-03-14', '2026-03-14 06:00:00+00', '2026-03-14 10:00:00+00');
    tramoDeJornada($employee, $site, '2026-03-14', '2026-03-14 15:00:00+00', '2026-03-14 19:00:00+00');

    expect(jornadasCompletasEn('2026-03-14'))->toBe([$site => ['complete' => 1, 'total' => 1]]);
})->group('RF-IN-08');

it('un solo tramo abierto descalifica la jornada entera', function (): void {
    // Lo que esta metrica existe para ver: alguien que ficho su entrada de la
    // mañana, salio a comer, volvio y **se fue sin fichar la salida**. Los tres
    // primeros tramos estan impecables y la jornada no vale: es la entrada sin
    // salida que RN-08 prohibe cerrar de oficio y que acaba en una correccion.
    ['site' => $site, 'employee' => $employee] = centroConEmpleado();

    tramoDeJornada($employee, $site, '2026-03-14', '2026-03-14 06:00:00+00', '2026-03-14 10:00:00+00');
    tramoDeJornada($employee, $site, '2026-03-14', '2026-03-14 15:00:00+00', null, 'open');

    expect(jornadasCompletasEn('2026-03-14'))->toBe([$site => ['complete' => 0, 'total' => 1]]);
})->group('RF-IN-08', 'RN-08');

it('descalifica tambien el tramo sin salida que quedo marcado como anomalo', function (): void {
    // La revision nocturna marca `anomalous` el turno abierto de trece horas
    // (RF-PR-01) y **no lo cierra**. Sigue siendo una jornada sin cerrar: si la
    // consulta mirase el estado en vez de `clocked_out_at`, marcar la anomalia
    // arreglaria el indicador sin arreglar el registro.
    ['site' => $site, 'employee' => $employee] = centroConEmpleado();

    tramoDeJornada($employee, $site, '2026-03-14', '2026-03-14 06:00:00+00', null, 'anomalous');

    expect(jornadasCompletasEn('2026-03-14'))->toBe([$site => ['complete' => 0, 'total' => 1]]);
})->group('RF-IN-08', 'RF-PR-01');

it('no cuenta el tramo anulado ni en el numerador ni en el denominador', function (): void {
    // Nada se borra (regla dura 5): el tramo anulado sigue en la tabla. Contarlo
    // haria que anular bien un fichaje duplicado empeorase el indicador.
    ['site' => $site, 'employee' => $employee] = centroConEmpleado();

    tramoDeJornada($employee, $site, '2026-03-14', '2026-03-14 06:00:00+00', '2026-03-14 14:00:00+00', 'voided');

    expect(jornadasCompletasEn('2026-03-14'))->toBe([]);
})->group('RF-IN-08', 'RN-13');

it('el tramo sustituido por una correccion no arrastra su version vieja', function (): void {
    // RN-13: corregir crea una version nueva y conserva la anterior con estado
    // `superseded`. La jornada es UNA, no dos, y esta completa. Sin el predicado
    // de estado, un dia corregido con mimo apareceria con una jornada de mas.
    ['site' => $site, 'employee' => $employee] = centroConEmpleado();

    tramoDeJornada($employee, $site, '2026-03-14', '2026-03-14 06:00:00+00', '2026-03-14 13:00:00+00', 'superseded');
    tramoDeJornada($employee, $site, '2026-03-14', '2026-03-14 06:00:00+00', '2026-03-14 14:00:00+00');

    expect(jornadasCompletasEn('2026-03-14'))->toBe([$site => ['complete' => 1, 'total' => 1]]);
})->group('RF-IN-08', 'RN-13');

it('una jornada cuyos tramos se anularon todos desaparece del recuento', function (): void {
    // El caso limite del anterior: no queda nada vigente, asi que no hay jornada
    // que medir. Cero sobre uno diria «alguien se dejo el turno abierto», que no
    // es lo que ocurrio.
    ['site' => $site, 'employee' => $employee] = centroConEmpleado();

    tramoDeJornada($employee, $site, '2026-03-14', '2026-03-14 06:00:00+00', '2026-03-14 14:00:00+00', 'voided');
    tramoDeJornada($employee, $site, '2026-03-14', '2026-03-14 15:00:00+00', null, 'voided');

    expect(jornadasCompletasEn('2026-03-14'))->toBe([]);
})->group('RF-IN-08', 'RN-13');

it('el turno de noche cuenta UNA jornada, en la fecha en la que empezo', function (): void {
    // Regla dura 4 y RN-05 aplicadas al indicador. 22:00 a 06:00 en Madrid es un
    // unico tramo con `work_date` del dia 14, y la salida cae en el 15. Si el
    // recuento agrupara por la fecha del instante en vez de por `work_date`,
    // partiria el turno en dos y el dia 15 estrenaria una jornada que nadie
    // trabajo.
    ['site' => $site, 'employee' => $employee] = centroConEmpleado();

    tramoDeJornada($employee, $site, '2026-03-14', '2026-03-14 21:00:00+00', '2026-03-15 05:00:00+00');

    expect(jornadasCompletasEn('2026-03-14'))->toBe([$site => ['complete' => 1, 'total' => 1]])
        ->and(jornadasCompletasEn('2026-03-15'))->toBe([]);
})->group('RF-IN-08', 'RN-05', 'RF-AT-08');

it('no arrastra las jornadas de los dias vecinos', function (): void {
    // La ventana es una fecha exacta. Un `>=` de mas convertiria el ratio de
    // ayer en el ratio del mes, que baja despacio y no dispara nada.
    //
    // Los turnos sin cerrar son de OTRAS personas: un tramo abierto ocupa un
    // intervalo sin fin y `shift_entries_no_overlap` (RN-02) no deja que el
    // mismo empleado fiche nada despues. Que el esquema lo impida es la
    // garantia; aqui solo hay que respetarla.
    $site = WorkforceFixtures::site('Hotel de adopcion', 'Europe/Madrid');

    tramoDeJornada(WorkforceFixtures::employee($site), $site, '2026-03-13', '2026-03-13 06:00:00+00', null, 'open');
    tramoDeJornada(WorkforceFixtures::employee($site), $site, '2026-03-14', '2026-03-14 06:00:00+00', '2026-03-14 14:00:00+00');
    tramoDeJornada(WorkforceFixtures::employee($site), $site, '2026-03-15', '2026-03-15 06:00:00+00', null, 'open');

    expect(jornadasCompletasEn('2026-03-14'))->toBe([$site => ['complete' => 1, 'total' => 1]]);
})->group('RF-IN-08');

it('suma las jornadas de todos los empleados del centro bajo su identificador', function (): void {
    // Tres personas, tres jornadas, una sola serie por centro. Y la clave del
    // resultado es el identificador del centro, no su nombre: la etiqueta que
    // sale a Prometheus no identifica a nadie (regla dura 21).
    $site = WorkforceFixtures::site('Hotel de adopcion', 'Europe/Madrid');

    tramoDeJornada(WorkforceFixtures::employee($site), $site, '2026-03-14', '2026-03-14 06:00:00+00', '2026-03-14 14:00:00+00');
    tramoDeJornada(WorkforceFixtures::employee($site), $site, '2026-03-14', '2026-03-14 06:00:00+00', '2026-03-14 14:00:00+00');
    tramoDeJornada(WorkforceFixtures::employee($site), $site, '2026-03-14', '2026-03-14 14:00:00+00', null, 'open');

    expect(jornadasCompletasEn('2026-03-14'))->toBe([$site => ['complete' => 2, 'total' => 3]]);
})->group('RF-IN-08');

it('un dia sin ninguna jornada no devuelve fila', function (): void {
    // Ni cero ni `NaN`: la ausencia es la unica forma honesta de decir «ese dia
    // el centro estaba cerrado». Cero se leeria como «nadie cerro su jornada»,
    // que es una alarma.
    centroConEmpleado();

    expect(jornadasCompletasEn('2026-03-14'))->toBe([]);
})->group('RF-IN-08');
