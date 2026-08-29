<?php

declare(strict_types=1);

use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Identity\Domain\Event\ManagementRoleAssigned;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Quality\Support\Commands;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Identity\ManagementUsers;

/*
 * `identity:2fa-reset` — la unica via del producto para retirar un segundo factor
 * (**RS-06**).
 *
 * **Por que existe.** Sin esto, perder el telefono deja a alguien fuera de su
 * cuenta para siempre, y a una instalacion con un solo administrador **sin
 * panel**. Los codigos de recuperacion —la alternativa habitual— son otra
 * credencial que emitir, entregar y custodiar, y quedan como deuda anotada.
 *
 * **Por que es consola y no API.** El Anexo B del doc 01 no tiene ninguna ruta de
 * gestion de usuarios, y un «quitale el segundo factor a esta persona» por API
 * seria, en manos de un administrador comprometido, la forma mas comoda de
 * preparar el acceso a la cuenta de otro.
 */

uses(RefreshDatabase::class);

it('retira el segundo factor y lo deja escrito en audit_log', function (): void {
    $user = ManagementUsers::withRole(UserRole::ADMIN);
    ManagementUsers::withActiveSecondFactor($user);

    [$exit, $output] = Commands::run('identity:2fa-reset '.$user->uuid.' --reason="Telefono perdido"');

    expect($exit)->toBe(0)
        // Sin el correo ni el nombre en la salida: este comando se ejecuta a
        // menudo con la salida redirigida a un fichero de instalacion.
        ->and($output)->not->toContain($user->email);

    $fila = DB::table('users')->where('uuid', $user->uuid)->first();

    expect($fila)->not->toBeNull()
        ->and($fila?->two_factor_secret)->toBeNull()
        ->and($fila?->two_factor_confirmed_at)->toBeNull()
        ->and($fila?->two_factor_last_slice)->toBeNull();

    $asiento = DB::table('audit_log')
        ->where('action', AuditAction::TwoFactorReset->value)
        ->orderByDesc('id')
        ->first();

    expect($asiento)->not->toBeNull();

    $payload = (string) json_encode($asiento);

    // El motivo y el UUID si; el correo y el secreto, nunca (regla dura 21).
    expect($payload)->toContain($user->uuid)
        ->and($payload)->toContain('Telefono perdido')
        ->and($payload)->not->toContain($user->email)
        ->and($payload)->not->toContain(ManagementUsers::TOTP_SECRET);
})->group('RS-06', 'RS-05', 'RF-ID-01');

it('anota un motivo por omision en lugar de dejar el asiento mudo', function (): void {
    $user = ManagementUsers::withRole(UserRole::RRHH);
    ManagementUsers::withActiveSecondFactor($user);

    Commands::run('identity:2fa-reset '.$user->uuid);

    $asiento = DB::table('audit_log')
        ->where('action', AuditAction::TwoFactorReset->value)
        ->orderByDesc('id')
        ->first();

    expect((string) json_encode($asiento))->toContain('Sin motivo declarado');
})->group('RS-05', 'RS-06');

it('falla sin escribir nada cuando la cuenta no existe', function (): void {
    [$exit] = Commands::run('identity:2fa-reset 0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90');

    expect($exit)->toBe(1)
        ->and(DB::table('audit_log')->where('action', AuditAction::TwoFactorReset->value)->count())->toBe(0);
})->group('RS-06');

it('sella en audit_log la asignacion de un rol, con el rol y sin el correo', function (): void {
    // RS-05, bloque D: un rol decide quien puede corregir horas y quien ve la
    // plantilla entera. Sin traza, «¿quien le dio acceso a esta persona al
    // registro de todo el hotel?» no tiene respuesta, y es la pregunta que se
    // hace despues de un incidente.
    //
    // Se publica el evento en vez de invocar `identity:create-user`, que pide la
    // contrasena por consola con eco apagado y no se puede alimentar desde aqui.
    // Lo que esta prueba protege es **el cableado**: que el listener este
    // registrado y traduzca el hecho al vocabulario cerrado de `AuditAction`.
    $user = ManagementUsers::withRole(UserRole::RRHH);

    Event::dispatch(new ManagementRoleAssigned(
        userUuid: $user->uuid,
        role: UserRole::RRHH,
        actorUuid: null,
        occurredAt: new DateTimeImmutable('2026-08-30T09:00:00Z'),
    ));

    $asiento = DB::table('audit_log')
        ->where('action', AuditAction::RoleAssignmentChanged->value)
        ->orderByDesc('id')
        ->first();

    expect($asiento)->not->toBeNull();

    $payload = (string) json_encode($asiento);

    expect($payload)->toContain($user->uuid)
        ->and($payload)->toContain('rrhh')
        // Sin sesion detras, el actor es el sistema: atribuirlo a la ultima
        // persona que entro al panel seria falsificar el trail.
        ->and($asiento?->actor_type)->toBe('system')
        ->and($payload)->not->toContain($user->email);
})->group('RS-05', 'RF-ID-02');
