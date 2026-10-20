<?php

declare(strict_types=1);

use App\Modules\Reporting\Domain\ValueObject\CorrectionAuthor;
use App\Modules\Reporting\Domain\ValueObject\DateRange;
use App\Modules\Reporting\Domain\ValueObject\JournalCorrection;
use App\Modules\Reporting\Domain\ValueObject\JournalShiftEntry;
use App\Modules\Reporting\Domain\ValueObject\JournalWorkDay;
use App\Modules\Reporting\Domain\ValueObject\ShiftMarks;
use App\Modules\Reporting\Domain\ValueObject\WorkDayJournal;
use App\Modules\Reporting\Infrastructure\Export\PersonalRecordRows;

/*
 * Un solo recorrido decide que filas tiene el registro propio en CSV y en PDF
 * (2.3.0, bloque 8): el contrato promete el mismo `X-Kronoqr-Report-Rows` en
 * los dos formatos, y esa promesa no puede depender de dos bucles gemelos.
 */

function filasPropiasTramo(string $uuid, string $entrada, string $salida): JournalShiftEntry
{
    $utc = new DateTimeZone('UTC');

    return new JournalShiftEntry(
        uuid: $uuid,
        version: 1,
        status: 'closed',
        siteId: 1,
        timeZone: 'Europe/Madrid',
        clockedInAt: new DateTimeImmutable($entrada, $utc),
        clockInRecordedAt: new DateTimeImmutable($entrada, $utc),
        clockInSource: 'qr_kiosk',
        clockedOutAt: new DateTimeImmutable($salida, $utc),
        clockOutRecordedAt: new DateTimeImmutable($salida, $utc),
        clockOutSource: 'qr_kiosk',
        durationMinutes: 480,
        recordedAt: new DateTimeImmutable($salida, $utc),
        closedBy: JournalShiftEntry::CLOSED_BY_CLOCK_OUT,
    );
}

function filasPropiasCorreccion(string $uuid): JournalCorrection
{
    $instante = new DateTimeImmutable('2026-03-20T09:00:00Z', new DateTimeZone('UTC'));

    return new JournalCorrection(
        shiftEntryUuid: $uuid,
        action: 'voided',
        performedAt: $instante,
        performedBy: new CorrectionAuthor('0199f0c2-0000-7000-8000-0000000000aa', 'Cuenta de RRHH'),
        reasonCode: 'ERROR_DE_ESCANEO_DUPLICADO',
        reasonText: null,
        before: new ShiftMarks(1, $instante, $instante, 0),
        after: null,
    );
}

function filasPropiasRegistro(): WorkDayJournal
{
    $madrid = 'Europe/Madrid';

    return new WorkDayJournal(
        employeeUuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
        timeZone: $madrid,
        range: DateRange::between('2026-03-01', '2026-03-31'),
        days: [
            new JournalWorkDay('2026-03-14', $madrid, null, [filasPropiasTramo('t1', '2026-03-14T05:00:00Z', '2026-03-14T13:00:00Z')], []),
            // Todos sus tramos anulados: la jornada no desaparece (regla dura 5).
            new JournalWorkDay('2026-03-15', $madrid, null, [], [filasPropiasCorreccion('t2')]),
            new JournalWorkDay('2026-03-16', $madrid, null, [filasPropiasTramo('t3', '2026-03-16T05:00:00Z', '2026-03-16T13:00:00Z')], [filasPropiasCorreccion('t3')]),
        ],
    );
}

it('recorre tramos, jornadas sin tramos y correcciones en el orden del registro', function (): void {
    $tipos = PersonalRecordRows::map(
        filasPropiasRegistro(),
        static fn (JournalWorkDay $day, JournalShiftEntry $entry): string => 'tramo '.$day->workDate,
        static fn (JournalWorkDay $day): string => 'sin tramos '.$day->workDate,
        static fn (JournalWorkDay $day, JournalCorrection $correction): string => 'correccion '.$day->workDate,
    );

    expect($tipos)->toBe([
        'tramo 2026-03-14',
        'sin tramos 2026-03-15',
        'correccion 2026-03-15',
        'tramo 2026-03-16',
        'correccion 2026-03-16',
    ]);
})->group('RF-ID-05', 'RN-13');

it('cuenta las mismas filas que compone, y cero en un periodo vacio', function (): void {
    expect(PersonalRecordRows::count(filasPropiasRegistro()))->toBe(5)
        ->and(PersonalRecordRows::count(new WorkDayJournal(
            employeeUuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
            timeZone: 'Europe/Madrid',
            range: DateRange::between('2026-02-01', '2026-02-28'),
            days: [],
        )))->toBe(0);
})->group('RF-ID-05', 'RL-05');
