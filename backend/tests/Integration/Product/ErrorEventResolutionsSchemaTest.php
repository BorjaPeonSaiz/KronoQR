<?php

declare(strict_types=1);

use App\Modules\Compliance\Infrastructure\Persistence\AuditLogSchema;
use App\Modules\Compliance\Infrastructure\Persistence\DatabaseErrorHistoryArchive;
use App\Modules\Product\Application\Port\ErrorEventQuery;
use App\Modules\Product\Application\Port\ErrorEventRepository;
use App\Modules\Product\Domain\ValueObject\ErrorEventStatusFilter;
use App\Modules\Product\Infrastructure\Persistence\DatabaseErrorEventRepository;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `error_event_resolutions`, el historial de resoluciones del historico de
 * errores (RF-PD-15, tarea 33.4 de la 2.3.0), contra PostgreSQL y con el rol
 * de la aplicacion.
 *
 * Lo que se afirma es lo que el esquema promete y el codigo da por hecho:
 * solo-append para la aplicacion, una resolucion por grupo e instante, la
 * purga del grupo arrastrando su historial **sin que la aplicacion tenga
 * `DELETE` aqui**, la resolucion anterior intacta tras reabrir, la copia del
 * historial al fusionar grupos y un `down()` que existe de verdad.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    WorkforceFixtures::site();
});

/** Un grupo de `error_events` escrito a mano, con su `last_seen_at`. */
function grupoConHistorial(string $ultimaVez = '2026-09-09 08:00:00+00', ?string $huella = null): int
{
    return (int) DB::table('error_events')->insertGetId([
        'fingerprint' => $huella ?? bin2hex(random_bytes(32)),
        'level' => 'error',
        'source' => 'api',
        'module' => 'product',
        'message' => 'algo fallo',
        'exception_class' => 'RuntimeException',
        'file' => 'app/Foo.php',
        'line' => 10,
        'context' => '{}',
        'app_version' => '2.3.0',
        'occurrences' => 1,
        'first_seen_at' => $ultimaVez,
        'last_seen_at' => $ultimaVez,
        'created_at' => $ultimaVez,
        'updated_at' => $ultimaVez,
    ]);
}

/**
 * El repositorio de produccion, por su clase: `merge()`
 * es del puerto del re-saneado (ADR-048), que el contenedor no expone.
 */
function repositorioDelHistorial(): DatabaseErrorEventRepository
{
    return new DatabaseErrorEventRepository(DB::connection(), DB::connection('error_events'));
}

/** Una fila del historial, con el rol de la aplicacion. */
function resolucionDelHistorial(int $grupo, string $instante, ?int $autor = null): void
{
    DB::table('error_event_resolutions')->insert([
        'error_event_id' => $grupo,
        'resolved_at' => $instante,
        'resolved_by_user_id' => $autor,
        'created_at' => $instante,
    ]);
}

/** El SQLSTATE de lo que lanza la sentencia, dentro de un punto de guardado. */
function sqlstateDelHistorial(Closure $sentencia): ?string
{
    try {
        DB::transaction(static fn (): mixed => $sentencia());
    } catch (QueryException $fallo) {
        return (string) $fallo->getCode();
    }

    return null;
}

/** La definicion que PostgreSQL guarda de una restriccion de la tabla. */
function restriccionDelHistorial(string $nombre): string
{
    $filas = DB::select('SELECT pg_get_constraintdef(oid) AS definicion FROM pg_constraint WHERE conname = ?', [$nombre]);
    $definicion = $filas === [] ? null : ($filas[0]->definicion ?? null);

    return is_string($definicion) ? $definicion : '';
}

