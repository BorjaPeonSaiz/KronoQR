<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Model;

use App\Modules\Shared\Domain\ValueObject\EmploymentStatus;
use App\Modules\Workforce\Domain\Exception\EmployeeAlreadyTerminated;
use App\Modules\Workforce\Domain\Exception\InvalidEmploymentPeriod;
use App\Modules\Workforce\Domain\ValueObject\EmployeeCode;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Una persona de la plantilla (RF-GP-01).
 *
 * **Por que Workforce tiene un modelo de dominio si es un modulo de soporte.**
 * ADR-002 le asigna la variante ligera del hexagono —controlador delgado, caso
 * de uso explicito y Eloquent— y aqui se sigue: no hay repositorio de agregados
 * ni eventos por cada campo. Lo que si hay es esta clase, y por dos razones
 * concretas. La primera es una invariante real: el estado y las dos fechas se
 * gobiernan entre si —`terminated` exige fecha de cese, y esa fecha no puede ser
 * anterior al alta (RF-GP-03, RL-02)—, y sin un sitio donde vivir esa regla se
 * repetiria en cada controlador hasta que uno se la saltara. La segunda es la
 * frontera: los puertos de este modulo no pueden hablar en modelos Eloquent
 * (ADR-025, restriccion 2), asi que algo tiene que cruzar, y es esto.
 *
 * **Inmutable.** Cada cambio devuelve una instancia nueva. Es lo que hace
 * imposible el fallo tipico de un modelo mutable: mutar el objeto, que la
 * escritura falle y seguir la peticion con un objeto que ya no corresponde a lo
 * que hay en la base de datos.
 *
 * **Sin reloj.** Ninguna fecha se calcula aqui (regla dura 2): la fecha de cese
 * la decide quien da la baja y entra ya resuelta.
 */
