<?php

declare(strict_types=1);

use App\Modules\Reporting\Domain\ValueObject\ReportExportProgress;
use App\Modules\Reporting\Domain\ValueObject\ReportExportStage;

/*
 * El avance de una exportacion en curso (**V5-PA-4**): el porcentaje se mide o
 * no se da, y nunca llega a 100 antes de que la fila este `completed`.
 */

const REPORT_EXPORT_PROGRESS_AT = '2026-03-08T09:00:05+00:00';

function avanceEn(): DateTimeImmutable
{
    return new DateTimeImmutable(REPORT_EXPORT_PROGRESS_AT);
}

it('una fase sin total no tiene porcentaje: la barra es indeterminada', function (): void {
    $avance = ReportExportProgress::at(ReportExportStage::Reading, avanceEn());

    expect($avance->stage)->toBe(ReportExportStage::Reading)
        ->and($avance->percent)->toBeNull()
        ->and($avance->updatedAt->format(DATE_ATOM))->toBe(REPORT_EXPORT_PROGRESS_AT);
})->group('RF-IN-06');

it('mide el porcentaje por filas, redondeando hacia abajo', function (int $hechas, int $total, int $porcentaje): void {
    expect(ReportExportProgress::ofRows(ReportExportStage::Writing, $hechas, $total, avanceEn())->percent)->toBe($porcentaje);
})->with([
    'nada' => [0, 1000, 0],
    'un tercio' => [1, 3, 33],
    'la mitad' => [500, 1000, 50],
    'casi' => [989, 1000, 98],
])->group('RF-IN-06');

it('nunca llega a 100 antes de cerrar la fila', function (int $hechas): void {
    // Queda la huella, el asiento y el cierre. Una barra llena que se queda
    // quieta se lee como «se ha colgado». Y el recuento previo y el recorrido
    // no se leen en el mismo instante: lo hecho puede pasar del total.
    expect(ReportExportProgress::ofRows(ReportExportStage::Writing, $hechas, 1000, avanceEn())->percent)
        ->toBe(ReportExportProgress::CEILING_BEFORE_COMPLETION);
})->with([999, 1000, 1500])->group('RF-IN-06');

it('con un total de cero no divide: no hay porcentaje', function (): void {
    expect(ReportExportProgress::ofRows(ReportExportStage::Writing, 0, 0, avanceEn())->percent)->toBeNull();
})->group('RF-IN-06');

it('rechaza un avance negativo', function (int $hechas, int $total): void {
    expect(static fn () => ReportExportProgress::ofRows(ReportExportStage::Writing, $hechas, $total, avanceEn()))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'hechas' => [-1, 10],
    'total' => [1, -1],
])->group('RF-IN-06');

it('se guarda y se relee igual', function (): void {
    $avance = ReportExportProgress::ofRows(ReportExportStage::Writing, 250, 1000, avanceEn());
    $releido = ReportExportProgress::fromArray($avance->toArray());

    expect($avance->toArray())->toBe([
        'stage' => 'writing',
        'percent' => 25,
        'updated_at' => avanceEn()->getTimestamp(),
    ])
        ->and($releido?->stage)->toBe(ReportExportStage::Writing)
        ->and($releido?->percent)->toBe(25)
        ->and($releido?->updatedAt->getTimestamp())->toBe(avanceEn()->getTimestamp());
})->group('RF-IN-06');

it('relee tambien una fase sin porcentaje, que es un avance y no la falta de uno', function (): void {
    $releido = ReportExportProgress::fromArray(ReportExportProgress::at(ReportExportStage::Sealing, avanceEn())->toArray());

    expect($releido)->toBeInstanceOf(ReportExportProgress::class)
        ->and($releido?->stage)->toBe(ReportExportStage::Sealing)
        ->and($releido?->percent)->toBeNull();
})->group('RF-IN-06');

it('un avance ilegible se lee como ninguno, sin romper la lista', function (array $guardado): void {
    expect(ReportExportProgress::fromArray($guardado))->toBeNull();
})->with([
    'vacio' => [[]],
    'fase desconocida' => [['stage' => 'printing', 'percent' => null, 'updated_at' => 1]],
    'fase que no es texto' => [['stage' => 3, 'percent' => null, 'updated_at' => 1]],
    'sin instante' => [['stage' => 'writing', 'percent' => 10]],
    'instante como texto' => [['stage' => 'writing', 'percent' => 10, 'updated_at' => '1']],
    'porcentaje como texto' => [['stage' => 'writing', 'percent' => '10', 'updated_at' => 1]],
    'porcentaje negativo' => [['stage' => 'writing', 'percent' => -1, 'updated_at' => 1]],
    'porcentaje completo' => [['stage' => 'writing', 'percent' => 100, 'updated_at' => 1]],
])->group('RF-IN-06');

it('acepta los extremos validos del porcentaje', function (int $porcentaje): void {
    expect(ReportExportProgress::fromArray(['stage' => 'writing', 'percent' => $porcentaje, 'updated_at' => 1])?->percent)
        ->toBe($porcentaje);
})->with([0, 99])->group('RF-IN-06');
