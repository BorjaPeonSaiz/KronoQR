<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use App\Modules\Reporting\Application\Port\AdoptionMetrics;
use DateTimeImmutable;
use Tests\Support\Attendance\RecordingCorrectionMetrics;

/**
 * Doble de `workdays_complete_ratio{site}` que se puede leer (doc 02 §8.2,
 * RF-IN-08).
 *
 * El adaptador real escribe un fichero `.prom` en el directorio del colector
 * *textfile*; con este doble la afirmacion es sobre **que jornada se midio y
 * que recuentos se publicaron**, que es lo que decide el caso de uso, sin
 * depender de un disco escribible.
 *
 * Mismo patron que {@see RecordingCorrectionMetrics}.
 */
final class RecordingAdoptionMetrics implements AdoptionMetrics
{
    /** @var list<array{bySite: array<int, array{complete: int, total: int}>, workDate: string, at: DateTimeImmutable}> */
    public array $published = [];

    public function publish(array $bySite, string $workDate, DateTimeImmutable $at): void
    {
        $this->published[] = ['bySite' => $bySite, 'workDate' => $workDate, 'at' => $at];
    }
}
