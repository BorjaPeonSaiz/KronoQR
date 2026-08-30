<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\Policy;

use App\Modules\Compliance\Domain\Exception\InvalidRetentionPolicy;
use App\Modules\Compliance\Domain\ValueObject\RetentionScope;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Cuanto se conserva cada tipo de dato y, por tanto, que puede purgarse hoy
 * (RL-02, RL-11, RF-PR-03).
 *
 * ## Ni un plazo escrito aqui (regla dura 14)
 *
 * Los tres valores llegan por el constructor y no hay ninguno por defecto: los
 * anos del registro de jornada y de `audit_log` salen del **perfil de
 * cumplimiento** del centro (`compliance_profiles.retention_years`, RF-PD-07), y
 * los dias del log tecnico y de `error_events` de la configuracion de la
 * instalacion (`ERROR_HISTORY_RETENTION_DAYS`, doc 02 §8.2.1). Escribir «4» aqui
 * convertiria una obligacion que cambia con la jurisdiccion en una constante de
 * PHP, que es exactamente lo que ADR-017 prohibe para poder vender el producto
 * en otro pais sin tocar el repositorio.
 *
 * ## Dos plazos y dos relojes, no uno
 *
 * El registro de jornada se compara por **fecha de jornada** (`work_date`), que
 * es una fecha civil, y el ciclo corto por **instante**, que es un
 * `TIMESTAMPTZ`. Mezclarlos daria un corte que se mueve con la hora a la que se
 * lanza el comando.
 *
 * ## El dia exacto del vencimiento NO se purga
 *
 * `purgesWorkDate()` es **estricta**: con cuatro anos de retencion y el reloj en
 * el 30 de agosto de 2030, la jornada del 30 de agosto de 2026 —que cumple hoy
 * sus cuatro anos— **se conserva**, y la del 29 se purga. La razon es que el
 * art. 34.9 ET obliga a conservar «durante cuatro anos», y el ultimo dia esta
 * dentro del plazo, no fuera. Es la misma semantica de limite abierto que usa la
 * politica de revision de `Attendance` para el desfase de reloj -nombrada en
 * prosa y no con `@see`, porque una referencia de docblock a otro modulo la
 * convierte Pint en un `use` real y el dominio dejaria de ser puro (regla dura
 * 1)-: un limite que pertenece a los dos lados se comporta distinto segun quien
 * lo evalue.
 *
 * ## Y una particion de `audit_log` solo cae cuando cae entera
 *
 * Una particion anual se suelta cuando su ultimo instante posible —el 1 de enero
 * del ano siguiente— ya esta fuera del plazo. Con el corte en agosto de 2026, la
 * particion de 2026 **no** se toca aunque parte de ella haya vencido: soltarla
 * llevaria por delante los asientos de septiembre a diciembre, que siguen bajo
 * deber de conservacion. `DROP PARTITION` es todo o nada (ADR-027), asi que el
 * redondeo tiene que ser hacia conservar de mas.
 *
 * **No conoce el reloj** (regla dura 2): el instante entra por parametro.
 */
final readonly class RetentionPolicy
{
    private function __construct(
        /** RL-02: anos de conservacion del registro de jornada y de `audit_log`. */
        public int $legalRecordYears,
        /** RL-11: dias de conservacion del log tecnico. */
        public int $technicalLogDays,
        /** RF-PD-15: dias de conservacion del historico de errores. */
        public int $errorHistoryDays,
    ) {
        self::positive($legalRecordYears, 'los anos del registro de jornada (RL-02)');
        self::positive($technicalLogDays, 'los dias del log tecnico (RL-11)');
        self::positive($errorHistoryDays, 'los dias del historico de errores (RF-PD-15)');
    }

    public static function of(int $legalRecordYears, int $technicalLogDays, int $errorHistoryDays): self
    {
        return new self($legalRecordYears, $technicalLogDays, $errorHistoryDays);
    }

    /**
     * Primera fecha de jornada que **sigue** conservandose, a medianoche UTC.
     *
     * Todo lo estrictamente anterior es purgable; esta fecha, no.
     */
    public function workRecordCutoff(DateTimeImmutable $now): DateTimeImmutable
    {
        return $this->utcMidnight($now)->sub(new DateInterval('P'.$this->legalRecordYears.'Y'));
    }

    public function purgesWorkDate(DateTimeImmutable $workDate, DateTimeImmutable $now): bool
    {
        return $this->utcMidnight($workDate) < $this->workRecordCutoff($now);
    }

    /**
     * Si la particion anual de `audit_log` del ano dado ya vencio **entera**.
     */
    public function purgesAuditPartition(int $year, DateTimeImmutable $now): bool
    {
        $endOfPartition = new DateTimeImmutable(($year + 1).'-01-01T00:00:00', new DateTimeZone('UTC'));

        return $endOfPartition <= $this->workRecordCutoff($now);
    }

    /**
     * Instante antes del cual el ciclo corto ya no conserva nada (RL-11).
     *
     * Un solo metodo para los dos almacenes porque la pregunta es la misma y el
     * plazo es un parametro: repetirlo por almacen es como acaban dos ciclos con
     * semanticas de limite distintas.
     */
    public function shortCycleCutoff(RetentionScope $scope, DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->sub(new DateInterval('P'.$this->daysFor($scope).'D'));
    }

    public function daysFor(RetentionScope $scope): int
    {
        return match ($scope) {
            RetentionScope::TechnicalLog => $this->technicalLogDays,
            RetentionScope::ErrorHistory => $this->errorHistoryDays,
            // Los dos ambitos legales se miden en anos y tienen su propio corte:
            // pedirles dias seria preguntar por una unidad que no usan.
            RetentionScope::WorkRecords, RetentionScope::AuditLog => throw InvalidRetentionPolicy::hasNoShortCycle($scope),
        };
    }

    private function utcMidnight(DateTimeImmutable $moment): DateTimeImmutable
    {
        return $moment->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0);
    }

    private static function positive(int $value, string $what): void
    {
        if ($value < 1) {
            throw InvalidRetentionPolicy::notPositive($what, $value);
        }
    }
}