it('declara las claves ajenas, la cascada con su grupo y el UNIQUE por grupo e instante', function (): void {
    expect(Schema::getColumnListing('error_event_resolutions'))
        ->toEqualCanonicalizing(['id', 'error_event_id', 'resolved_at', 'resolved_by_user_id', 'created_at'])
        ->and(restriccionDelHistorial('error_event_resolutions_error_event_id_foreign'))
        ->toBe('FOREIGN KEY (error_event_id) REFERENCES error_events(id) ON DELETE CASCADE')
        ->and(restriccionDelHistorial('error_event_resolutions_resolved_by_user_id_foreign'))
        ->toBe('FOREIGN KEY (resolved_by_user_id) REFERENCES users(id) ON DELETE SET NULL')
        ->and(restriccionDelHistorial('error_event_resolutions_error_event_id_resolved_at_unique'))
        ->toBe('UNIQUE (error_event_id, resolved_at)');

    $grupo = grupoConHistorial();
    resolucionDelHistorial($grupo, '2026-09-09 09:00:00+00');

    // Dos veces la misma resolucion: `23505`. Y un grupo que no existe: `23503`.
    expect(sqlstateDelHistorial(static fn () => resolucionDelHistorial($grupo, '2026-09-09 09:00:00+00')))->toBe('23505')
        ->and(sqlstateDelHistorial(static fn () => resolucionDelHistorial($grupo + 1000, '2026-09-09 09:00:00+00')))->toBe('23503')
        ->and(sqlstateDelHistorial(static fn () => DB::table('error_event_resolutions')->insert([
            'error_event_id' => $grupo,
            'resolved_at' => null,
            'created_at' => '2026-09-09 09:00:00+00',
        ])))->toBe('23502');
})->group('RF-PD-15');

it('es solo-append para el rol de la aplicacion', function (): void {
    $grupo = grupoConHistorial();
    resolucionDelHistorial($grupo, '2026-09-09 09:00:00+00');

    // `42501`, falta de privilegio: lo niega el motor, no el codigo.
    expect(DB::table('error_event_resolutions')->count())->toBe(1)
        ->and(sqlstateDelHistorial(static fn () => DB::table('error_event_resolutions')->update(['resolved_at' => '2026-09-09 10:00:00+00'])))->toBe('42501')
        ->and(sqlstateDelHistorial(static fn () => DB::table('error_event_resolutions')->delete()))->toBe('42501')
        ->and(sqlstateDelHistorial(static fn () => DB::statement('TRUNCATE error_event_resolutions')))->toBe('42501')
        ->and(DB::table('error_event_resolutions')->count())->toBe(1);
})->group('RF-PD-15', 'RS-07');

it('la purga de 90 dias arrastra el historial por cascada con el rol de la aplicacion', function (): void {
    // El rol de la aplicacion no tiene `DELETE` sobre el historial (prueba de
    // arriba), y aun asi purgar el grupo se lo lleva: la accion referencial la
    // ejecuta PostgreSQL con los privilegios del propietario de la tabla. Por
    // las dos purgas: la del comando del historico y la del ciclo de retencion.
    $viejo = grupoConHistorial('2026-05-01 08:00:00+00');
    $otroViejo = grupoConHistorial('2026-05-02 08:00:00+00');
    $reciente = grupoConHistorial('2026-09-09 08:00:00+00');

    resolucionDelHistorial($viejo, '2026-05-01 09:00:00+00');
    resolucionDelHistorial($otroViejo, '2026-05-02 09:00:00+00');
    resolucionDelHistorial($reciente, '2026-09-09 09:00:00+00');

    // Con el rol de la aplicacion, no con el del propietario.
    /** @var object{rol: string}|null $sesion */
    $sesion = DB::selectOne('SELECT current_user AS rol');

    expect($sesion?->rol)->toBe(AuditLogSchema::applicationRole());

    $corte = new DateTimeImmutable('2026-06-10 00:00:00+00');

    expect(app(ErrorEventRepository::class)->pruneOlderThan($corte->modify('-39 days'), 10))->toBe(1)
        ->and((new DatabaseErrorHistoryArchive(DB::connection()))->purge($corte, 10)->rows)->toBe(1);

    expect(DB::table('error_event_resolutions')->pluck('error_event_id')->all())->toBe([$reciente]);
})->group('RF-PD-15', 'RL-11');

