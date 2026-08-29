<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\ValueObject\TokenAbility;
use App\Modules\Shared\Domain\ValueObject\UserRole;

/*
 * El catalogo de roles de RF-ID-02 y los ambitos del doc 02 §7.3.
 *
 * Es el mismo para todos los clientes (regla dura 13, ADR-017): lo que cambia de
 * una instalacion a otra es quien tiene cada rol, nunca la lista.
 */

it('declara exactamente los seis roles de RF-ID-02', function (): void {
    $roles = array_map(static fn (UserRole $role): string => $role->value, UserRole::cases());

    expect($roles)->toBe([
        'admin',
        'rrhh',
        'responsable_departamento',
        'auditor',
        'empleado',
        'kiosk',
    ]);
})->group('RF-ID-02');

it('deja fuera del panel al empleado y al quiosco', function (): void {
    // El empleado entra a su portal con codigo y PIN (ADR-015, regla dura 12) y
    // el quiosco con token de dispositivo (RF-ID-04). Ninguno de los dos tiene
    // cuenta con contrasena, y una cuenta de gestion para ellos seria una puerta
    // que nadie ha pedido.
    expect(UserRole::EMPLEADO->isManagementRole())->toBeFalse()
        ->and(UserRole::KIOSK->isManagementRole())->toBeFalse()
        ->and(UserRole::ADMIN->isManagementRole())->toBeTrue()
        ->and(UserRole::RRHH->isManagementRole())->toBeTrue()
        ->and(UserRole::AUDITOR->isManagementRole())->toBeTrue()
        ->and(UserRole::RESPONSABLE_DEPARTAMENTO->isManagementRole())->toBeTrue();
})->group('RF-ID-02');

it('declara los ambitos del documento 02 §7.3 y ninguno mas', function (): void {
    $abilities = TokenAbility::names();
    sort($abilities);

    // La misma lista que el contrato OpenAPI declara en `securitySchemes`. Que
    // las dos coincidan lo comprueba ademas la suite de contrato: son tres
    // copias —enum, contrato y migracion del catalogo— y ninguna puede irse por
    // su cuenta.
    expect($abilities)->toBe([
        // La sesion pendiente de segundo factor (RS-06, tarea 2.1). No es un
        // ambito del §7.3 y no concede nada del producto: abre solo los tres
        // endpoints de `/auth/2fa/*`.
        '2fa:pending',
        'attendance:correct',
        'attendance:read',
        'audit:read',
        'credentials:*',
        'diagnostics:*',
        // La familia de plantilla partida en dos (RF-ID-03, tarea 2.1): leer con
        // el alcance del rol es una potestad distinta de modificar.
        'employees:*',
        'employees:read',
        'heartbeat:write',
        'incidents:*',
        'license:*',
        'reports:*',
        'reports:legal',
        'roster:read',
        'scan:write',
        'self:read',
        'settings:*',
        'support:*',
    ]);
})->group('RS-04', 'RF-ID-02');

it('no reconoce un ambito inventado', function (): void {
    expect(TokenAbility::tryFromName('employees:write'))->toBeNull()
        ->and(TokenAbility::tryFromName('*'))->toBeNull();
})->group('RS-04');

it('mantiene el ambito de la sesion pendiente fuera de los del quiosco', function (): void {
    // RS-04 y RS-06: los tres ambitos del quiosco estan escritos en un solo
    // sitio, y el reto de segundo factor no puede colarse ahi. Un token de
    // tablet con `2fa:pending` podria canjearse por una sesion de gestion.
    $kiosk = array_map(
        static fn (TokenAbility $ability): string => $ability->value,
        TokenAbility::kioskAbilities(),
    );

    expect($kiosk)->toBe(['scan:write', 'roster:read', 'heartbeat:write'])
        ->and($kiosk)->not->toContain(TokenAbility::TWO_FACTOR_PENDING->value)
        ->and($kiosk)->not->toContain(TokenAbility::EMPLOYEES_READ->value);
})->group('RS-04', 'RS-06');
