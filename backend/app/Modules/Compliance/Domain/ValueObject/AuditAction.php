<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

use App\Modules\Compliance\Domain\Exception\AuditActionHasNoEvent;

/**
 * Catalogo cerrado de acciones auditables (`/revision-cumplimiento` bloque D,
 * regla dura 6, ADR-010).
 *
 * **Por que un enum y no una cadena libre.** `action` es la columna por la que
 * se consulta el trail en una inspeccion y en la respuesta a una brecha
 * (RL-15). Con texto libre, la misma accion acaba escrita de tres formas por
 * tres modulos y la consulta que las reune no se puede escribir. Con el enum,
 * añadir una accion es un cambio visible en el catalogo, revisable, y obliga a
 * decir a que familia del bloque D pertenece.
 *
 * **Que NO va aqui.** Nada que no obligue a auditar. El bloque D es la frontera:
 * si una accion nueva no encaja en ninguna de las siete familias de
 * `AuditableEvent`, la pregunta no es que valor de enum ponerle, sino si de
 * verdad hay que auditarla — y ante la duda, si.
 *
 * El valor es `sujeto.verbo` en pasado, en ingles y en minusculas (doc 02 §3.5:
 * el codigo se escribe en ingles aunque el lenguaje ubicuo sea español).
 *
 * Las acciones de esta lista las escribiran las tareas que las producen —1.4
 * el fichaje, 1.13 la credencial, 1.5 el dispositivo, 1.17 la exportacion,
 * 2.10 la purga—. El catalogo nace completo a proposito: es lo que permite que
 * una tarea posterior no tenga que decidir el nombre por su cuenta.
 *
 * Las tres acciones de `pin.*` se anadieron con la tarea 1.13, que es la que las
 * produce: RF-ID-09 exige por escrito que emision, entrega y restablecimiento
 * queden en `audit_log`, y el catalogo nacio sin ellas. No amplia el bloque D
 * —caen en la familia de credenciales, porque el PIN es otro soporte de la misma
 * potestad—, pero si el vocabulario, y por eso se decide aqui y no en el modulo
 * que las emite.
 *
 * Las tres acciones de `auth.*` cierran el hueco de OWASP A09. Solo entran aqui
 * los hechos de **bajo volumen** —entrar, salir y que se abra un bloqueo—; el
 * fallo suelto no, y el motivo esta en
 * `docs/adr/ADR-039-que-hechos-de-autenticacion-dejan-asiento.md`.
 *
 * Las tres que anadio la tarea 2.1 siguen ese mismo criterio de volumen.
 * `auth.two_factor_enabled` y `auth.two_factor_reset` son el ciclo de vida de una
 * credencial de acceso —la segunda mitad de la de RS-06— y ocurren una vez por
 * persona y por telefono; **el codigo TOTP fallido no deja asiento**, por lo mismo
 * que no lo deja una contrasena fallida. `access.denied` es el intento de salirse
 * del alcance por departamento (RF-ID-03) de alguien que **ya esta autenticado y
 * autorizado en el endpoint**: es raro por definicion —solo se produce por error
 * de la interfaz o por manipulacion de una URL— y el escenario «Aislamiento por
 * departamento» del doc 01 §11 exige por escrito que quede en el trail.
 */
enum AuditAction: string
{
    // --- Fichajes (RL-01, RL-04, RN-13) --------------------------------------

    case ShiftEntryCreated = 'shift_entry.created';
    case ShiftEntryModified = 'shift_entry.modified';
    case ShiftEntryClosed = 'shift_entry.closed';
    case ShiftEntryVoided = 'shift_entry.voided';

    // --- Credenciales (RF-QR-*, ADR-014) -------------------------------------

    case CredentialIssued = 'credential.issued';
    case CredentialPrinted = 'credential.printed';
    case CredentialDelivered = 'credential.delivered';
    case CredentialRevoked = 'credential.revoked';
    case CredentialReissued = 'credential.reissued';

    // --- PIN del empleado (RF-ID-09, RL-05) ----------------------------------

    case PinIssued = 'pin.issued';
    case PinReset = 'pin.reset';
    case PinDelivered = 'pin.delivered';

    // --- Autenticacion (RF-ID-01, RF-ID-06, RS-12, OWASP A09) ----------------

    case LoginSucceeded = 'auth.login_succeeded';
    case Logout = 'auth.logout';
    case LockoutStarted = 'auth.lockout_started';

