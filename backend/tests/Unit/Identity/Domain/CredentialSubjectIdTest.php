<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Event\CredentialDelivered;
use App\Modules\Identity\Domain\Event\CredentialPrinted;
use App\Modules\Identity\Domain\Event\CredentialRevoked;
use App\Modules\Identity\Domain\Model\Credential;

/*
 * Los hechos de una credencial apuntan a su fila (RF-QR-03, RF-QR-04,
 * RF-QR-06, RL-04, R1-AR-03).
 *
 * El identificador es el `subject_id` del asiento. Antes, una credencial sin
 * guardar se publicaba con `?? 0` y dejaba un asiento sobre el sujeto 0; ahora
 * falla, en la credencial y en el evento.
 */

function credencialConFila(?int $id): Credential
{
    return new Credential(uuid: '0199f6a2-1111-7d3b-8a90-1b2c3d4e5f60', employeeId: 7, issuedAt: new DateTimeImmutable('2026-06-15T09:00:00Z'), id: $id);
}

it('da el identificador de la fila de una credencial guardada, y falla si no lo tiene', function (): void {
    expect(credencialConFila(42)->persistedId())->toBe(42)
        ->and(credencialConFila(42)->revoke('Perdida', new DateTimeImmutable('2026-06-16T09:00:00Z'))->persistedId())->toBe(42)
        ->and(static fn (): int => credencialConFila(null)->persistedId())->toThrow(LogicException::class);
})->group('RF-QR-03', 'RL-04');

it('los hechos de una credencial rechazan un identificador que no es de una fila', function (int $id): void {
    $at = new DateTimeImmutable('2026-06-15T09:00:00Z');
    $uuid = '0199f6a2-1111-7d3b-8a90-1b2c3d4e5f60';
    $employee = '0199f6a2-2222-7d3b-8a90-1b2c3d4e5f60';

    expect(static fn (): CredentialDelivered => new CredentialDelivered($id, $uuid, $employee, 3, 3, $at))->toThrow(InvalidArgumentException::class)
        ->and(static fn (): CredentialRevoked => new CredentialRevoked($id, $uuid, $employee, 'Perdida', 3, $at))->toThrow(InvalidArgumentException::class)
        ->and(static fn (): CredentialPrinted => new CredentialPrinted($id, $uuid, $employee, 'a3', false, 3, $at))->toThrow(InvalidArgumentException::class)
        ->and(new CredentialRevoked(1, $uuid, $employee, 'Perdida', null, $at)->credentialId)->toBe(1)
        ->and(new CredentialDelivered(1, $uuid, $employee, 3, null, $at)->credentialId)->toBe(1)
        ->and(new CredentialPrinted(1, $uuid, $employee, 'a3', true, null, $at)->credentialId)->toBe(1);
})->with(['cero' => 0, 'negativo' => -1])->group('RF-QR-03', 'RF-QR-04', 'RF-QR-06', 'RL-04');
