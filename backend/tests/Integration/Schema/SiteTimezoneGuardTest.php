<?php

declare(strict_types=1);

use App\Modules\Workforce\Domain\Exception\SiteTimezoneLocked;
use App\Modules\Workforce\Domain\Model\Site;
use App\Modules\Workforce\Domain\ValueObject\SiteTimezone;
use App\Modules\Workforce\Infrastructure\Persistence\EloquentSiteRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Tests\Feature\Quality\Support\Commands;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\CommittedDatabase;
use Tests\Support\Database\SchemaOwner;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * La zona horaria del centro queda fija **tambien en la base de datos** en
 * cuanto existe el primer tramo (RN-23, ADR-056 con su enmienda del bloque 2 de
 * la 2.3.0; residuo D2 de la 2.2.1).
 *
 * Contra PostgreSQL de verdad y con SQL directo: lo que se prueba es que la
 * credencial del runtime (`fichaje_app`, la conexion por defecto) no puede
 * cambiar la zona con registro horario **sin pasar por el caso de uso**, y que
 * la correccion de consola sigue teniendo su puerta, la funcion
 * `site_correct_timezone`.
 *
 * `CommittedDatabase` y no `RefreshDatabase`: el propietario es otra sesion y
 * no veria el centro ni los tramos sin confirmar, y la prueba de `down()` suelta
 * el *trigger* de `sites`, que esperaria para siempre a una transaccion
 * envolvente que hubiera leido la tabla.
 */

uses(CommittedDatabase::class);

const SITE_TIMEZONE_GUARD_MIGRATION = '2026_10_18_120000_guard_site_timezone_in_database';

/**
 * El centro de la instalacion, en Madrid.
 */
function siteGuardSite(): int
{
    return WorkforceFixtures::site('Hotel de la guarda', 'Europe/Madrid');
}

/**
 * Un tramo cerrado del centro, escrito por la aplicacion.
 */
function siteGuardEntry(int $site): void
{
    $employee = AttendanceFixtures::employeeIdOf(WorkforceFixtures::employee($site));

    DB::table('shift_entries')->insert([
        'uuid' => Str::uuid7()->toString(),
        'employee_id' => $employee,
        'site_id' => $site,
        'work_date' => '2026-03-14',
        'clocked_in_at' => '2026-03-14 07:00:00+00',
        'clocked_out_at' => '2026-03-14 15:00:00+00',
        'duration_minutes' => 480,
        'status' => 'closed',
        'clock_in_source' => 'qr_kiosk',
        'clock_out_source' => 'qr_kiosk',
        'version' => 1,
        'created_at' => '2026-03-14 07:00:00+00',
        'updated_at' => '2026-03-14 15:00:00+00',
    ]);
}

function siteGuardTimezone(int $site): string
{
    $timezone = DB::table('sites')->where('id', $site)->value('timezone');

    expect($timezone)->toBeString();
    \assert(is_string($timezone));

    return $timezone;
}

/**
 * Ejecuta `$write` en su transaccion y exige `$code`, con el mensaje que dice
 * `$fragment`.
 */
function siteGuardExpectDenied(string $what, Closure $write, string $code = '42501', string $fragment = 'RN-23', ?ConnectionInterface $connection = null): void
{
    $connection ??= DB::connection();

    try {
        $connection->transaction(static function () use ($write, $connection): void {
            $write($connection);
        });
    } catch (PDOException $exception) {
        expect($exception->getCode())->toBe($code, $what.': '.$exception->getMessage())
            ->and($exception->getMessage())->toContain($fragment);

        return;
    }

    Assert::fail('PostgreSQL ha admitido «'.$what.'».');
}

function siteGuardCorrect(int $site, string $from, string $to, ?ConnectionInterface $connection = null): void
{
    ($connection ?? DB::connection())->select(
        'SELECT public.'.EloquentSiteRepository::CORRECTION_FUNCTION.'(?::bigint, ?::text, ?::text)',
        [$site, $from, $to],
    );
}

function siteGuardFunctionExists(ConnectionInterface $connection, string $signature): bool
{
    /** @var object{oid: string|null} $row */
    $row = $connection->selectOne('SELECT to_regprocedure(?)::text AS oid', ['public.'.$signature]);

    return $row->oid !== null;
}

/**
 * @return list<string>
 */
function siteGuardTriggers(ConnectionInterface $connection): array
{
    /** @var list<string> */
    return $connection->table('pg_trigger')
        ->where('tgrelid', DB::raw("'public.sites'::regclass"))
        ->where('tgisinternal', false)
        ->orderBy('tgname')
        ->pluck('tgname')
        ->all();
}

