<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\ValueObject\TokenAbility;
use App\Modules\Reporting\Http\Request\RequestReportExportRequest;

/*
 * `RequestReportExportRequest` no puede importar `TokenAbility` de `Identity`
 * (Deptrac) y lleva una copia del ambito ancho de los informes. Si alguien
 * renombrara `reports:*`, la peticion en diferido dejaria pasar o rechazaria a
 * todo el mundo sin que nada lo dijera (V5-PA-4, doc 02 §7.3).
 */

it('exige el mismo ambito que Identity llama reports:*', function (): void {
    expect(RequestReportExportRequest::ALL_REPORTS_ABILITY)->toBe(TokenAbility::REPORTS_ALL->value);
})->group('RS-05', 'RQ-07');
