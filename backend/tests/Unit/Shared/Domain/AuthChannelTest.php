<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\AuthChannel;
use App\Modules\Shared\Domain\ValueObject\AuthFailureReason;
use App\Modules\Shared\Domain\ValueObject\AuthOutcome;
use App\Modules\Shared\Domain\ValueObject\PinOrigin;

/*
 * El vocabulario del rastro de autenticacion (OWASP A09, RS-12).
 *
 * Son tres enumeraciones y una traduccion, y aun asi tienen prueba propia por un
 * motivo concreto: **sus valores son etiquetas de una metrica y campos de un
 * asiento de `audit_log`**. Renombrar un caso no rompe la compilacion, rompe en
 * silencio la alerta que agrupa por canal y deja huerfano el historico de la
 * tabla que se enseña en una inspeccion. Estas pruebas convierten ese cambio
 * silencioso en un fallo.
 */

it('nombra los tres canales exactamente como los nombran la metrica y el asiento', function (): void {
    expect(array_map(
        static fn (AuthChannel $channel): string => $channel->value,
        AuthChannel::cases(),
    ))->toBe(['management', 'portal', 'kiosk_pin']);
})->group('RS-12');

it('nombra los tres desenlaces exactamente como los nombra la etiqueta outcome', function (): void {
    expect(array_map(
        static fn (AuthOutcome $outcome): string => $outcome->value,
        AuthOutcome::cases(),
    ))->toBe(['success', 'failure', 'lockout']);
})->group('RS-12');

it('resuelve el tipo de sujeto por el canal, para que no lo declare quien deja el rastro', function (): void {
    expect(AuthChannel::MANAGEMENT->subjectType())->toBe('user')
        ->and(AuthChannel::PORTAL->subjectType())->toBe('employee')
        ->and(AuthChannel::KIOSK_PIN->subjectType())->toBe('employee');
})->group('RS-12');

it('solo audita los hechos de sesion del panel', function (): void {
    // `audit_log.actor_type` no tiene tipo para un empleado (ADR-037): un
    // asiento de «este empleado abrio su portal» saldria atribuido a `system`,
    // que seria falso. El bloqueo si se audita en los tres canales, y no pasa
    // por aqui: lo decide el servidor, asi que su actor es verdadero.
    expect(AuthChannel::MANAGEMENT->sessionEventsAreAudited())->toBeTrue()
        ->and(AuthChannel::PORTAL->sessionEventsAreAudited())->toBeFalse()
        ->and(AuthChannel::KIOSK_PIN->sessionEventsAreAudited())->toBeFalse();
})->group('RS-13', 'RS-12', 'RS-05');

it('traduce cada puerta del PIN a su canal, en un solo sitio', function (): void {
    // Dos vocabularios que describen lo mismo: `PinOrigin` es parte de una clave
    // de cache y `AuthChannel` es una etiqueta de metrica. Si la traduccion
    // viviera en cada llamante, el contador contaria por una y la alerta
    // agruparia por otra.
    expect(PinOrigin::KIOSK->authChannel())->toBe(AuthChannel::KIOSK_PIN)
        ->and(PinOrigin::PORTAL->authChannel())->toBe(AuthChannel::PORTAL);
})->group('RS-12');

it('no nombra ningun motivo de fallo que separe lo que la respuesta no separa', function (): void {
    // RS-03. El catalogo no puede crecer con «codigo inexistente» ni «PIN
    // incorrecto»: el servidor devuelve un unico rechazo justamente para que no
    // exista la rama que los distingue, y un motivo nuevo obligaria a crearla.
    expect(array_map(
        static fn (AuthFailureReason $reason): string => $reason->value,
        AuthFailureReason::cases(),
    ))->toBe([
        'invalid_credentials',
        'locked',
        'sealed_pin_unreadable',
        'session_not_issued',
    ]);
})->group('RS-03', 'RS-12');