it('corre con el rol de la aplicacion, que es al que se le niega', function (): void {
    /** @var object{name: string} $row */
    $row = DB::selectOne('SELECT current_user AS name');

    expect($row->name)->toBe(Config::string('database.roles.application'));
})->group('RN-23', 'RS-07');

it('niega a la aplicacion cambiar la zona con un UPDATE directo en cuanto hay un tramo', function (): void {
    $site = siteGuardSite();
    siteGuardEntry($site);

    siteGuardExpectDenied(
        'UPDATE sites SET timezone con registro horario',
        static fn (ConnectionInterface $db): int => $db->update('UPDATE sites SET timezone = ? WHERE id = ?', ['Atlantic/Canary', $site]),
    );

    // Tambien con el nombre en la misma sentencia: se niega entera.
    siteGuardExpectDenied(
        'nombre y zona a la vez',
        static fn (ConnectionInterface $db): int => $db->table('sites')->where('id', $site)
            ->update(['name' => 'Hotel cambiado', 'timezone' => 'Atlantic/Canary']),
    );

    expect(siteGuardTimezone($site))->toBe('Europe/Madrid')
        ->and(DB::table('sites')->where('id', $site)->value('name'))->not->toBe('Hotel cambiado');
})->group('RN-23', 'RN-05', 'RS-07');

it('deja cambiar la zona mientras no hay tramos, y el nombre o la misma zona siempre', function (): void {
    $site = siteGuardSite();

    // Sin registro horario: el camino normal (asistente o PATCH /site).
    DB::update('UPDATE sites SET timezone = ? WHERE id = ?', ['Atlantic/Canary', $site]);
    expect(siteGuardTimezone($site))->toBe('Atlantic/Canary');

    siteGuardEntry($site);

    // Con registro horario: el panel manda el formulario entero, y guardar el
    // nombre con la zona de siempre tiene que seguir funcionando.
    DB::table('sites')->where('id', $site)->update(['name' => 'Hotel renombrado', 'timezone' => 'Atlantic/Canary']);

    expect(siteGuardTimezone($site))->toBe('Atlantic/Canary')
        ->and(DB::table('sites')->where('id', $site)->value('name'))->toBe('Hotel renombrado');
})->group('RN-23', 'RS-07');

it('el repositorio traduce el rechazo de la base de datos al 409 del dominio, con la zona que defiende', function (): void {
    $site = siteGuardSite();
    siteGuardEntry($site);

    $repository = resolve(EloquentSiteRepository::class);

    try {
        DB::transaction(static function () use ($repository, $site): void {
            $repository->save(new Site($site, 'Hotel de la guarda', SiteTimezone::fromString('Atlantic/Canary')));
        });

        Assert::fail('El repositorio ha guardado otra zona con registro horario.');
    } catch (SiteTimezoneLocked $locked) {
        expect($locked->parameters)->toBe(['timezone' => 'Europe/Madrid'])
            ->and($locked->getMessage())->toContain('from Europe/Madrid to Atlantic/Canary');
    }

    expect(siteGuardTimezone($site))->toBe('Europe/Madrid');
})->group('RN-23', 'RS-07');

it('el propietario de la tabla no pasa por la guarda: puede, y es un acto explicito', function (): void {
    $site = siteGuardSite();
    siteGuardEntry($site);

    $owner = SchemaOwner::connection();
    $owner->beginTransaction();

    try {
        $owner->update('UPDATE sites SET timezone = ? WHERE id = ?', ['Atlantic/Canary', $site]);

        /** @var object{timezone: string} $row */
        $row = $owner->selectOne('SELECT timezone FROM sites WHERE id = ?', [$site]);

        expect($row->timezone)->toBe('Atlantic/Canary');
    } finally {
        $owner->rollBack();
    }

    expect(siteGuardTimezone($site))->toBe('Europe/Madrid');
})->group('RN-23', 'RS-07');

it('la funcion de la correccion cambia la zona con registro horario, que es su unico caso', function (): void {
    $site = siteGuardSite();
    siteGuardEntry($site);

    siteGuardCorrect($site, 'Europe/Madrid', 'Atlantic/Canary');

    expect(siteGuardTimezone($site))->toBe('Atlantic/Canary');

    // La misma zona no hace nada, no falla y no deja traza.
    siteGuardCorrect($site, 'Atlantic/Canary', 'Atlantic/Canary');

    // Y la vuelta atras deja su propia fila: un A->B->A no se borra.
    siteGuardCorrect($site, 'Atlantic/Canary', 'Europe/Madrid');

    $traza = DB::table('site_timezone_changes')->orderBy('id')->get(['site_id', 'from_tz', 'to_tz', 'changed_at', 'txid']);

    expect(siteGuardTimezone($site))->toBe('Europe/Madrid')
        ->and($traza)->toHaveCount(2)
        ->and($traza->map(static fn (object $row): string => $row->site_id.':'.$row->from_tz.'->'.$row->to_tz)->all())
        ->toBe([$site.':Europe/Madrid->Atlantic/Canary', $site.':Atlantic/Canary->Europe/Madrid'])
        ->and($traza->every(static fn (object $row): bool => $row->changed_at !== null && $row->txid !== null))->toBeTrue();
})->group('RN-23', 'RL-04');

