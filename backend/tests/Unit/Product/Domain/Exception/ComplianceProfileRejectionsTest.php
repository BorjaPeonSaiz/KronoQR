<?php

declare(strict_types=1);

use App\Modules\Product\Domain\Exception\InvalidComplianceProfileValue;
use App\Modules\Product\Domain\ValueObject\ComplianceProfileField;
use App\Modules\Shared\Domain\ValueObject\Jurisdiction;
use App\Modules\Shared\Domain\ValueObject\LegalRetentionFloor;
use Tests\Support\Shared\RejectionTranslations;

/*
 * Lo que dice un rechazo del perfil de cumplimiento (RF-PD-07, ADR-017, regla
 * dura 14).
 *
 * Quien edita el perfil esta tocando umbrales legales: el `422` tiene que decir
 * que campo, que cota y que valor, en su idioma. `field` es lo que el borde
 * usa para señalar el campo del formulario; un rechazo que apunta al campo
 * equivocado deja a la persona corrigiendo lo que estaba bien.
 */

dataset('rechazos de un campo del perfil de cumplimiento', [
    'fuera de rango' => [
        fn (): InvalidComplianceProfileValue => InvalidComplianceProfileValue::outOfRange(ComplianceProfileField::MaxDailyHours, 13, 1, 12),
        'compliance-profile.errors.out_of_range',
        ['field' => 'max_daily_hours', 'minimum' => 1, 'maximum' => 12, 'value' => 13],
        ComplianceProfileField::MaxDailyHours,
        'Compliance profile field "max_daily_hours" accepts 1 to 12, got 13.',
    ],
    'no es un entero' => [
        fn (): InvalidComplianceProfileValue => InvalidComplianceProfileValue::notAnInteger(ComplianceProfileField::MinRestHours, 'string'),
        'compliance-profile.errors.not_integer',
        ['field' => 'min_rest_hours', 'received' => 'string'],
        ComplianceProfileField::MinRestHours,
        'Compliance profile field "min_rest_hours" expects an integer, got string.',
    ],
    'no es texto' => [
        fn (): InvalidComplianceProfileValue => InvalidComplianceProfileValue::notText(ComplianceProfileField::Name, 'int'),
        'compliance-profile.errors.not_text',
        ['field' => 'name', 'received' => 'int'],
        ComplianceProfileField::Name,
        'Compliance profile field "name" expects a string, got int.',
    ],
    'vacio' => [
        fn (): InvalidComplianceProfileValue => InvalidComplianceProfileValue::notEmpty(ComplianceProfileField::Name),
        'compliance-profile.errors.not_empty',
        ['field' => 'name'],
        ComplianceProfileField::Name,
        'Compliance profile field "name" does not accept an empty value.',
    ],
    'demasiado largo' => [
        fn (): InvalidComplianceProfileValue => InvalidComplianceProfileValue::tooLong(ComplianceProfileField::Name, 121, 120),
        'compliance-profile.errors.too_long',
        ['field' => 'name', 'maximum' => 120, 'length' => 121],
        ComplianceProfileField::Name,
        'Compliance profile field "name" accepts up to 120 characters, got 121.',
    ],
    'no es una lista de fechas' => [
        fn (): InvalidComplianceProfileValue => InvalidComplianceProfileValue::notADateList(ComplianceProfileField::HolidayCalendar, '2026-02-30'),
        'compliance-profile.errors.not_date_list',
        ['field' => 'holiday_calendar', 'received' => '2026-02-30'],
        ComplianceProfileField::HolidayCalendar,
        'Compliance profile field "holiday_calendar" expects a list of ISO dates, got 2026-02-30.',
    ],
    'repetido' => [
        fn (): InvalidComplianceProfileValue => InvalidComplianceProfileValue::duplicated(ComplianceProfileField::HolidayCalendar),
        'compliance-profile.errors.duplicated',
        ['field' => 'holiday_calendar'],
        ComplianceProfileField::HolidayCalendar,
        'Compliance profile field "holiday_calendar" does not accept repeated values.',
    ],
    'nombre ya usado' => [
        fn (): InvalidComplianceProfileValue => InvalidComplianceProfileValue::nameAlreadyUsed('Convenio de hosteleria'),
        'compliance-profile.errors.name_taken',
        ['name' => 'Convenio de hosteleria'],
        ComplianceProfileField::Name,
        'Compliance profile name "Convenio de hosteleria" is already in use.',
    ],
    'semanal por debajo de la diaria' => [
        fn (): InvalidComplianceProfileValue => InvalidComplianceProfileValue::weeklyBelowDaily(8, 9),
        'compliance-profile.errors.weekly_below_daily',
        ['weekly' => 8, 'daily' => 9],
        ComplianceProfileField::MaxWeeklyHours,
        'Weekly working hours (8) cannot be lower than daily working hours (9).',
    ],
    'conservacion por debajo del suelo legal' => [
        fn (): InvalidComplianceProfileValue => InvalidComplianceProfileValue::belowLegalRetentionFloor(
            3,
            LegalRetentionFloor::fromCatalog(Jurisdiction::fromCode('ES'), 4),
        ),
        'compliance-profile.errors.below_legal_floor',
        ['field' => 'retention_years', 'minimum' => 4, 'value' => 3, 'jurisdiction' => 'ES'],
        ComplianceProfileField::RetentionYears,
        'Compliance profile field "retention_years" cannot be lower than the legal floor of ES (4 years), got 3.',
    ],
]);

it('dice que campo fallo, por que y con que valores', function (
    InvalidComplianceProfileValue $rechazo,
    string $clave,
    array $parametros,
    ComplianceProfileField $campo,
    string $mensaje,
): void {
    expect($rechazo->translationKey)->toBe($clave)
        ->and($rechazo->parameters)->toBe($parametros)
        ->and($rechazo->field)->toBe($campo)
        ->and($rechazo->getMessage())->toBe($mensaje);
})->with('rechazos de un campo del perfil de cumplimiento')->group('RF-PD-07');

it('tiene texto en español y en ingles con todos sus marcadores sustituidos', function (InvalidComplianceProfileValue $rechazo): void {
    expect(RejectionTranslations::problemsWith($rechazo->translationKey, $rechazo->parameters))->toBe([]);
})->with('rechazos de un campo del perfil de cumplimiento')->group('RF-PD-07', 'RF-KI-05');
