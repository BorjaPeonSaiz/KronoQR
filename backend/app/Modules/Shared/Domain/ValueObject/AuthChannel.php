<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

/**
 * Por que puerta se autentica alguien (OWASP A09, RS-12, doc 02 §7.5).
 *
 * **Por que un tipo y no una cadena.** Es la etiqueta `channel` de
 * `kronoqr_auth_attempts_total`, el campo `channel` del log de fallo y el del
 * payload del asiento de `audit_log`. Los tres tienen que decir exactamente lo
 * mismo o la alerta que agrupa por canal cuenta dos poblaciones como una. Con
 * texto libre, «kiosk», «kiosk_pin» y «pin_kiosk» acabarian conviviendo.
 *
 * **Vive en `Shared` por lo mismo que {@see PinOrigin}**: lo usan tres modulos
 * que no pueden importarse entre si —`Identity` (panel y portal), `Workforce`
 * (el verificador del PIN) y `Attendance` (el fichaje de respaldo)— y la unica
 * capa que los tres alcanzan es esta (doc 02 §1.6).
 */
enum AuthChannel: string
{
    /** Panel de gestion: correo y contrasena (RF-ID-01). */
    case MANAGEMENT = 'management';

    /** Portal del empleado: codigo y PIN (RF-ID-05, RF-ID-06, ADR-015). */
    case PORTAL = 'portal';

    /** Fichaje de respaldo del quiosco: codigo y PIN sobre la tablet (RF-AT-11). */
    case KIOSK_PIN = 'kiosk_pin';

    /**
     * Sobre quien recae el hecho, en el vocabulario de `audit_log.subject_type`.
     *
     * El canal ya determina la clase de sujeto —el panel autentica cuentas de
     * gestion, las otras dos puertas autentican a personas de la plantilla—, asi
     * que quien deja el rastro no tiene que declararlo y no puede equivocarse.
     */
    public function subjectType(): string
    {
        return $this === self::MANAGEMENT ? 'user' : 'employee';
    }

    /**
     * Si abrir o cerrar sesion por este canal deja asiento en `audit_log`.
     *
     * **Hoy solo el panel, y no es una preferencia**: `audit_log.actor_type` no
     * tiene tipo para un empleado, asi que el asiento del portal o del quiosco
     * saldria atribuido a `system` (ADR-037). El reparto completo —y lo que si
     * deja rastro en las tres puertas— esta en
     * `docs/adr/ADR-039-que-hechos-de-autenticacion-dejan-asiento.md`.
     */
    public function sessionEventsAreAudited(): bool
    {
        return $this === self::MANAGEMENT;
    }
}
