<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;
use Tests\Feature\Quality\Support\Commands;
use Tests\Support\Compliance\IncidentFixtures;
use Tests\Support\Database\CommittedDatabase;
use Tests\Support\Database\SchemaOwner;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * El motor solo deja reabrir lo que cerro el sistema (RN-19, enmienda del
 * 27-10-2026 de ADR-057 §2; RL-04, RS-07, reglas duras 5 y 6).
 *
 * Contra PostgreSQL de verdad y con `fichaje_app`, que es quien reabre. La
 * unica transicion nueva es `resolved` → `open` de una `rejected_pin_scan` sin
 * autor ni nota, con `context` al dia. Lo que trabajo una persona —resuelta o
 * descartada— no se reabre, ningun otro tipo tampoco, y `context` sigue
 * inmutable fuera de esa transicion.
 *
 * `CommittedDatabase` porque la vuelta atras de la migracion corre en la sesion
 * del rol de migracion y tiene que ver la reapertura ya confirmada.
 */

uses(CommittedDatabase::class);

const INCIDENT_REOPENING_GUARD_MIGRATION = '2026_10_27_100100_allow_system_reopening_of_rejected_pin_scan';

/** El contexto de la reapertura: el intento tardio, sin subsanar. */
const INCIDENT_REOPENING_GUARD_CONTEXT = '{"scan_id":"019a0000-0000-7000-8000-0000000000a2","occurred_at":"2026-03-14T21:00:00Z","attempts":1,"lockout_attempts":0,"max_sync_delay_seconds":172800}';

/**
 * Una incidencia cerrada en la forma en que la cierra quien se pide: el sistema
 * (`CloseRecoveredIncident`, sin autor ni nota) o una persona.
 */
function reaperturaCerrada(string $type = 'rejected_pin_scan', string $by = 'system', string $outcome = 'resolved'): int
{
    $incident = IncidentFixtures::open(
        WorkforceFixtures::employee(WorkforceFixtures::site()),
        type: $type,
        severity: 'medium',
        context: ['scan_id' => '019a0000-0000-7000-8000-0000000000a1', 'attempts' => 1, 'lockout_attempts' => 0],
    );

    DB::table('incidents')->where('id', $incident)->where('status', 'open')->update([
        'status' => $outcome,
        'resolved_at' => '2026-03-16 03:30:00+00',
        'resolved_by_user_id' => $by === 'system' ? null : ManagementUsers::withRole(UserRole::RRHH)->id,
        'resolution_note' => $by === 'system' ? null : 'Revisado con la persona.',
        'updated_at' => '2026-03-16 03:30:00+00',
    ]);

    return $incident;
}

/**
 * La reapertura tal y como la escribe `DatabaseIncidentLedger::recordReopening`.
 *
 * @param  array<string, mixed>  $extra
 */
function reaperturaReabre(int $incident, array $extra = [], ?ConnectionInterface $connection = null): int
{
    return ($connection ?? DB::connection())->table('incidents')->where('id', $incident)->update([
        'status' => 'open',
        'resolved_at' => null,
        'resolved_by_user_id' => null,
        'resolution_note' => null,
        'context' => INCIDENT_REOPENING_GUARD_CONTEXT,
        'updated_at' => '2026-03-17 03:30:00+00',
        ...$extra,
    ]);
}

function reaperturaDenegada(string $what, Closure $write, ?ConnectionInterface $connection = null): void
{
    $connection ??= DB::connection();

    try {
        $connection->transaction(static function () use ($write, $connection): void {
            $write($connection);
        });
    } catch (PDOException $exception) {
        expect($exception->getCode())->toBe('42501', $what.': '.$exception->getMessage())
            ->and($exception->getMessage())->toContain('ADR-057');

        return;
    }

    Assert::fail('PostgreSQL ha admitido «'.$what.'».');
}

/**
 * @return object{status: string, resolved_at: string|null, resolved_by_user_id: int|null, resolution_note: string|null, context: string}
 */
function reaperturaFila(int $incident): object
{
    /** @var object{status: string, resolved_at: string|null, resolved_by_user_id: int|null, resolution_note: string|null, context: string} $row */
    $row = DB::table('incidents')->where('id', $incident)
        ->first(['status', 'resolved_at', 'resolved_by_user_id', 'resolution_note', 'context']);

    return $row;
}

it('el sistema reabre con el rol de la aplicacion la rejected_pin_scan que cerro, con el contexto al dia', function (): void {
    $incident = reaperturaCerrada();

    expect(reaperturaReabre($incident))->toBe(1);

    $row = reaperturaFila($incident);

    /** @var array<string, mixed> $context */
    $context = json_decode($row->context, true, 512, JSON_THROW_ON_ERROR);

    expect($row->status)->toBe('open')
        ->and($row->resolved_at)->toBeNull()
        ->and($row->resolved_by_user_id)->toBeNull()
        ->and($row->resolution_note)->toBeNull()
        ->and($context['scan_id'])->toBe('019a0000-0000-7000-8000-0000000000a2');

    // Y se puede volver a cerrar, sola o por una persona: es una abierta mas.
    expect(DB::table('incidents')->where('id', $incident)->where('status', 'open')->update([
        'status' => 'resolved',
        'resolved_at' => '2026-03-18 03:30:00+00',
        'updated_at' => '2026-03-18 03:30:00+00',
    ]))->toBe(1);
})->group('RN-19', 'RS-07', 'RL-04');

