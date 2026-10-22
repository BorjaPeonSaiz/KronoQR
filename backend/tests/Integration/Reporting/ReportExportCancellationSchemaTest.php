<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Quality\Support\Commands;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Identity\ManagementUsers;

/*
 * La migracion de las exportaciones largas en diferido (2.3.0, V5-PA-4):
 * `report_exports` admite `legal`, `adoption` y `cancelled`, y **las
 * invariantes van en la base de datos**, no solo en PHP:
 *
 * - `cancelled` si y solo si `cancelled_at`;
 * - la exportacion para la Inspeccion solo en CSV (RL-06);
 * - el catalogo cerrado de clases y estados sigue cerrado.
 *
 * Y su `down()` se ha probado: vuelve al catalogo anterior y la reaplica.
 */

uses(RefreshDatabase::class);

const REPORT_EXPORT_CANCELLATION_MIGRATION = '2026_10_22_100000_report_exports_legal_adoption_and_cancellation';

/**
 * Una fila minima de `report_exports` con lo que la prueba quiera cambiar.
 *
 * @param  array<string, mixed>  $overrides
 */
function reportExportCancellationRow(int $userId, array $overrides = []): void
{
    DB::transaction(static function () use ($userId, $overrides): void {
        DB::table('report_exports')->insert([
            'uuid' => Str::uuid7()->toString(),
            'kind' => 'legal',
            'format' => 'csv',
            'status' => 'pending',
            'parameters' => '{"from":"2026-03-01","to":"2026-03-31"}',
            'scope' => '{"unrestricted":true}',
            'requested_by_user_id' => $userId,
            'requested_at' => '2026-03-08 09:00:00+00',
            'criteria' => '[]',
            'download_count' => 0,
            'created_at' => '2026-03-08 09:00:00+00',
            'updated_at' => '2026-03-08 09:00:00+00',
            ...$overrides,
        ]);
    });
}

it('admite las clases legal y adoption y el estado cancelled con su fecha', function (): void {
    $user = ManagementUsers::withRole(UserRole::RRHH)->id;

    reportExportCancellationRow($user);
    reportExportCancellationRow($user, ['kind' => 'adoption', 'format' => 'pdf', 'status' => 'failed', 'failed_at' => '2026-03-08 09:01:00+00', 'failure_reason' => 'unexpected']);
    reportExportCancellationRow($user, ['status' => 'cancelled', 'cancelled_at' => '2026-03-08 09:00:30+00']);

    expect(DB::table('report_exports')->count())->toBe(3);
})->group('RF-IN-06', 'RF-IN-05', 'RF-IN-08');

it('rechaza desde el esquema lo que PHP tampoco construiria', function (array $overrides, string $constraint): void {
    $user = ManagementUsers::withRole(UserRole::RRHH)->id;

    expect(static fn () => reportExportCancellationRow($user, $overrides))
        ->toThrow(QueryException::class, $constraint);
})->with([
    'cancelada sin fecha' => [['status' => 'cancelled'], 'report_exports_chk_cancelled_is_stamped'],
    'fecha de cancelacion sin cancelar' => [['cancelled_at' => '2026-03-08 09:00:30+00'], 'report_exports_chk_cancelled_is_stamped'],
    'legal en XLSX' => [['format' => 'xlsx'], 'report_exports_chk_legal_format'],
    'clase desconocida' => [['kind' => 'inspection'], 'report_exports_chk_kind'],
    'estado desconocido' => [['status' => 'aborted'], 'report_exports_chk_status'],
])->group('RF-IN-06', 'RL-06');

it('la migracion se puede revertir y volver a aplicar', function (): void {
    $name = config()->string('database.migrations.connection');
    $migrator = DB::connection($name);

    try {
        [$back, $output] = Commands::run(
            'migrate:rollback --database='.$name.' --step='.reportExportCancellationStepsBack($migrator)
            .' --path=database/migrations/'.REPORT_EXPORT_CANCELLATION_MIGRATION.'.php'
        );

        expect($back)->toBe(0, $output)
            ->and($migrator->getSchemaBuilder()->hasColumn('report_exports', 'cancelled_at'))->toBeFalse();

        [$forward, $forwardOutput] = Commands::run('migrate --database='.$name);

        expect($forward)->toBe(0, $forwardOutput);
    } finally {
        // La base de pruebas es compartida: nunca se deja a medio migrar.
        Commands::run('migrate --database='.$name);
    }

    /** @var list<object{conname: string, convalidated: bool}> $checks */
    $checks = $migrator->select(<<<'SQL'
        SELECT conname, convalidated FROM pg_constraint
         WHERE conrelid = 'report_exports'::regclass
           AND conname IN ('report_exports_chk_cancelled_is_stamped', 'report_exports_chk_legal_format')
         ORDER BY conname
        SQL);

    expect($migrator->getSchemaBuilder()->hasColumn('report_exports', 'cancelled_at'))->toBeTrue()
        ->and(array_column(array_map(static fn (object $row): array => (array) $row, $checks), 'convalidated', 'conname'))
        ->toBe([
            'report_exports_chk_cancelled_is_stamped' => true,
            'report_exports_chk_legal_format' => true,
        ]);
})->group('RF-IN-06', 'RNF-D-04');

/** Pasos de `migrate:rollback` hasta deshacer esta migracion, incluida. */
function reportExportCancellationStepsBack(ConnectionInterface $connection): int
{
    /** @var list<string> $applied */
    $applied = $connection->table('migrations')->orderByDesc('id')->pluck('migration')->all();
    $position = array_search(REPORT_EXPORT_CANCELLATION_MIGRATION, $applied, true);

    return $position === false ? 0 : $position + 1;
}
