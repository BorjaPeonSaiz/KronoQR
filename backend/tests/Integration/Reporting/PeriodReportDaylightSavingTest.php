<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Reporting\PeriodReportFixtures;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * La semana del cambio de hora en el informe por periodo (**RN-09**, tarea 2.8).
 *
 * ## Por que esto no se puede dar por supuesto
 *
 * En 2026 los relojes de Madrid saltan el **29 de marzo** —a las 02:00 se pasa a
 * las 03:00, y ese dia tiene 23 horas— y vuelven el **25 de octubre**, con un dia
 * de 25 horas. Un turno que cruza cualquiera de los dos saltos dura lo que dura
 * **en el tiempo real transcurrido**, no lo que diga la resta de las horas de
 * reloj: en marzo, de 00:00 a 08:00 son siete horas de trabajo, y en octubre son
 * nueve.
 *
 * La aritmetica correcta la hace el nucleo sobre instantes UTC (RN-09) y la
 * guarda `daily_totals`. Lo que estas pruebas defienden es que el informe **no
 * la deshace**: no vuelve a restar horas de reloj, no aplica ningun
 * `AT TIME ZONE` y agrupa por `work_date`, que es una fecha civil ya calculada.
 * Si alguien reescribiera la consulta agrupando por `date_trunc('day',
 * clocked_in_at)`, estos dos casos son los que lo dirian.
 *
 * ## Van en Integration y no en Feature
 *
 * Lo que se ejercita es el comportamiento de PostgreSQL con fechas y con el
 * calendario, no la forma de la respuesta: la suite de integracion es la que
 * corre contra PostgreSQL de verdad, que es donde `generate_series` y
 * `date_trunc('week', ...)` existen.
 */

uses(RefreshDatabase::class);

/**
 * @return array{token: string, site: int, employee: string}
 */
function contextoDeCambioDeHora(): array
{
    $site = WorkforceFixtures::site('Hotel con cambio de hora');

    return [
        'token' => ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)),
        'site' => $site,
        'employee' => WorkforceFixtures::employee($site),
    ];
}

it('cuenta siete horas en el turno que cruza el salto de primavera', function (): void {
    // 29 de marzo de 2026: a las 02:00 los relojes marcan las 03:00. Un turno de
    // 00:00 a 08:00 en hora de reloj son **siete** horas reales, no ocho.
    $contexto = contextoDeCambioDeHora();

    PeriodReportFixtures::workDay(
        $contexto['site'],
        $contexto['employee'],
        '2026-03-29',
        '2026-03-29 00:00',
        '2026-03-29 08:00',
    );

    Api::as($contexto['token'])
        ->get('/api/v1/reports/period', ['from' => '2026-03-29', 'to' => '2026-03-29', 'granularity' => 'day'])
        ->assertStatus(200)
        ->assertJsonPath('data.0.worked_minutes', 420)
        ->assertJsonPath('data.0.worked', '07:00');
})->group('RN-09');

it('cuenta nueve horas en el turno que cruza el salto de otono', function (): void {
    // 25 de octubre de 2026: las 03:00 se repiten y el dia tiene 25 horas. El
    // mismo turno de reloj dura **nueve** horas reales.
    //
    // Las dos marcas se dan en hora de reloj de Madrid y ninguna de las dos cae
    // en la hora ambigua —00:00 y 08:00 existen una sola vez ese dia—, asi que la
    // conversion no necesita desempate.
    $contexto = contextoDeCambioDeHora();

    PeriodReportFixtures::workDay(
        $contexto['site'],
        $contexto['employee'],
        '2026-10-25',
        '2026-10-25 00:00',
        '2026-10-25 08:00',
    );

    Api::as($contexto['token'])
        ->get('/api/v1/reports/period', ['from' => '2026-10-25', 'to' => '2026-10-25', 'granularity' => 'day'])
        ->assertStatus(200)
        ->assertJsonPath('data.0.worked_minutes', 540)
        ->assertJsonPath('data.0.worked', '09:00');
})->group('RN-09');

it('la semana del cambio de hora suma las horas reales, no las de reloj', function (): void {
    // La comprobacion que pide el plan: la semana que contiene el salto refleja
    // las horas reales. Cinco turnos identicos en hora de reloj —00:00 a 08:00—
    // de lunes a viernes, y el viernes es el 27; el salto cae el domingo 29, que
    // tambien se trabaja.
    //
    //   5 x 8 h (dias normales) + 7 h (el domingo del salto) = 47 h = 2820 min.
    //
    // Si la consulta restara horas de reloj, saldrian 48 h. Si ademas agrupara
    // por el instante UTC, el domingo caeria en otra semana.
    $contexto = contextoDeCambioDeHora();

    foreach (['2026-03-23', '2026-03-24', '2026-03-25', '2026-03-26', '2026-03-27'] as $dia) {
        PeriodReportFixtures::workDay($contexto['site'], $contexto['employee'], $dia, $dia.' 00:00', $dia.' 08:00');
    }

    PeriodReportFixtures::workDay($contexto['site'], $contexto['employee'], '2026-03-29', '2026-03-29 00:00', '2026-03-29 08:00');

    Api::as($contexto['token'])
        ->get('/api/v1/reports/period', ['from' => '2026-03-23', 'to' => '2026-03-29', 'granularity' => 'week'])
        ->assertStatus(200)
        // Una sola semana ISO: del lunes 23 al domingo 29.
        ->assertJsonPath('meta.row_count', 1)
        ->assertJsonPath('data.0.period.from', '2026-03-23')
        ->assertJsonPath('data.0.period.to', '2026-03-29')
        ->assertJsonPath('data.0.days_in_period', 7)
        ->assertJsonPath('data.0.worked_minutes', 2820)
        ->assertJsonPath('data.0.worked', '47:00');
})->group('RN-09');