it('resolver, reabrir y volver a resolver deja la resolucion anterior intacta', function (): void {
    $repositorio = app(ErrorEventRepository::class);
    $primera = ManagementUsers::withRole(UserRole::ADMIN);
    $segunda = ManagementUsers::withRole(UserRole::ADMIN);
    $grupo = grupoConHistorial();

    $resuelto = $repositorio->resolve($grupo, $primera->id, new DateTimeImmutable('2026-09-09 09:00:00+00'));

    // Recien resuelto no hay resolucion ANTERIOR: la actual tambien esta en el
    // historial, pero no es de un ciclo pasado.
    expect($resuelto?->previousResolution)->toBeNull()
        ->and(DB::table('error_event_resolutions')->where('error_event_id', $grupo)->count())->toBe(1);

    // Una segunda pulsacion no escribe nada mas.
    $repositorio->resolve($grupo, $segunda->id, new DateTimeImmutable('2026-09-09 09:05:00+00'));

    expect(DB::table('error_event_resolutions')->where('error_event_id', $grupo)->count())->toBe(1);

    // La reapertura, como la hace el sumidero al recurrir: vacia el estado.
    DB::table('error_events')->where('id', $grupo)->update(['resolved_at' => null, 'resolved_by_user_id' => null]);

    // Por el listado del panel, que es quien lee la resolucion anterior.
    $reabierto = $repositorio->page(new ErrorEventQuery(status: ErrorEventStatusFilter::Open))->rows[0] ?? null;

    expect($reabierto?->resolvedAt)->toBeNull()
        ->and($reabierto?->previousResolution?->resolvedByUuid)->toBe($primera->uuid)
        ->and($reabierto?->previousResolution?->resolvedByName)->toBe($primera->name)
        ->and($reabierto?->previousResolution?->resolvedAt->format('Y-m-d H:i'))->toBe('2026-09-09 09:00');

    // Y la segunda resolucion: la anterior es la primera, no la actual.
    $otraVez = $repositorio->resolve($grupo, $segunda->id, new DateTimeImmutable('2026-09-10 09:00:00+00'));

    expect($otraVez?->resolvedByUuid)->toBe($segunda->uuid)
        ->and($otraVez?->previousResolution?->resolvedByUuid)->toBe($primera->uuid)
        ->and(DB::table('error_event_resolutions')->where('error_event_id', $grupo)->count())->toBe(2);
})->group('RF-PD-15');

it('al fusionar dos grupos el historial del absorbido pasa al superviviente', function (): void {
    // ADR-048, decision 9: el absorbido se borra, y su historial no puede irse
    // con el por la cascada sin pasar antes al que queda.
    $autor = ManagementUsers::withRole(UserRole::ADMIN);
    $superviviente = grupoConHistorial('2026-09-09 08:00:00+00');
    $absorbido = grupoConHistorial('2026-09-08 08:00:00+00');

    resolucionDelHistorial($superviviente, '2026-09-01 09:00:00+00', $autor->id);
    resolucionDelHistorial($absorbido, '2026-09-02 09:00:00+00', $autor->id);
    // La misma resolucion en los dos: la copia no la duplica.
    resolucionDelHistorial($absorbido, '2026-09-01 09:00:00+00', $autor->id);

    repositorioDelHistorial()->merge($superviviente, $absorbido);

    expect(DB::table('error_events')->where('id', $absorbido)->exists())->toBeFalse()
        ->and(DB::table('error_event_resolutions')->where('error_event_id', $superviviente)->orderBy('resolved_at')->pluck('resolved_at')->map(
            static fn (mixed $instante): string => (new DateTimeImmutable(is_string($instante) ? $instante : ''))->format('Y-m-d'),
        )->all())->toBe(['2026-09-01', '2026-09-02'])
        ->and(DB::table('error_event_resolutions')->where('error_event_id', $absorbido)->count())->toBe(0);
})->group('RF-PD-15');

it('la migracion se deshace y se reaplica dejando la tabla igual', function (): void {
    // «Una migracion cuyo `down()` no se ha probado no tiene `down()`». El
    // relleno con datos confirmados lo prueba `ErrorEventGroupingTest`: aqui la
    // transaccion de la prueba no deja ver nada a la conexion del propietario.
    $migracion = require database_path('migrations/2026_11_01_100000_create_error_event_resolutions_table.php');

    $antes = Schema::connection('pgsql_migrator')->getColumnListing('error_event_resolutions');

    DB::setDefaultConnection('pgsql_migrator');

    try {
        DB::connection('pgsql_migrator')->transaction(static fn () => $migracion->down());

        expect(Schema::connection('pgsql_migrator')->hasTable('error_event_resolutions'))->toBeFalse();

        DB::connection('pgsql_migrator')->transaction(static fn () => $migracion->up());
    } finally {
        DB::setDefaultConnection('pgsql');
    }

    expect(Schema::connection('pgsql_migrator')->getColumnListing('error_event_resolutions'))->toBe($antes)
        // Y los permisos vuelven a ser los de solo-append.
        ->and(sqlstateDelHistorial(static fn () => DB::table('error_event_resolutions')->delete()))->toBe('42501')
        ->and(sqlstateDelHistorial(static fn () => DB::table('error_event_resolutions')->update(['created_at' => '2026-09-09 09:00:00+00'])))->toBe('42501');
})->group('RF-PD-15');
