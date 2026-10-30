<?php

declare(strict_types=1);

use App\Modules\Compliance\Infrastructure\Persistence\DatabasePersonnelRecordArchive;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Database\SchemaOwner;
use Tests\Support\Identity\ManagementUsers;

/*
 * La barrera de la minimizacion de fichas en el motor (ADR-060, tarea 11.3 A;
 * RL-11, RL-02, RL-08, RS-07): el `CHECK`, el *trigger* que congela una ficha
 * minimizada, la funcion `SECURITY DEFINER` que es la unica que la minimiza y
 * su corte, y el `DELETE` retirado sobre contratos y ausencias.
 */

uses(RefreshDatabase::class);

const PERSONNEL_ANONYMIZATION_MIGRATION = '2026_10_21_100300_minimise_personnel_records_after_retention';

/** El corte admisible hoy con los 4 años de serie, con el reloj de la base de datos. */
function fichaCorte(int $years = 4, ?ConnectionInterface $connection = null): string
{
    /** @var object{today: string} $row */
    $row = ($connection ?? DB::connection())->selectOne("SELECT ((now() AT TIME ZONE 'UTC')::date)::text AS today");

    return (new DateTimeImmutable($row->today.' 00:00:00', new DateTimeZone('UTC')))
        ->sub(new DateInterval('P'.$years.'Y'))
        ->format('Y-m-d');
}

/**
 * Una persona dada de baja el `$cese`, con un contrato, dos versiones de una
 * ausencia, una sesion del portal y foto.
 *
 * @return array{id: int, uuid: string}
 */
function fichaDeBaja(string $cese = '2015-06-30', string $status = 'terminated', ?ConnectionInterface $connection = null): array
{
    $db = $connection ?? DB::connection();
    $site = $db->table('sites')->value('id');
    $site = \is_int($site) ? $site : 0;

    if ($site === 0) {
        $site = (int) $db->table('sites')->insertGetId(['name' => 'Centro de prueba', 'timezone' => 'Europe/Madrid']);
    }
    $uuid = Str::uuid7()->toString();

    $id = (int) $db->table('employees')->insertGetId([
        'uuid' => $uuid,
        'site_id' => $site,
        'first_name' => 'Lucia',
        'last_name' => 'Ferrer',
        'employee_code' => 'E'.Str::upper(Str::random(9)),
        'email' => 'lucia.'.Str::lower(Str::random(6)).'@hotel.example',
        'national_id_hash' => DB::raw("decode(md5(random()::text) || md5(random()::text), 'hex')"),
        'photo_path' => 'fotos/'.$uuid.'.jpg',
        'status' => $status,
        'hired_at' => '2010-01-01',
        'terminated_at' => $status === 'terminated' ? $cese : null,
        'locale' => 'es',
        'pin_hash' => 'x',
        'pin_issued_at' => '2010-01-02 00:00:00+00',
        'pin_length' => 6,
    ]);

    $db->table('employment_contracts')->insert([
        'employee_id' => $id,
        'weekly_hours' => 40,
        'schedule_type' => 'continua',
        'valid_from' => '2010-01-01',
        'created_at' => '2010-01-01 00:00:00+00',
    ]);

    foreach (['2014-08-01', '2014-09-01'] as $inicio) {
        $db->table('absences')->insert([
            'uuid' => Str::uuid7()->toString(),
            'employee_id' => $id,
            'type' => 'vacation',
            'starts_on' => $inicio,
            'ends_on' => $inicio,
            'created_at' => '2014-07-01 00:00:00+00',
        ]);
    }

    $db->table('personal_access_tokens')->insert([
        'tokenable_type' => 'App\Modules\Workforce\Infrastructure\Persistence\Employee',
        'tokenable_id' => $id,
        'name' => 'portal',
        'token' => hash('sha256', $uuid),
        'abilities' => '["self:read"]',
        'created_at' => '2015-01-01 00:00:00+00',
        'updated_at' => '2015-01-01 00:00:00+00',
    ]);

    return ['id' => $id, 'uuid' => $uuid];
}

/**
 * @return list<object{employee_uuid: string, terminated_on: string, contracts: int|string, absences: int|string, tokens: int|string, photo_path: string|null}>
 */
