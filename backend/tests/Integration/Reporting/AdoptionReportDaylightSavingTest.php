<?php

declare(strict_types=1);

use App\Modules\Reporting\Application\Port\AdoptionFactsReader;
use App\Modules\Reporting\Application\Port\WorkDayCompletionReader;
use App\Modules\Reporting\Domain\ValueObject\AdoptionReportQuery;
use App\Modules\Reporting\Domain\ValueObject\DateRange;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Reporting\AdoptionFixtures;
use Tests\Support\Reporting\PeriodReportFixtures;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **El turno de noche y el cambio de hora no distorsionan el cuadro** (RF-IN-08,
 * RN-05, RN-09, tarea 3.13).
 *
 * ## Por que integracion y no unidad
 *
 * Porque lo que se prueba **es la consulta**. El cuadro tiene dos clases de fuente
 * y cada una resuelve la fecha civil de una forma distinta, y las dos tienen que
 * dar el mismo dia:
 *
 *   - Las **jornadas** se filtran por `work_date`, que YA es una fecha civil con
 *     RN-05 aplicada: un turno de 22:00 a 06:00 pertenece entero al dia en que
 *     empezo (ADR-006, regla dura 4). Ahi no hay conversion de zona ninguna, y esa
 *     ausencia es la garantia.
 *   - Los **escaneos, las correcciones y las incidencias** solo tienen marcas de
 *     tiempo `TIMESTAMPTZ`, asi que hay que decidir en que dia civil cae un fichaje
 *     de las 23:40 — y eso lo decide la zona del CENTRO con `AT TIME ZONE` (reglas
 *     duras 3 y 4). En UTC, ese fichaje seria del dia siguiente y el cuadro de
 *     marzo dejaria fuera la ultima noche del mes.
 *
 * Un doble del puerto daria las dos cosas por buenas sin haberlas ejecutado nunca.
 *
 * ## El fin de semana que se prueba
 *
 * El 29 de marzo de 2026, cuando `Europe/Madrid` pasa de UTC+1 a UTC+2 y el domingo
 * tiene **23 horas**. Es el dia en el que un `date_trunc` en UTC se desplaza una
 * hora y empieza a atribuir fichajes al dia equivocado.
 */

uses(RefreshDatabase::class);

/**
 * Un hotel con un turno de noche que cruza el cambio de hora.
 *
 * @return array{site: int, employee: string, device: int}
 */
function hotelConCambioDeHora(): array
{
    $site = WorkforceFixtures::site('Hotel nocturno', 'Europe/Madrid');
    $employee = WorkforceFixtures::employee($site, WorkforceFixtures::department($site, 'Noche'));

    return ['site' => $site, 'employee' => $employee, 'device' => AttendanceFixtures::device($site)['id']];
}

it('atribuye el turno de noche entero a la jornada en la que empezo', function (): void {
    /*
     * 22:00 del 28 -> 06:00 del 29, con el cambio de hora en medio: ese domingo
     * son las 02:00 y el reloj salta a las 03:00, asi que el turno dura **siete**
     * horas de reloj y ocho de calendario. Da igual para el cuadro: lo que mide es
     * si la jornada quedo COMPLETA, y quedo.
     *
     * El 28 la jornada existe y esta completa; el 29 **no hay ninguna jornada**, y
     * eso es lo correcto (regla dura 4). Un cuadro que partiera el turno a
     * medianoche contaria dos jornadas —una de ellas con la entrada sin salida— y
     * enseñaria un 50 % de registro completo en un turno que se ficho perfecto.
     */
    $context = hotelConCambioDeHora();

    PeriodReportFixtures::workDay(
        $context['site'],
        $context['employee'],
        '2026-03-28',
        '2026-03-28 22:00',
        '2026-03-29 06:00',
    );

    $completion = app(WorkDayCompletionReader::class)->completionBetween('2026-03-28', '2026-03-29');

    expect($completion[$context['site']])->toBe(['complete' => 1, 'total' => 1]);
})->group('RF-IN-08', 'RN-05', 'RN-09');

