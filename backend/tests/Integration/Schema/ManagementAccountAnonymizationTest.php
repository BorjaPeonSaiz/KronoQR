<?php

declare(strict_types=1);

use App\Modules\Compliance\Infrastructure\Persistence\DatabaseManagementAccountArchive;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Database\SchemaOwner;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * La barrera de la minimizacion de cuentas de gestion en el motor (ADR-060,
 * tarea 11.3 B; RL-11, RS-07): `deactivated_at`, los dos `CHECK`, el *trigger*
 * que congela una cuenta minimizada y la funcion `SECURITY DEFINER` que es la
 * unica que la minimiza, con su propio corte.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    WorkforceFixtures::site();
});

const ACCOUNT_ANONYMIZATION_MIGRATION = '2026_10_21_100100_minimise_management_accounts_after_retention';

/** El corte admisible hoy, con el reloj de la base de datos y los años del perfil (4). */
function cuentaCorte(int $years = 4): string
{
    /** @var object{today: string} $row */
    $row = DB::selectOne("SELECT ((now() AT TIME ZONE 'UTC')::date)::text AS today");

    // La aritmetica de `RetentionPolicy::workRecordCutoff()` y de la funcion.
    return (new DateTimeImmutable($row->today.' 00:00:00', new DateTimeZone('UTC')))
        ->sub(new DateInterval('P'.$years.'Y'))
        ->format('Y-m-d');
}

/** Una cuenta dada de baja en `$on`, sin asientos suyos. */
function cuentaDeBaja(string $on = '2020-01-15 10:00:00+00'): int
{
    $user = ManagementUsers::withRole(UserRole::RRHH);
    DB::table('users')->where('id', $user->id)->update(['is_active' => false, 'deactivated_at' => $on]);

    return $user->id;
}

/**
 * @return list<object{user_id: int|string, user_uuid: string, deactivated_on: string, tokens: int|string}>
 */
function cuentaMinimizar(string $cutoff, int $batch = 100): array
{
    /** @var list<object{user_id: int|string, user_uuid: string, deactivated_on: string, tokens: int|string}> $rows */
    $rows = DB::select(
        'SELECT user_id, user_uuid::text AS user_uuid, deactivated_on::text AS deactivated_on, tokens FROM public.'
        .DatabaseManagementAccountArchive::ANONYMIZE_FUNCTION.'(?::date, ?::integer)',
        [$cutoff, $batch],
    );

    return $rows;
}

function cuentaRechazo(Closure $call): string
{
    try {
        DB::transaction(static function () use ($call): void {
            $call();
        });
    } catch (PDOException $exception) {
        return (string) $exception->getCode();
    }

    Assert::fail('Se ha aceptado lo que la barrera tenia que rechazar.');
}

it('minimiza la cuenta vencida, borra sus tokens y deja la forma del CHECK', function (): void {
    $id = cuentaDeBaja();
    $uuid = DB::table('users')->where('id', $id)->value('uuid');
    $uuid = is_string($uuid) ? $uuid : '';
    DB::table('personal_access_tokens')->insert([
        'tokenable_type' => 'App\Modules\Identity\Infrastructure\Persistence\User',
        'tokenable_id' => $id,
        'name' => 'panel',
        'token' => hash('sha256', 'cuenta'),
        'abilities' => '["*"]',
        'created_at' => '2020-01-01 00:00:00+00',
        'updated_at' => '2020-01-01 00:00:00+00',
    ]);

    $rows = cuentaMinimizar(cuentaCorte());

    expect($rows)->toHaveCount(1)
        ->and((int) $rows[0]->user_id)->toBe($id)
        ->and($rows[0]->user_uuid)->toBe($uuid)
        ->and($rows[0]->deactivated_on)->toBe('2020-01-15')
        ->and((int) $rows[0]->tokens)->toBe(1);

    /** @var object{name: string, email: string, password: string, anonymized_at: string|null, two_factor_secret: string|null} $fila */
    $fila = DB::table('users')->where('id', $id)->first();

    expect($fila->name)->toBe('')
        ->and($fila->email)->toBe('anonymized+'.$uuid.'@invalid')
        ->and($fila->password)->toBe('!')
        ->and($fila->anonymized_at)->not->toBeNull()
        ->and(DB::table('personal_access_tokens')->where('tokenable_id', $id)->count())->toBe(0);
})->group('RL-11', 'RS-07');

