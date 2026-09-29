<?php

declare(strict_types=1);

use App\Modules\Compliance\Application\Exception\AuditPartitionCreationUnavailable;
use App\Modules\Compliance\Infrastructure\Persistence\AuditLogSchema;
use App\Modules\Compliance\Infrastructure\Persistence\DatabaseAuditLogPartitions;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;
use Tests\Support\Database\RefreshDatabase;

/*
 * `audit_log_create_partition` contra PostgreSQL de verdad (ADR-042, RS-07,
 * RL-04, regla dura 6).
 *
 * LO QUE SE AFIRMA. Que el rol de la aplicacion puede pedir la particion del año
 * en curso o del siguiente, que la particion nace exactamente igual que las
 * demas —propietario, permisos, indices y restricciones— y que por esa puerta
 * no cabe nada mas: ni otros años, ni años sellados, ni tablas homonimas, ni
 * desvios por `pg_temp`, ni DDL propio.
 *
 * EL PATRON: UNA TRANSACCION DEL PROPIETARIO CON `SET LOCAL ROLE`. Tras
 * `migrate:fresh` ya existen las particiones del año en curso y del siguiente,
 * que es exactamente la ventana de la funcion: desde la conexion de la
 * aplicacion solo se podria probar el `false` idempotente. Asi que cada prueba
 * abre una transaccion con el rol de migracion, suelta como propietario lo que
 * necesite (`audit_log_<y+1>`, la funcion…), cambia con `SET LOCAL ROLE` al rol
 * de la aplicacion —desde ahi PostgreSQL comprueba los privilegios contra el,
 * incluido el `EXECUTE`— y al final lo deshace todo. El DDL de PostgreSQL es
 * transaccional, y la base de pruebas compartida no se entera.
 *
 * LOS AÑOS SALEN DEL MOTOR y no de un reloj fijo: la ventana de la funcion usa
 * el `now()` de PostgreSQL.
 *
 * NO SE CONSULTA `audit_log` POR LA CONEXION POR DEFECTO antes de abrir la
 * transaccion del propietario: la de `RefreshDatabase` retendria el bloqueo y el
 * `DETACH` esperaria hasta agotar el `lock_timeout`.
 */

uses(RefreshDatabase::class);

/**
 * El año UTC segun PostgreSQL.
 */
function particionFuncionAnoDelMotor(): int
{
    /** @var object{y: int}|null $row */
    $row = DB::connection('pgsql_migrator')->selectOne(
        "SELECT extract(year FROM now() AT TIME ZONE 'UTC')::int AS y"
    );

    return (int) $row?->y;
}

/**
 * Ejecuta `$preparar` como propietario y `$probar` con el rol de la aplicacion,
 * en una sola transaccion del rol de migracion que se deshace al final.
 *
 * @template T
 *
 * @param  Closure(Connection): void  $preparar
 * @param  Closure(Connection): T  $probar
 * @return T
 */
function particionFuncionComoAplicacionEnTransaccionDelPropietario(Closure $preparar, Closure $probar): mixed
{
    $owner = DB::connection('pgsql_migrator');

    $owner->beginTransaction();

    try {
        $owner->statement("SET LOCAL lock_timeout = '5s'");
        // Los limites de particion se imprimen en la zona de la sesion.
        $owner->statement("SET LOCAL TIME ZONE 'UTC'");

        $preparar($owner);

        $owner->statement('SET LOCAL ROLE "'.AuditLogSchema::applicationRole().'"');

        return $probar($owner);
    } finally {
        $owner->rollBack();
    }
}

/**
 * Suelta la particion de un año como propietario.
 */
function particionFuncionSoltar(Connection $owner, int $year): void
{
    $partition = AuditLogSchema::partitionName($year);

    $owner->statement('ALTER TABLE public.audit_log DETACH PARTITION public.'.$partition);
    $owner->statement('DROP TABLE public.'.$partition);
}

function particionFuncionPedir(Connection $connection, ?int $year): bool
{
    /** @var object{created: bool|null}|null $row */
    $row = $connection->selectOne('SELECT public.'.AuditLogSchema::CREATE_FUNCTION.'(?::integer) AS created', [$year]);

    return $row?->created === true;
}

