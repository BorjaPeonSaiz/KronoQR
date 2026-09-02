<?php

declare(strict_types=1);

use App\Modules\Compliance\Application\Port\RetentionPolicyProvider;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\CompliancePolicyProvider;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Time\FixedClock;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **Un calendario de festivos corrupto no puede apagar RN-10 ni RN-11**
 * (RF-PD-07, RF-PR-01, regla dura 19).
 *
 * ## El fallo real que esto fija
 *
 * Lo encontro la revision de la 5.2 reproduciendolo: una fila con
 * `holiday_calendar = '["navidad"]'` —escrita a mano, o por una version
 * posterior, o por una restauracion a medias— pasaba el filtro del adaptador,
 * que solo comprobaba que fueran cadenas, y hacia estallar al objeto de valor.
 * `DetectAttendanceAnomalies::handle()` resuelve la politica **una sola vez y
 * antes del bucle, sin `try`**, asi que la excepcion se llevaba por delante la
 * pasada nocturna entera: ni una incidencia de descanso insuficiente, ni una de
 * jornada larga, en toda la instalacion. Y `compliance:apply-retention` caia por
 * el mismo sitio.
 *
 * Un dato que **hoy no lee ninguna regla** apagaba dos que si. Eso es lo que se
 * comprueba aqui, y por eso la prueba ejecuta la pasada completa en vez de
 * construir la politica: lo que fallaba no era el objeto de valor, era el camino.
 *
 * POR QUE NO PODIA SER UNITARIA: el `jsonb` corrupto solo existe en PostgreSQL, y
 * lo que se afirma es que el camino que va de la columna al caso de uso lo
 * absorbe.
 */

uses(RefreshDatabase::class);

/** Deja el perfil por defecto con un calendario que ninguna fecha valida. */
function corruptTheHolidayCalendar(string $json): int
{
    /** @var int $profileId */
    $profileId = DB::table('compliance_profiles')->where('is_default', true)->value('id');

    DB::table('compliance_profiles')->where('id', $profileId)->update(['holiday_calendar' => $json]);

    return $profileId;
}

it('resuelve la politica descartando lo que no es una fecha, en vez de estallar', function (string $json): void {
    WorkforceFixtures::site();
    corruptTheHolidayCalendar($json);

    /** @var int $siteId */
    $siteId = DB::table('sites')->value('id');
    $policy = app(CompliancePolicyProvider::class)->forSite($siteId);

    // El calendario se pierde —no lo lee nadie todavia— y los cuatro umbrales
    // legales llegan intactos, que es lo unico que la revision diaria necesita.
    expect($policy->holidayCalendar)->toBe([])
        ->and($policy->minimumRestMinutes)->toBe(720)
        ->and($policy->maximumDailyMinutes)->toBe(540)
        ->and($policy->retentionYears)->toBe(4);
})->with([
    'texto que no es una fecha' => ['["navidad"]'],
    'fecha con formato espanol' => ['["25/12/2026"]'],
    'dia que no existe' => ['["2026-02-30"]'],
    'numeros en vez de cadenas' => ['[20261225]'],
    'objeto en vez de lista' => ['{"dia":"2026-12-25"}'],
    'JSON que no es ni una cosa ni otra' => ['"navidad"'],
])->group('RF-PD-07', 'RF-PR-01');

it('conserva los festivos validos y descarta solo los imposibles', function (): void {
    WorkforceFixtures::site();
    corruptTheHolidayCalendar('["2026-12-25","navidad","2026-01-01"]');

    /** @var int $siteId */
    $siteId = DB::table('sites')->value('id');

    // Lo que se puede salvar se salva, y ordenado: descartar el calendario
    // entero por una entrada mala seria perder informacion que si es buena.
    expect(app(CompliancePolicyProvider::class)->forSite($siteId)->holidayCalendar)
        ->toBe(['2026-01-01', '2026-12-25']);
})->group('RF-PD-07');

/**
 * Un tramo escrito directamente en la tabla: la deteccion necesita estados que
 * el fichaje tardaria horas en producir.
 */