it('se salta las cuentas activas, las que tienen asientos vivos y las que corrigieron o resolvieron', function (): void {
    $activa = ManagementUsers::withRole(UserRole::RRHH)->id;
    $reciente = cuentaDeBaja((new DateTimeImmutable('now'))->format('Y-m-d').' 00:00:00+00');
    $conAsiento = cuentaDeBaja();
    $vencida = cuentaDeBaja();

    DB::table('audit_log')->insert([
        'occurred_at' => now(),
        'actor_type' => 'user',
        'actor_id' => $conAsiento,
        'action' => 'auth.logout',
        'payload' => '{}',
        'prev_hash' => str_repeat('0', 64),
        'hash' => str_repeat('a', 64),
    ]);

    $minimizadas = array_map(static fn (object $row): int => (int) $row->user_id, cuentaMinimizar(cuentaCorte()));

    expect($minimizadas)->toBe([$vencida])
        ->and(DB::table('users')->whereIn('id', [$activa, $reciente, $conAsiento])->whereNotNull('anonymized_at')->count())->toBe(0);
})->group('RL-11', 'RS-07');

it('rechaza un corte posterior al admisible y un lote fuera de rango', function (): void {
    cuentaDeBaja();
    $tarde = (new DateTimeImmutable(cuentaCorte()))->modify('+1 day')->format('Y-m-d');

    expect(cuentaRechazo(static fn () => cuentaMinimizar($tarde)))->toBe('42501')
        ->and(cuentaRechazo(static fn () => cuentaMinimizar(cuentaCorte(), 0)))->toBe('22023')
        ->and(DB::table('users')->whereNotNull('anonymized_at')->count())->toBe(0);
})->group('RL-11', 'RS-07');

it('una cuenta minimizada queda congelada y la aplicacion no escribe anonymized_at', function (): void {
    $minimizada = cuentaDeBaja();
    cuentaMinimizar(cuentaCorte());
    $otra = ManagementUsers::withRole(UserRole::AUDITOR)->id;

    expect(cuentaRechazo(static fn () => DB::table('users')->where('id', $minimizada)->update(['name' => 'De vuelta'])))->toBe('42501')
        ->and(cuentaRechazo(static fn () => DB::table('users')->where('id', $otra)->update(['anonymized_at' => now()])))->toBe('42501');
})->group('RL-11', 'RS-07');

it('la baja sin fecha la sella el motor y una cuenta inactiva siempre tiene fecha', function (): void {
    $id = ManagementUsers::withRole(UserRole::RRHH)->id;

    DB::table('users')->where('id', $id)->update(['is_active' => false]);

    expect(DB::table('users')->where('id', $id)->value('deactivated_at'))->not->toBeNull();
})->group('RL-11', 'RF-ID-10');

it('el CHECK no admite una cuenta marcada como minimizada con sus datos dentro', function (): void {
    $owner = SchemaOwner::connection();
    $owner->beginTransaction();

    try {
        $owner->statement("SET LOCAL lock_timeout = '5s'");
        $id = $owner->table('users')->insertGetId([
            'uuid' => (string) Str::uuid7(),
            'name' => 'Con nombre',
            'email' => 'con-nombre@hotel.example',
            'password' => 'x',
            'is_active' => false,
        ]);

        $owner->statement('SAVEPOINT intento');

        try {
            $owner->table('users')->where('id', $id)->update(['anonymized_at' => now()]);
            Assert::fail('El CHECK ha admitido una cuenta minimizada con su nombre.');
        } catch (PDOException $exception) {
            expect((string) $exception->getCode())->toBe('23514');
        }
    } finally {
        $owner->rollBack();
    }
})->group('RL-11', 'RS-07');

