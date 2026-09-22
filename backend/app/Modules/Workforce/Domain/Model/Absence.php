<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Model;

use App\Modules\Workforce\Domain\Exception\AbsenceNotActive;
use App\Modules\Workforce\Domain\Exception\AbsenceRequiresNote;
use App\Modules\Workforce\Domain\Exception\InvalidAbsencePeriod;
use App\Modules\Workforce\Domain\ValueObject\AbsenceStatus;
use App\Modules\Workforce\Domain\ValueObject\AbsenceType;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Una ausencia registrada: **por que alguien no estuvo entre dos dias**
 * (**RF-GP-04**, doc 01 §5.5).
 *
 * ## Es un hecho, no una solicitud
 *
 * No hay estado «pendiente» ni «aprobada» ni metodo `approve()`. El doc 05 §8
 * acota el alcance con esas palabras —«sin flujo de aprobacion»— y la aprobacion
 * es de una fase posterior. Un metodo de aprobacion aqui desdibujaria una
 * expectativa que hoy esta anunciada al cliente y acotada.
 *
 * ## Calendario, no instantes
 *
 * `startsOn` y `endsOn` son fechas civiles y los dos extremos son
 * **inclusivos**, igual que la vigencia de {@see EmploymentContract}: una
 * ausencia del 1 al 3 cubre el 1, el 2 y el 3, y {@see self::days()} vale 3. Un
 * `TIMESTAMPTZ` obligaria a decidir a que hora empieza una baja medica, que no
 * significa nada.
 *
 * ## Versionar es crear, nunca sobrescribir
 *
 * {@see self::correctedWith()} devuelve **otra** instancia con `version + 1`,
 * `uuid` nuevo y `supersedesUuid` apuntando a esta; esta se marca con
 * {@see self::supersededBy()} y conserva intactos su tipo, sus fechas, su nota,
 * su autor y su momento (regla dura 5, RN-13). Es el mismo patron que
 * `shift_entries` (ADR-026, ADR-035), y por la misma razon: lo que fue verdad
 * sigue siendolo.
 *
 * {@see self::voidedWith()} **no** crea version: no hay una version posterior de
 * un hecho que no paso.
 *
 * ## Sin reloj y sin persistencia
 *
 * Ningun instante se calcula aqui (regla dura 2): el momento de la anulacion
 * entra ya resuelto desde el puerto `Clock`, y el `uuid` de la version nueva lo
 * genera quien puede —la capa de aplicacion—, porque un UUID v7 lleva un reloj
 * dentro. Y como el resto de `Domain/`, esta clase no sabe que existe una tabla.
 *
 * ## Dato de salud: lo que esta clase NO hace
 *
 * No compone mensajes con la nota ni con el tipo mas alla de lo imprescindible,
 * y no escribe en ningun sitio. Una baja medica es dato de salud (regla dura 21)
 * y el camino por el que un dato acaba en `error_events` empieza casi siempre en
 * el mensaje de una excepcion.
 */