function fichaMinimizar(string $cutoff, int $batch = 100, ?ConnectionInterface $connection = null): array
{
    /** @var list<object{employee_uuid: string, terminated_on: string, contracts: int|string, absences: int|string, tokens: int|string, photo_path: string|null}> $rows */
    $rows = ($connection ?? DB::connection())->select(
        'SELECT employee_uuid::text AS employee_uuid, terminated_on::text AS terminated_on, contracts, absences, tokens, photo_path'
        .' FROM public.'.DatabasePersonnelRecordArchive::ANONYMIZE_FUNCTION.'(?::date, ?::integer,'
        .' ARRAY(SELECT c.id FROM public.personnel_record_retention_candidates(?::date) c))',
        [$cutoff, $batch, $cutoff],
    );

    return $rows;
}

function fichaRechazo(Closure $call): string
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

function fichaTexto(mixed $value): string
{
    return \is_scalar($value) ? (string) $value : '';
}

it('minimiza la ficha vencida, borra contratos, ausencias y sesiones y devuelve la foto', function (): void {
    $ficha = fichaDeBaja();

    $rows = fichaMinimizar(fichaCorte());

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->employee_uuid)->toBe($ficha['uuid'])
        ->and($rows[0]->terminated_on)->toBe('2015-06-30')
        ->and((int) $rows[0]->contracts)->toBe(1)
        ->and((int) $rows[0]->absences)->toBe(2)
        ->and((int) $rows[0]->tokens)->toBe(1)
        ->and($rows[0]->photo_path)->toBe('fotos/'.$ficha['uuid'].'.jpg');

    /** @var object{first_name: string, last_name: string, employee_code: string, email: string|null, national_id_hash: mixed, photo_path: string|null, pin_hash: string|null, anonymized_at: string|null, status: string, terminated_at: string} $fila */
    $fila = DB::table('employees')->where('id', $ficha['id'])->first();

    expect($fila->first_name)->toBe('')
        ->and($fila->last_name)->toBe('')
        ->and($fila->employee_code)->toBe(str_replace('-', '', $ficha['uuid']))
        ->and($fila->email)->toBeNull()
        ->and($fila->national_id_hash)->toBeNull()
        ->and($fila->photo_path)->toBeNull()
        ->and($fila->pin_hash)->toBeNull()
        ->and($fila->anonymized_at)->not->toBeNull()
        ->and($fila->status)->toBe('terminated')
        ->and($fila->terminated_at)->toBe('2015-06-30')
        ->and(DB::table('employment_contracts')->where('employee_id', $ficha['id'])->count())->toBe(0)
        ->and(DB::table('absences')->where('employee_id', $ficha['id'])->count())->toBe(0)
        ->and(DB::table('personal_access_tokens')->where('tokenable_id', $ficha['id'])->where('name', 'portal')->count())->toBe(0);
})->group('RL-11', 'RL-08', 'RS-07');

it('se salta a quien no ha cumplido el plazo, a quien sigue de alta o suspendido y a quien conserva registro', function (): void {
    $reciente = fichaDeBaja((new DateTimeImmutable(fichaCorte()))->modify('+1 day')->format('Y-m-d'));
    $activa = fichaDeBaja(status: 'active');
    $suspendida = fichaDeBaja(status: 'suspended');
    $conRegistro = fichaDeBaja('2015-03-31');
    $vencida = fichaDeBaja('2015-03-31');

    DB::table('daily_totals')->insert([
        'employee_id' => $conRegistro['id'],
        'work_date' => '2015-03-30',
        'total_minutes' => 480,
        'shift_count' => 1,
        'recalculated_at' => '2015-03-30 23:00:00+00',
    ]);

    expect(array_map(static fn (object $row): string => $row->employee_uuid, fichaMinimizar(fichaCorte())))->toBe([$vencida['uuid']])
        ->and(DB::table('employees')->whereIn('id', [$reciente['id'], $activa['id'], $suspendida['id'], $conRegistro['id']])->whereNotNull('anonymized_at')->count())->toBe(0);
})->group('RL-11', 'RL-02', 'RS-07');

