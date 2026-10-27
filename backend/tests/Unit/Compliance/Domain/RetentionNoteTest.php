<?php

declare(strict_types=1);

use App\Modules\Compliance\Domain\ValueObject\RetentionNote;
use App\Modules\Compliance\Domain\ValueObject\RetentionNoteKind;

/*
 * Una observacion del informe de purga guarda que paso y con que cifras, no la
 * frase (RF-PR-03): la frase depende del idioma de la instalacion. Sin datos
 * personales (regla dura 21): un ano o un recuento.
 */

it('anota la particion vacia con su ano', function (): void {
    $note = RetentionNote::emptyAuditPartition(2021);

    expect($note->kind)->toBe(RetentionNoteKind::EmptyAuditPartition)
        ->and($note->parameters)->toBe(['year' => 2021]);
})->group('RF-PR-03');

it('anota las fotos huerfanas con cuantas son', function (): void {
    $note = RetentionNote::orphanedPersonnelPhotos(3);

    expect($note->kind)->toBe(RetentionNoteKind::OrphanedPersonnelPhotos)
        ->and($note->parameters)->toBe(['count' => 3]);
})->group('RF-PR-03');

it('cada clase de observacion tiene una clave distinta', function (): void {
    expect(array_map(static fn (RetentionNoteKind $kind): string => $kind->value, RetentionNoteKind::cases()))
        ->toBe(['empty_audit_partition', 'orphaned_personnel_photos']);
})->group('RF-PR-03');