final readonly class Absence
{
    /**
     * Techo de la nota, en caracteres.
     *
     * **La unica copia del numero en todo el producto.** Antes vivia suelto en
     * `StoreAbsenceRequest` y en el `CHECK` de la migracion, y la carga por
     * fichero —que no pasa por el `FormRequest`— no lo comprobaba: una nota de
     * 501 caracteres pasaba la fase de comprobacion como `create` y reventaba al
     * aplicar contra `absences_chk_note_length`, con la nota entera interpolada
     * en el mensaje de la `QueryException` y de ahi al log (regla dura 21). El
     * numero vive aqui —que es lo que las tres capas pueden importar— y el
     * `CHECK` lo repite porque el esquema de una instalacion no puede depender
     * de una clase de la aplicacion; las dos copias las ata una prueba.
     */
    public const int MAX_NOTE_LENGTH = 500;

    public function __construct(
        /** Identificador publico de **esta version** (UUID v7, ADR-035). */
        public string $uuid,
        /** Identificador **publico** de la persona. La clave interna no cruza al dominio. */
        public string $employeeUuid,
        public AbsenceType $type,
        /** Primer dia, inclusive. */
        public DateTimeImmutable $startsOn,
        /** Ultimo dia, inclusive. */
        public DateTimeImmutable $endsOn,
        /** Texto de quien la registro. Obligatorio si {@see AbsenceType::requiresNote()}. */
        public ?string $note = null,
        public AbsenceStatus $status = AbsenceStatus::Active,
        public int $version = 1,
        /** La version a la que esta sustituye, o `null` si es la original. */
        public ?string $supersedesUuid = null,
        /** La version que sustituyo a esta, o `null`. */
        public ?string $supersededByUuid = null,
        /** Por que se corrigio. Obligatorio a partir de la version 2. */
        public ?string $changeReason = null,
        public ?DateTimeImmutable $voidedAt = null,
        public ?string $voidReason = null,
        /** Clave interna, o `null` si todavia no se ha persistido. */
        public ?int $id = null,
    ) {
        $this->assertIdentity();
        $this->assertPeriod();
        $this->assertNote();
        $this->assertVersioning();
    }

    public function isActive(): bool
    {
        return $this->status === AbsenceStatus::Active;
    }

    /**
     * Si la ausencia cubre ese dia natural.
     *
     * **Los dos extremos entran**: una ausencia del 1 al 3 cubre el 1 y el 3, y
     * no cubre ni el 31 del mes anterior ni el 4. Es el limite exacto del que
     * depende que el informe no cuente un dia de mas ni de menos, y por eso
     * tiene prueba unitaria propia.
     */
    public function covers(DateTimeImmutable $day): bool
    {
        $date = self::asDate($day);

        return $date >= self::asDate($this->startsOn) && $date <= self::asDate($this->endsOn);
    }

    /**
     * Dias naturales que cubre, con los dos extremos dentro. Nunca menor que 1.
     *
     * **Dias naturales y no laborables.** Este producto no modela el cuadrante
     * teorico de nadie —no sabe que dias libra una persona, solo cuando ficho—,
     * asi que descontar descansos seria inventarlos. El informe lo dice en sus
     * criterios, que es donde quien lo lee puede discutirlo.
     */
    public function days(): int
    {
        return (int) self::asDate($this->startsOn)->diff(self::asDate($this->endsOn))->format('%a') + 1;
    }

    /**
     * Si las dos ausencias comparten algun dia natural.
     *
     * La comprobacion de verdad la hace `absences_no_overlap` en PostgreSQL,
     * porque una consulta previa desde PHP seria una carrera. Esto existe para
     * que el dominio sea probable sin base de datos y para que la importacion
     * pueda decir en que linea esta el choque **antes** de escribir nada.
     *
     * **No mira `status` ni `employeeUuid`**: quien pregunta ya sabe de quien
     * son las dos y cual de ellas esta vigente. Mezclar las tres preguntas aqui
     * haria que una comparacion de intervalos dependiera del ciclo de vida.
     */
    public function overlaps(self $other): bool
    {
        return self::asDate($this->startsOn) <= self::asDate($other->endsOn)
            && self::asDate($other->startsOn) <= self::asDate($this->endsOn);
    }

    /**
     * Si las dos describen exactamente el mismo hecho: misma persona, mismo
     * tipo y los mismos dos dias.
     *
     * Existe para la importacion: una linea identica a una ausencia ya
     * registrada sale como `unchanged` y **no** como solape, para que reimportar
     * el mismo cuadrante con una fila corregida no produzca treinta y nueve
     * conflictos donde no hay ninguno.
     *
     * **La nota no entra en la comparacion.** Quien reimporta un cuadrante no
     * suele arrastrar las notas, y tratar una nota ausente como un cambio
     * convertiria cada reimportacion en un rechazo masivo.
     */
    public function describesTheSameFactAs(self $other): bool
    {
        return $this->employeeUuid === $other->employeeUuid
            && $this->type === $other->type
            && $this->isoStartsOn() === $other->isoStartsOn()
            && $this->isoEndsOn() === $other->isoEndsOn();
    }

    /**
     * La **version siguiente** de esta ausencia (**RN-13**).
     *
     * Devuelve otra instancia: esta no se toca. Lo que cambia se pasa; lo que se
     * omite —`null` en los tres primeros— conserva su valor.
     *
     * **`note` necesita dos parametros y no uno.** `null` con `$noteGiven` a
     * `true` significa «borrala» y con `false` significa «no la toques»: es la
     * distincion que un `PATCH` tiene que poder expresar, y con un solo
     * parametro seria imposible vaciar una nota.
     *
     * @param  string  $uuid  El identificador de la version nueva, generado fuera:
     *                        un UUID v7 lleva un reloj dentro (regla dura 2).
     *
     * @throws AbsenceNotActive si esta version ya fue corregida o anulada
     */
    public function correctedWith(
        string $uuid,
        ?AbsenceType $type,
        ?DateTimeImmutable $startsOn,
        ?DateTimeImmutable $endsOn,
        ?string $note,
        bool $noteGiven,
        string $reason,
    ): self {
        $this->assertIsActive();

        return new self(
            uuid: $uuid,
            employeeUuid: $this->employeeUuid,
            type: $type ?? $this->type,
            startsOn: $startsOn ?? $this->startsOn,
            endsOn: $endsOn ?? $this->endsOn,
            note: $noteGiven ? $note : $this->note,
            status: AbsenceStatus::Active,
            version: $this->version + 1,
            // Apunta a la version que sustituye. El sentido contrario lo escribe
            // {@see self::supersededBy()} sobre aquella, y las dos escrituras
            // ocurren en la misma transaccion.
            supersedesUuid: $this->uuid,
            supersededByUuid: null,
            changeReason: $reason,
            voidedAt: null,
            voidReason: null,
            // Sin `id`: es una fila nueva, no esta.
            id: null,
        );
    }

    /**
     * Esta misma version, marcada como sustituida.
     *
     * **Es el unico cambio que se escribe sobre una fila anterior**, junto con
     * `superseded_by_id`, y no contradice la regla dura 5: no sobrescribe lo
     * registrado —ni el tipo, ni las fechas, ni la nota, ni el autor— solo
     * declara que a partir de ahora hay otra version, que es un dato que antes
     * no existia. Mismo criterio que `EmploymentContract::closedBefore()`.
     */
    public function supersededBy(string $supersedingUuid): self
    {
        return new self(
            uuid: $this->uuid,
            employeeUuid: $this->employeeUuid,
            type: $this->type,
            startsOn: $this->startsOn,
            endsOn: $this->endsOn,
            note: $this->note,
            status: AbsenceStatus::Superseded,
            version: $this->version,
            supersedesUuid: $this->supersedesUuid,
            supersededByUuid: $supersedingUuid,
            changeReason: $this->changeReason,
            voidedAt: $this->voidedAt,
            voidReason: $this->voidReason,
            id: $this->id,
        );
    }

    /**
     * Esta misma ausencia, anulada (**no** una version nueva).
     *
     * Anular declara que el hecho no ocurrio, y de un hecho que no paso no hay
     * version posterior: `version` no sube y `supersededByUuid` sigue a `null`.
     * Es el mismo criterio que `POST /shift-entries/{uuid}/void` (ADR-026).
     *
     * @param  DateTimeImmutable  $at  Instante del puerto `Clock`, nunca calculado aqui.
     *
     * @throws AbsenceNotActive si ya estaba anulada o sustituida
     */
    public function voidedWith(DateTimeImmutable $at, string $reason): self
    {
        $this->assertIsActive();

        return new self(
            uuid: $this->uuid,
            employeeUuid: $this->employeeUuid,
            type: $this->type,
            startsOn: $this->startsOn,
            endsOn: $this->endsOn,
            note: $this->note,
            status: AbsenceStatus::Voided,
            version: $this->version,
            supersedesUuid: $this->supersedesUuid,
            supersededByUuid: null,
            changeReason: $this->changeReason,
            voidedAt: $at,
            voidReason: $reason,
            id: $this->id,
        );
    }

    /**
     * La misma ausencia con su clave interna ya asignada.
     *
     * Existe para que el repositorio pueda devolver hacia arriba lo que acaba de
     * insertar sin releerlo, igual que hace el alta de un contrato.
     */
    public function withId(int $id): self
    {
        return new self(
            uuid: $this->uuid,
            employeeUuid: $this->employeeUuid,
            type: $this->type,
            startsOn: $this->startsOn,
            endsOn: $this->endsOn,
            note: $this->note,
            status: $this->status,
            version: $this->version,
            supersedesUuid: $this->supersedesUuid,
            supersededByUuid: $this->supersededByUuid,
            changeReason: $this->changeReason,
            voidedAt: $this->voidedAt,
            voidReason: $this->voidReason,
            id: $id,
        );
    }

    public function hasNote(): bool
    {
        return $this->note !== null && trim($this->note) !== '';
    }

    public function isoStartsOn(): string
    {
        return $this->startsOn->format('Y-m-d');
    }

    public function isoEndsOn(): string
    {
        return $this->endsOn->format('Y-m-d');
    }

    /**
     * Si el intervalo toca al menos un dia de la relacion laboral.
     *
     * Decision 2 de la ficha 3.10: una ausencia que termina antes del alta o
     * empieza despues del cese se rechaza, porque esos dias no eran dias de
     * trabajo y registrarlos no justificaria nada. El solape **parcial** si se
     * admite y el informe cuenta solo los dias de alta, con el mismo criterio
     * con el que ya cuenta `days_without_contract`.
     *
     * @param  DateTimeImmutable|null  $terminatedAt  `null` si la persona sigue de alta.
     */
    public function fallsWithinEmployment(DateTimeImmutable $hiredAt, ?DateTimeImmutable $terminatedAt): bool
    {
        if (self::asDate($this->endsOn) < self::asDate($hiredAt)) {
            return false;
        }

        return $terminatedAt === null || self::asDate($this->startsOn) <= self::asDate($terminatedAt);
    }

    /**
     * La hora del dia no significa nada en una fecha de calendario: se normaliza
     * a medianoche para que la comparacion sea entre fechas y no una pregunta
     * sobre husos horarios, igual que en `EmploymentContract` y en `DateRange`.
     */
    private static function asDate(DateTimeImmutable $moment): DateTimeImmutable
    {
        return $moment->setTime(0, 0);
    }

    private function assertIsActive(): void
    {
        if (! $this->isActive()) {
            throw AbsenceNotActive::forAbsence($this->uuid, $this->status->value);
        }
    }

    private function assertIdentity(): void
    {
        if ($this->uuid === '') {
            throw new InvalidArgumentException('Una ausencia tiene un identificador publico.');
        }

        if ($this->employeeUuid === '') {
            throw new InvalidArgumentException('Una ausencia pertenece a una persona identificada por su UUID publico.');
        }
    }

    private function assertPeriod(): void
    {
        if (self::asDate($this->startsOn) > self::asDate($this->endsOn)) {
            throw InvalidAbsencePeriod::isInverted($this->isoStartsOn(), $this->isoEndsOn());
        }
    }

    private function assertNote(): void
    {
        if ($this->type->requiresNote() && ! $this->hasNote()) {
            throw AbsenceRequiresNote::forType($this->type);
        }
    }

    /**
     * Las invariantes del encadenado de versiones, las mismas que declara la
     * migracion.
     *
     * Aqui rompen en voz alta y con el nombre de la clase; alli protegen de lo
     * que no pasa por el dominio. Son `InvalidArgumentException` y no excepciones
     * de dominio a proposito: no son casos de negocio que alguien pueda provocar
     * desde un formulario, sino incoherencias del llamante.
     */
    private function assertVersioning(): void
    {
        if ($this->version < 1) {
            throw new InvalidArgumentException('La primera version de una ausencia es la 1.');
        }

        if (($this->version === 1) !== ($this->changeReason === null)) {
            throw new InvalidArgumentException(
                'El motivo del cambio es obligatorio a partir de la version 2, y no existe en la 1.',
            );
        }

        if (($this->status === AbsenceStatus::Voided) !== ($this->voidedAt !== null)) {
            throw new InvalidArgumentException('Una ausencia anulada tiene momento de anulacion, y solo ella.');
        }

        if (($this->voidedAt !== null) !== ($this->voidReason !== null)) {
            throw new InvalidArgumentException('Una anulacion lleva siempre motivo, y solo una anulacion lo lleva.');
        }

        if (($this->status === AbsenceStatus::Superseded) !== ($this->supersededByUuid !== null)) {
            throw new InvalidArgumentException('Una version sustituida apunta a la que la sustituyo, y solo ella.');
        }
    }
}
