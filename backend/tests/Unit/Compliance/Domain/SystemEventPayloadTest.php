<?php

declare(strict_types=1);

use App\Modules\Compliance\Domain\Exception\AuditActorNotAllowedForAction;
use App\Modules\Compliance\Domain\Exception\InvalidSystemEventPayload;
use App\Modules\Compliance\Domain\ValueObject\AuditableEvent;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditActor;
use App\Modules\Compliance\Domain\ValueObject\AuditEntryDraft;
use App\Modules\Compliance\Domain\ValueObject\AuditPayload;
use App\Modules\Compliance\Domain\ValueObject\AuditSubject;
use App\Modules\Compliance\Domain\ValueObject\SystemEventPayload;
use App\Modules\Compliance\Domain\ValueObject\SystemRestoreReason;
use App\Modules\Compliance\Domain\ValueObject\SystemUpdateStep;

/*
 * El payload de los dos asientos que deja el instalador (RF-PD-10, RL-04,
 * RS-07, tarea 5.7).
 *
 * POR QUE ESTAS PRUEBAS SON DISTINTAS DE LAS DEMAS DEL CATALOGO. Todos los
 * demas payloads de `audit_log` los construye codigo tipado de la aplicacion.
 * Estos dos los construye `update.sh` —un script de shell, en el peor momento
 * posible— y llegan por la linea de comandos como JSON. Lo que aqui se prueba no
 * es que el dominio sepa formatear un array: es que sepa decir que NO a lo que
 * un script puede colar sin querer, en una tabla que despues no admite ni un
 * `UPDATE`.
 */

/**
 * Un payload de actualizacion valido, para partir de el y estropear un campo.
 *
 * @return array<string, mixed>
 */
function updatedData(): array
{
    return [
        'from_version' => '1.3.2',
        'to_version' => '1.4.0',
        'migrations_applied' => 3,
        'chain_before' => str_repeat('a', 64),
        'chain_after' => str_repeat('b', 64),
        'backup_fingerprint' => str_repeat('c', 64),
        'report_id' => 'update-20260909T031500Z',
    ];
}

/**
 * Un payload de vuelta atras valido.
 *
 * @return array<string, mixed>
 */
function restoredData(): array
{
    return [
        'backup_file' => 'fichaje-20260909-031200.dump.gpg',
        'backup_fingerprint' => str_repeat('d', 64),
        'backup_taken_at' => '2026-09-09T03:12:04Z',
        'failed_step' => SystemUpdateStep::Migrations->value,
        'reason' => SystemRestoreReason::MigrationFailed->value,
        'from_version' => '1.3.2',
        'to_version' => '1.4.0',
    ];
}

it('acepta el payload completo de una actualizacion', function (): void {
    $event = SystemEventPayload::for(AuditAction::SystemUpdated, updatedData());

    expect($event->action)->toBe(AuditAction::SystemUpdated)
        ->and($event->payload)->toBeInstanceOf(AuditPayload::class)
        ->and($event->payload->data)->toBe(updatedData());
})->group('RL-04', 'RS-07', 'RF-PD-10');

it('acepta el payload completo de una vuelta atras', function (): void {
    $event = SystemEventPayload::for(AuditAction::SystemRestoredFromBackup, restoredData());

    expect($event->payload->data)->toBe(restoredData());
})->group('RL-04', 'RS-07', 'RF-PD-10');

it('exige los campos sin los que el asiento no dice nada', function (string $action, array $data, string $field): void {
    unset($data[$field]);

    expect(fn () => SystemEventPayload::for(AuditAction::from($action), $data))
        ->toThrow(InvalidSystemEventPayload::class, 'Falta el campo obligatorio «'.$field.'»');
})->with([
    // Sin las versiones, el asiento no dice de donde a donde fue la instalacion,
    // que es justo lo que permite explicar por que una jornada de marzo se
    // calculo distinto de una de abril.
    ['system.updated', updatedData(), 'from_version'],
    ['system.updated', updatedData(), 'to_version'],
    ['system.updated', updatedData(), 'migrations_applied'],
    // Sin el fichero y el momento de la copia, «restaure una copia» no acota
    // nada: el intervalo descartado no se puede delimitar.
    ['system.restored_from_backup', restoredData(), 'backup_file'],
    ['system.restored_from_backup', restoredData(), 'backup_taken_at'],
    ['system.restored_from_backup', restoredData(), 'failed_step'],
    ['system.restored_from_backup', restoredData(), 'reason'],
    ['system.restored_from_backup', restoredData(), 'from_version'],
    ['system.restored_from_backup', restoredData(), 'to_version'],
])->group('RL-04', 'RF-PD-10');

