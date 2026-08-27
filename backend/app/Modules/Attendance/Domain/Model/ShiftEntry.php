<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Model;

use App\Modules\Attendance\Domain\Exception\ShiftEntryAlreadyClosed;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Attendance\Domain\ValueObject\ShiftEntryStatus;
use App\Modules\Attendance\Domain\ValueObject\ShiftTimes;
use App\Modules\Attendance\Domain\ValueObject\TimeRange;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Attendance\Domain\ValueObject\WorkedDuration;
use DateTimeImmutable;

/**
 * El **tramo**: un par entrada/salida, la unidad minima de tiempo trabajado
 * (glosario del doc 01 §13).
 *
 * Es una entidad **dentro** del agregado `WorkDay`, no una raiz. Nadie lo abre
 * ni lo cierra por fuera: quien protege RN-01 y RN-02 es la jornada, y un tramo
 * modificado a su espalda deja esas invariantes sin guardian. `@internal` marca
 * los metodos que solo `WorkDay` debe llamar; la excepcion es
 * `reconstitute()`, que necesita el repositorio de la tarea 1.4 para rehidratar
 * lo que ya esta en la base de datos.
 *
 * **Un turno que cruza la medianoche es un solo tramo** (RN-05, ADR-006, regla
 * dura 4). No hay ningun sitio en esta clase donde se parta nada a las 23:59, y
 * eso es deliberado: la solucion intuitiva fabrica dos marcas que nadie produjo,
 * rompe el calculo del descanso entre jornadas y distorsiona la jornada diaria.
 *
 * Guarda los dos instantes por separado y construye su `TimeRange` cuando esta
 * cerrado, en lugar de guardar el intervalo: un tramo abierto **no tiene fin**,
 * y darle uno provisional seria inventarse una salida.
 *
 * **Las marcas son inmutables; lo que cambia es cual es la version vigente**
 * (RN-13, regla dura 5, ADR-026). Corregir un tramo no reescribe sus horas:
 * produce {@see nextVersion()}, un tramo nuevo con `version + 1`, y deja a este
 * en `superseded` apuntando a aquel. La unica salvedad es `close()`, que rellena
 * una salida que **no existia**: ahi no se sobrescribe ningun hecho registrado,
 * se completa el que faltaba.
 */
final class ShiftEntry
{
    private function __construct(
        private readonly string $uuid,
        private readonly string $employeeUuid,
        private readonly WorkDate $workDate,
        private readonly DateTimeImmutable $clockedInAt,
        private ?DateTimeImmutable $clockedOutAt,
        private readonly ScanOrigin $clockInSource,
        private ?ScanOrigin $clockOutSource,
        private ShiftEntryStatus $status,
        private readonly int $version,
        private ?string $supersededByUuid,
    ) {}

    /**
     * Abre un tramo.
     *
     * El `uuid` llega de fuera porque generarlo aqui exigiria un UUID v7, que
     * lleva la hora dentro: el dominio no pregunta la hora, la recibe (regla
     * dura 2). Lo genera el caso de uso.
     *
     * La jornada tampoco se deriva aqui del `clockedInAt`. **La recibe**, y esa
     * es la diferencia entre que la vuelta de una pausa a las 02:30 continue la
     * jornada de ayer o abra una nueva (ADR-024).
     *
     * @internal Solo lo llama WorkDay::clockIn().
     */
    public static function open(
        string $uuid,
        string $employeeUuid,
        WorkDate $workDate,
        DateTimeImmutable $clockedInAt,
        ScanOrigin $source,
    ): self {
        TimeRange::assertUtc('clockedInAt', $clockedInAt);

        return new self(
            $uuid,
            $employeeUuid,
            $workDate,
            $clockedInAt,
            null,
            $source,
            null,
            ShiftEntryStatus::OPEN,
            1,
            null,
        );
    }

    /**
     * Da de alta un tramo que nunca se ficho, con las marcas que una persona
     * autorizada declara (RF-PA-04, accion `created`).
     *
     * Es la primera version de un tramo igual que {@see open()}, y por eso nace
     * en la 1: lo que lo distingue de un fichaje no es el numero de version sino
     * el `source` —`manual_admin`— y la fila de `shift_corrections` que lo
     * explica. Nace ya cerrado si trae las dos marcas, porque un alta manual con
     * salida no pasa por ningun `close()`.
     *
     * @internal Solo lo llama WorkDay::addEntry().
     */
    public static function declaredManually(
        string $uuid,
        string $employeeUuid,
        WorkDate $workDate,
        ShiftTimes $times,
        ScanOrigin $source,
    ): self {
        return new self(
            $uuid,
            $employeeUuid,
            $workDate,
            $times->clockedInAt,
            $times->clockedOutAt,
            $source,
            $times->isOpen() ? null : $source,
            $times->isOpen() ? ShiftEntryStatus::OPEN : ShiftEntryStatus::CLOSED,
            1,
            null,
        );
    }

