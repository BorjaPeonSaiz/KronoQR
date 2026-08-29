<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Port;

/**
 * Deja constancia de un **acceso denegado a datos personales de terceros**
 * (RS-05, RF-ID-03, escenario «Aislamiento por departamento» del doc 01 §11).
 *
 * ## Por que un puerto propio y no {@see PersonalDataAccessLog}
 *
 * Aquel describe una **divulgacion**: alguien se llevo N registros. Este describe
 * lo contrario: alguien intento llegar a datos que no le corresponden y el
 * servidor no se los dio. Mezclarlos en un solo metodo obligaria a leer
 * `record_count: 0` como «denegado», que es una convencion que nadie recuerda seis
 * meses despues, y ademas confundiria las dos preguntas que `audit_log` tiene que
 * saber responder por separado: *que se llevo esa cuenta* (RL-15) y *que intento
 * esa cuenta* (deteccion, `T1550.001`).
 *
 * La arista es la de ADR-025, igual que en `PersonalDataAccessLog`: el puerto lo
 * declara `Shared` porque lo necesitan `Workforce`, `Reporting` y `Attendance`, y
 * lo implementa `Compliance`, que es quien tiene la cadena de hash.
 *
 * ## Que se apunta y que no
 *
 * **El `employee_uuid` del recurso, si.** Es lo que convierte «alguien recibio un
 * 403» en «alguien intento ver el registro de esta persona», y sin el la entrada
 * no sirve para nada. **El nombre, jamas** (regla dura 21).
 *
 * **El motivo tecnico exacto, no.** El asiento dice que el alcance no llegaba; no
 * copia la lista de departamentos del actor ni la del recurso. `audit_log` no es
 * el log tecnico y su retencion es de cuatro años.
 *
 * ## No es el `403` de un rol equivocado
 *
 * Este puerto se llama cuando alguien **con** el rol y **con** el ambito adecuados
 * intenta salirse de su alcance. Un `403` por rol o por ambito de token —un
 * quiosco contra un endpoint de gestion, un auditor intentando escribir— no deja
 * asiento y no debe dejarlo: es trafico de fondo que cualquiera puede provocar sin
 * autenticarse siquiera, y llenaria la cadena de hash con el ruido de un escaner
 * de puertos (mismo criterio con el que ADR-039 deja los fallos de autenticacion
 * fuera de `audit_log`).
 */
interface AuthorizationJournal
{
    /**
     * @param  string  $dataset  Que se intento alcanzar, en vocabulario estable y en
     *                           ingles: `employee_profile`, `employee_workdays`,
     *                           `shift_entry`. El mismo vocabulario que
     *                           {@see PersonalDataAccessLog::recordDisclosure()}.
     * @param  string|null  $employeeUuid  UUID publico de la persona cuyo dato se pedia, o
     *                                     `null` si el intento no apuntaba a nadie concreto.
     *                                     Nunca su nombre ni su codigo.
     * @param  array<string, scalar>  $context  Alcance del intento. Sin datos personales.
     */
    public function recordScopeDenial(string $dataset, ?string $employeeUuid, array $context = []): void;
}