it('la traza de cambios de zona solo la lee la aplicacion: no la escribe, no la borra ni la vacia', function (string $statement): void {
    $site = siteGuardSite();
    siteGuardEntry($site);
    siteGuardCorrect($site, 'Europe/Madrid', 'Atlantic/Canary');

    expect(DB::table('site_timezone_changes')->count())->toBe(1);

    siteGuardExpectDenied($statement, static fn (ConnectionInterface $db): bool => $db->statement(str_replace(':site', (string) $site, $statement)), '42501', '');

    expect(DB::table('site_timezone_changes')->count())->toBe(1);
})->with([
    "INSERT INTO site_timezone_changes (site_id, from_tz, to_tz) VALUES (:site, 'Atlantic/Canary', 'Europe/Madrid')",
    "UPDATE site_timezone_changes SET to_tz = 'Europe/Lisbon'",
    'DELETE FROM site_timezone_changes',
    'TRUNCATE site_timezone_changes',
    "SELECT nextval('site_timezone_changes_id_seq')",
    'CREATE TRIGGER x AFTER INSERT ON site_timezone_changes FOR EACH ROW EXECUTE FUNCTION public.sites_guard_timezone()',
])->group('RN-23', 'RS-07');

it('la funcion se niega sin registro horario, con otra zona de partida o sin centro', function (): void {
    $site = siteGuardSite();

    siteGuardExpectDenied(
        'corregir sin tramos',
        static fn (ConnectionInterface $db) => siteGuardCorrect($site, 'Europe/Madrid', 'Atlantic/Canary', $db),
        '42501',
        'no tiene registro horario',
    );

    siteGuardEntry($site);

    siteGuardExpectDenied(
        'corregir desde una zona que no es la del centro',
        static fn (ConnectionInterface $db) => siteGuardCorrect($site, 'Europe/Lisbon', 'Atlantic/Canary', $db),
        'P0002',
        'no Europe/Lisbon',
    );

    siteGuardExpectDenied(
        'corregir un centro que no existe',
        static fn (ConnectionInterface $db) => siteGuardCorrect($site + 1000, 'Europe/Madrid', 'Atlantic/Canary', $db),
        'P0002',
        'no existe el centro',
    );

    siteGuardExpectDenied(
        'corregir sin zona nueva',
        static fn (ConnectionInterface $db) => siteGuardCorrect($site, 'Europe/Madrid', '', $db),
        '22023',
        'faltan',
    );

    expect(siteGuardTimezone($site))->toBe('Europe/Madrid');
})->group('RN-23', 'RS-07');

