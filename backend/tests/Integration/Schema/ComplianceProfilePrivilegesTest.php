<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;
use Tests\Feature\Quality\Support\Commands;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * El suelo legal de conservacion no se esquiva desde el runtime (tarea 6.5 del
 * bloque 6 de la 2.2.1, ADR-057 §7; RL-02, RS-07).
 *
 * El suelo de `retention_years` se resuelve por la jurisdiccion del perfil del
 * centro. Con la credencial de la aplicacion no se puede cambiar esa
 * jurisdiccion, marcar otro perfil por defecto, crear o borrar perfiles ni
 * reasignar el perfil del centro. Contra PostgreSQL de verdad y con
 * `fichaje_app` como conexion por defecto.
 */

uses(RefreshDatabase::class);

const COMPLIANCE_PROFILE_PRIVILEGES_MIGRATION = '2026_10_13_100100_restrict_application_writes_on_compliance_profiles';

/**
 * En el orden de las columnas de la tabla.
 *
 * @var list<string>
 */
const COMPLIANCE_PROFILE_PRIVILEGES_EDITABLE = [
    'name', 'retention_years', 'min_rest_hours', 'max_daily_hours', 'max_weekly_hours',
    'break_required_after_hours', 'week_starts_on', 'holiday_calendar', 'updated_at', 'updated_by_user_id',
];

/**
 * @var list<string>
 */
const COMPLIANCE_PROFILE_PRIVILEGES_SITE_COLUMNS = ['name', 'timezone'];

/**
 * @return array<string, bool>
 */
function complianceProfileTablePrivileges(string $table, ?ConnectionInterface $connection = null): array
{
    $connection ??= DB::connection();
    $application = Config::string('database.roles.application');
    $result = [];

    foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'TRUNCATE', 'REFERENCES', 'TRIGGER'] as $privilege) {
        /** @var object{granted: bool}|null $row */
        $row = $connection->selectOne('SELECT has_table_privilege(?, ?, ?) AS granted', [$application, $table, $privilege]);
        $result[$privilege] = (bool) $row?->granted;
    }

    return $result;
}

/**
 * @return list<string>
 */
