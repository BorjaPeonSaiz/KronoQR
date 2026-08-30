<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\UseCase;

use App\Modules\Compliance\Application\Port\IncidentDigest;
use App\Modules\Compliance\Application\Port\IncidentNotice;
use App\Modules\Compliance\Application\Port\IncidentNotices;
use App\Modules\Compliance\Application\Port\IncidentNotifier;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\PersonalDataAccessLog;

/**
 * Avisa a cada responsable de las incidencias que tiene sin ver (RF-PR-01: «se
 * notifica al responsable del departamento»).
 *
 * **Un resumen por persona y por pasada.** Quince hallazgos del mismo
 * departamento son un correo con quince lineas, no quince correos: un aviso que
 * nadie lee es lo mismo que no avisar.
 *
 * **El sello va despues del envio, y solo si el envio salio.** `notified_at` se
 * escribe cuando el aviso se ha entregado; si el correo falla, la incidencia
 * sigue pendiente y entra en el resumen de la noche siguiente. Al reves —sellar
 * antes— un servidor de correo mal configurado convertiria cada incidencia en un
 * aviso perdido para siempre, y nadie se enteraria.
 *
 * **Y un fallo de correo no rompe la deteccion.** Las incidencias ya estan
 * abiertas y visibles en la bandeja, que es lo que el registro necesita; el
 * aviso es una comodidad encima. Quien atrapa el fallo es el adaptador, que
 * devuelve `false`. Para que ese `false` exista, el envio es **sincrono**: con
 * la notificacion encolada, `notify()` solo metia un trabajo en la cola, siempre
 * decia que si, y el sello se escribia sobre avisos que nadie recibia.
 *
 * ## El aviso es una divulgacion de datos personales, y deja asiento
 *
 * El correo saca de la instalacion **nombres de la plantilla** por SMTP. Es
 * exactamente lo que RS-05 obliga a registrar —igual que el padron del quiosco o
 * la bandeja de la tarea 2.5— y ademas es el unico camino por el que esos datos
 * salen del servidor del cliente, asi que el asiento es lo que permite responder
 * ante una brecha (RL-15) «que se fue, a quien y cuando».
 *
 * Se escribe **despues de entregar** y solo si se entrego: un correo que no sale
 * no divulga nada. Uno por responsable y por pasada, con el actor `system` que le
 * corresponde a un comando programado (ADR-039).
 */
final readonly class NotifyPendingIncidents
{
    /** Vocabulario estable del `audit_log`, en ingles: el conjunto que se divulga. */
    private const string DATASET = 'incident_digest';

    public function __construct(
        private IncidentNotices $notices,
        private IncidentNotifier $notifier,
        private PersonalDataAccessLog $disclosures,
        private Clock $clock,
    ) {}

    /**
     * Devuelve a cuantos responsables se ha avisado.
     */
    public function handle(): int
    {
        $sent = 0;

        foreach ($this->notices->pendingByManager() as $digest) {
            if (! $this->notifier->notify($digest)) {
                continue;
            }

            $this->recordDisclosure($digest);

            $this->notices->markNotified($digest->incidentIds(), $this->clock->now());
            $sent++;
        }

        return $sent;
    }

    /**
     * El asiento de la divulgacion (RS-05, RL-15, ADR-037).
     *
     * **Lleva los `employee_uuid`, y es la excepcion deliberada** a la norma de
     * `PersonalDataAccessLog` de no enumerar a los afectados. Ahi la norma
     * protege de convertir `audit_log` en una segunda copia del padron: son
     * conjuntos de cientos de personas que **no salen del servidor**. Aqui son
     * unas pocas, salen por correo a otra maquina, y sin la lista el asiento no
     * responde a la unica pregunta que RL-15 hace de verdad —de quien se fueron
     * los datos—. Nunca nombres: identificadores, que es lo que la regla dura 21
     * permite y lo que la Inspeccion puede resolver contra `employees`.
     *
     * Se serializan separados por comas porque el contexto del puerto es de
     * escalares: es una clave del payload canonico de `audit_log`, no una
     * estructura que nadie vaya a consultar por dentro.
     */
    private function recordDisclosure(IncidentDigest $digest): void
    {
        $employeeUuids = array_values(array_unique(array_map(
            static fn (IncidentNotice $notice): string => $notice->employeeUuid,
            $digest->incidents,
        )));

        // Orden estable: dos asientos del mismo conjunto tienen que verse iguales
        // seis meses despues, y el orden de la bandeja depende de la severidad.
        sort($employeeUuids);

        $this->disclosures->recordDisclosure(self::DATASET, \count($employeeUuids), [
            'manager_user_id' => $digest->managerUserId,
            'incident_count' => \count($digest->incidents),
            'employee_uuids' => implode(',', $employeeUuids),
        ]);
    }
}
