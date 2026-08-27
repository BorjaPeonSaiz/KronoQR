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
        'device' => AuditableEvent::DeviceLifecycle,
        'personal_data' => AuditableEvent::PersonalDataAccess,
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