function complianceProfileColumnsWith(string $table, string $privilege, ?ConnectionInterface $connection = null): array
{
    $connection ??= DB::connection();

    /** @var list<object{column_name: string}> $rows */
    $rows = $connection->select(
        'SELECT column_name FROM information_schema.columns
          WHERE table_schema = current_schema() AND table_name = ?
            AND has_column_privilege(?, format(\'%I\', table_name), column_name, ?)
          ORDER BY ordinal_position',
        [$table, Config::string('database.roles.application'), $privilege],
    );

    return array_map(static fn (object $row): string => $row->column_name, $rows);
}

function complianceProfileExpectDenied(string $what, Closure $write): void
{
    try {
        DB::transaction(static function () use ($write): void {
            $write();
        });
    } catch (QueryException $exception) {
        expect($exception->getCode())->toBe('42501', $what.': '.$exception->getMessage());

        return;
    }

    Assert::fail('PostgreSQL ha permitido «'.$what.'» al rol de la aplicacion.');
}

function complianceProfileDefaultId(): int
{
    $id = DB::table('compliance_profiles')->where('is_default', true)->value('id');

    return is_numeric($id) ? (int) $id : throw new RuntimeException('No hay perfil por defecto.');
}

function complianceProfileStepsBack(ConnectionInterface $connection): int
{
    /** @var list<string> $applied */
    $applied = $connection->table('migrations')->orderByDesc('id')->pluck('migration')->all();

    $steps = 0;

    foreach ($applied as $name) {
        $steps++;

        if ($name === COMPLIANCE_PROFILE_PRIVILEGES_MIGRATION) {
            return $steps;
        }
    }

    return 0;
}

it('el catalogo deja compliance_profiles sin INSERT ni DELETE y con UPDATE solo de lo que edita el panel', function (): void {
    expect(complianceProfileTablePrivileges('compliance_profiles'))->toBe([
        'SELECT' => true,
        'INSERT' => false,
        'UPDATE' => false,
        'DELETE' => false,
        'TRUNCATE' => false,
        'REFERENCES' => false,
        'TRIGGER' => false,
    ])
        ->and(complianceProfileColumnsWith('compliance_profiles', 'UPDATE'))->toBe(COMPLIANCE_PROFILE_PRIVILEGES_EDITABLE)
        ->and(complianceProfileColumnsWith('compliance_profiles', 'INSERT'))->toBe([]);
})->group('RL-02', 'RS-07');

it('el catalogo deja sites con INSERT y UPDATE solo de name y timezone', function (): void {
    expect(complianceProfileTablePrivileges('sites'))->toBe([
        'SELECT' => true,
        'INSERT' => false,
        'UPDATE' => false,
        'DELETE' => true,
        'TRUNCATE' => false,
        'REFERENCES' => false,
        'TRIGGER' => false,
    ])
        ->and(complianceProfileColumnsWith('sites', 'INSERT'))->toBe(COMPLIANCE_PROFILE_PRIVILEGES_SITE_COLUMNS)
        ->and(complianceProfileColumnsWith('sites', 'UPDATE'))->toBe(COMPLIANCE_PROFILE_PRIVILEGES_SITE_COLUMNS);
})->group('RL-02', 'RS-07');

it('el motor rechaza esquivar el suelo legal desde la aplicacion', function (string $what, Closure $write): void {
    WorkforceFixtures::site();

    complianceProfileExpectDenied($what, $write);
})->with([
    'cambiar la jurisdiccion del perfil' => [
        'compliance_profiles.jurisdiction',
        static fn () => DB::table('compliance_profiles')->update(['jurisdiction' => 'PT']),
    ],
    'quitar el perfil por defecto' => [
        'compliance_profiles.is_default',
        static fn () => DB::table('compliance_profiles')->update(['is_default' => false]),
    ],
    'crear un perfil de otra jurisdiccion' => [
        'INSERT compliance_profiles',
        static fn () => DB::table('compliance_profiles')->insert([
            'name' => 'Otro', 'jurisdiction' => 'PT', 'retention_years' => 1, 'min_rest_hours' => 12,
            'max_daily_hours' => 9, 'max_weekly_hours' => 40, 'break_required_after_hours' => 6,
            'week_starts_on' => 1, 'holiday_calendar' => '[]', 'is_default' => false,
        ]),
    ],
    'borrar un perfil' => [
        'DELETE compliance_profiles',
        static fn () => DB::table('compliance_profiles')->delete(),
    ],
    'vaciar los perfiles' => [
        'TRUNCATE compliance_profiles',
        static fn () => DB::statement('TRUNCATE compliance_profiles CASCADE'),
    ],
    'reasignar el perfil del centro' => [
        'sites.compliance_profile_id',
        static fn () => DB::table('sites')->update(['compliance_profile_id' => complianceProfileDefaultId()]),
    ],
    'reescribir los ajustes del centro' => [
        'sites.settings',
        static fn () => DB::table('sites')->update(['settings' => '{}']),
    ],
    'crear un centro con perfil' => [
        'INSERT sites.compliance_profile_id',
        static fn () => DB::table('sites')->insert([
            'name' => 'Otro centro', 'timezone' => 'Europe/Madrid', 'compliance_profile_id' => complianceProfileDefaultId(),
        ]),
    ],
])->group('RL-02', 'RS-07');

it('la aplicacion sigue pudiendo editar el perfil como lo hace el panel', function (): void {
    $id = complianceProfileDefaultId();

    // Las columnas de DatabaseComplianceProfileRepository::save.
    DB::table('compliance_profiles')->where('id', $id)->update([
        'name' => 'Convenio de hosteleria',
        'min_rest_hours' => 12,
        'max_daily_hours' => 9,
        'max_weekly_hours' => 40,
        'break_required_after_hours' => 6,
        'week_starts_on' => 1,
        'holiday_calendar' => '[]',
        'retention_years' => 5,
        'updated_at' => '2026-10-13 09:00:00+00',
        'updated_by_user_id' => null,
    ]);

    expect(DB::table('compliance_profiles')->where('id', $id)->value('retention_years'))->toBe(5);
})->group('RL-02', 'RS-07');

it('la aplicacion sigue pudiendo crear y editar el centro como lo hacen POST y PATCH /site', function (): void {
    // La misma escritura que EloquentSiteRepository::add, y su candado de
    // padre (`FOR KEY SHARE`, que exige UPDATE sobre alguna columna).
    $site = WorkforceFixtures::site();

    DB::table('sites')->where('id', $site)->update(['name' => 'Hotel renombrado', 'timezone' => 'Atlantic/Canary']);
    DB::select('SELECT id FROM sites ORDER BY id FOR KEY SHARE');

    expect(DB::table('sites')->where('id', $site)->value('timezone'))->toBe('Atlantic/Canary')
        ->and(DB::table('sites')->where('id', $site)->value('compliance_profile_id'))->toBeNull();
})->group('RL-02', 'RS-07');

it('su down() devuelve exactamente lo que habia y su up() lo vuelve a quitar', function (): void {
    $name = config()->string('database.migrations.connection');
    $migrator = DB::connection($name);

    $steps = complianceProfileStepsBack($migrator);

    expect($steps)->toBeGreaterThan(0);

    try {
        [$back, $backOutput] = Commands::run('migrate:rollback --database='.$name.' --step='.$steps);

        expect($back)->toBe(0, $backOutput)
            ->and(complianceProfileTablePrivileges('compliance_profiles', $migrator))->toMatchArray([
                'INSERT' => true, 'UPDATE' => true, 'DELETE' => true, 'TRUNCATE' => false,
            ])
            ->and(complianceProfileTablePrivileges('sites', $migrator))->toMatchArray([
                'INSERT' => true, 'UPDATE' => true, 'DELETE' => true, 'TRUNCATE' => false,
            ]);
    } finally {
        [$forward, $forwardOutput] = Commands::run('migrate --database='.$name);
    }

    expect($forward)->toBe(0, $forwardOutput)
        ->and(complianceProfileTablePrivileges('compliance_profiles', $migrator)['INSERT'])->toBeFalse()
        ->and(complianceProfileColumnsWith('compliance_profiles', 'UPDATE', $migrator))->toBe(COMPLIANCE_PROFILE_PRIVILEGES_EDITABLE)
        ->and(complianceProfileColumnsWith('sites', 'UPDATE', $migrator))->toBe(COMPLIANCE_PROFILE_PRIVILEGES_SITE_COLUMNS)
        ->and(complianceProfileColumnsWith('sites', 'INSERT', $migrator))->toBe(COMPLIANCE_PROFILE_PRIVILEGES_SITE_COLUMNS);
})->group('RL-02', 'RS-07');
