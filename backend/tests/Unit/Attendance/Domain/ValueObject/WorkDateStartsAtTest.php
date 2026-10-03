<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\ValueObject\WorkDate;

/*
 * `WorkDate::startsAt()`: el primer instante de la fecha civil en la zona del
 * centro, en UTC. Es la cota «no antes del alta» de RN-20 y RN-22, y con el
 * cambio de hora (RN-09) la medianoche local no dista siempre lo mismo de la UTC.
 */

it('devuelve la medianoche local de la fecha civil en UTC, tambien a ambos lados del cambio de hora', function (string $isoDate, string $expected): void {
    expect(WorkDate::fromIsoDate($isoDate, new DateTimeZone('Europe/Madrid'))->startsAt()->format('Y-m-d H:i:s e'))
        ->toBe($expected);
})->with([
    'invierno' => ['2026-01-01', '2025-12-31 23:00:00 UTC'],
    'verano' => ['2026-08-01', '2026-07-31 22:00:00 UTC'],
    'dia del cambio de primavera' => ['2026-03-29', '2026-03-28 23:00:00 UTC'],
])->group('RN-20', 'RN-22', 'RN-09');