it('deja fuera lo opcional sin protestar', function (): void {
    // La huella de la copia es opcional a proposito: calcular el sha256 de un
    // volcado de gigabytes puede tardar y puede fallar, y un asiento sin huella
    // vale infinitamente mas que ningun asiento.
    $data = restoredData();
    unset($data['backup_fingerprint']);

    expect(SystemEventPayload::for(AuditAction::SystemRestoredFromBackup, $data)->payload->data)
        ->not->toHaveKey('backup_fingerprint');
})->group('RF-PD-10');

it('rechaza cualquier clave fuera de la lista cerrada', function (): void {
    // El control que impide que el script se invente campos. Sin el, el payload
    // acabaria siendo un cajon y el catalogo dejaria de significar nada.
    $data = updatedData();
    $data['operator'] = 'quien lanzo la actualizacion';

    expect(fn () => SystemEventPayload::for(AuditAction::SystemUpdated, $data))
        ->toThrow(InvalidSystemEventPayload::class, 'no esta en la lista cerrada');
})->group('RL-04', 'RS-07');

it('no admite en una accion las claves de la otra', function (): void {
    // `reason` es del asiento de la vuelta atras. Admitirlo en el de la
    // actualizacion permitiria escribir un motivo de fallo en un asiento que
    // describe un exito.
    $data = updatedData();
    $data['reason'] = SystemRestoreReason::MigrationFailed->value;

    expect(fn () => SystemEventPayload::for(AuditAction::SystemUpdated, $data))
        ->toThrow(InvalidSystemEventPayload::class, 'no esta en la lista cerrada');
})->group('RL-04');

it('rechaza una accion que no es del ciclo de vida de la instalacion', function (): void {
    expect(fn () => SystemEventPayload::for(AuditAction::ShiftEntryCreated, []))
        ->toThrow(InvalidSystemEventPayload::class, 'no es del ciclo de vida de la instalacion');
})->group('RS-07');

it('rechaza un correo en cualquier campo', function (): void {
    // Regla dura 21. El nombre del fichero de copia lo compone el producto, pero
    // el instalador lo lee de disco: si alguien renombro la copia a mano con su
    // correo dentro, ese correo quedaria para siempre en una tabla solo-append
    // que ademas se exporta (RL-20) y viaja al fabricante (ADR-020).
    $data = restoredData();
    $data['backup_file'] = 'copia-de-ana.perez@hotel.example.dump';

    expect(fn () => SystemEventPayload::for(AuditAction::SystemRestoredFromBackup, $data))
        ->toThrow(InvalidSystemEventPayload::class, 'parece contener una direccion de correo');
})->group('RS-07', 'RF-PD-10');

it('rechaza un documento de identidad en cualquier campo', function (string $identifier): void {
    $data = restoredData();
    $data['backup_file'] = 'copia-'.$identifier.'.dump';

    expect(fn () => SystemEventPayload::for(AuditAction::SystemRestoredFromBackup, $data))
        ->toThrow(InvalidSystemEventPayload::class, 'parece contener un DNI o un NIE');
})->with([
    '12345678Z',
    'X1234567L',
])->group('RS-07', 'RF-PD-10');

it('rechaza la ruta absoluta de la copia y se queda con el nombre', function (): void {
    // La ruta describe la topografia del servidor del cliente y el trail sale de
    // la instalacion. El nombre basta para encontrar el fichero; el directorio
    // lo sabe quien opera la maquina.
    $data = restoredData();
    $data['backup_file'] = '/var/backups/kronoqr/daily/fichaje-20260909.dump';

    expect(fn () => SystemEventPayload::for(AuditAction::SystemRestoredFromBackup, $data))
        ->toThrow(InvalidSystemEventPayload::class);
})->group('RS-07', 'RF-PD-10');

