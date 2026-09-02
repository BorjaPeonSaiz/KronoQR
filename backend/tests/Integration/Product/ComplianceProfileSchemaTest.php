<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ValueObject\ComplianceProfileField;
use App\Modules\Product\Domain\ValueObject\ComplianceProfileFieldType;
use App\Modules\Shared\Application\Port\CompliancePolicyProvider;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * El esquema de `compliance_profiles` y la resolucion del perfil por centro
 * (RF-PD-07, regla dura 14).
 *
 * POR QUE NO PODIA SER UNITARIA: lo que se comprueba aqui son restricciones de
 * PostgreSQL —los `CHECK` de rango, el indice unico parcial del perfil por
 * defecto y la clave ajena de `sites`— y la cascada de dos escalones que resuelve
 * el umbral. Un doble en memoria daria todo eso por bueno sin haberlo comprobado
 * nunca, y es justo la ultima linea de defensa de un umbral legal.
 */

uses(RefreshDatabase::class);

it('entrega el perfil ES-hosteleria sembrado por la migracion, con sus ocho valores', function (): void {
    // El producto que se instala en casa del cliente. Si alguno cambiara sin
    // querer, cambiaria lo que se considera un incumplimiento en veinte hoteles.
    $profile = DB::table('compliance_profiles')->where('name', 'ES-hosteleria')->first();

    expect($profile)->not->toBeNull()
        ->and($profile?->jurisdiction)->toBe('ES')
        ->and($profile?->min_rest_hours)->toBe(12)
        ->and($profile?->max_daily_hours)->toBe(9)
        ->and($profile?->max_weekly_hours)->toBe(40)
        ->and($profile?->break_required_after_hours)->toBe(6)
        ->and($profile?->week_starts_on)->toBe(1)
        ->and($profile?->holiday_calendar)->toBe('[]')
        ->and($profile?->retention_years)->toBe(4)
        ->and($profile?->is_default)->toBeTrue();
})->group('RF-PD-07', 'RL-02');

it('no admite dos perfiles por defecto', function (): void {
    // El indice unico parcial `one_default_compliance_profile`. Sin el, dos
    // perfiles se disputarian el fallback de un centro sin perfil asignado y el
    // umbral que rige dependeria del plan de la consulta.
    expect(fn (): int => DB::table('compliance_profiles')->insertGetId([
        'name' => 'Otro convenio',
        'jurisdiction' => 'ES',
        'retention_years' => 4,
        'min_rest_hours' => 12,
        'max_daily_hours' => 9,
        'max_weekly_hours' => 40,
        'break_required_after_hours' => 6,
        'week_starts_on' => 1,
        'holiday_calendar' => '[]',
        'is_default' => true,
    ]))->toThrow(QueryException::class);
})->group('RF-PD-07');

it('rechaza en el esquema los umbrales que el dominio tampoco admite', function (string $column, int $value): void {
    // La ultima linea de defensa: vale igual si alguien edita la fila con `psql`,
    // que es donde la validacion del `FormRequest` y la del objeto de valor no
    // llegan. Un `max_daily_hours = 90` apagaria RN-11 en silencio.
    expect(fn (): int => DB::table('compliance_profiles')
        ->where('name', 'ES-hosteleria')
        ->update([$column => $value]))->toThrow(QueryException::class);
})->with([
    'jornada diaria imposible' => ['max_daily_hours', 90],
    'descanso imposible' => ['min_rest_hours', 40],
    'pausa imposible' => ['break_required_after_hours', 30],
    'semana imposible' => ['max_weekly_hours', 200],
    'retencion imposible' => ['retention_years', 100],
    'jornada diaria a cero' => ['max_daily_hours', 0],
    'semana que empieza el dia 8' => ['week_starts_on', 8],
])->group('RF-PD-07');

it('rechaza en el esquema una jornada semanal por debajo de la diaria', function (): void {
    // Nadie puede trabajar mas en un dia que en una semana. Es la unica
    // invariante del perfil que habla de dos columnas a la vez, asi que su sitio
    // es el esquema y no una regla por campo.
    expect(fn (): int => DB::table('compliance_profiles')
        ->where('name', 'ES-hosteleria')
        ->update(['max_weekly_hours' => 8]))->toThrow(QueryException::class);
})->group('RF-PD-07');

