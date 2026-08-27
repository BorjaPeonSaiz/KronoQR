<?php

declare(strict_types=1);

use App\Modules\Compliance\Application\Command\RecordAuditEntryCommand;
use App\Modules\Compliance\Application\Port\AuditTrail;
use App\Modules\Compliance\Application\UseCase\RecordAuditEntry;
use App\Modules\Compliance\Application\UseCase\VerifyAuditChain;
use App\Modules\Compliance\Domain\AuditChain;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditActor;
use App\Modules\Compliance\Domain\ValueObject\AuditChainBreakKind;
use App\Modules\Compliance\Domain\ValueObject\AuditChainVerification;
use App\Modules\Compliance\Domain\ValueObject\AuditEntryDraft;
use App\Modules\Compliance\Domain\ValueObject\AuditPayload;
use App\Modules\Compliance\Domain\ValueObject\AuditSubject;
use App\Modules\Compliance\Infrastructure\Metrics\TextfileAuditMetrics;
use App\Modules\Compliance\Infrastructure\Persistence\AuditLogSchema;
use App\Modules\Compliance\Infrastructure\Persistence\DatabaseAuditChainReader;
use App\Modules\Compliance\Infrastructure\Persistence\DatabaseAuditTrail;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Time\FixedClock;
use Tests\Support\Time\Instants;

/*
 * `audit_log` contra PostgreSQL de verdad: la cadena, los permisos y las
 * particiones (RS-07, regla dura 6, ADR-010, ADR-027).
 *
 * POR QUE ESTA SUITE NO PODIA SER UNITARIA. Todo lo que se comprueba aqui vive
 * en el motor y no en PHP: que el rol de la aplicacion **no pueda** ejecutar un
 * `UPDATE`, que un `INSERT` sin particion de destino falle, que `jsonb` no
 * conserve el orden de las claves. Un doble en memoria daria las tres por buenas
 * sin haberlas comprobado nunca, y son exactamente las tres de las que depende
 * que el registro tenga valor probatorio.
 *
 * LA CONEXION POR DEFECTO DE ESTAS PRUEBAS ES LA DE LA APLICACION
 * (`fichaje_app`). Es deliberado: una prueba de permisos que corriera con el rol
 * propietario —o peor, con un superusuario— pasaria siempre. Solo el
 * `migrate:fresh` inicial usa el rol de migracion
 * (Tests\Support\Database\RefreshDatabase).
 */

uses(RefreshDatabase::class);

/** Instante fijo: la cadena no depende del reloj y una prueba que si dependiera fallaria un dia al año. */
const AUDIT_AT = '2026-08-19 06:00:00';

function auditTrail(): AuditTrail
{
    return app(AuditTrail::class);
}

/**
 * @param  array<array-key, mixed>  $payload
 */
function appendAudit(array $payload = [], ?string $occurredAt = null, ?AuditAction $action = null): void
{
    auditTrail()->append(new AuditEntryDraft(
        occurredAt: Instants::utc($occurredAt ?? AUDIT_AT),
        actor: AuditActor::device(1),
        action: $action ?? AuditAction::ShiftEntryCreated,
        subject: AuditSubject::of('shift_entry', 1),
        payload: AuditPayload::of($payload),
    ));
}

/**
 * Comprueba que **PostgreSQL** rechaza la escritura, y que lo hace por permisos.
 *
 * Va dentro de una transaccion propia —que sobre la de `RefreshDatabase` es un
 * `SAVEPOINT`— porque en PostgreSQL un error aborta la transaccion entera: sin
 * ese punto de retorno, la prueba no podria seguir consultando despues.
 *
 * @param  Closure(): void  $write
 */
function expectPermissionDenied(string $what, Closure $write): void
{
    try {
        DB::transaction(static function () use ($write): void {
            $write();
        });
    } catch (QueryException $exception) {
        // El error tiene que venir del MOTOR y decir «permission denied»
        // (SQLSTATE 42501). Si viniera de la aplicacion, bastaria un `if`
        // olvidado para que el registro probatorio quedase abierto.
        expect($exception->getCode())->toBe('42501', $what.': '.$exception->getMessage());

        return;
    }

    Assert::fail(
        'PostgreSQL ha permitido «'.$what.'» al rol de la aplicacion. '
        .'La regla dura 6 no esta puesta: audit_log ha dejado de ser solo-append.'
    );
}