it('exige la forma de cada campo', function (string $field, mixed $value): void {
    $data = restoredData();
    $data[$field] = $value;

    expect(fn () => SystemEventPayload::for(AuditAction::SystemRestoredFromBackup, $data))
        ->toThrow(InvalidSystemEventPayload::class, 'no cumple su forma');
})->with([
    'version que no lo es' => ['from_version', 'la de ayer'],
    'huella que no es sha256' => ['backup_fingerprint', 'ABCDEF'],
    // Regla dura 3: en el trail no hay instantes sin zona. Uno «casi» en UTC es
    // peor que ninguno, porque nadie lo mira dos veces.
    'instante sin Z' => ['backup_taken_at', '2026-09-09 03:12:04'],
    'instante en otra zona' => ['backup_taken_at', '2026-09-09T05:12:04+02:00'],
    'paso inventado' => ['failed_step', 'paso_4'],
    // El motivo es codigo cerrado y no la frase que el script le enseno al
    // operador: una frase traducida no se puede agrupar dos anos despues.
    'motivo en texto libre' => ['reason', 'fallo la migracion de la tabla de fichajes'],
    'informe con ruta' => ['report_id', 'informes/update-20260909T031500Z.log'],
])->group('RL-04', 'RS-07');

it('admite el recuento de migraciones o una lista corta, y nada mas', function (mixed $value, bool $valid): void {
    $data = updatedData();
    $data['migrations_applied'] = $value;

    $build = fn () => SystemEventPayload::for(AuditAction::SystemUpdated, $data);

    $valid
        ? expect($build()->payload->data['migrations_applied'])->toBe($value)
        : expect($build)->toThrow(InvalidSystemEventPayload::class);
})->with([
    'un recuento' => [7, true],
    // Cero es legitimo: una version puede no traer ninguna migracion.
    'ninguna' => [0, true],
    'una lista corta' => [['2026_09_13_100000_create_error_events_table'], true],
    'un recuento negativo' => [-1, false],
    'una lista larga' => [array_fill(0, 26, '2026_09_13_100000_create_error_events_table'), false],
    'nombres inventados' => [['la de los fichajes'], false],
    'un mapa' => [['first' => '2026_09_13_100000_create_error_events_table'], false],
])->group('RF-PD-10');

it('coloca las dos acciones en el ciclo de vida de la instalacion', function (AuditAction $action): void {
    // Ni retencion —aquella perdida es planificada y sellada— ni cambio de
    // parametro del calculo. Si alguien las moviera, esta prueba lo diria.
    expect($action->event())->toBe(AuditableEvent::InstallationLifecycle)
        ->and($action->requiresSystemActor())->toBeTrue();
})->with([
    AuditAction::SystemUpdated,
    AuditAction::SystemRestoredFromBackup,
])->group('RS-07', 'RF-PD-10');

it('no deja construir un asiento del sistema firmado por una persona', function (AuditActor $actor): void {
    // El estado imposible se rechaza al construir. Un
    // `system.restored_from_backup` con actor humano afirmaria que esa persona
    // decidio descartar un intervalo del registro, y la tabla no admite UPDATE:
    // esa afirmacion no se podria corregir nunca.
    expect(fn (): AuditEntryDraft => new AuditEntryDraft(
        occurredAt: new DateTimeImmutable('2026-09-09T03:20:00+00:00'),
        actor: $actor,
        action: AuditAction::SystemRestoredFromBackup,
        subject: AuditSubject::of('installation'),
        payload: AuditPayload::empty(),
    ))->toThrow(AuditActorNotAllowedForAction::class, 'solo puede escribirla el actor «system»');
})->with([
    'una cuenta de gestion' => AuditActor::user(1),
    'un quiosco' => AuditActor::device(1),
    // Ni siquiera el fabricante con una concesion de soporte: ADR-020 le da
    // acceso temporal a datos, no la potestad de firmar que restauro la base.
    'una concesion de soporte' => AuditActor::supportGrant(1),
    'el rol de mantenimiento' => AuditActor::maintenance(),
])->group('RL-04', 'RS-07', 'RF-PD-10');

it('deja construirlo con el actor sistema', function (): void {
    $draft = new AuditEntryDraft(
        occurredAt: new DateTimeImmutable('2026-09-09T03:20:00+00:00'),
        actor: AuditActor::system(),
        action: AuditAction::SystemUpdated,
        subject: AuditSubject::of('installation'),
        payload: SystemEventPayload::for(AuditAction::SystemUpdated, updatedData())->payload,
    );

    // El borrador guarda el NOMBRE de la accion (`AuditActionName`), no el caso
    // del enum: al leer una fila puede no haber enum detras. En el camino de
    // escritura, que es este, el caso siempre esta.
    expect($draft->action->known())->toBe(AuditAction::SystemUpdated)
        ->and($draft->action->value)->toBe('system.updated');
})->group('RF-PD-10');
