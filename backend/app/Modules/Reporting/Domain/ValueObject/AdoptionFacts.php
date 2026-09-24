<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

/**
 * Todo lo que el cuadro de impacto necesita saber, **sin un solo porcentaje**
 * (**RF-IN-08**).
 *
 * ## Dos periodos y dos fotos, y la diferencia es de fondo
 *
 * `current` y `previous` describen **lo que paso** en dos ventanas de tiempo:
 * jornadas, fichajes, correcciones, incidencias resueltas. `openIncidents` y
 * `employeesWithoutDeliveredCredential` describen **lo que queda pendiente hoy**,
 * y por eso no tienen version «anterior»: una cola no pertenece a ningun periodo.
 * Comparar «tres incidencias abiertas» con «tres incidencias abiertas el mes
 * pasado» exigiria saber cuantas estaban abiertas el 28 de febrero a las 23:59,
 * que es un dato que este producto no guarda — y fabricarlo dando por buena la
 * cifra de hoy seria enseñar una variacion inventada.
 *
 * ## La linea base no es una medida: es una declaracion
 *
 * `baselineManualMinutesPerMonth` sale de `BASELINE_MANUAL_HOURS_PER_MONTH`, un
 * ajuste de la instalacion, y describe **el trabajo que se hacia antes de instalar
 * KronoQR**. Ninguna metrica de una aplicacion puede observar eso, asi que lo
 * declara el cliente o no existe: `null` cuando no lo declaro, y el indicador sale
 * vacio. Es honesto no inventar un porcentaje de mejora (§1.3, nota de coherencia
 * de la ficha 3.13).
 */
final readonly class AdoptionFacts
{
    public function __construct(
        public AdoptionPeriodFacts $current,
        public AdoptionPeriodFacts $previous,
        /** Incidencias de cualquier tipo abiertas **hoy**. Foto, no flujo. */
        public int $openIncidents,
        /** Personas de alta **hoy** sin credencial vigente entregada (RF-QR-08). */
        public int $employeesWithoutDeliveredCredential,
        /** `null` = el cliente no ha declarado su linea base. Nunca cero por defecto. */
        public ?int $baselineManualMinutesPerMonth = null,
    ) {}

    /**
     * Los mismos hechos con las horas de los dos periodos pegadas.
     *
     * Las trae `GeneratePeriodReport` y no la consulta de hechos: ver el docblock
     * de {@see AdoptionPeriodFacts}.
     */
    public function withWorkedTime(
        int $currentWorkedMinutes,
        int $currentContractedMinutes,
        int $previousWorkedMinutes,
        int $previousContractedMinutes,
    ): self {
        return new self(
            current: $this->current->withWorkedTime($currentWorkedMinutes, $currentContractedMinutes),
            previous: $this->previous->withWorkedTime($previousWorkedMinutes, $previousContractedMinutes),
            openIncidents: $this->openIncidents,
            employeesWithoutDeliveredCredential: $this->employeesWithoutDeliveredCredential,
            baselineManualMinutesPerMonth: $this->baselineManualMinutesPerMonth,
        );
    }

    /**
     * Los mismos hechos con la linea base declarada.
     *
     * **Cero significa «no declarado»** en el catalogo del ajuste, y se traduce a
     * `null` aqui, en un solo sitio: un cero que llegara al indicador se pintaria
     * como «cero horas al mes consolidando hojas», que es una afirmacion
     * espectacular y falsa.
     */
    public function withDeclaredBaselineHours(int $hoursPerMonth): self
    {
        return new self(
            current: $this->current,
            previous: $this->previous,
            openIncidents: $this->openIncidents,
            employeesWithoutDeliveredCredential: $this->employeesWithoutDeliveredCredential,
            baselineManualMinutesPerMonth: $hoursPerMonth <= 0 ? null : $hoursPerMonth * 60,
        );
    }
}