it('se salta a quien tiene viva la particion de audit_log del año de su baja', function (): void {
    $owner = SchemaOwner::connection();
    $owner->beginTransaction();

    try {
        $owner->statement("SET LOCAL lock_timeout = '5s'");
        $owner->statement("CREATE TABLE IF NOT EXISTS public.audit_log_2015 PARTITION OF public.audit_log FOR VALUES FROM ('2015-01-01') TO ('2016-01-01')");
        $viva = fichaDeBaja('2015-06-30', connection: $owner);
        $soltada = fichaDeBaja('2014-06-30', connection: $owner);

        $minimizadas = array_map(static fn (object $row): string => $row->employee_uuid, fichaMinimizar(fichaCorte(connection: $owner), connection: $owner));

        expect($minimizadas)->toBe([$soltada['uuid']])
            ->and($owner->table('employees')->where('id', $viva['id'])->value('anonymized_at'))->toBeNull();
    } finally {
        $owner->rollBack();
    }
})->group('RL-11', 'RL-02', 'RS-07');

it('rechaza un corte posterior al admisible y un lote fuera de rango', function (): void {
    fichaDeBaja();
    $tarde = (new DateTimeImmutable(fichaCorte()))->modify('+1 day')->format('Y-m-d');

    expect(fichaRechazo(static fn () => fichaMinimizar($tarde)))->toBe('42501')
        ->and(fichaRechazo(static fn () => fichaMinimizar(fichaCorte(), 0)))->toBe('22023')
        ->and(DB::table('employees')->whereNotNull('anonymized_at')->count())->toBe(0);
})->group('RL-11', 'RS-07');

it('calcula el corte con los años de la ficha del perfil', function (): void {
    fichaDeBaja((new DateTimeImmutable(fichaCorte(6)))->format('Y-m-d'));
    DB::table('compliance_profiles')->where('is_default', true)->update(['personnel_record_retention_years' => 6]);

    // Con 6 años, el corte de 4 ya es posterior al admisible.
    expect(fichaRechazo(static fn () => fichaMinimizar(fichaCorte(4))))->toBe('42501')
        ->and(fichaMinimizar(fichaCorte(6)))->toHaveCount(1);
})->group('RL-11', 'RS-07');

it('una ficha minimizada queda congelada y la aplicacion no escribe anonymized_at', function (): void {
    $minimizada = fichaDeBaja();
    fichaMinimizar(fichaCorte());
    $otra = fichaDeBaja(status: 'active');

    expect(fichaRechazo(static fn () => DB::table('employees')->where('id', $minimizada['id'])->update(['first_name' => 'De vuelta'])))->toBe('42501')
        ->and(fichaRechazo(static fn () => DB::table('employees')->where('id', $otra['id'])->update(['anonymized_at' => now()])))->toBe('42501');
})->group('RL-11', 'RS-07');

it('la aplicacion ya no borra contratos, ausencias, fichas ni cuentas de gestion', function (string $table): void {
    fichaDeBaja(status: 'active');

    expect(fichaRechazo(static fn () => DB::table($table)->delete()))->toBe('42501');
})->with(['employment_contracts', 'absences', 'employees', 'users'])->group('RL-11', 'RS-07');

it('solo minimiza las fichas de la lista que conto el plan, y sin lista no minimiza ninguna', function (): void {
    $contada = fichaDeBaja('2015-03-31');
    $liberada = fichaDeBaja('2015-04-30');

    /** @var list<object{employee_uuid: string}> $rows */
    $rows = DB::select(
        'SELECT employee_uuid::text AS employee_uuid FROM public.'.DatabasePersonnelRecordArchive::ANONYMIZE_FUNCTION
        .'(?::date, 100, ARRAY[?]::bigint[])',
        [fichaCorte(), $contada['id']],
    );

    expect(array_map(static fn (object $row): string => $row->employee_uuid, $rows))->toBe([$contada['uuid']])
        ->and(DB::table('employees')->where('id', $liberada['id'])->value('anonymized_at'))->toBeNull()
        ->and(fichaRechazo(static fn () => DB::select(
            'SELECT * FROM public.'.DatabasePersonnelRecordArchive::ANONYMIZE_FUNCTION.'(?::date, 100, NULL)',
            [fichaCorte()],
        )))->toBe('22023');
})->group('RL-11', 'RS-07');

