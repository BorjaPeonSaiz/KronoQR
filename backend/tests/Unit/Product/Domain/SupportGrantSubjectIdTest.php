<?php

declare(strict_types=1);

use App\Modules\Product\Domain\Event\SupportAccessGranted;
use App\Modules\Product\Domain\Model\SupportGrant;
use App\Modules\Product\Domain\ValueObject\SupportGrantAuthor;
use App\Modules\Product\Domain\ValueObject\SupportScope;

/*
 * La concesion de soporte es el `actor_id` con el que actuara y el sujeto de su
 * asiento (RF-PD-11, RL-04, R1-AR-03): sin fila, falla en lugar de apuntar al 0.
 */

function concesionDeSoporteSinGuardar(): SupportGrant
{
    return SupportGrant::grant(
        uuid: '0199f6a2-3333-7d3b-8a90-1b2c3d4e5f60',
        grantedBy: new SupportGrantAuthor(id: 7, uuid: '0199f6a2-4c1e-7d3b-8a90-1b2c3d4e5f60', name: 'Cuenta de prueba'),
        reason: 'Incidencia #123',
        scope: SupportScope::Diagnostics,
        hours: 24,
        maximumHours: 72,
        grantedAt: new DateTimeImmutable('2026-06-15T09:00:00Z'),
    );
}

it('da el identificador de la concesion guardada, y falla si no lo tiene', function (): void {
    expect(concesionDeSoporteSinGuardar()->withId(9)->persistedId())->toBe(9)
        ->and(static fn (): int => concesionDeSoporteSinGuardar()->persistedId())->toThrow(LogicException::class);
})->group('RF-PD-11', 'RL-04');

it('el hecho de la concesion rechaza un identificador que no es de una fila', function (int $id): void {
    $at = new DateTimeImmutable('2026-06-15T09:00:00Z');

    expect(static fn (): SupportAccessGranted => new SupportAccessGranted($id, '0199f6a2-3333-7d3b-8a90-1b2c3d4e5f60', 'diagnostics', 24, $at, 'Incidencia #123', 7, $at))
        ->toThrow(InvalidArgumentException::class)
        ->and(new SupportAccessGranted(1, '0199f6a2-3333-7d3b-8a90-1b2c3d4e5f60', 'diagnostics', 24, $at, 'Incidencia #123', 7, $at)->grantId)->toBe(1);
})->with(['cero' => 0, 'negativo' => -1])->group('RF-PD-11', 'RL-04');