// --- La genesis --------------------------------------------------------------

it('escribe la primera entrada con la genesis del documento como prev_hash', function (): void {
    appendAudit();

    /** @var object{prev_hash: string, hash: string}|null $row */
    $row = DB::table('audit_log')->orderBy('id')->first();

    expect($row?->prev_hash)
        ->toBe('5a4bce588b4e0fa301a7a7befe42825a5d44ec5b90d26697b300acca0add5f2e')
        ->and($row?->prev_hash)->toBe(AuditChain::genesisHash());
})->group('RS-07');

it('encadena cada entrada con el hash de la anterior', function (): void {
    appendAudit(['n' => 1]);
    appendAudit(['n' => 2]);
    appendAudit(['n' => 3]);

    /** @var list<object{prev_hash: string, hash: string}> $rows */
    $rows = DB::table('audit_log')->orderBy('id')->get()->all();

    expect($rows)->toHaveCount(3)
        ->and($rows[1]->prev_hash)->toBe($rows[0]->hash)
        ->and($rows[2]->prev_hash)->toBe($rows[1]->hash);
})->group('RS-07');

// --- Regla dura 6: solo-append, comprobado por el motor -----------------------

it('niega al rol de la aplicacion el UPDATE sobre audit_log', function (): void {
    appendAudit();

    expectPermissionDenied('UPDATE sobre audit_log', static function (): void {
        DB::statement("UPDATE audit_log SET action = 'x'");
    });
})->group('RS-07');

it('niega al rol de la aplicacion el DELETE sobre audit_log', function (): void {
    appendAudit();

    expectPermissionDenied('DELETE sobre audit_log', static function (): void {
        DB::statement('DELETE FROM audit_log');
    });
})->group('RS-07');

it('niega el UPDATE y el DELETE tambien sobre la particion, atacada directamente', function (): void {
    // Es la mitad que se olvida: los permisos NO se heredan al adjuntar una
    // particion. Con la tabla madre protegida y la particion abierta, bastaria
    // escribir `audit_log_2026` en lugar de `audit_log` para reescribir el año
    // entero.
    appendAudit();

    $partition = AuditLogSchema::partitionName(2026);

    expectPermissionDenied('UPDATE sobre '.$partition, static function () use ($partition): void {
        DB::statement('UPDATE '.$partition." SET action = 'x'");
    });

    expectPermissionDenied('DELETE sobre '.$partition, static function () use ($partition): void {
        DB::statement('DELETE FROM '.$partition);
    });
})->group('RS-07');

it('niega el TRUNCATE, que no es un DELETE pero vacia igual', function (): void {
    expectPermissionDenied('TRUNCATE sobre audit_log', static function (): void {
        DB::statement('TRUNCATE audit_log');
    });
})->group('RS-07');

it('deja al rol de la aplicacion solo INSERT y SELECT sobre la tabla y sus particiones', function (): void {
    // La comprobacion declarativa, que es la que se lee en una auditoria: no
    // «lo intentamos y fallo», sino «el catalogo dice que no puede».
    $application = Config::string('database.roles.application');

    $relations = ['audit_log', AuditLogSchema::partitionName(2026)];

    foreach ($relations as $relation) {
        foreach (['UPDATE', 'DELETE', 'TRUNCATE'] as $privilege) {
            /** @var object{granted: bool}|null $result */
            $result = DB::selectOne(
                'SELECT has_table_privilege(?, ?, ?) AS granted',
                [$application, $relation, $privilege],
            );

            expect($result?->granted)->toBeFalse(
                $application.' tiene '.$privilege.' sobre '.$relation.'. Regla dura 6.'
            );
        }

        foreach (['INSERT', 'SELECT'] as $privilege) {
            /** @var object{granted: bool}|null $result */
            $result = DB::selectOne(
                'SELECT has_table_privilege(?, ?, ?) AS granted',
                [$application, $relation, $privilege],
            );

            expect($result?->granted)->toBeTrue(
                $application.' no puede '.$privilege.' sobre '.$relation.': no podria auditar nada.'
            );
        }
    }
})->group('RS-07');

