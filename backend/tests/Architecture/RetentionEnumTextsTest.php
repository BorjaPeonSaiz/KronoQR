<?php

declare(strict_types=1);

use App\Modules\Compliance\Domain\ValueObject\RetentionMode;
use App\Modules\Compliance\Domain\ValueObject\RetentionNoteKind;
use App\Modules\Compliance\Domain\ValueObject\RetentionScope;

/*
 * **Cada ambito y cada modo de la purga tiene su texto en los dos idiomas**
 * (RF-PR-03, RL-11, RF-KI-05).
 *
 * El informe de retencion y `compliance:apply-retention` nombran el ambito, su
 * unidad, el modo y las observaciones leyendo
 * `retention.report.scopes|units|modes|observations.<valor>`. Los
 * enums del dominio no llevan texto. Un ambito nuevo sin su clave no falla:
 * Laravel imprime la propia clave en un documento que se archiva como
 * constancia de la purga. `LangParityTest` compara los dos idiomas entre si,
 * pero no sabe que claves deberia haber; esta prueba lo deduce de los enums.
 *
 * Se leen los ficheros y no `__()` por el mismo motivo que en `LangParityTest`:
 * el idioma de respaldo devolveria el texto espanol para la clave que falta.
 */
it('tiene en lang/es y lang/en el nombre, la unidad, el modo y las observaciones de cada caso de los enums de retencion', function (string $locale): void {
    /** @var mixed $texts */
    $texts = require \dirname(__DIR__, 2).'/lang/'.$locale.'/retention.php';

    expect($texts)->toBeArray();

    /** @var array{report: array{scopes: array<string, mixed>, units: array<string, mixed>, modes: array<string, mixed>, observations: array<string, mixed>}} $texts */
    $report = $texts['report'];

    foreach (RetentionScope::cases() as $scope) {
        foreach (['scopes', 'units'] as $family) {
            $text = $report[$family][$scope->value] ?? null;

            expect(\is_string($text) && trim($text) !== '')
                ->toBeTrue('Falta lang/'.$locale.'/retention.php report.'.$family.'.'.$scope->value);
        }
    }

    foreach (RetentionMode::cases() as $mode) {
        $text = $report['modes'][$mode->value] ?? null;

        expect(\is_string($text) && trim($text) !== '')
            ->toBeTrue('Falta lang/'.$locale.'/retention.php report.modes.'.$mode->value);
    }

    foreach (RetentionNoteKind::cases() as $kind) {
        $text = $report['observations'][$kind->value] ?? null;

        expect(\is_string($text) && trim($text) !== '')
            ->toBeTrue('Falta lang/'.$locale.'/retention.php report.observations.'.$kind->value);
    }
})->with(['es', 'en'])->group('RF-PR-03', 'RL-11', 'RF-KI-05');