it('la funcion es SECURITY DEFINER del rol de migracion, con search_path, y solo la ejecuta la aplicacion', function (): void {
    $application = Config::string('database.roles.application');
    $maintenance = Config::string('database.roles.maintenance', 'fichaje_maintenance');

    $can = static function (string $role, string $function): bool {
        /** @var object{granted: bool} $row */
        $row = DB::selectOne('SELECT has_function_privilege(?, ?, \'EXECUTE\') AS granted', [$role, 'public.'.$function]);

        return (bool) $row->granted;
    };

    expect($can($application, 'site_correct_timezone(bigint, text, text)'))->toBeTrue()
        ->and($can('public', 'site_correct_timezone(bigint, text, text)'))->toBeFalse()
        ->and($can($maintenance, 'site_correct_timezone(bigint, text, text)'))->toBeFalse()
        ->and($can('public', 'sites_guard_timezone()'))->toBeFalse()
        ->and($can($application, 'sites_guard_timezone()'))->toBeFalse();

    /** @var object{definer: bool, owner: string, config: string} $function */
    $function = DB::selectOne(<<<'SQL'
        SELECT p.prosecdef AS definer, pg_get_userbyid(p.proowner) AS owner, array_to_string(p.proconfig, ';') AS config
          FROM pg_proc p
         WHERE p.oid = 'public.site_correct_timezone(bigint, text, text)'::regprocedure
        SQL);

    expect($function->definer)->toBeTrue()
        ->and($function->owner)->toBe(Config::string('database.roles.migration'))
        ->and($function->config)->toContain('search_path=pg_catalog, pg_temp');

    // La del *trigger* NO es SECURITY DEFINER: si lo fuera, `current_user`
    // seria siempre el propietario y no vigilaria nada.
    /** @var object{definer: bool} $guard */
    $guard = DB::selectOne("SELECT p.prosecdef AS definer FROM pg_proc p WHERE p.oid = 'public.sites_guard_timezone()'::regprocedure");

    expect($guard->definer)->toBeFalse()
        ->and(siteGuardTriggers(DB::connection()))->toContain('zz_sites_guard_timezone');

    // BEFORE UPDATE de fila y sin lista de columnas (`OF timezone`): lo filtra
    // el IS DISTINCT FROM. Y es el ultimo de los BEFORE de `sites` por nombre.
    /** @var object{columns: int|string, timing: bool} $trigger */
    $trigger = DB::selectOne("SELECT cardinality(g.tgattr::int2[]) AS columns, (g.tgtype & 2) <> 0 AS timing FROM pg_trigger g WHERE g.tgrelid = 'public.sites'::regclass AND g.tgname = 'zz_sites_guard_timezone'");

    expect((int) $trigger->columns)->toBe(0)
        ->and($trigger->timing)->toBeTrue()
        ->and(siteGuardTriggers(DB::connection()))->toBe(['zz_sites_guard_timezone']);

    /** @var object{owner: string, writes: bool, reads: bool} $trace */
    $trace = DB::selectOne(<<<'SQL'
        SELECT pg_get_userbyid(c.relowner) AS owner,
               has_table_privilege(current_user, c.oid, 'INSERT') OR has_table_privilege(current_user, c.oid, 'UPDATE')
                   OR has_table_privilege(current_user, c.oid, 'DELETE') OR has_table_privilege(current_user, c.oid, 'TRUNCATE') AS writes,
               has_table_privilege(current_user, c.oid, 'SELECT') AS reads
          FROM pg_class c WHERE c.oid = 'public.site_timezone_changes'::regclass
        SQL);

    expect($trace->owner)->toBe(Config::string('database.roles.migration'))
        ->and($trace->writes)->toBeFalse()
        ->and($trace->reads)->toBeTrue();
})->group('RN-23', 'RS-07');

it('la aplicacion no puede desactivar, soltar ni reescribir la guarda', function (string $statement): void {
    siteGuardExpectDenied($statement, static fn (ConnectionInterface $db): bool => $db->statement($statement), '42501', '');
})->with([
    'ALTER TABLE sites DISABLE TRIGGER zz_sites_guard_timezone',
    'DROP TRIGGER zz_sites_guard_timezone ON sites',
    'CREATE OR REPLACE FUNCTION public.sites_guard_timezone() RETURNS trigger LANGUAGE plpgsql AS $x$ BEGIN RETURN NEW; END; $x$',
    'ALTER FUNCTION public.site_correct_timezone(bigint, text, text) SECURITY INVOKER',
])->group('RN-23', 'RS-07');

it('su down() suelta el trigger, las funciones y la traza, y su up() los vuelve a poner', function (): void {
    $name = config()->string('database.migrations.connection');
    $migrator = DB::connection($name);

    /** @var list<string> $applied */
    $applied = $migrator->table('migrations')->orderByDesc('id')->pluck('migration')->all();
    $steps = array_search(SITE_TIMEZONE_GUARD_MIGRATION, $applied, true);

    expect($steps)->toBeInt();
    \assert(is_int($steps));

    try {
        [$back, $backOutput] = Commands::run('migrate:rollback --database='.$name.' --step='.($steps + 1));

        expect($back)->toBe(0, $backOutput)
            ->and(siteGuardFunctionExists($migrator, 'site_correct_timezone(bigint, text, text)'))->toBeFalse()
            ->and(siteGuardFunctionExists($migrator, 'sites_guard_timezone()'))->toBeFalse()
            ->and(siteGuardTriggers($migrator))->not->toContain('zz_sites_guard_timezone')
            ->and($migrator->getSchemaBuilder()->hasTable('site_timezone_changes'))->toBeFalse();
    } finally {
        [$forward, $forwardOutput] = Commands::run('migrate --database='.$name);
    }

    expect($forward)->toBe(0, $forwardOutput)
        ->and(siteGuardFunctionExists($migrator, 'site_correct_timezone(bigint, text, text)'))->toBeTrue()
        ->and(siteGuardFunctionExists($migrator, 'sites_guard_timezone()'))->toBeTrue()
        ->and(siteGuardTriggers($migrator))->toContain('zz_sites_guard_timezone')
        ->and($migrator->getSchemaBuilder()->hasTable('site_timezone_changes'))->toBeTrue();
})->group('RN-23', 'RF-PD-10');