/**
 * El SQLSTATE con que falla `$intento`, dentro de un SAVEPOINT para que la
 * transaccion siga utilizable despues.
 *
 * @param  Closure(): void  $intento
 */
function particionFuncionCodigoDeError(Connection $connection, string $que, Closure $intento): string
{
    try {
        $connection->transaction(static function () use ($intento): void {
            $intento();
        });
    } catch (QueryException $exception) {
        return (string) $exception->getCode();
    }

    Assert::fail('PostgreSQL ha permitido «'.$que.'».');
}

/**
 * @return object{owner: string, acl: list<string>, parent_is_audit_log: bool, bound: string, schema: string}
 */
function particionFuncionFicha(Connection $connection, int $year): object
{
    /** @var object{owner: string, acl: string, parent_is_audit_log: bool, bound: string|null, schema: string}|null $row */
    $row = $connection->selectOne(<<<'SQL'
        SELECT pg_get_userbyid(c.relowner) AS owner,
               array_to_json(ARRAY(SELECT a::text FROM unnest(c.relacl) a ORDER BY 1)) AS acl,
               EXISTS (SELECT 1 FROM pg_inherits i
                        WHERE i.inhrelid = c.oid AND i.inhparent = 'public.audit_log'::regclass) AS parent_is_audit_log,
               pg_get_expr(c.relpartbound, c.oid) AS bound,
               n.nspname AS schema
          FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
         WHERE n.nspname = 'public' AND c.relname = ?
    SQL, [AuditLogSchema::partitionName($year)]);

    if ($row === null) {
        Assert::fail('No existe public.'.AuditLogSchema::partitionName($year).'.');
    }

    /** @var list<string> $acl */
    $acl = json_decode($row->acl, true, 512, JSON_THROW_ON_ERROR);

    return (object) [
        'owner' => $row->owner,
        'acl' => $acl,
        'parent_is_audit_log' => $row->parent_is_audit_log,
        'bound' => (string) $row->bound,
        'schema' => $row->schema,
    ];
}

// --- Crear ---------------------------------------------------------------------

it('crea con el rol de la aplicacion la particion del año siguiente, adjunta y con sus limites', function (): void {
    $next = particionFuncionAnoDelMotor() + 1;

    [$created, $ficha] = particionFuncionComoAplicacionEnTransaccionDelPropietario(
        static fn (Connection $owner) => particionFuncionSoltar($owner, $next),
        static fn (Connection $app): array => [particionFuncionPedir($app, $next), particionFuncionFicha($app, $next)],
    );

    expect($created)->toBeTrue()
        ->and($ficha->parent_is_audit_log)->toBeTrue()
        ->and($ficha->bound)->toContain("'".$next."-01-01 00:00:00+00'")
        ->and($ficha->bound)->toContain("'".($next + 1)."-01-01 00:00:00+00'");
})->group('RS-07', 'RL-04');

it('la particion nueva es del migrador y tiene los mismos permisos que audit_log_2026', function (): void {
    $next = particionFuncionAnoDelMotor() + 1;
    $application = Config::string('database.roles.application');
    $maintenance = Config::string('database.roles.maintenance');

    [$nueva, $referencia, $privilegios] = particionFuncionComoAplicacionEnTransaccionDelPropietario(
        static fn (Connection $owner) => particionFuncionSoltar($owner, $next),
        static function (Connection $app) use ($next, $application, $maintenance): array {
            particionFuncionPedir($app, $next);

            $relation = 'public.'.AuditLogSchema::partitionName($next);
            $privilegios = [];

            foreach ([$application, $maintenance] as $role) {
                foreach (['INSERT', 'SELECT', 'UPDATE', 'DELETE', 'TRUNCATE'] as $privilege) {
                    /** @var object{granted: bool}|null $row */
                    $row = $app->selectOne('SELECT has_table_privilege(?, ?, ?) AS granted', [$role, $relation, $privilege]);
                    $privilegios[$role][$privilege] = $row?->granted;
                }
            }

            return [
                particionFuncionFicha($app, $next),
                particionFuncionFicha($app, AuditLogSchema::FIRST_YEAR),
                $privilegios,
            ];
        },
    );

    expect($nueva->owner)->toBe(Config::string('database.roles.migration'))
        // Los ALTER DEFAULT PRIVILEGES del migrador tambien se aplican dentro de
        // la funcion: sin sus REVOKE, aqui apareceria `arwd` para la aplicacion.
        ->and($nueva->acl)->toBe($referencia->acl)
        ->and($privilegios[$application])->toBe([
            'INSERT' => true, 'SELECT' => true, 'UPDATE' => false, 'DELETE' => false, 'TRUNCATE' => false,
        ])
        ->and($privilegios[$maintenance])->toBe([
            'INSERT' => false, 'SELECT' => true, 'UPDATE' => false, 'DELETE' => false, 'TRUNCATE' => false,
        ]);
})->group('RS-07', 'RL-04');