it('sustituye el motivo de revocar sus tarjetas por uno fijo y deja el resto de la tarjeta', function (): void {
    $ficha = fichaDeBaja();

    DB::table('credentials')->insert([
        'uuid' => Str::uuid7()->toString(),
        'employee_id' => $ficha['id'],
        'key_id' => 'a3',
        'secret_hash' => hash('sha256', $ficha['uuid']),
        'issued_at' => '2014-01-01 00:00:00+00',
        'printed_at' => '2014-01-01 00:00:00+00',
        'revoked_at' => '2015-06-30 10:00:00+00',
        'revoked_reason' => 'Lucia Ferrer perdio la tarjeta en el vestuario',
    ]);

    $migration = require database_path('migrations/'.PERSONNEL_ANONYMIZATION_MIGRATION.'.php');

    fichaMinimizar(fichaCorte());

    /** @var object{revoked_reason: string, revoked_at: string, key_id: string} $tarjeta */
    $tarjeta = DB::table('credentials')->where('employee_id', $ficha['id'])->first();

    expect($tarjeta->revoked_reason)->toBe($migration::CREDENTIAL_REASON)
        ->and($tarjeta->revoked_at)->not->toBeNull()
        ->and($tarjeta->key_id)->toBe('a3');
})->group('RL-11', 'RL-08');

it('una ficha minimizada no recibe contratos ni ausencias nuevos', function (string $table): void {
    $ficha = fichaDeBaja();
    fichaMinimizar(fichaCorte());

    $row = $table === 'absences'
        ? ['uuid' => Str::uuid7()->toString(), 'employee_id' => $ficha['id'], 'type' => 'vacation', 'starts_on' => '2015-05-01', 'ends_on' => '2015-05-01', 'created_at' => '2026-10-22 00:00:00+00']
        : ['employee_id' => $ficha['id'], 'weekly_hours' => 40, 'schedule_type' => 'continua', 'valid_from' => '2015-01-01', 'created_at' => '2026-10-22 00:00:00+00'];

    expect(fichaRechazo(static fn () => DB::table($table)->insert($row)))->toBe('42501')
        ->and(DB::table($table)->where('employee_id', $ficha['id'])->count())->toBe(0);
})->with(['employment_contracts', 'absences'])->group('RL-11', 'RS-07');

