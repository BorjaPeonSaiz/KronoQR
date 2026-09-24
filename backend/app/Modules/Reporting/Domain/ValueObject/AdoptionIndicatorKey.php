<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

use App\Modules\Reporting\Domain\Exception\MissingAdoptionIndicatorUnit;

/**
 * Los doce indicadores del cuadro de impacto y adopcion (**RF-IN-08**, §1.3 del
 * documento 01).
 *
 * ## El orden del enumerado es el orden de lectura
 *
 * Y no es cosmetica: el cuadro se lee de arriba abajo en la pantalla, en el CSV y
 * en el PDF, y los tres recorren esta lista. Las tres primeras son las que
 * contestan «¿esta llegando el registro?», la cuarta y la quinta «¿se puede
 * fichar?», las cuatro siguientes «¿queda trabajo pendiente?» y las tres ultimas
 * ponen las horas en contexto.
 *
 * ## Seis de los doce son los del §1.3; los otros seis explican a los primeros
 *
 * El §1.3 fija seis metricas con objetivo. Los otros seis no son adornos:
 *
 * - **`offline_resolved_ratio`** es lo que hace creible la disponibilidad. Sin el,
 *   «99,94 %» no dice si el merito es del servidor o de la cola offline (ADR-008),
 *   que es la mitad del diseño.
 * - **`incident_resolution_median_minutes`** acompaña a la media porque una sola
 *   incidencia olvidada tres semanas dispara la media y no la mediana; con las dos
 *   se distingue «vamos lentos» de «se nos quedo una».
 * - **`open_incidents`** y **`employees_without_credential`** son cola pendiente,
 *   no flujo del periodo: la foto de hoy, sin comparacion posible.
 * - **`worked_minutes`** y **`contracted_minutes`** los pide RF-IN-08 por escrito
 *   («horas trabajadas frente a contratadas») y son lo que da escala a los
 *   porcentajes: un 1 % de correcciones sobre cien fichajes y sobre catorce mil no
 *   es la misma noticia.
 *
 * ## Cuatro no admiten comparacion **por definicion**
 *
 * {@see self::comparesAgainstPreviousPeriod()}. Vive aqui y no en la pantalla
 * porque es una propiedad del indicador: comparar «incidencias abiertas hoy» con
 * «incidencias abiertas hoy en el periodo anterior» no significa nada —el periodo
 * anterior no tiene un «hoy»— y una pantalla que lo intentara enseñaria una
 * variacion inventada.
 */
enum AdoptionIndicatorKey: string
{
    /**
     * §1.3: «% de jornadas con registro completo», objetivo ≥ 99 %.
     *
     * Jornadas del periodo con **todos** sus tramos cerrados sobre jornadas con
     * algun tramo. Es la misma definicion que publica `workdays_complete_ratio{site}`
     * (doc 02 §8.2) y la comparte el mismo lector, para que el cuadro y Grafana no
     * puedan contar cosas distintas.
     */
    case WorkDaysCompleteRatio = 'workdays_complete_ratio';

    /** §1.3: «% de fichajes por QR sobre el total», objetivo ≥ 98 %. Sobre los **aceptados**. */
    case QrScansRatio = 'qr_scans_ratio';

    /** §1.3: «correcciones manuales / total de fichajes», objetivo < 2 %. */
    case ManualCorrectionsRatio = 'manual_corrections_ratio';

    /**
     * §1.3 y **RNF-D-01**: «disponibilidad del flujo de fichaje (incluye modo
     * offline)», objetivo ≥ 99,9 %.
     *
     * **No es el tiempo de servicio de la API.** Fichajes atendidos —todos los
     * `scan_events`, porque un rechazo por regla de negocio es un intento
     * atendido— sobre esos mas los intentos que el quiosco no llego a cursar.
     * Medirlo como *uptime* de `/health` contaria como caida precisamente el
     * escenario que el diseño resuelve.
     */
    case ClockingAvailabilityRatio = 'clocking_availability_ratio';

    /** De los fichajes atendidos, los que llegaron por la cola offline. Sin objetivo. */
    case OfflineResolvedRatio = 'offline_resolved_ratio';

    /** §1.3: «tiempo medio hasta resolver un turno sin cerrar», objetivo < 24 h. */
    case IncidentResolutionMeanMinutes = 'incident_resolution_mean_minutes';

    /** La mediana de lo mismo, como dato secundario. Sin objetivo propio. */
    case IncidentResolutionMedianMinutes = 'incident_resolution_median_minutes';

    /** RF-IN-08: «incidencias abiertas». Foto de hoy, de cualquier tipo. */
    case OpenIncidents = 'open_incidents';

    /** RF-IN-08: «empleados sin credencial entregada». Foto de hoy. */
    case EmployeesWithoutCredential = 'employees_without_credential';

    /** RF-IN-08: «horas trabajadas frente a contratadas», la primera mitad. */
    case WorkedMinutes = 'worked_minutes';

    /** La segunda mitad. Prorrateadas por dia natural de vigencia del contrato (RF-IN-03). */
    case ContractedMinutes = 'contracted_minutes';