    // --- Segundo factor de gestion (RF-ID-01, RS-06, tarea 2.1) --------------

    case TwoFactorEnabled = 'auth.two_factor_enabled';
    case TwoFactorReset = 'auth.two_factor_reset';

    // --- Acceso denegado por alcance (RF-ID-03, RS-05, tarea 2.1) -----------

    case AccessDenied = 'access.denied';

    // --- Dispositivos (RF-KI-*) ----------------------------------------------

    case DeviceProvisioned = 'device.provisioned';
    case DevicePaired = 'device.paired';
    case DeviceRevoked = 'device.revoked';

    // --- Acceso a datos personales de terceros (RS-05) -----------------------

    case PersonalDataAccessed = 'personal_data.accessed';

    // --- Exportacion legal (RL-03, RL-06, RF-IN-05) --------------------------

    case LegalExportGenerated = 'legal_export.generated';

    // --- Autoridad y parametros del calculo (RF-ID-03, RF-PD-01, bloque E) ---

    case RoleAssignmentChanged = 'role_assignment.changed';
    case PermissionChanged = 'permission.changed';
    case CalculationSettingChanged = 'calculation_setting.changed';

    // --- Retencion (RL-02, ADR-027) ------------------------------------------

    case RetentionPartitionSealed = 'retention.partition_sealed';
    case RetentionPartitionDropped = 'retention.partition_dropped';

    /**
     * Sujeto de la accion —la parte anterior al punto— y familia del bloque D a
     * la que pertenece.
     *
     * Es una tabla y no un `match` de veinte ramas por dos motivos: la
     * correspondencia es exactamente la del sujeto, y un `match` caso por caso
     * disparaba la regla de complejidad ciclomatica del §3.5 sin que hubiera
     * ninguna complejidad real que repartir.
     *
     * @var array<string, AuditableEvent>
     */
    private const array EVENT_BY_SUBJECT = [
        'shift_entry' => AuditableEvent::ShiftEntryLifecycle,
        'credential' => AuditableEvent::CredentialLifecycle,
        // El PIN es una credencial de acceso mas —la del portal (RL-05) y la
        // del fichaje de respaldo (RF-AT-11)—, con el mismo ciclo de emision y
        // entrega que la tarjeta. Cae en la misma familia del bloque D: no es
        // una familia nueva, es otro soporte de la misma potestad.
        'pin' => AuditableEvent::CredentialLifecycle,
        // La sesion es otra credencial de acceso, con el mismo ciclo de emision
        // y revocacion que la tarjeta y que el PIN (ADR-039): no abre familia
        // nueva del bloque D, abre vocabulario, y por eso se decide aqui y no en
        // Identity.
        'auth' => AuditableEvent::CredentialLifecycle,
        'device' => AuditableEvent::DeviceLifecycle,
        'personal_data' => AuditableEvent::PersonalDataAccess,
        // El intento de acceder a datos de terceros cae en la misma familia que
        // el acceso consumado, y no en una nueva: el bloque D pregunta por el
        // HECHO —alguien fue a por datos personales que no le corresponden—, no
        // por su desenlace. Separarlos en dos familias obligaria a consultar dos
        // veces para responder «que hizo esa cuenta con la plantilla».
        'access' => AuditableEvent::PersonalDataAccess,
        'legal_export' => AuditableEvent::LegalExport,
        'role_assignment' => AuditableEvent::AuthorityOrCalculationChange,
        'permission' => AuditableEvent::AuthorityOrCalculationChange,
        'calculation_setting' => AuditableEvent::AuthorityOrCalculationChange,
        'retention' => AuditableEvent::RetentionPurge,
    ];

    /**
     * Familia del bloque D a la que pertenece. Sin esto el catalogo seria una
     * lista de cadenas y no la lista del bloque D.
     *
     * Se decide por el SUJETO del valor —la parte anterior al punto— y no caso
     * por caso: son veinte casos y siete familias, y la correspondencia es
     * exactamente la del sujeto. El `match` no lleva `default` a proposito: una
     * accion con un sujeto nuevo lanza `UnhandledMatchError` en la primera
     * prueba que la toque, que es como debe enterarse quien la añade de que
     * tiene que decir a que familia del bloque D pertenece.
     */
    public function event(): AuditableEvent
    {
        $subject = explode('.', $this->value)[0];

        return self::EVENT_BY_SUBJECT[$subject]
            ?? throw new AuditActionHasNoEvent($this->value, $subject);
    }
}