it('atribuye un fichaje de la ultima noche del mes al dia civil del centro, no al de UTC', function (): void {
    /*
     * Un fichaje a las 23:40 del 31 de marzo en Madrid son las **21:40 UTC del
     * 31**… pero uno a las 00:30 del 1 de abril son las 22:30 UTC del **31**. En
     * UTC, ese segundo fichaje seria de marzo; en la zona del centro es de abril,
     * y es de abril de verdad: es la hora que vivio quien paso la tarjeta.
     *
     * Marzo tiene que contar el primero y no el segundo.
     */
    $context = hotelConCambioDeHora();
    $employeeId = AttendanceFixtures::employeeIdOf($context['employee']);

    AdoptionFixtures::scan($context['device'], $employeeId, '2026-03-31 23:40:00+02');
    AdoptionFixtures::scan($context['device'], $employeeId, '2026-04-01 00:30:00+02');

    $facts = app(AdoptionFactsReader::class)->factsFor(
        AdoptionReportQuery::of(DateRange::between('2026-03-01', '2026-03-31')),
        'Europe/Madrid',
    );

    expect($facts->current->attendedScans)->toBe(1)
        ->and($facts->current->acceptedScansFrom('qr_kiosk'))->toBe(1);
})->group('RF-IN-08', 'RN-05', 'RN-09');

it('cuenta la misma jornada que el lector de la metrica de Grafana sobre el mismo dia', function (): void {
    /*
     * COHERENCIA CON EL CUADRO DE MANDO (decision 8 de la ficha 3.13).
     *
     * El cuadro del panel y `workdays_complete_ratio{site}` comparten **el mismo
     * lector**, no dos consultas parecidas: con dos, bastaria añadir un estado a
     * `shift_entries` y actualizar solo una para que Grafana y el panel dieran dos
     * porcentajes de la misma semana — y el cuadro perderia justo la funcion que
     * tiene.
     *
     * Lo que NO comparten es la ventana: Grafana mira el dia anterior y el cuadro
     * un periodo civil cerrado. Por eso la comparacion se hace sobre **el mismo
     * dia**, que es donde las dos tienen que coincidir exactamente.
     *
     * LO QUE SE AFIRMA ES EL RECUENTO, no que los dos metodos devuelvan lo mismo:
     * desde la 3.13 `completionOn()` delega en `completionBetween()`, asi que
     * compararlos entre si seria una tautologia. Lo que protege a Grafana es que
     * **una jornada con un tramo abierto de dos no cuenta como completa**, que es la
     * definicion que las dos publican.
     */
    $context = hotelConCambioDeHora();
    $other = WorkforceFixtures::employee($context['site'], null);

    PeriodReportFixtures::workDay($context['site'], $context['employee'], '2026-03-10', '2026-03-10 06:00', '2026-03-10 14:00');
    PeriodReportFixtures::workDay($context['site'], $other, '2026-03-10', '2026-03-10 06:00', null);

    expect(app(WorkDayCompletionReader::class)->completionOn('2026-03-10')[$context['site']])
        ->toBe(['complete' => 1, 'total' => 2]);
})->group('RF-IN-08');

it('no cuenta los tramos anulados ni las versiones sustituidas por una correccion', function (): void {
    // Regla dura 5: nada se borra. Contar la version anterior de un tramo haria
    // que RECTIFICAR BIEN un dia lo empeorase en el indicador, que es exactamente
    // al reves de lo que el cuadro quiere premiar.
    $context = hotelConCambioDeHora();

    PeriodReportFixtures::workDay($context['site'], $context['employee'], '2026-03-11', '2026-03-11 06:00', '2026-03-11 14:00');

    DB::table('shift_entries')
        ->where('work_date', '2026-03-11')
        ->update(['status' => 'superseded']);

    $completion = app(WorkDayCompletionReader::class)->completionBetween('2026-03-11', '2026-03-11');

    expect($completion)->toBe([]);
})->group('RF-IN-08');
