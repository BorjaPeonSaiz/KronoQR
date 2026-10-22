<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ValueObject\DoctorFinding;
use App\Modules\Product\Domain\ValueObject\DoctorStatus;

/*
 * Un hallazgo de `product:doctor` y las claves de sus textos (RF-PD-13). La
 * variante vale tambien en verde (R4-PL-03): «el ultimo cierre cuadra» y
 * «todavia no hay ningun cierre que comprobar» son dos `ok` distintos.
 */

it('un ok sin variante usa el texto por defecto y no tiene que hacer', function (): void {
    $finding = DoctorFinding::ok('database.audit_chain', ['status' => 'verified'], ['year' => 2025]);

    expect($finding->status)->toBe(DoctorStatus::Ok)
        ->and($finding->variant)->toBeNull()
        ->and($finding->messageKey())->toBe('doctor.checks.database.audit_chain.ok')
        ->and($finding->fixKey())->toBeNull()
        ->and($finding->params)->toBe(['year' => 2025])
        ->and($finding->details)->toBe(['status' => 'verified']);
})->group('RF-PD-13');

it('un ok con variante lleva su propio texto y sigue sin que hacer', function (): void {
    $finding = DoctorFinding::ok('database.audit_chain', ['status' => 'no_anchor'], variant: 'no_anchor');

    expect($finding->status)->toBe(DoctorStatus::Ok)
        ->and($finding->variant)->toBe('no_anchor')
        ->and($finding->messageKey())->toBe('doctor.checks.database.audit_chain.ok_no_anchor')
        ->and($finding->fixKey())->toBeNull()
        ->and($finding->params)->toBe([])
        ->and($finding->details)->toBe(['status' => 'no_anchor']);
})->group('RF-PD-13');

it('un aviso y un fallo con variante tienen texto y que hacer con la misma variante', function (): void {
    $warning = DoctorFinding::warning('license.branding_not_applied', 'license_inactive', ['x' => 1], ['allowed' => false]);
    $failure = DoctorFinding::failure('database.audit_chain');

    expect($warning->status)->toBe(DoctorStatus::Warning)
        ->and($warning->messageKey())->toBe('doctor.checks.license.branding_not_applied.warning_license_inactive')
        ->and($warning->fixKey())->toBe('doctor.fixes.license.branding_not_applied.warning_license_inactive')
        ->and($warning->params)->toBe(['x' => 1])
        ->and($warning->details)->toBe(['allowed' => false])
        ->and($failure->status)->toBe(DoctorStatus::Failure)
        ->and($failure->messageKey())->toBe('doctor.checks.database.audit_chain.failure')
        ->and($failure->fixKey())->toBe('doctor.fixes.database.audit_chain.failure');
})->group('RF-PD-13');