it('la particion nueva hereda los indices y las restricciones de la tabla madre', function (): void {
    $next = particionFuncionAnoDelMotor() + 1;

    /**
     * @return array{indexes: int, checks: list<string>}
     */
    $estructura = static function (Connection $connection, int $year): array {
        $relation = 'public.'.AuditLogSchema::partitionName($year);

        /** @var object{n: int}|null $indexes */
        $indexes = $connection->selectOne('SELECT count(*)::int AS n FROM pg_index WHERE indrelid = ?::regclass', [$relation]);

        /** @var list<object{def: string}> $checks */
        $checks = $connection->select(
            "SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conrelid = ?::regclass AND contype = 'c' ORDER BY 1",
            [$relation],
        );

        return [
            'indexes' => (int) $indexes?->n,
            'checks' => array_map(static fn (object $row): string => $row->def, $checks),
        ];
    };

    [$nueva, $referencia] = particionFuncionComoAplicacionEnTransaccionDelPropietario(
        static fn (Connection $owner) => particionFuncionSoltar($owner, $next),
        static function (Connection $app) use ($next, $estructura): array {
            particionFuncionPedir($app, $next);

            return [$estructura($app, $next), $estructura($app, AuditLogSchema::FIRST_YEAR)];
        },
    );

    expect($referencia['indexes'])->toBeGreaterThan(0)
        ->and($referencia['checks'])->not->toBe([])
        ->and($nueva)->toBe($referencia);
})->group('RS-07', 'RL-04');

it('es idempotente', function (): void {
    $next = particionFuncionAnoDelMotor() + 1;

    [$primera, $segunda] = particionFuncionComoAplicacionEnTransaccionDelPropietario(
        static fn (Connection $owner) => particionFuncionSoltar($owner, $next),
        static fn (Connection $app): array => [particionFuncionPedir($app, $next), particionFuncionPedir($app, $next)],
    );

    expect($primera)->toBeTrue()
        ->and($segunda)->toBeFalse();
})->group('RS-07', 'RL-04');

// --- Lo que no cabe por esa puerta ---------------------------------------------

it('rechaza los años fuera de la ventana', function (string $caso): void {
    $year = particionFuncionAnoDelMotor();

    $pedido = match ($caso) {
        'año anterior' => $year - 1,
        'dentro de dos años' => $year + 2,
        'antes del primer año' => AuditLogSchema::FIRST_YEAR - 1,
        default => null,
    };

    $code = particionFuncionComoAplicacionEnTransaccionDelPropietario(
        static function (Connection $owner): void {},
        static fn (Connection $app): string => particionFuncionCodigoDeError(
            $app,
            'crear la particion de '.var_export($pedido, true),
            static function () use ($app, $pedido): void {
                particionFuncionPedir($app, $pedido);
            },
        ),
    );

    expect($code)->toBe('22023');
})->with(['año anterior', 'dentro de dos años', 'antes del primer año', 'NULL'])->group('RS-07', 'RL-04');