it('el motor no reabre lo que trabajo una persona ni otro tipo de incidencia', function (string $case): void {
    $incident = match ($case) {
        'resuelta por una persona' => reaperturaCerrada(by: 'person'),
        'descartada por una persona' => reaperturaCerrada(by: 'person', outcome: 'dismissed'),
        'de otro tipo cerrada sin persona' => reaperturaCerrada(type: 'out_of_order_scan'),
        default => throw new LogicException($case),
    };

    reaperturaDenegada('reabrir una '.$case, static fn (ConnectionInterface $connection): int => reaperturaReabre($incident, connection: $connection));

    // Ni siquiera el propietario: tambien pasa por el trigger.
    reaperturaDenegada(
        'el propietario reabre una '.$case,
        static fn (ConnectionInterface $owner): int => reaperturaReabre($incident, connection: $owner),
        SchemaOwner::connection(),
    );

    expect(reaperturaFila($incident)->status)->toBe($case === 'descartada por una persona' ? 'dismissed' : 'resolved');
})->with([
    'resuelta por una persona',
    'descartada por una persona',
    'de otro tipo cerrada sin persona',
])->group('RN-19', 'RS-07', 'RL-04', 'RN-13');

it('el motor no reabre una descartada sin autor ni nota', function (): void {
    // No existe en el producto —descartar es siempre de una persona—, pero el
    // esquema no lo da por supuesto: solo `resolved` vuelve a `open`.
    $incident = reaperturaCerrada(outcome: 'dismissed');

    reaperturaDenegada('reabrir una descartada', static fn (ConnectionInterface $connection): int => reaperturaReabre($incident, connection: $connection));
})->group('RN-19', 'RS-07', 'RL-04');

it('el motor rechaza una reapertura que deja la resolucion puesta o toca otra columna', function (string $case): void {
    $incident = reaperturaCerrada();

    $extra = match ($case) {
        'con el instante de cierre' => ['resolved_at' => '2026-03-16 03:30:00+00'],
        'con una nota' => ['resolution_note' => 'Reabierta.'],
        'con otra severidad' => ['severity' => 'high'],
        'con otra deteccion' => ['detected_at' => '2026-03-17 03:30:00+00'],
        default => throw new LogicException($case),
    };

    // Con el propietario, para que lo que niega sea el trigger y no el
    // privilegio de columna.
    reaperturaDenegada(
        'reabrir '.$case,
        static fn (ConnectionInterface $owner): int => reaperturaReabre($incident, $extra, $owner),
        SchemaOwner::connection(),
    );
})->with([
    'con el instante de cierre',
    'con una nota',
    'con otra severidad',
    'con otra deteccion',
])->group('RN-19', 'RS-07', 'RL-04');

it('el contexto no cambia fuera de la reapertura', function (string $case): void {
    $incident = match ($case) {
        'abierta' => IncidentFixtures::open(WorkforceFixtures::employee(WorkforceFixtures::site()), type: 'rejected_pin_scan', severity: 'medium'),
        'cerrada por el sistema, sin reabrirla' => reaperturaCerrada(),
        default => throw new LogicException($case),
    };

    reaperturaDenegada('cambiar el contexto de una '.$case, static fn (ConnectionInterface $connection): int => $connection->table('incidents')
        ->where('id', $incident)
        ->update(['context' => INCIDENT_REOPENING_GUARD_CONTEXT, 'updated_at' => '2026-03-17 03:30:00+00']));
})->with([
    'abierta',
    'cerrada por el sistema, sin reabrirla',
])->group('RN-19', 'RS-07', 'RL-04', 'RN-13');

it('su down() no deja a medias una reapertura ya hecha, y su up() la vuelve a admitir', function (): void {
    $name = config()->string('database.migrations.connection');
    $migrator = DB::connection($name);
    $reopened = reaperturaCerrada();
    reaperturaReabre($reopened);
    $closed = reaperturaCerrada();

    /** @var list<string> $applied */
    $applied = $migrator->table('migrations')->orderByDesc('id')->pluck('migration')->all();
    $steps = (int) array_search(INCIDENT_REOPENING_GUARD_MIGRATION, $applied, true) + 1;

    expect(in_array(INCIDENT_REOPENING_GUARD_MIGRATION, $applied, true))->toBeTrue();

    try {
        [$back, $backOutput] = Commands::run('migrate:rollback --database='.$name.' --step='.$steps);

        expect($back)->toBe(0, $backOutput);

        // La reabierta sigue entera: abierta, sin resolucion, con su contexto.
        $row = reaperturaFila($reopened);

        expect($row->status)->toBe('open')
            ->and($row->resolved_at)->toBeNull()
            ->and($row->context)->toContain('0000000000a2');

        // Sin la enmienda: ni reabrir ni tocar el contexto, y la reabierta se
        // resuelve como cualquier otra abierta.
        // Con el propietario: a la aplicacion ya se lo niega el privilegio.
        reaperturaDenegada(
            'reabrir sin la enmienda',
            static fn (ConnectionInterface $owner): int => reaperturaReabre($closed, connection: $owner),
            SchemaOwner::connection(),
        );

        expect(DB::table('incidents')->where('id', $reopened)->where('status', 'open')->update([
            'status' => 'resolved',
            'resolved_at' => '2026-03-18 09:00:00+00',
            'resolved_by_user_id' => ManagementUsers::withRole(UserRole::ADMIN)->id,
            'resolution_note' => 'Revisado.',
            'updated_at' => '2026-03-18 09:00:00+00',
        ]))->toBe(1);

        /** @var object{granted: bool} $granted */
        $granted = DB::selectOne("SELECT has_column_privilege(current_user, 'incidents', 'context', 'UPDATE') AS granted");

        expect($granted->granted)->toBeFalse();
    } finally {
        [$forward, $forwardOutput] = Commands::run('migrate --database='.$name);
    }

    expect($forward)->toBe(0, $forwardOutput)
        ->and(reaperturaReabre($closed))->toBe(1);
})->group('RN-19', 'RS-07', 'RL-04');