it('no deja que el rol de la aplicacion sea superusuario ni propietario de audit_log', function (): void {
    // La comprobacion que hace que todas las anteriores signifiquen algo. Sobre
    // un SUPERUSUARIO, PostgreSQL ni mira los privilegios; un PROPIETARIO puede
    // volver a otorgarse lo que se le revoque. Con `fichaje_app` en cualquiera
    // de los dos papeles, los REVOKE de arriba serian decorativos.
    $application = Config::string('database.roles.application');

    /** @var object{rolsuper: bool}|null $role */
    $role = DB::selectOne('SELECT rolsuper FROM pg_roles WHERE rolname = ?', [$application]);

    expect($role?->rolsuper)->toBeFalse($application.' es superusuario: los GRANT no se comprueban.');

    /** @var object{owner: string}|null $owner */
    $owner = DB::selectOne(<<<'SQL'
        SELECT r.rolname AS owner
        FROM pg_class c
        JOIN pg_roles r ON r.oid = c.relowner
        WHERE c.relname = 'audit_log'
    SQL);

    expect($owner?->owner)->not->toBe($application)
        ->and($owner?->owner)->toBe(Config::string('database.roles.migration'));
})->group('RS-07');

it('no da al rol de la aplicacion permiso para escribir un ancla', function (): void {
    // Sellar una particion es el paso previo a soltarla (ADR-027). Si la
    // aplicacion pudiera escribir un ancla, podria fabricar la coartada de una
    // purga que nunca se registro.
    $application = Config::string('database.roles.application');

    /** @var object{granted: bool}|null $result */
    $result = DB::selectOne(
        'SELECT has_table_privilege(?, ?, ?) AS granted',
        [$application, 'audit_chain_anchors', 'INSERT'],
    );

    expect($result?->granted)->toBeFalse();
})->group('RS-07');

// --- El escenario ineludible del doc 02 §9.4 ---------------------------------

/**
 * Escribe tres entradas, deja que `$tamper` toque la tabla por SQL directo y
 * devuelve el resultado de verificar la cadena despues.
 *
 * **Todo ocurre sobre la conexion de MIGRACION y en una transaccion propia que
 * se deshace al final.** No es un rodeo: quien puede alterar `audit_log` es
 * justamente quien NO es el rol de la aplicacion —eso lo prueban las pruebas de
 * permisos de arriba—, y dos conexiones distintas no ven las escrituras sin
 * confirmar de la otra. Simular al atacante desde otra sesion sobre filas que
 * todavia no estan confirmadas no alteraria nada y la prueba pasaria sin haber
 * probado nada.
 *
 * @param  Closure(Connection, int): void  $tamper
 * @return array{0: AuditChainVerification, 1: AuditChainVerification, 2: string}
 */
function tamperedChainVerification(Closure $tamper): array
{
    $metricsPath = sys_get_temp_dir().'/kronoqr-audit-metrics-'.bin2hex(random_bytes(6));
    Config::set('observability.metrics.textfile_path', $metricsPath);
    Config::set('observability.metrics.enabled', true);

    $connection = DB::connection('pgsql_migrator');
    $clock = FixedClock::at('2026-08-20 04:05:00');
    $metrics = new TextfileAuditMetrics;

    $verify = new VerifyAuditChain(new DatabaseAuditChainReader($connection), $metrics, $clock);
    $trail = new DatabaseAuditTrail($connection);

    $connection->beginTransaction();

    try {
        foreach ([1, 2, 3] as $n) {
            $trail->append(new AuditEntryDraft(
                occurredAt: Instants::utc(AUDIT_AT),
                actor: AuditActor::device(1),
                action: AuditAction::ShiftEntryCreated,
                subject: AuditSubject::of('shift_entry', $n),
                payload: AuditPayload::of(['n' => $n]),
            ));
        }

        $before = $verify->handle();

        /** @var object{id: int} $target */
        $target = $connection->table('audit_log')->orderBy('id')->skip(1)->firstOrFail();

        $tamper($connection, (int) $target->id);

        return [$before, $verify->handle(), $metricsPath];
    } finally {
        $connection->rollBack();
    }
}

