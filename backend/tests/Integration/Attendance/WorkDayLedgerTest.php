<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\WorkDayLedger;
use App\Modules\Attendance\Domain\Model\WorkDay;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **La relectura de una sola jornada** (`WorkDayLedger::workDayOf()`, RF-PR-02,
 * RN-06, ADR-026, tarea 3.6).
 *
 * POR QUE ES UNA PRUEBA DE INTEGRACION. Lo que se comprueba es el predicado de
 * vigencia contra la tabla —que un tramo anulado o sustituido no entra en el
 * agregado— y el mapeo a `WorkDay`. Con un doble en memoria seria el mismo
 * codigo afirmando que sabe filtrar.
 *
 * QUE DEPENDE DE ESTO. Es la lectura con la que la reconciliacion decide, dentro
 * de su transaccion y con la fila bloqueada, si una divergencia era real. Si
 * contara un tramo anulado, la pasada «corregiria» la proyeccion hacia un total
 * que nadie trabajo; si se dejara uno vigente, pondria a cero una jornada que
 * existe. Las dos cosas acaban en la nomina de alguien.
 *
 * LA OTRA MITAD ES QUE COINCIDA CON `workDaysBetween()`: son las dos lecturas
 * que la reconciliacion compara consigo misma —el bloque primero, la jornada
 * despues— y el dia que dijeran cosas distintas, la pasada encontraria
 * divergencias que solo existen en su propia aritmetica.
 */

uses(RefreshDatabase::class);

/**
 * @return array{site: int, employee: string, other: string}
 */
function ledgerScenario(): array
{
    $site = WorkforceFixtures::site('Hotel del ledger', 'Europe/Madrid');
    $department = WorkforceFixtures::department($site, 'Cocina');

    return [
        'site' => $site,
        'employee' => WorkforceFixtures::employee($site, $department),
        'other' => WorkforceFixtures::employee($site, $department),
    ];
}

/**
 * Un tramo escrito directamente en la tabla.
 *
 * Sin pasar por el fichaje a proposito: hacen falta estados —anulado,
 * sustituido— que el camino normal tarda una correccion entera en producir, y lo
 * que se prueba aqui es la lectura, no como se llego a ellos.
 */
function ledgerShiftEntry(
    string $employeeUuid,
    int $siteId,
    string $workDate,
    string $clockedInAt,
    ?string $clockedOutAt = null,
    string $status = 'closed',
): string {
    $uuid = Str::uuid7()->toString();

    DB::table('shift_entries')->insert([
        'uuid' => $uuid,
        'employee_id' => AttendanceFixtures::employeeIdOf($employeeUuid),
        'site_id' => $siteId,
        'work_date' => $workDate,
        'clocked_in_at' => $clockedInAt,
        'clocked_out_at' => $clockedOutAt,
        'duration_minutes' => $clockedOutAt === null
            ? null
            : intdiv(strtotime($clockedOutAt) - strtotime($clockedInAt), 60),
        'status' => $clockedOutAt === null ? 'open' : $status,
        'clock_in_source' => 'qr_kiosk',
        'clock_out_source' => $clockedOutAt === null ? null : 'qr_kiosk',
        'version' => 1,
        'created_at' => $clockedInAt,
        'updated_at' => $clockedInAt,
    ]);

    return $uuid;
}

function ledgerDate(string $isoDate = '2026-03-14'): WorkDate
{
    return WorkDate::fromIsoDate($isoDate, new DateTimeZone('Europe/Madrid'));
}

function attendanceLedger(): WorkDayLedger
{
    return app(WorkDayLedger::class);
}

it('devuelve la jornada de esa persona con sus tramos vigentes', function (): void {
    $scenario = ledgerScenario();

    ledgerShiftEntry($scenario['employee'], $scenario['site'], '2026-03-14', '2026-03-14 06:00:00+00', '2026-03-14 10:00:00+00');
    ledgerShiftEntry($scenario['employee'], $scenario['site'], '2026-03-14', '2026-03-14 11:00:00+00', '2026-03-14 14:00:00+00');

    $workDay = attendanceLedger()->workDayOf($scenario['employee'], ledgerDate());

    expect($workDay)->toBeInstanceOf(WorkDay::class)
        ->and($workDay?->employeeUuid())->toBe($scenario['employee'])
        ->and($workDay?->workDate()->isoDate)->toBe('2026-03-14')
        ->and($workDay?->shiftCount())->toBe(2)
        ->and($workDay?->totalWorked()->minutes)->toBe(420)
        ->and($workDay?->hasOpenEntry())->toBeFalse();
})->group('RF-PR-02', 'RN-06');

