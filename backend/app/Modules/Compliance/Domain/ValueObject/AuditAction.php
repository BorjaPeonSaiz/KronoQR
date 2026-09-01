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

    // --- Clave de firma de las tarjetas (RF-QR-07, doc 02 §5.3, tarea 2.12) --

    /**
     * Se ha abierto una rotacion de la clave HMAC con solape: a partir de este
     * asiento hay dos claves vigentes y una reemision pendiente de imprimir por
     * cada tarjeta firmada con la saliente.
     *
     * **Sujeto propio y no `credential.*`**, porque no recae sobre ninguna
     * credencial concreta: recae sobre el material criptografico con el que se
     * firman todas. Colgarlo de una credencial cualquiera obligaria a elegir una
     * al azar como `subject_id`, y la pregunta que este asiento responde
     * —«cuando se roto la clave y quien lo hizo»— dejaria de contestarse con un
     * filtro por `action`. La reemision de cada tarjeta si deja su
     * `credential.reissued`, uno por persona.
     *
     * **Misma familia del bloque D que la tarjeta** (`CredentialLifecycle`): la
     * clave es lo que hace valida a la tarjeta, y rotarla es un acto del ciclo de
     * vida de todas ellas a la vez.
     */
    case SigningKeyRotated = 'signing_key.rotated';

    /**
     * Se ha cerrado el solape: la clave saliente ya no verifica ninguna tarjeta
     * activa y el operador puede vaciar `QR_SIGNING_KEY_PREVIOUS`.
     *
     * Es el asiento que permite responder, meses despues, por que una tarjeta
     * concreta dejo de funcionar: desde este momento su firma ya no se admite.
     */
    case SigningKeyRetired = 'signing_key.retired';

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

    /**
     * Se ha registrado el contrato de una persona (RF-GP-02, tarea 2.8).
     *
     * **Sujeto propio en lugar de reutilizar `calculation_setting.changed`**, y
     * la decision merece explicacion porque la familia es la misma. Lo que las
     * separa es sobre **quien** actuan: un ajuste de calculo es de la
     * instalacion —un umbral, un redondeo— y no tiene sujeto; un contrato es de
     * una persona concreta y su `payload` lleva `employee_uuid`. Con un solo
     * valor, la pregunta «¿quien cambio las horas contratadas de alguien?»
     * obligaria a filtrar por el contenido del JSON en lugar de por la columna
     * indexada, que es justo lo que el catalogo cerrado existe para evitar.
     *
     * **Y no es de la familia de la plantilla**: cambiar un apellido no mueve
     * ninguna cifra, y `weekly_hours` es la cifra contra la que se mide la
     * jornada de esa persona (RF-IN-03). Es un parametro del calculo, y ante la
     * duda sobre si algo con efecto en horas de trabajo se audita, la respuesta
     * es si.
     */
    case EmploymentContractRegistered = 'employment_contract.registered';

    /**
     * La reconciliacion nocturna ha corregido un agregado de `daily_totals`
     * (RF-PR-02, tarea 2.7). Misma familia que un cambio de parametro del
     * calculo: el agregado es el RESULTADO del calculo de la jornada, y lo que
     * este asiento describe es que ese resultado cambio sin que nadie lo
     * pidiera. El payload lleva `employee_uuid`, `work_date`, los campos
     * divergentes y el antes y el despues completos.
     */
    case ProjectionReconciled = 'projection.reconciled';

    // --- Incidencias del registro horario (RF-PR-01, tarea 2.6) --------------

    /**
     * La deteccion automatica ha abierto una incidencia y la ha asignado —o no ha
     * podido asignarla—. **No cambia ningun fichaje** (RN-08).
     */
    case IncidentOpened = 'incident.opened';

    /**
     * Una persona la ha dado por trabajada (tarea 2.5, `POST /incidents/{id}/resolve`).
     *
     * Se declara aqui y no en aquella tarea porque el catalogo se decide en un
     * solo sitio: el mismo criterio por el que las acciones de `pin.*` nacieron
     * completas antes de que existiera quien las escribiera.
     */
    case IncidentResolved = 'incident.resolved';

    // --- Licencia (RF-PD-04, ADR-018, ADR-028, tarea 5.3) --------------------

    /**
     * Se ha activado una clave de licencia.
     *
     * Cambia **que ha contratado el cliente** a ojos del producto: plan, limites
     * y funcionalidades accesorias. Es la unica forma de responder «¿desde
     * cuando tiene este hotel el plan grande?» y «¿quien metio esta clave?».
     * El payload lleva la huella corta de la clave, nunca la clave entera.
     */
    case LicenseActivated = 'license.activated';

    /**
     * Un alta ha dejado la instalacion por encima de una cifra del plan
     * (**ADR-028**).
     *
     * **El alta se hizo igual.** Este asiento no describe un rechazo: describe
     * la fecha exacta desde la que el cliente opera por encima de lo contratado,
     * que es *la prueba que sostiene la reclamacion comercial* — literalmente lo
     * que dice ADR-028. Lleva el limite, el valor contratado, el alcanzado y si
     * es el cruce del umbral o un alta posterior en exceso.
     */
    case LicensePlanExceeded = 'license.plan_exceeded';

    // --- Retencion (RL-02, ADR-027) ------------------------------------------

    case RetentionPartitionSealed = 'retention.partition_sealed';
    case RetentionPartitionDropped = 'retention.partition_dropped';

    /**
     * La purga por retencion del REGISTRO DE JORNADA (RL-02, RF-PR-03): filas
     * vencidas borradas de `shift_entries` y de lo que cuelga de ellas. No es un
     * `DROP PARTITION` -eso es `retention.partition_dropped`- y por eso no comparte
     * accion: el payload lleva el alcance, la fecha de corte, el umbral aplicado y
     * el recuento por tabla.
     */
    case RetentionPurgeExecuted = 'retention.purge_executed';

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
        // La clave de firma es lo que hace valida a la tarjeta: rotarla y
        // retirarla son actos del ciclo de vida de TODAS las credenciales a la
        // vez (tarea 2.12), no una familia nueva del bloque D.
        'signing_key' => AuditableEvent::CredentialLifecycle,
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
        // Abrir y resolver una incidencia son la misma familia porque son las dos
        // mitades del mismo hecho: se detecto algo con relevancia legal y alguien
        // respondio de ello. Separarlas obligaria a consultar dos veces para
        // responder «que se detecto en marzo y que se hizo con ello».
        'incident' => AuditableEvent::IncidentLifecycle,
        'role_assignment' => AuditableEvent::AuthorityOrCalculationChange,
        'permission' => AuditableEvent::AuthorityOrCalculationChange,
        'calculation_setting' => AuditableEvent::AuthorityOrCalculationChange,
        // El contrato fija las horas contra las que se mide la jornada de una
        // persona (RF-IN-03): es un parametro del calculo, con sujeto propio.
        'employment_contract' => AuditableEvent::AuthorityOrCalculationChange,
        // La proyeccion es el resultado del calculo; corregirla es un cambio
        // del calculo que nadie pidio (RF-PR-02, tarea 2.7).
        'projection' => AuditableEvent::AuthorityOrCalculationChange,
        'retention' => AuditableEvent::RetentionPurge,
        // Activar una clave y superar una cifra del plan son las dos mitades
        // del mismo hecho —que ha contratado el cliente y que esta usando— y
        // por eso comparten familia. No caben en
        // `AuthorityOrCalculationChange`: ninguna de las dos mueve un minuto
        // trabajado ni concede una potestad a nadie, y meterlas ahi ensuciaria
        // la consulta con la que una inspeccion pregunta quien movio las reglas
        // del calculo. Ver `AuditableEvent::LicenseLifecycle`.
        'license' => AuditableEvent::LicenseLifecycle,
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