    /**
     * Rehidrata un tramo ya persistido, comprobando lo mismo que se comprobo al
     * escribirlo.
     *
     * Se valida al reconstruir y no solo al crear porque la base de datos es un
     * origen mas: una fila escrita por una migracion, por una importacion o por
     * una version anterior del codigo entra por aqui, y un tramo cerrado con la
     * salida antes de la entrada tiene que fallar tan pronto como se lea.
     *
     * @internal Lo llama el repositorio de Attendance (tarea 1.4).
     */
    public static function reconstitute(
        string $uuid,
        string $employeeUuid,
        WorkDate $workDate,
        DateTimeImmutable $clockedInAt,
        ?DateTimeImmutable $clockedOutAt,
        ScanOrigin $clockInSource,
        ?ScanOrigin $clockOutSource,
        ShiftEntryStatus $status,
        int $version,
        ?string $supersededByUuid = null,
    ): self {
        TimeRange::assertUtc('clockedInAt', $clockedInAt);

        if ($clockedOutAt !== null) {
            // Construir el intervalo es la comprobacion de RN-03: si la salida
            // no es estrictamente posterior a la entrada, no llega a existir.
            new TimeRange($clockedInAt, $clockedOutAt);
        }

        return new self(
            $uuid,
            $employeeUuid,
            $workDate,
            $clockedInAt,
            $clockedOutAt,
            $clockInSource,
            $clockOutSource,
            $status,
            $version,
            $supersededByUuid,
        );
    }

    /**
     * Cierra el tramo. RN-03 lo verifica el propio `TimeRange`.
     *
     * @internal Solo lo llama WorkDay::clockOut().
     */
    public function close(DateTimeImmutable $clockedOutAt, ScanOrigin $source): void
    {
        if (! $this->status->isOpen()) {
            throw ShiftEntryAlreadyClosed::withUuid($this->uuid);
        }

        TimeRange::assertUtc('clockedOutAt', $clockedOutAt);
        new TimeRange($this->clockedInAt, $clockedOutAt);

        $this->clockedOutAt = $clockedOutAt;
        $this->clockOutSource = $source;
        $this->status = ShiftEntryStatus::CLOSED;
    }

    /**
     * Marca el tramo para revision humana (RN-07, RN-08).
     *
     * No lo cierra ni lo corrige: ya esta cerrado con las marcas que el empleado
     * produjo. Lo unico que cambia es que una persona tiene que mirarlo.
     *
     * @internal Solo lo llama WorkDay::clockOut(), con el veredicto de ClockingPolicy.
     */
    public function markAnomalous(): void
    {
        $this->status = ShiftEntryStatus::ANOMALOUS;
    }

    /**
     * La version corregida de este tramo (RN-13, RF-PA-04).
     *
     * **Devuelve un tramo nuevo y no toca este.** Es toda la regla dura 5 en una
     * linea: las marcas de esta version se quedan como estaban, y quien las
     * quiera consultar dentro de dos anos las encontrara con su `version` y su
     * motivo. El agregado marca despues a esta como `superseded` con
     * {@see markSupersededBy()}, y las dos filas conviven en la tabla (ADR-026).
     *
     * **El origen se cambia marca a marca, no en bloque.** Si el responsable
     * solo rectifica la hora de salida, `clock_in_source` sigue diciendo
     * `qr_kiosk`, porque esa entrada la ficho la persona con su tarjeta y eso no
     * ha dejado de ser verdad. Solo la marca que cambia pasa a `manual_admin`.
     * Perder ese detalle convertiria cualquier tramo tocado en «todo esto lo
     * escribio un administrador», que ante Inspeccion es una afirmacion mas
     * fuerte —y mas fea— que la que corresponde.
     *
     * Un tramo corregido a abierto conserva su version de salida a nulo: es lo
     * que ocurre cuando se rectifica la entrada de un turno que sigue en curso.
     *
     * @internal Solo lo llama WorkDay::correctEntry().
     */
    public function nextVersion(string $uuid, ShiftTimes $times, ScanOrigin $source): self
    {
        $clockInChanged = $times->clockedInAt->getTimestamp() !== $this->clockedInAt->getTimestamp();
        $clockOutChanged = $times->clockedOutAt?->getTimestamp() !== $this->clockedOutAt?->getTimestamp();

        return new self(
            $uuid,
            $this->employeeUuid,
            // La jornada es la misma: un tramo no cambia de dia al corregirse
            // (RN-05, regla dura 4). Quien lo intente choca antes con
            // `CorrectionWouldChangeWorkDate` en el agregado.
            $this->workDate,
            $times->clockedInAt,
            $times->clockedOutAt,
            $clockInChanged ? $source : $this->clockInSource,
            match (true) {
                $times->isOpen() => null,
                $clockOutChanged => $source,
                default => $this->clockOutSource,
            },
            $times->isOpen() ? ShiftEntryStatus::OPEN : ShiftEntryStatus::CLOSED,
            $this->version + 1,
            null,
        );
    }