final readonly class Employee
{
    public function __construct(
        public string $uuid,
        public EmployeeCode $code,
        public string $firstName,
        public string $lastName,
        public ?string $email,
        public int $siteId,
        public ?int $departmentId,
        public EmploymentStatus $status,
        public DateTimeImmutable $hiredAt,
        public ?DateTimeImmutable $terminatedAt,
        public string $locale,
    ) {
        $this->assertIdentityIsComplete();
        $this->assertAssignmentIsValid();
        $this->assertEmploymentPeriodIsCoherent();
    }

    /**
     * @throws InvalidArgumentException
     */
    private function assertIdentityIsComplete(): void
    {
        if ($this->uuid === '') {
            throw new InvalidArgumentException('Un empleado necesita su UUID publico.');
        }

        if (trim($this->firstName) === '' || trim($this->lastName) === '') {
            throw new InvalidArgumentException('Un empleado necesita nombre y apellidos.');
        }

        if ($this->locale === '') {
            throw new InvalidArgumentException('Un empleado necesita un idioma para su portal y su tarjeta.');
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    private function assertAssignmentIsValid(): void
    {
        if ($this->siteId < 1) {
            throw new InvalidArgumentException('Un empleado esta adscrito a un centro.');
        }

        if ($this->departmentId !== null && $this->departmentId < 1) {
            throw new InvalidArgumentException('El departamento, si existe, es un identificador valido.');
        }
    }

    /**
     * Las dos fechas y el estado se gobiernan entre si: es la invariante que
     * justifica que este modelo exista (RF-GP-03, RL-02).
     *
     * @throws InvalidArgumentException
     * @throws InvalidEmploymentPeriod
     */
    private function assertEmploymentPeriodIsCoherent(): void
    {
        if ($this->status === EmploymentStatus::TERMINATED && $this->terminatedAt === null) {
            // Sin fecha de cese, la retencion de RL-02 no sabe desde cuando
            // contar y RN-14 no sabe desde cuando esta persona no podia fichar.
            throw new InvalidArgumentException('Una baja necesita su fecha de cese (RF-GP-03).');
        }

        if ($this->terminatedAt !== null && $this->terminatedAt < $this->dateOnly($this->hiredAt)) {
            throw InvalidEmploymentPeriod::terminationBeforeHiring(
                $this->terminatedAt->format('Y-m-d'),
                $this->hiredAt->format('Y-m-d'),
            );
        }
    }

    /**
     * Alta: nace activa y sin fecha de cese.
     */
    public static function hire(
        string $uuid,
        EmployeeCode $code,
        string $firstName,
        string $lastName,
        ?string $email,
        int $siteId,
        ?int $departmentId,
        DateTimeImmutable $hiredAt,
        string $locale,
    ): self {
        return new self(
            uuid: $uuid,
            code: $code,
            firstName: $firstName,
            lastName: $lastName,
            email: $email,
            siteId: $siteId,
            departmentId: $departmentId,
            status: EmploymentStatus::ACTIVE,
            hiredAt: $hiredAt,
            terminatedAt: null,
            locale: $locale,
        );
    }

    /**
     * Baja **logica** (RF-GP-03, regla dura 5): cambia el estado y fija la fecha.
     *
     * Nada se borra. La ficha, sus tramos y sus jornadas siguen ahi: el registro
     * horario se conserva cuatro anos (RL-02) y una inspeccion puede pedir el de
     * alguien que ya no trabaja en el hotel.
     *
     * @throws EmployeeAlreadyTerminated
     * @throws InvalidEmploymentPeriod
     */
    public function offboard(DateTimeImmutable $terminatedAt): self
    {
        if ($this->status === EmploymentStatus::TERMINATED) {
            throw EmployeeAlreadyTerminated::withUuid($this->uuid);
        }

        return $this->with(
            status: EmploymentStatus::TERMINATED,
            terminatedAt: $this->dateOnly($terminatedAt),
            terminatedAtGiven: true,
        );
    }

    /**
     * Suspension temporal: deja de fichar (RN-14) pero sigue en plantilla.
     *
     * @throws EmployeeAlreadyTerminated
     */
    public function suspend(): self
    {
        $this->refuseIfTerminated();

        return $this->with(status: EmploymentStatus::SUSPENDED);
    }

    /**
     * @throws EmployeeAlreadyTerminated
     */
    public function reinstate(): self
    {
        $this->refuseIfTerminated();

        return $this->with(status: EmploymentStatus::ACTIVE);
    }

    /**
     * Cambios de ficha. **Ni el codigo ni el UUID entran aqui**: hay tarjetas
     * impresas con ese codigo (RF-QR-01) y reescribirlo dejaria a alguien sin
     * poder fichar con la suya.
     *
     * @throws EmployeeAlreadyTerminated
     */
    public function updateProfile(
        ?string $firstName = null,
        ?string $lastName = null,
        ?string $email = null,
        bool $emailGiven = false,
        ?int $departmentId = null,
        bool $departmentGiven = false,
        ?string $locale = null,
    ): self {
        $this->refuseIfTerminated();

        return new self(
            uuid: $this->uuid,
            code: $this->code,
            firstName: $firstName ?? $this->firstName,
            lastName: $lastName ?? $this->lastName,
            email: $emailGiven ? $email : $this->email,
            siteId: $this->siteId,
            departmentId: $departmentGiven ? $departmentId : $this->departmentId,
            status: $this->status,
            hiredAt: $this->hiredAt,
            terminatedAt: $this->terminatedAt,
            locale: $locale ?? $this->locale,
        );
    }

    /**
     * RN-14: solo el activo ficha.
     */
    public function canClock(): bool
    {
        return $this->status->canClock();
    }

    /**
     * Nombre de pila e inicial del primer apellido, que es **todo** lo que el
     * quiosco y el padron pueden ver de una persona (§7.3, RF-AT-05).
     *
     * Un token de quiosco robado no debe permitir reconstruir la plantilla del
     * hotel, y esta es la forma en que se garantiza en un solo sitio.
     */
    public function displayName(): string
    {
        $initial = mb_substr(trim($this->lastName), 0, 1);

        return trim($this->firstName).' '.mb_strtoupper($initial).'.';
    }

    /**
     * @throws EmployeeAlreadyTerminated
     */
    private function refuseIfTerminated(): void
    {
        if ($this->status === EmploymentStatus::TERMINATED) {
            throw EmployeeAlreadyTerminated::withUuid($this->uuid);
        }
    }

    private function with(
        EmploymentStatus $status,
        ?DateTimeImmutable $terminatedAt = null,
        bool $terminatedAtGiven = false,
    ): self {
        return new self(
            uuid: $this->uuid,
            code: $this->code,
            firstName: $this->firstName,
            lastName: $this->lastName,
            email: $this->email,
            siteId: $this->siteId,
            departmentId: $this->departmentId,
            status: $status,
            hiredAt: $this->hiredAt,
            terminatedAt: $terminatedAtGiven ? $terminatedAt : $this->terminatedAt,
            locale: $this->locale,
        );
    }

    /**
     * `hired_at` y `terminated_at` son fechas civiles, no instantes: se comparan
     * a medianoche para que una baja el mismo dia del alta no se rechace por
     * unas horas.
     */
    private function dateOnly(DateTimeImmutable $date): DateTimeImmutable
    {
        return $date->setTime(0, 0);
    }
}
