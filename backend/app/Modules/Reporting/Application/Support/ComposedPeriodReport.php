<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Support;

use App\Modules\Reporting\Domain\ValueObject\PeriodReport;

/**
 * Un informe por periodo **con su asiento de divulgacion todavia sin escribir**
 * (RS-05, decision 13 de la ficha 3.12).
 *
 * ## Por que existe, en una frase
 *
 * Porque hay un llamante —el resumen semanal— que necesita **componer el
 * informe, hablar con un servidor SMTP y solo despues dejar constancia**, y el
 * asiento no puede quedarse esperando a que termine esa conversacion.
 *
 * ## El fallo que evita
 *
 * Cada asiento de `audit_log` toma el candado de la cadena de hash
 * (`pg_advisory_xact_lock`, ADR-010), y ese candado **se suelta con el commit**.
 * Con el envio dentro de la transaccion del asiento, una pasada del resumen
 * semanal retenia el candado por el que pasa **cada fichaje** durante una
 * conversacion SMTP completa por destinatario —hasta la espera del socket con un
 * relevo caido—, un lunes a las 06:00 UTC, que en verano son las 08:00 locales:
 * la entrada del turno de mañana. El registro horario del hotel entero
 * esperando a que responda un servidor de correo.
 *
 * Separando las dos cosas, la transaccion del asiento dura lo que dura un
 * `INSERT`, como en el resto del producto.
 *
 * ## No es un modo «sin auditoria»
 *
 * El asiento **se escribe igual**, y de hecho con el mismo contenido: lo unico
 * que cambia es **cuando**. Quien recibe este objeto se lleva tambien el
 * contexto ya calculado y esta obligado a registrarlo en cuanto la divulgacion
 * se consuma; si no llega a consumarse —el correo no salio— no hay nada que
 * registrar, y esa es justamente la segunda razon por la que el asiento va
 * despues: un intento fallido inflaria el alcance de una brecha con datos que
 * no salieron de la instalacion (RL-15).
 */
final readonly class ComposedPeriodReport
{
    /**
     * @param  array<string, scalar>  $disclosure  El contexto del asiento, ya resuelto. Sin
     *                                             datos personales mas alla de los
     *                                             `employee_uuid` que el puerto admite
     *                                             (regla dura 21).
     */
    public function __construct(
        public PeriodReport $report,
        public ReportDataset $dataset,
        public array $disclosure,
    ) {}

    /**
     * Cuantos registros lleva la divulgacion, para el segundo parametro de
     * `PersonalDataAccessLog::recordDisclosure()`.
     *
     * Vive aqui y no lo recalcula quien llama para que el asiento diferido diga
     * exactamente lo mismo que diria el inmediato.
     */
    public function recordCount(): int
    {
        return $this->report->rowCount();
    }
}