it('el maximo del catalogo de campos y el del esquema son el mismo numero', function (): void {
    // Las dos copias son deliberadas —una da el `422` util y la otra protege de
    // `psql`— y por eso hay que atarlas: si divergieran, un valor aceptado por el
    // dominio saldria como `500` de PostgreSQL, que es un error del cliente
    // presentado como una averia.
    /** @var object{def: string}|null $definition */
    $definition = DB::selectOne(
        "SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint
         WHERE conrelid = 'compliance_profiles'::regclass
           AND conname = 'compliance_profiles_chk_threshold_bounds'"
    );

    expect($definition)->not->toBeNull();

    $sql = $definition === null ? '' : $definition->def;

    foreach (ComplianceProfileField::cases() as $field) {
        if ($field->type() !== ComplianceProfileFieldType::Integer || $field === ComplianceProfileField::WeekStartsOn) {
            continue;
        }

        expect($sql)->toContain($field->value.' <= '.$field->maximum());
    }
})->group('RF-PD-07');

it('resuelve el umbral del centro por su perfil asignado y no por el de la instalacion', function (): void {
    // La cascada tiene dos escalones y el primero manda. Sin esta prueba, un
    // centro con convenio propio recibiria el umbral del perfil por defecto y
    // nadie lo notaria: los dos numeros existen y los dos son plausibles.
    $siteId = WorkforceFixtures::site();

    $ownId = DB::table('compliance_profiles')->insertGetId([
        'name' => 'Convenio propio',
        'jurisdiction' => 'ES',
        'retention_years' => 6,
        'min_rest_hours' => 10,
        'max_daily_hours' => 8,
        'max_weekly_hours' => 38,
        'break_required_after_hours' => 5,
        'week_starts_on' => 7,
        'holiday_calendar' => '["2026-12-25"]',
        'is_default' => false,
    ]);

    DB::table('sites')->where('id', $siteId)->update(['compliance_profile_id' => $ownId]);

    $policy = app(CompliancePolicyProvider::class)->forSite($siteId);

    expect($policy->minimumRestMinutes)->toBe(600)
        ->and($policy->maximumDailyMinutes)->toBe(480)
        ->and($policy->breakRequiredAfterMinutes)->toBe(300)
        ->and($policy->maximumWeeklyMinutes)->toBe(2280)
        ->and($policy->weekStartsOn)->toBe(7)
        ->and($policy->holidayCalendar)->toBe(['2026-12-25'])
        ->and($policy->retentionYears)->toBe(6);
})->group('RF-PD-07', 'RN-10', 'RN-11', 'RN-12');

it('cae en el perfil por defecto cuando el centro no tiene ninguno asignado', function (): void {
    // `sites.compliance_profile_id` es nullable y significa «usa el de la
    // instalacion»: es lo que permite que un cliente con un solo convenio no
    // tenga que asignarlo, y que el asistente cree el centro antes de decidirlo.
    $siteId = WorkforceFixtures::site();

    DB::table('sites')->where('id', $siteId)->update(['compliance_profile_id' => null]);

    $policy = app(CompliancePolicyProvider::class)->forSite($siteId);

    expect($policy->minimumRestMinutes)->toBe(720)
        ->and($policy->maximumDailyMinutes)->toBe(540)
        ->and($policy->breakRequiredAfterMinutes)->toBe(360)
        ->and($policy->retentionYears)->toBe(4);
})->group('RF-PD-07');

it('no deja borrar un perfil que algun centro esta usando', function (): void {
    // `restrictOnDelete`. Sin el, borrar el perfil dejaria al centro apuntando a
    // la nada y el fichaje seguiria funcionando —regla dura 19— pero la revision
    // diaria se quedaria sin umbrales que aplicar.
    $siteId = WorkforceFixtures::site();
    $profileId = DB::table('compliance_profiles')->where('is_default', true)->value('id');

    DB::table('sites')->where('id', $siteId)->update(['compliance_profile_id' => $profileId]);

    expect(fn (): int => DB::table('compliance_profiles')->where('id', $profileId)->delete())
        ->toThrow(QueryException::class);
})->group('RF-PD-07');
