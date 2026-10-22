<?php

declare(strict_types=1);

use App\Modules\Reporting\Domain\ValueObject\CorrectionAuthor;
use App\Modules\Reporting\Domain\ValueObject\DateRange;
use App\Modules\Reporting\Domain\ValueObject\JournalCorrection;
use App\Modules\Reporting\Domain\ValueObject\JournalWorkDay;
use App\Modules\Reporting\Domain\ValueObject\ShiftMarks;
use App\Modules\Reporting\Domain\ValueObject\WorkDayJournal;
use App\Modules\Reporting\Infrastructure\Export\PersonalRecordDigest;

/*
 * La forma canonica de la huella del registro propio no es ambigua (R7-RV-03,
 * RF-ID-05). El motivo de una correccion es texto libre y el nombre de su autor
 * tambien lo escribe una persona: sin escapar los separadores, dos registros
 * distintos podian dar el mismo texto canonico y la misma huella.
 */

/**
 * Un registro con una sola correccion, con autor, codigo y motivo a elegir.
 *
 * @param  list<JournalWorkDay>  $extraDays
 */
function huellaRegistro(string $author, string $code, ?string $text, array $extraDays = []): WorkDayJournal
{
    $utc = new DateTimeZone('UTC');
    $at = new DateTimeImmutable('2026-03-16T09:00:00Z', $utc);

    $correction = new JournalCorrection(
        shiftEntryUuid: '0199f0c2-0000-7000-8000-000000000002',
        action: 'edit',
        performedAt: $at,
        performedBy: new CorrectionAuthor('0199f0c2-0000-7000-8000-0000000000aa', $author),
        reasonCode: $code,
        reasonText: $text,
        before: new ShiftMarks(1, $at, null, 0),
        after: new ShiftMarks(2, $at, $at, 0),
    );

    return new WorkDayJournal(
        employeeUuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
        timeZone: 'Europe/Madrid',
        range: DateRange::between('2026-03-01', '2026-03-31'),
        days: [new JournalWorkDay('2026-03-15', 'Europe/Madrid', null, [], [$correction]), ...$extraDays],
    );
}

it('no confunde un separador dentro de un campo con el limite entre dos campos', function (): void {
    // Con la forma 1 los dos daban `Ana<US>X<US>Y<US>` y la misma huella.
    $a = PersonalRecordDigest::of(huellaRegistro("Ana\x1FX", 'Y', ''));
    $b = PersonalRecordDigest::of(huellaRegistro('Ana', 'X', "Y\x1F"));

    expect($a->canonicalText)->not->toBe($b->canonicalText)
        ->and($a->sha256)->not->toBe($b->sha256);
})->group('RF-ID-05', 'RL-05');

it('no deja que un motivo con un salto de linea se haga pasar por otra jornada', function (): void {
    // El motivo forja la linea `day` que el otro registro tiene de verdad.
    $forjado = PersonalRecordDigest::of(huellaRegistro('Ana', 'X', "t\nday\x1F2026-03-16\x1F0\x1F0"));
    $real = PersonalRecordDigest::of(huellaRegistro('Ana', 'X', 't', [
        new JournalWorkDay('2026-03-16', 'Europe/Madrid', null, [], []),
    ]));

    expect($forjado->canonicalText)->not->toBe($real->canonicalText)
        ->and($forjado->sha256)->not->toBe($real->sha256)
        // Un registro de una jornada sigue teniendo las lineas que le tocan.
        ->and(substr_count($forjado->canonicalText, "\n"))->toBe(4);
})->group('RF-ID-05', 'RL-05');

it('escribe los caracteres de control en texto y duplica la barra invertida', function (): void {
    $huella = PersonalRecordDigest::of(huellaRegistro('Ana', 'X', "a\\b\x1Fc\nd\re"));

    expect($huella->canonicalText)->toStartWith("kronoqr-personal-record/2\n")
        ->and($huella->canonicalText)->toEndWith('a\\\\b\\x1Fc\\nd\\re')
        // Un `\x1F` literal en el texto del motivo seria el `\x1F` de un campo,
        // que ya no puede ser: tantos separadores como campos menos uno.
        ->and(substr_count(explode("\n", $huella->canonicalText)[4], "\x1F"))->toBe(7)
        ->and($huella->toText())->toBe(hash('sha256', $huella->canonicalText))
        ->and($huella->toText())->toMatch('/^[0-9a-f]{64}$/');
})->group('RF-ID-05', 'RL-05');

it('una barra invertida escrita a mano no se confunde con un escape', function (): void {
    // `\x1F` escrito como texto (cuatro caracteres) y el caracter de control.
    $texto = PersonalRecordDigest::of(huellaRegistro('Ana', 'X', 'a\\x1Fb'));
    $control = PersonalRecordDigest::of(huellaRegistro('Ana', 'X', "a\x1Fb"));

    expect($texto->sha256)->not->toBe($control->sha256);
})->group('RF-ID-05', 'RL-05');
