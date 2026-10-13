<?php

declare(strict_types=1);

use App\Modules\Compliance\Domain\Exception\PurgeReviewRejected;
use App\Modules\Compliance\Domain\ValueObject\PurgeReviewReason;

/*
 * El motivo de dar por revisada una purga no admisible (RL-04, ADR-057
 * enmienda del 10-10-2026 §6). Una revision sin explicacion serviria para tapar
 * una purga indebida: de 10 a 1000 caracteres, contados como caracteres y no
 * como bytes, sin los espacios de los extremos.
 */

it('admite de diez a mil caracteres', function (int $length): void {
    expect(PurgeReviewReason::fromText(str_repeat('a', $length))->text)->toBe(str_repeat('a', $length));
})->with([10, 1000])->group('RL-04');

it('rechaza nueve caracteres y mil uno, con el limite en el motivo', function (int $length, string $key, array $parameters): void {
    try {
        PurgeReviewReason::fromText(str_repeat('a', $length));
        $rechazo = null;
    } catch (PurgeReviewRejected $exception) {
        $rechazo = $exception;
    }

    expect($rechazo?->translationKey)->toBe($key)
        ->and($rechazo?->parameters)->toBe($parameters);
})->with([
    'nueve' => [9, 'work-record-review.reason_too_short', ['min' => 10]],
    'mil uno' => [1001, 'work-record-review.reason_too_long', ['max' => 1000]],
])->group('RL-04');

it('recorta los espacios de los extremos antes de contar', function (): void {
    expect(PurgeReviewReason::fromText("  Incidente INC-7 investigado  \n")->text)->toBe('Incidente INC-7 investigado')
        ->and(fn (): PurgeReviewReason => PurgeReviewReason::fromText('   corto    '))
        ->toThrow(PurgeReviewRejected::class);
})->group('RL-04');

it('cuenta caracteres multibyte como uno', function (): void {
    // Diez eñes son diez caracteres y veinte bytes: valen. Mil eñes, tambien.
    expect(PurgeReviewReason::fromText(str_repeat('ñ', 10))->text)->toBe(str_repeat('ñ', 10))
        ->and(PurgeReviewReason::fromText(str_repeat('ñ', 1000))->text)->toBe(str_repeat('ñ', 1000))
        ->and(fn (): PurgeReviewReason => PurgeReviewReason::fromText(str_repeat('ñ', 9)))
        ->toThrow(PurgeReviewRejected::class)
        ->and(fn (): PurgeReviewReason => PurgeReviewReason::fromText(str_repeat('ñ', 1001)))
        ->toThrow(PurgeReviewRejected::class);
})->group('RL-04');