it('el CHECK no admite una ficha marcada como minimizada con sus datos dentro', function (): void {
    $owner = SchemaOwner::connection();
    $owner->beginTransaction();

    try {
        $owner->statement("SET LOCAL lock_timeout = '5s'");
        $ficha = fichaDeBaja(connection: $owner);
        $owner->statement('SAVEPOINT intento');

        try {
            $owner->table('employees')->where('id', $ficha['id'])->update(['anonymized_at' => now()]);
            Assert::fail('El CHECK ha admitido una ficha minimizada con su nombre.');
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

    expect($can($application, 'personnel_record_anonymize_batch(date, integer, bigint[])'))->toBeTrue()
        ->and($can('public', 'personnel_record_anonymize_batch(date, integer, bigint[])'))->toBeFalse()
        ->and($can('public', 'personnel_record_retention_candidates(date)'))->toBeFalse()
        ->and($can('public', 'employees_guard_anonymized()'))->toBeFalse();

    /** @var object{definer: bool, owner: string, config: string} $function */
    $function = DB::selectOne(<<<'SQL'
        SELECT p.prosecdef AS definer, pg_get_userbyid(p.proowner) AS owner, array_to_string(p.proconfig, ';') AS config
          FROM pg_proc p
         WHERE p.oid = 'public.personnel_record_anonymize_batch(date, integer, bigint[])'::regprocedure
        SQL);

    expect($function->definer)->toBeTrue()
        ->and($function->owner)->toBe(Config::string('database.roles.migration'))
        ->and($function->config)->toContain('search_path=pg_catalog, pg_temp')
        ->and($function->config)->toContain('lock_timeout=5s');
})->group('RL-11', 'RS-07');

it('una tabla temporal de la aplicacion no la desvia', function (): void {
    $vencida = fichaDeBaja();

    DB::statement('CREATE TEMP TABLE employees (id bigint, status text)');
    DB::statement('CREATE TEMP TABLE shift_entries (employee_id bigint)');
    DB::statement('CREATE TEMP TABLE compliance_profiles (id bigint, personnel_record_retention_years integer, is_default boolean)');
    DB::statement('INSERT INTO pg_temp.compliance_profiles VALUES (1, 1, true)');

    // Con su perfil falso, un año valdria; la funcion sigue usando el de verdad.
    expect(fichaRechazo(static fn () => fichaMinimizar(fichaCorte(1))))->toBe('42501')
        ->and(array_map(static fn (object $row): string => $row->employee_uuid, fichaMinimizar(fichaCorte())))->toBe([$vencida['uuid']]);
})->group('RL-11', 'RS-07');

it('su down() se niega con fichas ya minimizadas y no toca nada', function (): void {
    fichaDeBaja();
    fichaMinimizar(fichaCorte());

    $migration = require database_path('migrations/'.PERSONNEL_ANONYMIZATION_MIGRATION.'.php');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'ya minimizadas');

    expect(DB::table('employees')->whereNotNull('anonymized_at')->count())->toBe(1)
        ->and(fichaTexto(DB::table('employees')->whereNotNull('anonymized_at')->value('first_name')))->toBe('');
})->group('RL-11');

it('sustituye el motivo de corregir la fecha de cese de sus periodos y conserva las fechas', function (): void {
    // ADR-060, ADR-062 §7: el motivo es texto libre de RRHH y pudo nombrar a la
    // persona; las fechas de la correccion, quien y cuando son registro (RN-13).
    $ficha = fichaDeBaja();
    $rrhh = ManagementUsers::withRole(UserRole::RRHH);

    $periodo = (int) DB::table('employment_periods')->insertGetId([
        'uuid' => Str::uuid7()->toString(),
        'employee_id' => $ficha['id'],
        'hired_on' => '2010-01-01',
        'terminated_on' => '2015-06-30',
        'offboarded_at' => '2015-07-01 00:00:00+00',
    ]);

    DB::table('employment_period_corrections')->insert([
        'employment_period_id' => $periodo,
        'previous_terminated_on' => '2015-06-15',
        'terminated_on' => '2015-06-30',
        'reason' => 'Lucia Ferrer trabajo hasta el 30, lo dice su jefa de recepcion',
        'corrected_by_user_id' => $rrhh->id,
        'corrected_at' => '2015-07-02 09:00:00+00',
    ]);

    $migration = require database_path('migrations/2026_10_30_110000_minimise_termination_correction_reasons.php');

    fichaMinimizar(fichaCorte());

    /** @var object{reason: string, previous_terminated_on: string, terminated_on: string, corrected_by_user_id: int} $correccion */
    $correccion = DB::table('employment_period_corrections')->where('employment_period_id', $periodo)->first();

    expect($correccion->reason)->toBe($migration::CORRECTION_REASON)
        ->and($correccion->previous_terminated_on)->toBe('2015-06-15')
        ->and($correccion->terminated_on)->toBe('2015-06-30')
        ->and($correccion->corrected_by_user_id)->toBe($rrhh->id)
        ->and(DB::table('employees')->where('id', $ficha['id'])->value('anonymized_at'))->not->toBeNull();
})->group('RL-11', 'RL-08', 'RN-27');

it('una ficha minimizada no recibe periodos, ni cambia los suyos, ni admite correcciones de su cese', function (): void {
    // ADR-060, ADR-062 §6 y §7: la misma regla que el dominio, en el motor.
    $ficha = fichaDeBaja();
    $rrhh = ManagementUsers::withRole(UserRole::RRHH);

    $periodo = (int) DB::table('employment_periods')->insertGetId([
        'uuid' => Str::uuid7()->toString(),
        'employee_id' => $ficha['id'],
        'hired_on' => '2010-01-01',
        'terminated_on' => '2015-06-30',
        'offboarded_at' => '2015-07-01 00:00:00+00',
    ]);

    fichaMinimizar(fichaCorte());

    expect(fichaRechazo(static fn () => DB::table('employment_periods')->insert([
        'uuid' => Str::uuid7()->toString(),
        'employee_id' => $ficha['id'],
        'hired_on' => '2026-01-01',
    ])))->toBe('42501')
        ->and(fichaRechazo(static fn () => DB::table('employment_periods')->where('id', $periodo)->update(['terminated_on' => '2015-06-20'])))->toBe('42501')
        ->and(fichaRechazo(static fn () => DB::table('employment_period_corrections')->insert([
            'employment_period_id' => $periodo,
            'previous_terminated_on' => '2015-06-30',
            'terminated_on' => '2015-06-20',
            'reason' => 'Correccion de una ficha ya minimizada',
            'corrected_by_user_id' => $rrhh->id,
            'corrected_at' => '2026-10-30 09:00:00+00',
        ])))->toBe('42501')
        ->and(DB::table('employment_periods')->where('employee_id', $ficha['id'])->pluck('terminated_on')->all())->toBe(['2015-06-30'])
        ->and(DB::table('employment_period_corrections')->where('employment_period_id', $periodo)->count())->toBe(0);
})->group('RL-11', 'RS-07', 'RN-27');
