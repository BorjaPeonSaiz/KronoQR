<?php

declare(strict_types=1);

use App\Modules\Compliance\Domain\AuditChain;
use App\Modules\Compliance\Domain\ValueObject\AuditableEvent;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditActionName;
use App\Modules\Compliance\Domain\ValueObject\AuditActor;
use App\Modules\Compliance\Domain\ValueObject\AuditEntryDraft;
use App\Modules\Compliance\Domain\ValueObject\AuditPayload;
use App\Modules\Compliance\Domain\ValueObject\AuditSubject;
use Tests\Support\Time\Instants;

/*
 * El nombre de la accion, separado del catalogo cerrado.
 *
 * POR QUE EXISTE ESTA PRUEBA. La cadena de auditoria se verifica por hash, no
 * por catalogo (doc 02 §7.4): la accion entra en el hash como cadena literal.
 * Un verificador de la version N tiene que poder recorrer filas escritas por la
 * version N+1 —las acciones nuevas las estrena SIEMPRE la version siguiente—, y
 * con `AuditAction::from()` en la lectura no podia: reventaba con un `ValueError`
 * y el verificador diario de RS-07 dejaba de correr. Paso de verdad en la etapa
 * ⑧b de la CI del cierre de Fase 5, con `system.restored_from_backup`.
 *
 * Lo que se fija aqui: leer nunca falla, escribir sigue exigiendo el catalogo, y
 * el hash de una fila no depende de que esta version reconozca su accion.
 */

it('reconoce un nombre del catalogo y devuelve su caso', function (): void {
    $name = AuditActionName::fromStorage('shift_entry.created');

    expect($name->isKnown())->toBeTrue()
        ->and($name->known())->toBe(AuditAction::ShiftEntryCreated)
        ->and($name->value)->toBe('shift_entry.created')
        ->and($name->event())->toBe(AuditableEvent::ShiftEntryLifecycle);
})->group('RS-07', 'RL-04');

it('conserva como cadena un nombre que esta version no conoce, sin lanzar nada', function (): void {
    // Exactamente la forma del defecto: una accion `system.*` que la version
    // siguiente estrenara y esta no tiene en su enum.
    $name = AuditActionName::fromStorage('system.future_action');

    expect($name->isKnown())->toBeFalse()
        ->and($name->known())->toBeNull()
        ->and($name->value)->toBe('system.future_action');
})->group('RS-07', 'RL-04', 'RF-PD-10');

it('no clasifica en una familia una accion que no conoce', function (): void {
    // `AuditAction::event()` decide la familia por el prefijo. Aplicar ese
    // criterio a un nombre desconocido seria inventarse su semantica: se dice
    // «no lo se» y quien necesite la familia decide que hacer con el nulo.
    expect(AuditActionName::fromStorage('system.future_action')->event())->toBeNull()
        ->and(AuditActionName::fromStorage('cosa_nueva.hecha')->event())->toBeNull();
})->group('RS-07', 'RL-04');

it('no exige actor sistema a un nombre desconocido', function (): void {
    // La invariante «esto solo lo escribe el sistema» es del camino de
    // ESCRITURA, y ahi siempre hay enum. Un nombre desconocido solo llega desde
    // la base de datos: la fila ya existe y no hay nada que impedir. Si esto
    // devolviera `true` por el prefijo, verificar una fila `system.*` alterada
    // en su actor haria estallar al verificador en vez de denunciar la rotura.
    expect(AuditActionName::fromStorage('system.future_action')->requiresSystemActor())->toBeFalse()
        ->and(AuditActionName::of(AuditAction::SystemUpdated)->requiresSystemActor())->toBeTrue()
        ->and(AuditActionName::of(AuditAction::ShiftEntryCreated)->requiresSystemActor())->toBeFalse();
})->group('RS-07', 'RF-PD-10');

it('compara por el valor, no por la identidad del objeto', function (): void {
    expect(AuditActionName::of(AuditAction::SystemUpdated)->equals(AuditActionName::fromStorage('system.updated')))
        ->toBeTrue()
        ->and(AuditActionName::fromStorage('system.updated')->equals(AuditActionName::fromStorage('system.restored_from_backup')))
        ->toBeFalse();
})->group('RS-07');

it('da el mismo hash tanto si la accion se reconoce como si no', function (): void {
    // El nucleo del asunto. Si el hash dependiera de reconocer la accion, una
    // version antigua denunciaria como rota toda fila escrita por una nueva.
    $previous = AuditChain::genesisHash();

    $write = new AuditEntryDraft(
        occurredAt: Instants::utc('2026-09-09 03:20:00'),
        actor: AuditActor::system(),
        action: AuditAction::SystemUpdated,
        subject: AuditSubject::of('installation'),
        payload: AuditPayload::of(['to_version' => '2.2.0']),
    );

    // El mismo hecho, reconstruido al leer la fila por un binario que NO tiene
    // `system.updated` en su catalogo: el nombre sobrevive como cadena.
    $read = new AuditEntryDraft(
        occurredAt: Instants::utc('2026-09-09 03:20:00'),
        actor: AuditActor::system(),
        action: AuditActionName::fromStorage('system.updated'),
        subject: AuditSubject::of('installation'),
        payload: AuditPayload::of(['to_version' => '2.2.0']),
    );

    expect(AuditChain::hashFor($read, $previous))->toBe(AuditChain::hashFor($write, $previous));
})->group('RS-07', 'RL-04');

it('sigue distinguiendo dos acciones distintas en el hash', function (): void {
    // La contraparte: tolerar lo desconocido no puede colapsar nombres. Si dos
    // acciones distintas dieran el mismo hash, cambiar `credential.revoked` por
    // `credential.issued` en la tabla no dejaria rastro.
    $previous = AuditChain::genesisHash();

    $draftWith = static fn (string $action): AuditEntryDraft => new AuditEntryDraft(
        occurredAt: Instants::utc('2026-09-09 03:20:00'),
        actor: AuditActor::system(),
        action: AuditActionName::fromStorage($action),
        subject: AuditSubject::of('installation'),
        payload: AuditPayload::of([]),
    );

    expect(AuditChain::hashFor($draftWith('system.future_action'), $previous))
        ->not->toBe(AuditChain::hashFor($draftWith('system.other_action'), $previous));
})->group('RS-07', 'RL-04');
