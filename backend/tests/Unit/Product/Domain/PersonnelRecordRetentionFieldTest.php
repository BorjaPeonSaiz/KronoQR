<?php

declare(strict_types=1);

use App\Modules\Compliance\Domain\Policy\RetentionPolicy;
use App\Modules\Compliance\Domain\ValueObject\RetentionPolicySnapshot;
use App\Modules\Product\Domain\Exception\InvalidComplianceProfileValue;
use App\Modules\Product\Domain\ValueObject\ComplianceProfileField;
use App\Modules\Product\Domain\ValueObject\ComplianceProfileSnapshot;
use App\Modules\Product\Domain\ValueObject\ComplianceProfileSource;
use App\Modules\Shared\Domain\ValueObject\CompliancePolicy;
use App\Modules\Shared\Domain\ValueObject\Jurisdiction;
use App\Modules\Shared\Domain\ValueObject\LegalRetentionFloor;

/*
 * `personnel_record_retention_years` como campo del perfil y como dato de la
 * politica (ADR-060, tarea 11.3 A; RL-11, regla dura 14): entre 1 y 30, sin
 * valor de serie en el dominio (el 4 es el `DEFAULT` de la columna), y cuenta
 * como cambio que afecta a la retencion.
 */

function fichaPerfil(int $personnelYears = 4): ComplianceProfileSnapshot
{
    return new ComplianceProfileSnapshot(
        id: 1,
        name: 'ES-hosteleria',
        jurisdiction: 'ES',
        minRestHours: 12,
        maxDailyHours: 9,
        maxWeeklyHours: 40,
        breakRequiredAfterHours: 6,
        weekStartsOn: 1,
        holidayCalendar: [],
        retentionYears: 4,
        isDefault: true,
        source: ComplianceProfileSource::InstallationDefault,
        personnelRecordRetentionYears: $personnelYears,
    );
}

function fichaSuelo(): LegalRetentionFloor
{
    return LegalRetentionFloor::fromCatalog(Jurisdiction::fromCode('ES'), 4);
}

it('declara el campo con su rango y como cambio que afecta a la retencion', function (): void {
    $field = ComplianceProfileField::PersonnelRecordRetentionYears;

    expect($field->value)->toBe('personnel_record_retention_years')
        ->and($field->minimum())->toBe(1)
        ->and($field->maximum())->toBe(30)
        ->and($field->affectsRetention())->toBeTrue()
        ->and($field->affectsComplianceView())->toBeFalse()
        ->and($field->complianceRule())->toBeNull()
        ->and(ComplianceProfileField::RetentionYears->affectsRetention())->toBeTrue()
        ->and(ComplianceProfileField::MinRestHours->affectsRetention())->toBeFalse();
})->group('RL-11', 'RF-PD-07');

it('nace con 4 años y lo cambia un PATCH sin tocar lo demas', function (): void {
    $before = fichaPerfil();
    $after = $before->with(['personnel_record_retention_years' => 6], fichaSuelo());

    expect($before->personnelRecordRetentionYears)->toBe(4)
        ->and($before->valueOf(ComplianceProfileField::PersonnelRecordRetentionYears))->toBe(4)
        ->and($after->personnelRecordRetentionYears)->toBe(6)
        ->and($after->retentionYears)->toBe(4)
        ->and($before->fieldsThatChange(['personnel_record_retention_years' => 6]))
        ->toBe([ComplianceProfileField::PersonnelRecordRetentionYears])
        ->and($before->fieldsThatChange(['personnel_record_retention_years' => 4]))->toBe([]);

    // Un `PATCH` de otro campo lo conserva.
    expect(fichaPerfil(7)->with(['min_rest_hours' => 10], fichaSuelo())->personnelRecordRetentionYears)->toBe(7);
})->group('RL-11', 'RF-PD-07');

it('rechaza un plazo fuera de 1 a 30', function (int $years): void {
    expect(fn (): ComplianceProfileSnapshot => fichaPerfil()->with(['personnel_record_retention_years' => $years], fichaSuelo()))
        ->toThrow(InvalidComplianceProfileValue::class);
})->with([0, 31])->group('RL-11', 'RF-PD-07');

it('la politica lo lleva y no admite cero', function (): void {
    $policy = static fn (int $years): CompliancePolicy => new CompliancePolicy(
        minimumRestMinutes: 720,
        maximumDailyMinutes: 540,
        breakRequiredAfterMinutes: 360,
        retentionYears: 4,
        maximumWeeklyMinutes: 2400,
        weekStartsOn: 1,
        holidayCalendar: [],
        personnelRecordRetentionYears: $years,
    );

    expect($policy(6)->personnelRecordRetentionYears)->toBe(6)
        ->and(fn (): CompliancePolicy => $policy(0))->toThrow(InvalidArgumentException::class, 'ficha de personal');
})->group('RL-11');

it('ningun objeto del dominio trae los años de la ficha de serie (regla dura 14)', function (string $class, string $method, string $parameter): void {
    expect((new ReflectionParameter([$class, $method], $parameter))->isOptional())->toBeFalse();
})->with([
    'la politica de cumplimiento' => [CompliancePolicy::class, '__construct', 'personnelRecordRetentionYears'],
    'el perfil' => [ComplianceProfileSnapshot::class, '__construct', 'personnelRecordRetentionYears'],
    'la politica de retencion' => [RetentionPolicy::class, 'of', 'personnelRecordYears'],
    'la foto de la politica en el informe' => [RetentionPolicySnapshot::class, '__construct', 'personnelRecordYears'],
])->group('RL-11');