it('no recrea un año sellado', function (): void {
    // Un año purgado no puede reaparecer vacio: permitiria escribir entradas
    // retrodatadas en un año que el verificador da por cerrado (ADR-027).
    $next = particionFuncionAnoDelMotor() + 1;

    $code = particionFuncionComoAplicacionEnTransaccionDelPropietario(
        static function (Connection $owner) use ($next): void {
            particionFuncionSoltar($owner, $next);

            $owner->table(AuditLogSchema::ANCHORS_TABLE)->insert([
                'partition_year' => $next,
                'first_hash' => str_repeat('a', 64),
                'last_hash' => str_repeat('b', 64),
                'row_count' => 1,
                'sealed_at' => '2026-01-01 00:00:00+00',
                'sealed_by' => AuditLogSchema::migrationRole(),
            ]);
        },
        static fn (Connection $app): string => particionFuncionCodigoDeError(
            $app,
            'recrear el año sellado '.$next,
            static function () use ($app, $next): void {
                particionFuncionPedir($app, $next);
            },
        ),
    );

    expect($code)->toBe('22023');
})->group('RS-07', 'RL-04');

it('falla si existe una tabla con ese nombre que no es particion', function (): void {
    // `CREATE TABLE IF NOT EXISTS` no habria hecho nada y el año se habria
    // quedado sin particion, en silencio.
    $next = particionFuncionAnoDelMotor() + 1;

    $code = particionFuncionComoAplicacionEnTransaccionDelPropietario(
        static function (Connection $owner) use ($next): void {
            particionFuncionSoltar($owner, $next);
            $owner->statement('CREATE TABLE public.'.AuditLogSchema::partitionName($next).' (id int)');
        },
        static fn (Connection $app): string => particionFuncionCodigoDeError(
            $app,
            'dar por buena una tabla suelta',
            static function () use ($app, $next): void {
                particionFuncionPedir($app, $next);
            },
        ),
    );

    expect($code)->toBe('42P07');
})->group('RS-07', 'RL-04');

it('no se deja desviar por una tabla temporal', function (): void {
    // El propietario de la funcion es superusuario. Con `pg_temp` implicito,
    // PostgreSQL lo buscaria PRIMERO, y una tabla temporal `audit_log` del rol
    // de la aplicacion podria suplantar a la de verdad.
    $next = particionFuncionAnoDelMotor() + 1;

    [$created, $ficha] = particionFuncionComoAplicacionEnTransaccionDelPropietario(
        static fn (Connection $owner) => particionFuncionSoltar($owner, $next),
        static function (Connection $app) use ($next): array {
            $app->statement('CREATE TEMP TABLE audit_log (id int)');

            return [particionFuncionPedir($app, $next), particionFuncionFicha($app, $next)];
        },
    );

    expect($created)->toBeTrue()
        ->and($ficha->schema)->toBe('public')
        ->and($ficha->parent_is_audit_log)->toBeTrue();
})->group('RS-07', 'RL-04');

// --- El catalogo ---------------------------------------------------------------

it('el catalogo de la funcion es el esperado', function (): void {
    $application = Config::string('database.roles.application');
    $maintenance = Config::string('database.roles.maintenance');
    $signature = 'public.'.AuditLogSchema::CREATE_FUNCTION.'(integer)';

    /** @var object{prosecdef: bool, owner: string, config: string, acl_is_null: bool, public_grants: int}|null $row */
    $row = DB::selectOne(<<<'SQL'
        SELECT p.prosecdef,
               pg_get_userbyid(p.proowner) AS owner,
               array_to_json(p.proconfig) AS config,
               p.proacl IS NULL AS acl_is_null,
               (SELECT count(*)::int FROM aclexplode(p.proacl) a WHERE a.grantee = 0) AS public_grants
          FROM pg_proc p
         WHERE p.oid = ?::regprocedure
    SQL, [$signature]);

    /** @var list<string> $config */
    $config = json_decode((string) $row?->config, true, 512, JSON_THROW_ON_ERROR);

    /** @var object{granted: bool}|null $app */
    $app = DB::selectOne('SELECT has_function_privilege(?, ?, ?) AS granted', [$application, $signature, 'EXECUTE']);
    /** @var object{granted: bool}|null $mnt */
    $mnt = DB::selectOne('SELECT has_function_privilege(?, ?, ?) AS granted', [$maintenance, $signature, 'EXECUTE']);

    expect($row?->prosecdef)->toBeTrue()
        ->and($row?->owner)->toBe(Config::string('database.roles.migration'))
        ->and($config)->toContain('search_path=pg_catalog, pg_temp', 'lock_timeout=5s')
        // Un `proacl` NULL significa «los permisos por defecto», y por defecto
        // `PUBLIC` tiene `EXECUTE` sobre toda funcion.
        ->and($row?->acl_is_null)->toBeFalse()
        ->and($row?->public_grants)->toBe(0)
        ->and($app?->granted)->toBeTrue()
        ->and($mnt?->granted)->toBeFalse();
})->group('RS-07', 'RL-04');

