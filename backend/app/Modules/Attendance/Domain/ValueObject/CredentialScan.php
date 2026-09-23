<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\ValueObject;

use App\Modules\Attendance\Domain\Policy\CredentialPatternPolicy;
use App\Modules\Attendance\Domain\Policy\ReviewPolicy;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * **Un uso de credencial en un quiosco**, tal y como lo mira la deteccion de
 * patrones anomalos (RF-PR-06, RN-16, tarea 3.11).
 *
 * Es la unidad de {@see CredentialPatternPolicy}: ni una jornada ni un tramo,
 * sino el hecho elemental «esta persona presento su credencial en esta tablet a
 * esta hora». Los tres patrones de RF-PR-06 se enuncian sobre pares de estos y
 * sobre nada mas, que es lo que permite que la regla sea pura.
 *
 * ## Que entra aqui y que no (decision 4 de la ficha)
 *
 * Solo los escaneos **del quiosco** —`qr_kiosk` y `pin_kiosk`: el PIN de
 * emergencia tambien es una credencial personal, y se presta con mas facilidad
 * que la tarjeta— y solo los **aceptados**. Un rechazo no es un uso de
 * credencial, y muchos ni siquiera resuelven a una persona. `manual_admin` e
 * `import` no pasan por ningun quiosco: no hay tarjeta que prestar. El filtro
 * vive en el adaptador porque es una condicion de la consulta, no una regla.
 *
 * ## Ni un nombre de persona (regla dura 21)
 *
 * La persona es `employeeUuid`. El **quiosco** si viaja con su rotulo: un
 * dispositivo no es una persona, y sin el nombre de la sala la incidencia
 * obliga a quien la revisa a traducir un identificador numerico a mano. Se
 * recorta a 64 caracteres porque esa es la longitud que el esquema
 * `IncidentContext` del contrato admite para una cadena, y ese rotulo acaba
 * dentro del contexto del hallazgo — `devices.name` admite 120.
 *
 * ## `clockSkewSeconds` viaja para poder NO evaluar (RN-16)
 *
 * RN-16 dice que la secuencia imposible **no se evalua** sobre **una hora en
 * duda por el reloj**: si el quiosco venia desviado por encima de la tolerancia
 * del centro (RN-15, RF-AT-10), el hueco medido entre dos escaneos no describe
 * ningun transito y afirmar una imposibilidad fisica seria senalar a alguien por
 * un reloj mal puesto.
 *
 * **Lo que viaja es el desfase medido, no la marca** (decision 16 de la ficha
 * 3.11, bloqueante B4 de la revision). Aqui estuvo `flagged_for_review` y era un
 * error: `ReviewPolicy::requiresReview()` la pone tambien para **todo** fichaje
 * por PIN (RF-AT-11), asi que excluir por ella dejaba sin RN-16 justo al camino
 * que la decision 4 incluye a proposito —el PIN es la credencial que se presta
 * con mas facilidad—. Quien decide si el desfase supera el umbral es el dominio,
 * con el mismo metodo que uso el quiosco ({@see ReviewPolicy::exceedsSkewTolerance()}).
 *
 * `null` cuando no se midio —un fichaje sin desfase que anotar—: entonces no hay
 * nada que poner en duda y el escaneo se evalua.
 */
final readonly class CredentialScan
{
    /** Lo que admite una cadena de `incidents.context` en el contrato (esquema `IncidentContext`). */
    public const int MAX_DEVICE_NAME = 64;

    private function __construct(
        /** Identificador publico del empleado. **Nunca su nombre** (regla dura 21). */
        public string $employeeUuid,
        /** `devices.id`: el quiosco donde se presento la credencial. */
        public int $deviceId,
        /** Rotulo del quiosco (`devices.name`), ya recortado a lo que admite el contrato. */
        public string $deviceName,
        /** Momento real del escaneo, en UTC (reglas duras 3 y 9: es `occurred_at`, no `recorded_at`). */
        public DateTimeImmutable $occurredAt,
        /**
         * Fecha civil del escaneo **en la zona del centro**.
         *
         * Es el dia por el que se agrupan las coincidencias (decision 2 de la
         * ficha: «una por par de personas y dia civil del centro») y la jornada
         * que lleva el hallazgo. Se deriva de `occurred_at` y no del tramo, por
         * lo mismo que `out_of_order_scan`: es la unica fecha que el propio
         * hecho sostiene, y un escaneo puede no haber producido tramo ninguno.
         */
        public WorkDate $workDate,
        /** `scan_events.scan_id`, con el que una persona encuentra el fichaje en el log. */
        public string $scanId,
        /** El tramo que abrio o cerro, si lo hubo. */
        public ?string $shiftEntryUuid,
        /**
         * Desfase medido entre el reloj del quiosco y el del servidor, **con
         * signo** y en segundos. `null` cuando no se midio. Ver el docblock de
         * la clase: es lo que pone una hora en duda para RN-16.
         */
        public ?int $clockSkewSeconds,
    ) {}

    public static function of(
        string $employeeUuid,
        int $deviceId,
        string $deviceName,
        DateTimeImmutable $occurredAt,
        WorkDate $workDate,
        string $scanId,
        ?string $shiftEntryUuid = null,
        ?int $clockSkewSeconds = null,
    ): self {
        TimeRange::assertUtc('occurredAt', $occurredAt);

        if (trim($employeeUuid) === '') {
            throw new InvalidArgumentException('Un escaneo sin empleado no describe ningun uso de credencial.');
        }

        return new self(
            employeeUuid: $employeeUuid,
            deviceId: $deviceId,
            // El recorte ocurre UNA vez, al construir, y no donde se compone el
            // contexto: asi ningun camino puede meter en `incidents.context` una
            // cadena mas larga de la que el contrato publica.
            deviceName: mb_substr($deviceName, 0, self::MAX_DEVICE_NAME),
            occurredAt: $occurredAt,
            workDate: $workDate,
            scanId: $scanId,
            shiftEntryUuid: $shiftEntryUuid,
            clockSkewSeconds: $clockSkewSeconds,
        );
    }

    /**
     * El desfase medido como objeto de valor, para que
     * {@see ReviewPolicy::exceedsSkewTolerance()} lo evalue con el mismo criterio
     * con el que lo evaluo el quiosco.
     *
     * `null` cuando no se midio: no hay nada que poner en duda.
     */
    public function clockSkew(): ?ClockSkew
    {
        return $this->clockSkewSeconds === null ? null : ClockSkew::ofSeconds($this->clockSkewSeconds);
    }

    /** Segundos entre este escaneo y otro, en valor absoluto. */
    public function gapSecondsTo(self $other): int
    {
        return abs($other->occurredAt->getTimestamp() - $this->occurredAt->getTimestamp());
    }

    /** Si los dos escaneos son de la misma persona. */
    public function isSamePersonAs(self $other): bool
    {
        return $this->employeeUuid === $other->employeeUuid;
    }

    /** Si los dos escaneos ocurrieron en el mismo quiosco. */
    public function isSameDeviceAs(self $other): bool
    {
        return $this->deviceId === $other->deviceId;
    }
}