it('detecta por SQL directo la alteracion de una fila e incrementa la metrica', function (): void {
    // Escenario ineludible «Cadena de auditoria» del doc 02 §9.4.
    [$before, $after, $metricsPath] = tamperedChainVerification(
        static function (Connection $connection, int $id): void {
            $connection->statement(
                "UPDATE audit_log SET payload = '{\"n\": 99}'::jsonb WHERE id = ?",
                [$id],
            );
        },
    );

    // Antes de tocar nada, la cadena verificaba y la metrica estaba en cero.
    expect($before->isIntact())->toBeTrue()
        ->and($before->rowsVerified)->toBe(3);

    expect($after->isIntact())->toBeFalse()
        ->and($after->failureCount())->toBe(1)
        ->and($after->breaks[0]->kind)->toBe(AuditChainBreakKind::ContentAltered)
        // La metrica que el doc 02 §8.2 exige que este siempre en cero.
        ->and(metricValue($metricsPath))->toBe(1);
})->group('RS-07');

it('detecta que se ha borrado una fila por fuera de la aplicacion', function (): void {
    [$before, $after] = tamperedChainVerification(
        static function (Connection $connection, int $id): void {
            $connection->statement('DELETE FROM audit_log WHERE id = ?', [$id]);
        },
    );

    expect($before->isIntact())->toBeTrue()
        ->and($after->isIntact())->toBeFalse()
        ->and($after->breaks[0]->kind)->toBe(AuditChainBreakKind::BrokenLink);
})->group('RS-07');

/**
 * Lee `audit_chain_verification_failures_total` del fichero que consume el
 * colector textfile de node-exporter.
 */
function metricValue(string $path): int
{
    $file = $path.'/kronoqr_audit_chain.prom';

    if (! is_file($file)) {
        return 0;
    }

    $contents = (string) file_get_contents($file);

    if (preg_match('/^audit_chain_verification_failures_total\s+(\d+)/m', $contents, $match) !== 1) {
        return 0;
    }

    return (int) $match[1];
}

// --- Serializacion canonica contra `jsonb` -----------------------------------

it('da el mismo hash aunque PostgreSQL devuelva las claves del payload en otro orden', function (): void {
    // `jsonb` NO conserva el orden de insercion: lo almacena ordenado por
    // longitud de clave y despues por bytes. Sin canonicalizacion al leer, el
    // verificador denunciaria una manipulacion en cada fila con mas de una
    // clave, todos los dias, desde el primero.
    $payload = ['zeta' => 1, 'a' => 2, 'media_clave' => 3, 'bb' => 4];

    appendAudit($payload);

    /** @var object{payload: string, hash: string}|null $row */
    $row = DB::table('audit_log')->orderBy('id')->first();

    // Se comprueba que la base de datos DE VERDAD lo reordena: si algun dia
    // dejara de hacerlo, esta prueba dejaria de probar lo que dice.
    expect($row?->payload)->not->toBe(json_encode($payload, JSON_THROW_ON_ERROR));

    // Y aun asi la cadena verifica.
    expect(app(VerifyAuditChain::class)->handle()->isIntact())->toBeTrue();
})->group('RS-07');

it('escribe en la columna el mismo JSON canonico que entra en el hash', function (): void {
    appendAudit(['b' => 1, 'a' => 2]);

    /** @var object{payload: string}|null $row */
    $row = DB::table('audit_log')->orderBy('id')->first();

    // PostgreSQL reordena al guardar, asi que se compara ya decodificado: lo
    // que importa es que el contenido sea el mismo y que la forma canonica se
    // reconstruya igual.
    expect(AuditPayload::fromStorage((array) json_decode((string) $row?->payload, true, 512, JSON_THROW_ON_ERROR))->encode())
        ->toBe(AuditPayload::of(['b' => 1, 'a' => 2])->encode());
})->group('RS-07');

