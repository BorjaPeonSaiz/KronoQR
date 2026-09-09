<?php

declare(strict_types=1);

use App\Modules\Compliance\Application\Port\AuditChainHead;
use App\Modules\Compliance\Application\UseCase\VerifyAuditChain;
use App\Modules\Compliance\Domain\ValueObject\SystemRestoreReason;
use App\Modules\Compliance\Domain\ValueObject\SystemUpdateStep;
use App\Modules\Compliance\Infrastructure\Persistence\AuditLogSchema;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;

/*
 * El asiento que deja el instalador, contra PostgreSQL de verdad (RF-PD-10,
 * RL-04, RS-07, regla dura 6, tarea 5.7).
 *
 * POR QUE NO PODIA SER UNITARIA. Lo que hay que demostrar aqui es que el asiento
 * **entra en la cadena real**: que se encadena sobre el ultimo eslabon que
 * habia, que `compliance:verify-audit-chain` sigue en verde despues y que el
 * `prev_hash` que queda escrito es exactamente la punta que el instalador leyo
 * un instante antes. Ninguna de las tres cosas se puede comprobar con un doble
 * en memoria: viven en el motor, en el candado de ADR-010 y en la secuencia.
 *
 * EL ESCENARIO CENTRAL es el de la vuelta atras. La cadena que queda tras
 * restaurar una copia es integra —es la de la copia— y el verificador sale en
 * verde: el intervalo descartado no deja hueco. Lo unico que lo hace visible es
 * un asiento escrito ENCIMA de la cadena restaurada, cuyo `prev_hash` es el
 * ultimo hecho que sobrevivio.
 */

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $data
 */
function recordSystemEvent(string $action, array $data): int
{
    return Artisan::call('compliance:record-system-event', [
        'action' => $action,
        '--data' => json_encode($data, JSON_THROW_ON_ERROR),
    ]);
}

/**
 * @return array<string, mixed>
 */
function updatePayload(): array
{
    return [
        'from_version' => '1.3.2',
        'to_version' => '1.4.0',
        'migrations_applied' => 2,
        'chain_before' => str_repeat('a', 64),
        'chain_after' => str_repeat('b', 64),
        'backup_fingerprint' => str_repeat('c', 64),
        'report_id' => 'update-20260909T031500Z',
    ];
}

/**
 * @return array<string, mixed>
 */
function restorePayload(): array
{
    return [
        'backup_file' => 'kronoqr-20260909-031204.dump',
        'backup_fingerprint' => str_repeat('e', 64),
        'backup_taken_at' => '2026-09-09T03:12:04Z',
        'failed_step' => SystemUpdateStep::Migrations->value,
        'reason' => SystemRestoreReason::MigrationFailed->value,
        'from_version' => '1.3.2',
        'to_version' => '1.4.0',
    ];
}

/**
 * @return object{id: int, actor_type: string, actor_id: ?int, action: string, subject_type: ?string, subject_id: ?int, payload: string, prev_hash: string, hash: string, ip: ?string, user_agent: ?string}
 */
function lastAuditRow(): object
{
    /** @var object{id: int, actor_type: string, actor_id: ?int, action: string, subject_type: ?string, subject_id: ?int, payload: string, prev_hash: string, hash: string, ip: ?string, user_agent: ?string} $row */
    $row = DB::table(AuditLogSchema::TABLE)->orderByDesc('id')->firstOrFail();

    return $row;
}

it('deja el asiento de la actualizacion firmado por el sistema y sobre la instalacion', function (): void {
    expect(recordSystemEvent('system.updated', updatePayload()))->toBe(0);

    $row = lastAuditRow();

    expect($row->action)->toBe('system.updated')
        // No hay sesion de nadie detras: lo escribe el instalador.
        ->and($row->actor_type)->toBe('system')
        ->and($row->actor_id)->toBeNull()
        // El hecho no recae sobre ninguna fila: recae sobre el producto entero.
        ->and($row->subject_type)->toBe('installation')
        ->and($row->subject_id)->toBeNull()
        // Ni IP ni agente: no viene de una peticion.
        ->and($row->ip)->toBeNull()
        ->and($row->user_agent)->toBeNull()
        // `toEqual` y no `toBe`: PostgreSQL no conserva el orden de las claves
        // de un `jsonb` —las ordena por longitud y despues por bytes—, y da
        // igual, porque la forma canonica ordena al leer (ver `AuditPayload`).
        ->and(json_decode($row->payload, true, 8, JSON_THROW_ON_ERROR))->toEqual(updatePayload());
})->group('RL-04', 'RS-07', 'RF-PD-10');