it('la ejecuta la aplicacion y nadie mas, como propietario y con el search_path cerrado', function (): void {
    $application = Config::string('database.roles.application');

    $can = static function (string $role, string $function): bool {
        /** @var object{granted: bool} $row */
        $row = DB::selectOne("SELECT has_function_privilege(?, ?, 'EXECUTE') AS granted", [$role, 'public.'.$function]);

        return (bool) $row->granted;
    };

    expect($can($application, 'management_account_anonymize_batch(date, integer)'))->toBeTrue()
        ->and($can('public', 'management_account_anonymize_batch(date, integer)'))->toBeFalse()
        ->and($can('public', 'management_account_retention_candidates(date)'))->toBeFalse()
        ->and($can('public', 'users_guard_anonymized()'))->toBeFalse()
        ->and($can('public', 'users_stamp_deactivation()'))->toBeFalse();

    /** @var object{definer: bool, owner: string, config: string} $function */
    $function = DB::selectOne(<<<'SQL'
        SELECT p.prosecdef AS definer, pg_get_userbyid(p.proowner) AS owner, array_to_string(p.proconfig, ';') AS config
          FROM pg_proc p
         WHERE p.oid = 'public.management_account_anonymize_batch(date, integer)'::regprocedure
        SQL);

    expect($function->definer)->toBeTrue()
        ->and($function->owner)->toBe(Config::string('database.roles.migration'))
        ->and($function->config)->toContain('search_path=pg_catalog, pg_temp')
        ->and($function->config)->toContain('lock_timeout=5s');
})->group('RL-11', 'RS-07');

it('una tabla temporal de la aplicacion no la desvia', function (): void {
    $vencida = cuentaDeBaja();

    DB::statement('CREATE TEMP TABLE users (id bigint, is_active boolean)');
    DB::statement('CREATE TEMP TABLE audit_log (actor_type text, actor_id bigint, subject_type text, subject_id bigint)');

    expect(array_map(static fn (object $row): int => (int) $row->user_id, cuentaMinimizar(cuentaCorte())))->toBe([$vencida]);
})->group('RL-11', 'RS-07');

it('rellena la fecha de baja con el ultimo asiento user.deactivated', function (): void {
    $migration = require database_path('migrations/'.ACCOUNT_ANONYMIZATION_MIGRATION.'.php');
    $owner = SchemaOwner::connection();
    $owner->beginTransaction();

    try {
        $owner->statement("SET LOCAL lock_timeout = '5s'");
        $owner->statement('ALTER TABLE users DROP CONSTRAINT users_chk_deactivated_has_date');
        $owner->statement('ALTER TABLE users DISABLE TRIGGER users_stamp_deactivation');

        $id = $owner->table('users')->insertGetId([
            'uuid' => (string) Str::uuid7(),
            'name' => 'De baja antigua',
            'email' => 'baja-antigua@hotel.example',
            'password' => 'x',
            'is_active' => false,
            'updated_at' => '2024-05-05 00:00:00+00',
        ]);
        $sinAsiento = $owner->table('users')->insertGetId([
            'uuid' => (string) Str::uuid7(),
            'name' => 'Sin asiento',
            'email' => 'sin-asiento@hotel.example',
            'password' => 'x',
            'is_active' => false,
            'updated_at' => '2023-03-03 00:00:00+00',
        ]);

        foreach (['2026-01-02 10:00:00+00', '2026-02-02 10:00:00+00'] as $n => $at) {
            $owner->table('audit_log')->insert([
                'occurred_at' => $at,
                'actor_type' => 'system',
                'action' => 'user.deactivated',
                'subject_type' => 'user',
                'subject_id' => $id,
                'payload' => '{}',
                'prev_hash' => str_repeat('0', 64),
                'hash' => str_repeat((string) $n, 64),
            ]);
        }

        $owner->statement($migration::BACKFILL_DEACTIVATED_AT);

        expect(substr(cuentaTexto($owner->table('users')->where('id', $id)->value('deactivated_at')), 0, 10))->toBe('2026-02-02')
            ->and(substr(cuentaTexto($owner->table('users')->where('id', $sinAsiento)->value('deactivated_at')), 0, 10))->toBe('2023-03-03');
    } finally {
        $owner->rollBack();
    }
})->group('RL-11', 'RF-ID-10');

it('su down() se niega con cuentas ya minimizadas y no toca nada', function (): void {
    cuentaDeBaja();
    cuentaMinimizar(cuentaCorte());

    $migration = require database_path('migrations/'.ACCOUNT_ANONYMIZATION_MIGRATION.'.php');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'ya minimizadas');

    expect(DB::table('users')->whereNotNull('anonymized_at')->count())->toBe(1);
})->group('RL-11');

function cuentaTexto(mixed $value): string
{
    return \is_scalar($value) ? (string) $value : '';
}