it('el rol de la aplicacion sigue sin poder crear nada por su cuenta', function (): void {
    // Con la conexion por defecto, que es la del rol de la aplicacion de
    // verdad, no un `SET ROLE`. La funcion es la UNICA forma de DDL que puede
    // provocar (ADR-042).
    $connection = DB::connection();

    $year = particionFuncionAnoDelMotor() + 5;

    $intentos = [
        'CREATE TABLE … PARTITION OF audit_log' => sprintf(
            "CREATE TABLE public.%s PARTITION OF public.audit_log FOR VALUES FROM ('%d-01-01T00:00:00Z') TO ('%d-01-01T00:00:00Z')",
            AuditLogSchema::partitionName($year),
            $year,
            $year + 1,
        ),
        'CREATE TABLE en public' => 'CREATE TABLE public.kq_intruso (id int)',
        'CREATE FUNCTION en public' => 'CREATE FUNCTION public.kq_intrusa() RETURNS int LANGUAGE sql AS $$ SELECT 1 $$',
    ];

    foreach ($intentos as $que => $sql) {
        $code = particionFuncionCodigoDeError($connection, $que, static function () use ($connection, $sql): void {
            $connection->statement($sql);
        });

        expect($code)->toBe('42501', $que.' no lo ha rechazado el motor por permisos.');
    }
})->group('RS-07', 'RL-04');

// --- El adaptador ----------------------------------------------------------------

it('el adaptador crea la particion a traves de la funcion y la ve en el catalogo', function (): void {
    $next = particionFuncionAnoDelMotor() + 1;

    $years = particionFuncionComoAplicacionEnTransaccionDelPropietario(
        static fn (Connection $owner) => particionFuncionSoltar($owner, $next),
        static function (Connection $app) use ($next): array {
            $partitions = new DatabaseAuditLogPartitions($app);

            expect($partitions->years())->not->toContain($next);

            $partitions->create($next);

            return $partitions->years();
        },
    );

    expect($years)->toContain($next);
})->group('RS-07');

it('el adaptador traduce la funcion ausente a su excepcion de aplicacion', function (): void {
    $next = particionFuncionAnoDelMotor() + 1;

    $thrown = particionFuncionComoAplicacionEnTransaccionDelPropietario(
        static function (Connection $owner) use ($next): void {
            particionFuncionSoltar($owner, $next);
            $owner->statement(AuditLogSchema::createFunctionRemovalStatement());
        },
        static function (Connection $app) use ($next): ?Throwable {
            try {
                (new DatabaseAuditLogPartitions($app))->create($next);
            } catch (Throwable $exception) {
                return $exception;
            }

            return null;
        },
    );

    expect($thrown)->toBeInstanceOf(AuditPartitionCreationUnavailable::class)
        ->and($thrown?->getMessage())->toContain(AuditPartitionCreationUnavailable::MIGRATION)
        ->and($thrown instanceof AuditPartitionCreationUnavailable ? $thrown->year : null)->toBe($next);
})->group('RS-07');

it('el adaptador deja subir tal cual los rechazos que no son una migracion pendiente', function (): void {
    $year = particionFuncionAnoDelMotor();

    $thrown = particionFuncionComoAplicacionEnTransaccionDelPropietario(
        static function (Connection $owner): void {},
        static function (Connection $app) use ($year): ?Throwable {
            try {
                (new DatabaseAuditLogPartitions($app))->create($year + 2);
            } catch (Throwable $exception) {
                return $exception;
            }

            return null;
        },
    );

    expect($thrown)->toBeInstanceOf(QueryException::class)
        ->and($thrown instanceof QueryException ? $thrown->getCode() : null)->toBe('22023');
})->group('RS-07');
