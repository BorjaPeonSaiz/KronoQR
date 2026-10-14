<?php

declare(strict_types=1);

use App\Modules\Compliance\Application\Port\AuditLogPartitions;
use App\Modules\Compliance\Infrastructure\Persistence\AuditLogSchema;
use Database\Seeders\Support\KioskClocking;
use Database\Seeders\Support\KioskClockingAudit;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Quality\Support\Commands;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\CommittedDatabase;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Las particiones anuales de `audit_log` que la semilla de desarrollo necesita
 * y la instalacion no tiene (ADR-027, regla dura 6; revision del bloque 11 de
 * la 2.2.1).
 *
 * La semilla escribe los asientos del quiosco en la transaccion del tramo, y la
 * particion que falte la crea el rol de migracion por OTRA conexion. Creada
 * dentro de esa transaccion, el DDL pide un candado exclusivo sobre
 * `audit_log` que la transaccion de la aplicacion —con un asiento ya escrito—
 * no suelta: dos conexiones esperandose, sin que PostgreSQL lo vea. Por eso se
 * crean antes, y `KioskClockingAudit` se niega a hacerlo dentro.
 *
 * Con el reloj en febrero de 2029 la ventana de noventa dias de la semilla cae
 * entera en dos años que las migraciones no crean (2028 y 2029): es el camino
 * que la semilla completa, sembrada con el reloj real, no recorre. Volumen
 * reducido —dos personas y un quiosco— para no repetir la semilla completa.
 *
 * CommittedDatabase por lo mismo que la semilla: el DDL del rol de migracion no
 * puede esperar a la transaccion envolvente de `RefreshDatabase`.
 */

uses(CommittedDatabase::class);

const SEED_AUDIT_PARTITIONS_YEARS = [2028, 2029];

/**
 * Suelta, como propietario, las particiones de 2028 y 2029 que existan: las
 * crea esta prueba y no deben quedarse para las siguientes.
 */
function seedAuditPartitionsDrop(): void
{
    $owner = DB::connection(config()->string('database.migrations.connection'));

    foreach (array_intersect(SEED_AUDIT_PARTITIONS_YEARS, resolve(AuditLogPartitions::class)->years()) as $year) {
        $partition = AuditLogSchema::partitionName($year);

        $owner->statement('ALTER TABLE public.audit_log DETACH PARTITION public.'.$partition);
        $owner->statement('DROP TABLE public.'.$partition);
    }
}

beforeEach(function (): void {
    seedAuditPartitionsDrop();
});

afterEach(function (): void {
    seedAuditPartitionsDrop();
});

it('siembra el volumen en años sin particion de audit_log creandolas antes de abrir la transaccion', function (): void {
    FrozenTime::at('2029-02-15 10:00:00');
    $site = WorkforceFixtures::site();
    AttendanceFixtures::device($site);
    WorkforceFixtures::employee($site);
    WorkforceFixtures::employee($site);

    expect(array_intersect(SEED_AUDIT_PARTITIONS_YEARS, resolve(AuditLogPartitions::class)->years()))->toBe([]);

    [$sembrada, $salida] = Commands::run('db:seed --class=VolumeSeeder --force');

    expect($sembrada)->toBe(0, $salida)
        ->and(resolve(AuditLogPartitions::class)->years())->toContain(2028, 2029)
        ->and(DB::table('audit_log')->where('occurred_at', '<', '2029-01-01')->count())->toBeGreaterThan(0)
        ->and(DB::table('audit_log')->where('occurred_at', '>=', '2029-01-01')->count())->toBeGreaterThan(0);

    [$completa, $salidaCompleta] = Commands::run('compliance:reconcile-work-record --full');

    expect($completa)->toBe(0, $salidaCompleta);
})->group('RL-04', 'RS-07');

it('no crea particiones con la transaccion de la aplicacion abierta', function (): void {
    $auditoria = resolve(KioskClockingAudit::class);
    $enero = new DateTimeImmutable('2029-01-10T08:00:00Z');

    expect(fn () => DB::transaction(fn () => $auditoria->preparePartitionsBetween($enero, $enero)))
        ->toThrow(LogicException::class, 'antes de abrir la transaccion')
        ->and(resolve(AuditLogPartitions::class)->years())->not->toContain(2029);
})->group('RL-04');

it('no escribe ningun asiento de un lote cuyo año no tiene particion', function (): void {
    $auditoria = resolve(KioskClockingAudit::class);
    $tramo = new KioskClocking(
        employeeUuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
        siteId: WorkforceFixtures::site(),
        shiftEntryUuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b91',
        workDate: '2029-01-10',
        timezone: new DateTimeZone('Europe/Madrid'),
        clockedInAt: new DateTimeImmutable('2029-01-10T08:00:00Z'),
        clockedOutAt: new DateTimeImmutable('2029-01-10T16:00:00Z'),
        dailyTotalMinutes: 480,
    );

    expect(fn () => $auditoria->record([$tramo]))
        ->toThrow(LogicException::class, 'audit_log no tiene particion para 2029')
        ->and(DB::table('audit_log')->count())->toBe(0);
})->group('RL-04');
