<?php

declare(strict_types=1);

use App\Modules\Compliance\Domain\ValueObject\AuditableEvent;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;

/*
 * El bloque D de `/revision-cumplimiento` convertido en algo que falla solo.
 *
 * La lista de que DEBE escribir en audit_log vivia unicamente en una skill en
 * Markdown: una accion nueva podia nacer sin auditoria y nada lo delataba. Estas
 * pruebas no comprueban que alguien llame al escritor —eso lo hara la prueba de
 * la tarea que lo llame—, comprueban que el CATALOGO sigue cubriendo todas sus
 * familias y que nadie añade una accion sin decir a cual pertenece.
 */

it('cubre cada familia auditable con al menos una accion', function (): void {
    $covered = array_unique(array_map(
        static fn (AuditAction $action): string => $action->event()->value,
        AuditAction::cases(),
    ));

    $missing = array_diff(
        array_map(static fn (AuditableEvent $event): string => $event->value, AuditableEvent::cases()),
        $covered,
    );

    expect($missing)->toBe(
        [],
        'El bloque D de /revision-cumplimiento enumera hechos que OBLIGAN a auditar y estos se han '
        .'quedado sin ninguna accion en el catalogo: '.implode(', ', $missing)
    );
})->group('RS-07');

it('nombra el ciclo completo de cada familia que el bloque D enumera', function (string $action): void {
    // El bloque D no dice «credenciales»: dice «emite, imprime, entrega, revoca
    // o reemite». Los cinco verbos, uno a uno, porque olvidar «imprime» es
    // exactamente el descuido que deja sin traza la entrega de una tarjeta.
    expect(AuditAction::tryFrom($action))->not->toBeNull($action.' ha desaparecido del catalogo.');
})->with([
    // Crea, modifica, anula o cierra un fichaje.
    'shift_entry.created',
    'shift_entry.modified',
    'shift_entry.closed',
    'shift_entry.voided',
    // Emite, imprime, entrega, revoca o reemite una credencial.
    'credential.issued',
    'credential.printed',
    'credential.delivered',
    'credential.revoked',
    'credential.reissued',
    // Rota y retira la clave con la que se firman todas las tarjetas (tarea
    // 2.12). Es el ciclo de vida de la credencial visto desde el material
    // criptografico: sin estos dos, no se puede explicar por que una tarjeta de
    // hace dos años dejo de verificar.
    'signing_key.rotated',
    'signing_key.retired',
    // Entra, sale y se bloquea una credencial de acceso (OWASP A09). El fallo
    // suelto NO esta y no debe estar: se queda en el log tecnico y en
    // `kronoqr_auth_attempts_total`, porque un ataque de fuerza bruta no puede
    // meter escrituras en la cadena por la que pasa cada fichaje.
    'auth.login_succeeded',
    'auth.logout',
    'auth.lockout_started',
    // Provisiona, empareja o revoca un dispositivo.
    'device.provisioned',
    'device.paired',
    'device.revoked',
    // Accede a datos personales de terceros.
    'personal_data.accessed',
    // Genera una exportacion legal.
    'legal_export.generated',
    // Cambia roles, permisos o configuracion con efecto en el calculo de horas.
    'role_assignment.changed',
    'permission.changed',
    'calculation_setting.changed',
    // Tambien con efecto en el calculo: el contrato de una persona (tarea 2.8)
    // y la correccion de un agregado por la reconciliacion nocturna (tarea 2.7).
    'employment_contract.registered',
    'projection.reconciled',
    // Abre o resuelve una incidencia del registro horario (tarea 2.6).
    'incident.opened',
    'incident.resolved',
    // Activa una licencia o supera una cifra del plan (tarea 5.3, ADR-028).
    // Ninguna de las dos impide nada: la segunda describe un alta que SI se
    // hizo, y su asiento es la fecha desde la que el cliente opera fuera de
    // plan.
    'license.activated',
    'license.plan_exceeded',
    // Ejecuta una purga por retencion.
    'retention.partition_sealed',
    'retention.partition_dropped',
    'retention.purge_executed',
])->group('RS-07');

it('no abre familia nueva del bloque D al anadir la autenticacion', function (): void {
    // Una sesion es otra credencial de acceso —se emite al entrar, se revoca al
    // salir y el bloqueo suspende la potestad de usarla—, con el mismo ciclo que
    // la tarjeta y que el PIN. Si alguien la moviera a una familia propia, el
    // catalogo tendria una familia mas sin necesitarla, y esta prueba lo diria.
    expect(AuditAction::LoginSucceeded->event())->toBe(AuditableEvent::CredentialLifecycle)
        ->and(AuditAction::Logout->event())->toBe(AuditableEvent::CredentialLifecycle)
        ->and(AuditAction::LockoutStarted->event())->toBe(AuditableEvent::CredentialLifecycle);
})->group('RS-07', 'RS-12');

it('no abre familia nueva del bloque D al anadir la rotacion de clave', function (): void {
    // La clave HMAC es lo que hace valida a la tarjeta: rotarla y retirarla son
    // actos del ciclo de vida de TODAS las credenciales a la vez (tarea 2.12,
    // RF-QR-07). Si alguien les diera familia propia, el bloque D tendria una
    // familia mas sin ningun hecho nuevo detras.
    expect(AuditAction::SigningKeyRotated->event())->toBe(AuditableEvent::CredentialLifecycle)
        ->and(AuditAction::SigningKeyRetired->event())->toBe(AuditableEvent::CredentialLifecycle);
})->group('RS-07', 'RF-QR-07');

it('no admite ninguna accion de fallo de autenticacion en el catalogo', function (): void {
    // El control que impide que alguien «complete» el catalogo. Un
    // `auth.login_failed` en `audit_log` seria una escritura por cada intento
    // fallido bajo el candado global de ADR-010, es decir, un ataque de fuerza
    // bruta degradando el camino de fichaje.
    $prohibidas = array_filter(
        AuditAction::cases(),
        static fn (AuditAction $action): bool => str_contains($action->value, 'failed')
            || str_contains($action->value, 'rejected'),
    );

    expect($prohibidas)->toBe([]);
})->group('RS-07', 'RS-12');

it('no repite ningun valor en el catalogo', function (): void {
    $values = array_map(static fn (AuditAction $action): string => $action->value, AuditAction::cases());

    expect(array_unique($values))->toHaveCount(\count($values));
})->group('RS-07');