    /**
     * Este tramo ocurrio, se conserva, y otra version lo sustituye (ADR-026).
     *
     * No borra ni cambia una sola marca: lo unico que cambia es que deja de ser
     * la version vigente, con lo que sale del indice unico de RN-01, de la
     * restriccion de exclusion de RN-02 y del recalculo de RN-06. El puntero a
     * la version que lo sustituye es lo que hace recorrible el historico
     * (RL-04).
     *
     * @internal Solo lo llama WorkDay::correctEntry().
     */
    public function markSupersededBy(string $replacementUuid): void
    {
        $this->status = ShiftEntryStatus::SUPERSEDED;
        $this->supersededByUuid = $replacementUuid;
    }

    /**
     * Este tramo **no ocurrio** (ADR-026, RF-PA-04, accion `voided`).
     *
     * No crea version nueva y no pone `superseded_by_id`: no hay ninguna version
     * posterior de un hecho que no paso. La fila se queda en la tabla con sus
     * marcas y su motivo, que es lo que distingue una anulacion de un borrado.
     *
     * @internal Solo lo llama WorkDay::voidEntry().
     */
    public function markVoided(): void
    {
        $this->status = ShiftEntryStatus::VOIDED;
    }

    public function uuid(): string
    {
        return $this->uuid;
    }

    public function employeeUuid(): string
    {
        return $this->employeeUuid;
    }

    public function workDate(): WorkDate
    {
        return $this->workDate;
    }

    public function clockedInAt(): DateTimeImmutable
    {
        return $this->clockedInAt;
    }

    public function clockedOutAt(): ?DateTimeImmutable
    {
        return $this->clockedOutAt;
    }

    public function clockInSource(): ScanOrigin
    {
        return $this->clockInSource;
    }

    public function clockOutSource(): ?ScanOrigin
    {
        return $this->clockOutSource;
    }

    public function status(): ShiftEntryStatus
    {
        return $this->status;
    }

    public function version(): int
    {
        return $this->version;
    }

    /**
     * Identificador de la version que sustituye a esta, o `null` si esta es la
     * vigente (`shift_entries.superseded_by_id`, resuelto a uuid).
     *
     * El dominio encadena versiones por uuid y no por la clave interna: es el
     * identificador publico, el que sale por la API y el que viaja en los
     * eventos. Traducirlo a `superseded_by_id` es trabajo del repositorio.
     */
    public function supersededByUuid(): ?string
    {
        return $this->supersededByUuid;
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /**
     * Las dos marcas de este tramo como objeto de valor: lo que una correccion
     * recibe, compara y sustituye.
     */
    public function times(): ShiftTimes
    {
        return ShiftTimes::of($this->clockedInAt, $this->clockedOutAt);
    }

    /**
     * El intervalo trabajado, o `null` mientras el tramo siga abierto.
     */
    public function period(): ?TimeRange
    {
        if ($this->clockedOutAt === null) {
            return null;
        }

        return new TimeRange($this->clockedInAt, $this->clockedOutAt);
    }

    /**
     * Lo que este tramo aporta al total del dia.
     *
     * Un tramo abierto aporta cero, no «lo que lleve hasta ahora»: preguntarlo
     * exigiria el reloj, y el registro legal cuenta lo fichado, no lo que va
     * corriendo. El panel en vivo muestra el tiempo transcurrido, pero eso es
     * presentacion (RF-PA-01), no el total de RN-06.
     */
    public function workedDuration(): WorkedDuration
    {
        return $this->period()?->duration() ?? WorkedDuration::zero();
    }

    /**
     * RN-02, con la semantica `[inicio, fin)` de la restriccion de exclusion.
     * Un tramo abierto se trata como si llegara hasta el infinito.
     */
    public function coversInstant(DateTimeImmutable $instant): bool
    {
        $period = $this->period();

        if ($period === null) {
            return $instant->getTimestamp() >= $this->clockedInAt->getTimestamp();
        }

        return $period->contains($instant);
    }

    /**
     * Si este tramo sigue vivo despues del instante dado. Es lo que hay que
     * saber para abrir otro: el tramo nuevo no tiene fin, asi que solapa con
     * todo lo que continue despues de su inicio.
     */
    public function extendsBeyond(DateTimeImmutable $instant): bool
    {
        return $this->period()?->endsAfter($instant) ?? true;
    }
}