it('encadena el asiento sobre la punta que habia y deja la cadena verificable', function (): void {
    // Primero un asiento cualquiera, para que la cadena no este vacia: lo que se
    // comprueba es que el asiento del sistema se ENGANCHA a lo que ya hay.
    expect(recordSystemEvent('system.updated', updatePayload()))->toBe(0);

    /** @var AuditChainHead $head */
    $head = app(AuditChainHead::class);
    $before = $head->snapshot();

    expect(recordSystemEvent('system.restored_from_backup', restorePayload()))->toBe(0);

    $row = lastAuditRow();

    // ESTE es el punto entero de la tarea: el `prev_hash` del asiento de la
    // vuelta atras es la huella del ultimo hecho que sobrevivio a la
    // restauracion. El asiento dice, dentro del propio registro, «lo que hay
    // antes de mi es la copia de las 03:12».
    expect($row->action)->toBe('system.restored_from_backup')
        ->and($row->prev_hash)->toBe($before->hash)
        ->and($before->lastEntryId)->toBeLessThan($row->id);

    /** @var VerifyAuditChain $verify */
    $verify = app(VerifyAuditChain::class);

    expect($verify->handle()->isIntact())->toBeTrue()
        ->and(Artisan::call('compliance:verify-audit-chain'))->toBe(0);
})->group('RL-04', 'RS-07', 'RF-PD-10');

it('publica la punta de la cadena para que el instalador pueda anotarla', function (): void {
    expect(recordSystemEvent('system.updated', updatePayload()))->toBe(0);

    $row = lastAuditRow();

    expect(Artisan::call('compliance:audit-chain-head'))->toBe(0);

    /** @var array{hash: string, last_entry_id: int} $printed */
    $printed = json_decode(trim(Artisan::output()), true, 8, JSON_THROW_ON_ERROR);

    expect($printed['hash'])->toBe($row->hash)
        ->and($printed['last_entry_id'])->toBe($row->id);
})->group('RS-07', 'RF-PD-10');

it('no escribe nada cuando el payload no cumple la lista cerrada', function (array $data, string $action): void {
    // Falla cerrado y sin dejar rastro a medias: o el asiento es el que el
    // catalogo describe, o no hay asiento. Un asiento con una clave inventada no
    // se puede corregir despues (la tabla no admite UPDATE).
    expect(recordSystemEvent($action, $data))->toBe(1)
        ->and(DB::table(AuditLogSchema::TABLE)->count())->toBe(0);
})->with([
    'clave fuera de la lista' => [[...updatePayload(), 'operator' => 'turno de noche'], 'system.updated'],
    'falta un obligatorio' => [['from_version' => '1.3.2'], 'system.updated'],
    'un correo dentro' => [
        [...restorePayload(), 'backup_file' => 'copia-de-ana.perez@hotel.example.dump'],
        'system.restored_from_backup',
    ],
    'la ruta absoluta de la copia' => [
        [...restorePayload(), 'backup_file' => '/var/backups/kronoqr/daily/copia.dump'],
        'system.restored_from_backup',
    ],
])->group('RS-07', 'RF-PD-10');

it('no admite por consola ninguna accion que no sea del ciclo de vida de la instalacion', function (string $action): void {
    // El comando no es una puerta generica de escritura de `audit_log`: los
    // demas asientos los produce el codigo que produce el hecho. Si esta puerta
    // admitiera `shift_entry.created`, cualquiera con acceso a la consola podria
    // fabricar un fichaje en el registro legal.
    expect(recordSystemEvent($action, updatePayload()))->toBe(1)
        ->and(DB::table(AuditLogSchema::TABLE)->count())->toBe(0);
})->with([
    'shift_entry.created',
    'legal_export.generated',
    'retention.partition_dropped',
    'system.rebooted',
])->group('RS-07', 'RF-PD-10');

it('rechaza un --data que no es un objeto JSON', function (?string $data): void {
    expect(Artisan::call('compliance:record-system-event', array_filter([
        'action' => 'system.updated',
        '--data' => $data,
    ], static fn (?string $value): bool => $value !== null)))->toBe(1)
        ->and(DB::table(AuditLogSchema::TABLE)->count())->toBe(0);
})->with([
    'JSON roto' => ['{"from_version":'],
    'una lista' => ['["1.3.2","1.4.0"]'],
    'vacio' => [''],
    'sin la opcion' => [null],
])->group('RF-PD-10');