it('no cuenta los tramos anulados ni los sustituidos', function (): void {
    // ADR-026: el historico se conserva —nada se borra, regla dura 5— y **no**
    // forma parte del agregado. Si entrara, este dia valdria 960 minutos en vez
    // de 480.
    $scenario = ledgerScenario();

    ledgerShiftEntry($scenario['employee'], $scenario['site'], '2026-03-14', '2026-03-14 06:00:00+00', '2026-03-14 14:00:00+00');
    ledgerShiftEntry($scenario['employee'], $scenario['site'], '2026-03-14', '2026-03-14 15:00:00+00', '2026-03-14 18:00:00+00', 'voided');
    ledgerShiftEntry($scenario['employee'], $scenario['site'], '2026-03-14', '2026-03-14 19:00:00+00', '2026-03-14 22:00:00+00', 'superseded');

    $workDay = attendanceLedger()->workDayOf($scenario['employee'], ledgerDate());

    expect(DB::table('shift_entries')->count())->toBe(3)
        ->and($workDay?->shiftCount())->toBe(1)
        ->and($workDay?->totalWorked()->minutes)->toBe(480);
})->group('RF-PR-02', 'RN-06', 'RN-13');

it('devuelve nada cuando la jornada no existe, el dia no es ese o la persona tampoco', function (): void {
    $scenario = ledgerScenario();

    ledgerShiftEntry($scenario['employee'], $scenario['site'], '2026-03-14', '2026-03-14 06:00:00+00', '2026-03-14 14:00:00+00');

    // Otro dia de la misma persona, otra persona del mismo dia, y un UUID que no
    // existe —lo que queda tras una purga por retencion (RL-02)—. Los tres son
    // `null` y no una jornada vacia: la diferencia entre «no hay nada» y «hay un
    // dia a cero» es justo lo que la reconciliacion necesita distinguir para no
    // inventarse una fila.
    expect(attendanceLedger()->workDayOf($scenario['employee'], ledgerDate('2026-03-15')))->toBeNull()
        ->and(attendanceLedger()->workDayOf($scenario['other'], ledgerDate()))->toBeNull()
        ->and(attendanceLedger()->workDayOf('0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90', ledgerDate()))->toBeNull();
})->group('RF-PR-02');

it('dice exactamente lo mismo que la lectura en bloque de ese dia', function (): void {
    // La garantia que hace segura la relectura de la correccion: las dos mitades
    // de la comparacion tienen que construir el agregado igual.
    $scenario = ledgerScenario();

    ledgerShiftEntry($scenario['employee'], $scenario['site'], '2026-03-14', '2026-03-14 06:00:00+00', '2026-03-14 10:00:00+00');
    // Turno abierto en la misma jornada: es el caso en que las dos lecturas mas
    // facilmente podrian discrepar, porque `has_open_shift` no sale de una suma.
    ledgerShiftEntry($scenario['employee'], $scenario['site'], '2026-03-14', '2026-03-14 21:00:00+00');
    ledgerShiftEntry($scenario['other'], $scenario['site'], '2026-03-14', '2026-03-14 07:00:00+00', '2026-03-14 15:00:00+00');

    $inBulk = [];

    foreach (attendanceLedger()->workDaysBetween(ledgerDate(), ledgerDate()) as $workDay) {
        $inBulk[$workDay->employeeUuid()] = $workDay;
    }

    foreach ([$scenario['employee'], $scenario['other']] as $employeeUuid) {
        $single = attendanceLedger()->workDayOf($employeeUuid, ledgerDate());
        $fromBulk = $inBulk[$employeeUuid] ?? null;

        expect($single)->toBeInstanceOf(WorkDay::class)
            ->and($fromBulk)->toBeInstanceOf(WorkDay::class)
            ->and($single?->shiftCount())->toBe($fromBulk?->shiftCount())
            ->and($single?->totalWorked()->minutes)->toBe($fromBulk?->totalWorked()->minutes)
            ->and($single?->hasOpenEntry())->toBe($fromBulk?->hasOpenEntry())
            ->and($single?->hasAnomaly())->toBe($fromBulk?->hasAnomaly())
            ->and($single?->firstClockInAt()?->format('U.u'))->toBe($fromBulk?->firstClockInAt()?->format('U.u'))
            ->and($single?->lastClockOutAt()?->format('U.u'))->toBe($fromBulk?->lastClockOutAt()?->format('U.u'));
    }
})->group('RF-PR-02', 'RN-06');