function corruptCalendarShift(string $employeeUuid, int $siteId, string $workDate, string $in, ?string $out): void
{
    DB::table('shift_entries')->insert([
        'uuid' => Str::uuid7()->toString(),
        'employee_id' => DB::table('employees')->where('uuid', $employeeUuid)->value('id'),
        'site_id' => $siteId,
        'work_date' => $workDate,
        'clocked_in_at' => $in,
        'clocked_out_at' => $out,
        'duration_minutes' => $out === null ? null : intdiv(strtotime($out) - strtotime($in), 60),
        'status' => $out === null ? 'open' : 'closed',
        'clock_in_source' => 'qr_kiosk',
        'clock_out_source' => $out === null ? null : 'qr_kiosk',
        'version' => 1,
        'created_at' => $in,
        'updated_at' => $in,
    ]);
}

it('abre las incidencias de RN-10 y RN-11 aunque el calendario este corrupto', function (): void {
    // **La prueba que faltaba.** Antes de la correccion, esta pasada moria en la
    // primera linea con un `InvalidArgumentException` y no abria ninguna
    // incidencia: un festivo mal escrito dejaba a toda la instalacion sin
    // revision diaria, y nadie se enteraba hasta que alguien echaba de menos una
    // alerta.
    $site = WorkforceFixtures::site('Hotel del calendario roto', 'Europe/Madrid');
    $department = WorkforceFixtures::department($site, 'Cocina');
    $employee = WorkforceFixtures::employee($site, $department);

    corruptTheHolidayCalendar('["navidad","2026-02-30"]');

    // Sale el 12 a las 22:00 y entra el 13 a las 09:00: once horas de descanso,
    // por debajo de las doce del perfil (RN-10). Y el 13 acumula 9 h 30 (RN-11).
    corruptCalendarShift($employee, $site, '2026-03-12', '2026-03-12 14:00:00+00', '2026-03-12 21:00:00+00');
    corruptCalendarShift($employee, $site, '2026-03-13', '2026-03-13 08:00:00+00', '2026-03-13 13:00:00+00');
    corruptCalendarShift($employee, $site, '2026-03-13', '2026-03-13 14:00:00+00', '2026-03-13 18:30:00+00');

    app()->instance(Clock::class, FixedClock::at('2026-03-14 19:00:00'));

    expect(Artisan::call('attendance:detect-incidents'))->toBe(0);

    $abiertas = DB::table('incidents')->pluck('type')->all();

    expect($abiertas)->toContain('insufficient_rest')
        ->and($abiertas)->toContain('long_shift');
})->group('RF-PD-07', 'RF-PR-01', 'RN-10', 'RN-11');

it('deja la purga por retencion en pie con el calendario corrupto', function (): void {
    // El otro camino que caia por lo mismo: el plazo sale del perfil, y resolver
    // el perfil estallaba. Una purga que no se puede ni simular es una purga que
    // no se hace, y el plazo legal sigue corriendo.
    WorkforceFixtures::site();
    corruptTheHolidayCalendar('["navidad"]');

    expect(app(RetentionPolicyProvider::class)->forInstallation()->legalRecordYears)->toBe(4);
})->group('RF-PD-07', 'RL-02');

it('deja constancia del descarte en el log, sin nombres ni fechas', function (): void {
    // El descarte no puede ser silencioso: es el unico rastro de que la fila esta
    // mal, y quien lo lea tiene que poder ir a arreglarla. Al log solo viaja el
    // identificador del perfil y cuantas entradas se cayeron: este log sale de la
    // instalacion dentro del paquete de diagnostico (ADR-020, regla dura 21).
    WorkforceFixtures::site();
    $profileId = corruptTheHolidayCalendar('["navidad","navidad"]');

    $avisos = [];

    Log::listen(static function (MessageLogged $message) use (&$avisos): void {
        if ($message->message === 'product.compliance_profile_calendar_discarded') {
            $avisos[] = $message->context;
        }
    });

    /** @var int $siteId */
    $siteId = DB::table('sites')->value('id');

    app(CompliancePolicyProvider::class)->forSite($siteId);

    expect($avisos)->toHaveCount(1)
        ->and($avisos[0]['profile_id'])->toBe($profileId)
        ->and($avisos[0]['rejected'])->toBe(2)
        // Ni una fecha ni un nombre: solo cuantas se cayeron.
        ->and(json_encode($avisos[0], JSON_THROW_ON_ERROR))->not->toContain('navidad');
})->group('RF-PD-07', 'RS-05');
