<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

use App\Modules\Reporting\Domain\Exception\InvalidAdoptionFacts;
use App\Modules\Reporting\Domain\Policy\AdoptionIndicators;

/**
 * Los **hechos** de un periodo del cuadro de impacto: recuentos y sumas, ni un
 * porcentaje (**RF-IN-08**).
 *
 * ## Por que aqui no hay ni una division
 *
 * Porque la aritmetica es de {@see AdoptionIndicators},
 * y porque **ningun porcentaje se calcula en SQL** (decision 1 de la ficha 3.13).
 * Un `round(100.0 * complete / total, 2)` dentro de un `SELECT` no se puede
 * verificar a mano en una prueba unitaria: para comprobar que «12 de 13 jornadas
 * son 92,31 %» habria que levantar PostgreSQL, sembrar trece jornadas y confiar
 * en que la que falla es la aritmetica y no el `WHERE`. Con los hechos separados
 * del calculo, la prueba del calculo es una linea y la de la consulta es otra.
 *
 * ## Las horas llegan por otro camino, y por eso se añaden despues
 *
 * `workedMinutes` y `contractedMinutes` **no** salen de la consulta de hechos:
 * salen de `GeneratePeriodReport`, que es quien sabe prorratear lo contratado por
 * dia de vigencia (RF-IN-03) y descontar festivos y ausencias (RF-GP-04). Tener
 * una segunda SQL que sumara horas seria tener dos verdades sobre las mismas
 * horas, y la que se creeria seria la equivocada (regla dura 7). De ahi
 * {@see self::withWorkedTime()}: el lector entrega los recuentos y el caso de uso
 * pega las horas.
 */
final readonly class AdoptionPeriodFacts
{
    /**
     * @param  int  $workDaysWithActivity  Jornadas —persona y fecha— con algun tramo vigente.
     * @param  int  $workDaysComplete  De esas, las que no tienen ningun tramo abierto.
     * @param  array<string, int>  $acceptedScansByOrigin  Fichajes aceptados por `scan_events.origin`.
     *                                                     Los cuatro origenes, tambien a cero.
     * @param  int  $attendedScans  **Todos** los `scan_events` del periodo, aceptados y rechazados:
     *                              un rechazo por regla de negocio es un intento atendido.
     * @param  int  $offlineResolvedScans  De esos, los que llegaron por la cola offline
     *                                     (`recorded_at − occurred_at` por encima del umbral).
     * @param  int  $failedAttempts  Intentos que el quiosco **no llego a cursar**, por ocurrencias de
     *                               `error_events` de las clases de fichaje del quiosco.
     * @param  int  $corrections  Filas de `shift_corrections` creadas en el periodo.
     * @param  int  $resolvedOpenShiftIncidents  Incidencias `open_shift_expired` resueltas en el periodo.
     * @param  int|null  $resolutionMeanMinutes  Media de `resolved_at − detected_at` de esas, o `null`
     *                                           si no se resolvio ninguna.
     * @param  int|null  $resolutionMedianMinutes  La mediana de lo mismo.
     * @param  int  $workedMinutes  Horas trabajadas del periodo, de `GeneratePeriodReport`.
     * @param  int  $contractedMinutes  Horas contratadas del mismo periodo.
     */
    public function __construct(
        public int $workDaysWithActivity,
        public int $workDaysComplete,
        public array $acceptedScansByOrigin,
        public int $attendedScans,
        public int $offlineResolvedScans,
        public int $failedAttempts,
        public int $corrections,
        public int $resolvedOpenShiftIncidents,
        public ?int $resolutionMeanMinutes,
        public ?int $resolutionMedianMinutes,
        public int $workedMinutes = 0,
        public int $contractedMinutes = 0,
    ) {
        // Un recuento negativo no es un dato malo: es una consulta mal escrita, y
        // el cuadro lo convertiria en un porcentaje creible. Se rompe aqui, donde
        // se ve, y no tres capas mas arriba en una division.
        foreach ([
            'jornadas con actividad' => $workDaysWithActivity,
            'jornadas completas' => $workDaysComplete,
            'fichajes atendidos' => $attendedScans,
            'fichajes resueltos sin servidor' => $offlineResolvedScans,
            'intentos fallidos' => $failedAttempts,
            'correcciones' => $corrections,
            'incidencias resueltas' => $resolvedOpenShiftIncidents,
            'minutos trabajados' => $workedMinutes,
            'minutos contratados' => $contractedMinutes,
        ] as $what => $value) {
            if ($value < 0) {
                throw InvalidAdoptionFacts::negative($what, $value);
            }
        }

        if ($workDaysComplete > $workDaysWithActivity) {
            throw InvalidAdoptionFacts::completeExceedsTotal($workDaysComplete, $workDaysWithActivity);
        }
    }

    /**
     * Los mismos hechos con las horas del informe por periodo pegadas.
     *
     * Devuelve una instancia nueva —esto es un objeto de valor— y existe porque
     * las horas llegan de otro sitio: ver el docblock de la clase.
     */
    public function withWorkedTime(int $workedMinutes, int $contractedMinutes): self
    {
        return new self(
            workDaysWithActivity: $this->workDaysWithActivity,
            workDaysComplete: $this->workDaysComplete,
            acceptedScansByOrigin: $this->acceptedScansByOrigin,
            attendedScans: $this->attendedScans,
            offlineResolvedScans: $this->offlineResolvedScans,
            failedAttempts: $this->failedAttempts,
            corrections: $this->corrections,
            resolvedOpenShiftIncidents: $this->resolvedOpenShiftIncidents,
            resolutionMeanMinutes: $this->resolutionMeanMinutes,
            resolutionMedianMinutes: $this->resolutionMedianMinutes,
            workedMinutes: $workedMinutes,
            contractedMinutes: $contractedMinutes,
        );
    }

    /** Un periodo sin nada que contar: instalacion recien puesta en marcha, hotel cerrado. */
    public static function empty(): self
    {
        return new self(
            workDaysWithActivity: 0,
            workDaysComplete: 0,
            acceptedScansByOrigin: [],
            attendedScans: 0,
            offlineResolvedScans: 0,
            failedAttempts: 0,
            corrections: 0,
            resolvedOpenShiftIncidents: 0,
            resolutionMeanMinutes: null,
            resolutionMedianMinutes: null,
        );
    }

    /** Fichajes aceptados del periodo, que es el denominador del reparto y del ratio de correcciones. */
    public function acceptedScans(): int
    {
        return array_sum($this->acceptedScansByOrigin);
    }

    /**
     * Fichajes aceptados de un origen concreto. Cero cuando ese origen no aparece:
     * «no hubo ninguno» y «no aparece en la consulta» son lo mismo aqui.
     */
    public function acceptedScansFrom(string $origin): int
    {
        return $this->acceptedScansByOrigin[$origin] ?? 0;
    }

    /**
     * Intentos de fichar del periodo: los atendidos mas los que el quiosco no
     * pudo cursar.
     *
     * Es el denominador de RNF-D-01, y se calcula aqui —en el dominio— y no en la
     * consulta por lo mismo que todo lo demas: la unica prueba que etiqueta
     * RNF-D-01 en todo el proyecto verifica esta suma a mano.
     */
    public function clockingAttempts(): int
    {
        return $this->attendedScans + $this->failedAttempts;
    }
}
