<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Query;

use App\Modules\Reporting\Application\Exception\EmployeeNotFound;
use App\Modules\Reporting\Application\Port\WorkDayJournalReader;
use App\Modules\Reporting\Domain\ValueObject\DateRange;
use App\Modules\Reporting\Domain\ValueObject\WorkDayJournal;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\PersonalDataAccessLog;
use DateTimeZone;

/**
 * Consulta el registro horario de una persona (RF-PA-03).
 *
 * ## Que decide esta clase y que no
 *
 * Decide **tres** cosas y ninguna es una regla de negocio: que rango se lee
 * cuando no lo dicen, que consultar el registro de un tercero deja constancia, y
 * que un empleado inexistente es un `404`. El calculo de los totales no se
 * decide aqui —lo define RN-06 y lo hace `JournalWorkDay`— y la autorizacion
 * tampoco, que es de la policy.
 *
 * ## El rango por omision se resuelve en la zona del centro
 *
 * `Clock` devuelve UTC (regla dura 3), y «hoy» a las 00:30 de Madrid es todavia
 * ayer en UTC. Preguntar por el rango por omision sin convertir a la zona del
 * centro dejaria fuera la jornada en curso justo en el turno de noche, que es
 * cuando alguien mira esta pantalla (RN-04). Por eso se busca antes la zona del
 * empleado y solo despues se decide que dia es hoy.
 *
 * **`now()` no aparece por ningun lado**: el instante entra por el puerto `Clock`
 * (regla dura 2). Sin eso, la prueba del rango por omision dependeria del dia en
 * que se ejecute la suite.
 *
 * ## Consultar el registro de otro deja constancia
 *
 * RS-05 no admite matices: *«todo acceso a datos personales de terceros queda
 * registrado en el trail de auditoria»*. Aqui alguien con responsabilidad de
 * gestion esta leyendo las horas de trabajo de otra persona, que es el dato
 * personal mas sensible que este producto guarda de nadie.
 *
 * Se registra **el alcance** —de quien, que rango, cuantas jornadas— y nunca lo
 * divulgado (regla dura 21): ni las horas, ni el nombre, ni una sola marca. El
 * `employee_uuid` **si** va, al contrario que en el padron del quiosco: alli
 * enumerar a los afectados seria una segunda copia de la plantilla, y aqui el
 * afectado es uno solo y saber de quien se consulto el registro es exactamente
 * lo que RS-05 quiere poder responder.
 *
 * El apunte se escribe **antes** de devolver: si la escritura de auditoria falla,
 * la divulgacion no ocurre. Es la misma decision que en el fichaje (regla dura 6,
 * ADR-027) y con la misma consecuencia deliberada.
 *
 * ## Consultar el registro PROPIO no lo deja
 *
 * Desde la tarea 1.11, este mismo caso de uso sirve tambien `GET
 * /api/v1/me/workdays` (RF-ID-05, RL-05): la persona mirando sus propias horas
 * desde el portal. Ahi no se escribe el apunte, y el motivo es el literal de
 * RS-05 —*«acceso a datos personales de **terceros**»*—: no hay tercero. Un
 * asiento por cada consulta convertiria un derecho reconocido por el art. 34.9
 * ET en una traza del ejercicio de ese derecho, guardada cuatro años (RL-02) y
 * consultable por el empleador.
 *
 * Hay ademas una razon tecnica que apunta en la misma direccion: el catalogo de
 * actores de `audit_log` no tiene un tipo para un empleado —solo `user`,
 * `device`, `system` y `maintenance`—, asi que el apunte saldria atribuido a
 * `system`, que seria una entrada que miente en la tabla que se enseña en una
 * inspeccion. Si el producto decidiera que quiere constancia de los accesos al
 * portal, es un cambio del dominio de auditoria y de la restriccion de su
 * esquema, no de este `if`.
 *
 * Lo decide la peticion, no el llamante: {@see EmployeeWorkDayRange} lo declara
 * y por omision es `false`, de modo que el caso que audita es el que se asume.
 */
final readonly class ReadEmployeeWorkDays
{
    /**
     * El mes que el panel abre por omision. No es un umbral legal —esos se leen
     * del perfil de cumplimiento (regla dura 14)— sino la ventana con la que se
     * abre una pantalla, y por eso es una constante y no configuracion.
     */
    public const int DEFAULT_DAYS = 31;

    /** Vocabulario estable del `audit_log`, en ingles y sin datos dentro. */
    private const string DATASET = 'employee_workdays';

    public function __construct(
        private WorkDayJournalReader $journal,
        private PersonalDataAccessLog $disclosures,
        private Clock $clock,
    ) {}

    public function handle(EmployeeWorkDayRange $query): WorkDayJournal
    {
        $timeZone = $this->journal->timeZoneOf($query->employeeUuid);

        if ($timeZone === null) {
            throw EmployeeNotFound::withUuid($query->employeeUuid);
        }

        $range = $this->resolve($query, $timeZone);

        $journal = $this->journal->journalFor($query->employeeUuid, $timeZone, $range);

        if (! $query->selfService) {
            $this->disclosures->recordDisclosure(self::DATASET, $journal->dayCount(), [
                'employee_uuid' => $query->employeeUuid,
                'from' => $range->isoFrom(),
                'to' => $range->isoTo(),
            ]);
        }

        return $journal;
    }

    private function resolve(EmployeeWorkDayRange $query, string $timeZone): DateRange
    {
        $to = $query->to ?? $this->today($timeZone);

        if ($query->from === null) {
            return DateRange::endingOn($to, self::DEFAULT_DAYS);
        }

        return DateRange::between($query->from, $to);
    }

    /**
     * Que dia es hoy **para esa persona**, que es el unico «hoy» que decide a
     * que jornada pertenece el turno que esta haciendo ahora mismo.
     */
    private function today(string $timeZone): string
    {
        return $this->clock->now()
            ->setTimezone(new DateTimeZone($timeZone))
            ->format('Y-m-d');
    }
}