// --- Particiones (ADR-027) ---------------------------------------------------

it('falla de forma visible al escribir en un año sin particion y no confirma la accion auditada', function (): void {
    // Un INSERT sin particion de destino aborta y ARRASTRA la transaccion de la
    // accion auditada. Es intencionado (ADR-027): un fichaje que ocurre sin
    // dejar traza es peor que un fichaje que no ocurre, porque el segundo se
    // puede corregir.
    $sinParticion = (int) gmdate('Y') + 5;

    $sitesBefore = DB::table('sites')->count();

    try {
        DB::transaction(function () use ($sinParticion): void {
            // La «accion auditada»: una escritura de negocio que debe deshacerse
            // si la auditoria no se puede escribir.
            DB::table('sites')->insert([
                'name' => 'Centro que no debe quedar',
                'timezone' => 'Europe/Madrid',
                'settings' => '{}',
                'created_at' => AUDIT_AT,
            ]);

            appendAudit([], $sinParticion.'-06-01 06:00:00');
        });

        Assert::fail(
            'PostgreSQL ha aceptado una entrada de auditoria de un año sin particion. '
            .'El fallo tiene que ser visible: en silencio, la tabla perderia entradas sin avisar.'
        );
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('no partition of relation');
    }

    expect(DB::table('sites')->count())->toBe($sitesBefore);
})->group('RS-07');

it('crea la particion del año en curso y la del literal de ADR-027', function (): void {
    /** @var list<object{relname: string}> $partitions */
    $partitions = DB::select(<<<'SQL'
        SELECT child.relname
        FROM pg_inherits
        JOIN pg_class parent ON parent.oid = pg_inherits.inhparent
        JOIN pg_class child  ON child.oid  = pg_inherits.inhrelid
        WHERE parent.relname = 'audit_log'
    SQL);

    $names = array_map(static fn (object $row): string => $row->relname, $partitions);

    expect($names)->toContain('audit_log_'.AuditLogSchema::FIRST_YEAR)
        ->and($names)->toContain('audit_log_'.gmdate('Y'));
})->group('RS-07');

it('atribuye la entrada a la particion del año UTC y no al de la zona del centro', function (): void {
    // Regla dura 3. En Madrid ya es 2027 cuando en UTC todavia es 2026: sin la
    // `Z` explicita en el limite de la particion, esta entrada caeria en el año
    // siguiente y la cadena quedaria repartida de forma incoherente.
    appendAudit([], '2026-12-31 23:30:00');

    expect(DB::table('audit_log_2026')->count())->toBe(1);
})->group('RS-07');

// --- El caso de uso publico --------------------------------------------------

it('resuelve el instante por el puerto Clock cuando el caso de uso no lo recibe', function (): void {
    // Regla dura 2: el caso de uso pide la hora al puerto, no la inventa.
    $entry = app(RecordAuditEntry::class)->handle(new RecordAuditEntryCommand(
        actor: AuditActor::system(),
        action: AuditAction::LegalExportGenerated,
        subject: AuditSubject::none(),
        payload: AuditPayload::of(['period' => '2026-08']),
    ));

    expect($entry->id)->not->toBeNull()
        ->and($entry->draft->occurredAt->getOffset())->toBe(0);
})->group('RS-07');

it('deshace la entrada de auditoria si la transaccion de quien la llama se deshace', function (): void {
    // La otra mitad de la garantia: el escritor NO abre transaccion propia, se
    // une a la de quien audita. Si abriera una suya, una accion revertida
    // dejaria en el registro una traza de algo que no ocurrio.
    try {
        DB::transaction(static function (): void {
            appendAudit(['n' => 1]);

            throw new RuntimeException('la accion auditada ha fallado');
        });
    } catch (RuntimeException) {
        // Esperado.
    }

    expect(DB::table('audit_log')->count())->toBe(0);
})->group('RS-07');
