<?php

declare(strict_types=1);

use App\Modules\Workforce\Application\Command\PinProvisioning;
use App\Modules\Workforce\Application\Command\RegisterEmployeeCommand;

/*
 * Que hace el alta con el PIN (RF-ID-09, RF-GP-05), decidido en el comando.
 *
 * Diferir el PIN solo lo puede pedir la importacion masiva: un alta individual
 * sin PIN dejaria a alguien sin fichaje de respaldo (RF-AT-11) ni portal
 * (RL-05). El comando hace ese estado imposible de construir.
 */

it('emite el PIN en el alta si nadie dice otra cosa', function (): void {
    $command = new RegisterEmployeeCommand(
        departmentId: null,
        firstName: 'Youssef',
        lastName: 'Amrani',
        email: null,
        nationalId: null,
        hiredAt: '2026-01-15',
        locale: 'es',
    );

    expect($command->pin)->toBe(PinProvisioning::IssueNow)
        ->and($command->viaImport)->toBeFalse();
})->group('RF-ID-09', 'RF-GP-01');

it('no deja diferir el PIN de un alta individual', function (): void {
    new RegisterEmployeeCommand(
        departmentId: null,
        firstName: 'Youssef',
        lastName: 'Amrani',
        email: null,
        nationalId: null,
        hiredAt: '2026-01-15',
        locale: 'es',
        pin: PinProvisioning::DeferredToCardHandover,
        viaImport: false,
    );
})->throws(InvalidArgumentException::class)->group('RF-ID-09', 'RF-GP-05');

it('deja diferir el PIN de un alta por importacion', function (): void {
    $command = new RegisterEmployeeCommand(
        departmentId: null,
        firstName: 'Youssef',
        lastName: 'Amrani',
        email: null,
        nationalId: null,
        hiredAt: '2026-01-15',
        locale: 'es',
        pin: PinProvisioning::DeferredToCardHandover,
        viaImport: true,
    );

    expect($command->pin)->toBe(PinProvisioning::DeferredToCardHandover)
        ->and($command->viaImport)->toBeTrue();
})->group('RF-ID-09', 'RF-GP-05');

it('emite el PIN de un alta por importacion si no pide diferirlo', function (): void {
    $command = new RegisterEmployeeCommand(
        departmentId: null,
        firstName: 'Youssef',
        lastName: 'Amrani',
        email: null,
        nationalId: null,
        hiredAt: '2026-01-15',
        locale: 'es',
        viaImport: true,
    );

    expect($command->pin)->toBe(PinProvisioning::IssueNow);
})->group('RF-ID-09', 'RF-GP-05');