    /**
     * §1.3: «horas/mes consolidando hojas de horas», objetivo −80 %.
     *
     * Lo **declara el cliente** en `BASELINE_MANUAL_HOURS_PER_MONTH`: es anterior a
     * la instalacion y el sistema no puede observarlo. Sin declarar sale vacio.
     */
    case BaselineManualMinutesPerMonth = 'baseline_manual_minutes_per_month';

    /**
     * Los doce, en el orden de lectura del cuadro.
     *
     * `self::cases()` ya lo da, y este metodo existe para que el orden sea una
     * promesa explicita que una prueba pueda afirmar: el CSV, el PDF y la pantalla
     * lo recorren, y reordenar el enumerado cambiaria el papel que un hotel ya
     * tenga archivado.
     *
     * @return list<self>
     */
    public static function inReadingOrder(): array
    {
        return self::cases();
    }

    /**
     * En que unidad se expresa.
     *
     * **Tabla y no `match`**, al contrario que en los enumerados cortos del
     * producto: con doce casos un `match` supera el techo de complejidad de doc 02
     * §3.5, y bajarlo por un enumerado que solo enumera seria cambiar la regla por
     * el caso. La tabla tiene el mismo rigor: el `?? throw` revienta en voz alta
     * con el nombre de la clave que falta —es el patron de `AuditAction::event()`—
     * y la prueba unitaria de la politica de
     * adopcion recorre los doce, asi que un indicador nuevo sin unidad no llega a pasar la suite.
     *
     * @return array<string, AdoptionIndicatorUnit>
     */
    private static function unitByKey(): array
    {
        return [
            self::WorkDaysCompleteRatio->value => AdoptionIndicatorUnit::Percent,
            self::QrScansRatio->value => AdoptionIndicatorUnit::Percent,
            self::ManualCorrectionsRatio->value => AdoptionIndicatorUnit::Percent,
            self::ClockingAvailabilityRatio->value => AdoptionIndicatorUnit::Percent,
            self::OfflineResolvedRatio->value => AdoptionIndicatorUnit::Percent,
            self::IncidentResolutionMeanMinutes->value => AdoptionIndicatorUnit::Minutes,
            self::IncidentResolutionMedianMinutes->value => AdoptionIndicatorUnit::Minutes,
            self::WorkedMinutes->value => AdoptionIndicatorUnit::Minutes,
            self::ContractedMinutes->value => AdoptionIndicatorUnit::Minutes,
            self::BaselineManualMinutesPerMonth->value => AdoptionIndicatorUnit::Minutes,
            self::OpenIncidents->value => AdoptionIndicatorUnit::Count,
            self::EmployeesWithoutCredential->value => AdoptionIndicatorUnit::Count,
        ];
    }

    public function unit(): AdoptionIndicatorUnit
    {
        return self::unitByKey()[$this->value]
            ?? throw new MissingAdoptionIndicatorUnit($this->value);
    }

    /**
     * El objetivo del §1.3, o `null` para los seis que no tienen ninguno.
     *
     * **Las cifras estan aqui y en un solo sitio.** Y en una tabla en lugar de un
     * `match`, por lo mismo que {@see self::unitByKey()}: doce casos no caben en el
     * techo de complejidad. Una clave **ausente** de la tabla significa «sin
     * objetivo», que es lo que hace que los seis sin objetivo no tengan que
     * enumerarse para devolver `null` — y la prueba que recorre los doce es la que
     * garantiza que la ausencia sea deliberada y no un olvido.
     *
     * @return array<string, AdoptionTarget>
     */
    private static function targetByKey(): array
    {
        return [
            self::WorkDaysCompleteRatio->value => AdoptionTarget::atLeast(99.0),
            self::QrScansRatio->value => AdoptionTarget::atLeast(98.0),
            self::ManualCorrectionsRatio->value => AdoptionTarget::atMost(2.0),
            self::ClockingAvailabilityRatio->value => AdoptionTarget::atLeast(99.9),
            // 24 h expresadas en la unidad del indicador. El §1.3 dice «< 24 h» y
            // aqui son minutos por lo mismo que en todo el producto: los minutos
            // suman y las horas decimales no.
            self::IncidentResolutionMeanMinutes->value => AdoptionTarget::atMost(1440.0),
            self::BaselineManualMinutesPerMonth->value => AdoptionTarget::reductionOf(80.0),
        ];
    }

    public function target(): ?AdoptionTarget
    {
        return self::targetByKey()[$this->value] ?? null;
    }

    /**
     * Los cuatro que **no** se comparan con el periodo anterior.
     *
     * Las dos fotos de hoy —una cola pendiente no pertenece a ningun periodo—, el
     * reparto offline (acompaña a la disponibilidad y se lee con ella) y la linea
     * base declarada, que no es una medida.
     *
     * Se enumeran los que NO y no los que SI porque son menos y porque la regla es
     * mas facil de leer asi: «todo se compara, salvo dos fotos, un subindicador y un
     * dato declarado».
     *
     * @return list<self>
     */
    private static function snapshots(): array
    {
        return [
            self::OpenIncidents,
            self::EmployeesWithoutCredential,
            self::OfflineResolvedRatio,
            self::BaselineManualMinutesPerMonth,
        ];
    }

    /** ¿Tiene sentido comparar este indicador con el periodo anterior? */
    public function comparesAgainstPreviousPeriod(): bool
    {
        return ! in_array($this, self::snapshots(), true);
    }
}
