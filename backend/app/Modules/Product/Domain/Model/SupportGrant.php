<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Model;

use App\Modules\Product\Domain\Exception\InvalidSupportGrant;
use App\Modules\Product\Domain\Exception\SupportGrantAlreadyClosed;
use App\Modules\Product\Domain\ValueObject\SupportGrantAuthor;
use App\Modules\Product\Domain\ValueObject\SupportGrantStatus;
use App\Modules\Product\Domain\ValueObject\SupportScope;
use DateTimeImmutable;

/**
 * Una concesion de acceso de soporte: expresa, temporal, limitada, revocable y
 * auditada (**RF-PD-11**, RL-18, ADR-020, regla dura 16).
 *
 * ## Es un objeto con ciclo de vida, no una casilla
 *
 * Lo dice ADR-020 por escrito: *«sin caducidad automatica, una concesion
 * olvidada es una cuenta permanente con otro nombre»*. Por eso caduca sola
 * —{@see self::statusAt()} lo decide con el instante que recibe— y por eso
 * revocar **marca** en lugar de borrar (regla dura 5): la pregunta «¿entro el
 * fabricante en mi instalacion, cuando y por que?» hay que poder contestarla
 * años despues.
 *
 * ## Sin reloj propio (regla dura 2)
 *
 * Ningun metodo de esta clase lee la hora. La recibe, siempre, de quien la tiene
 * resuelta por el puerto `Clock`. Sin esto no se puede probar el limite exacto
 * —«en `expires_at` justo, ¿vale o no vale?»— sin mover el reloj de la maquina,
 * que es la prueba que RF-PD-11 exige.
 *
 * ## El limite es `>`, no `>=`
 *
 * En el instante exacto de `expires_at` la concesion **ya no vale**. Es la misma
 * lectura que hace Sanctum con la caducidad del token que se emite —si las dos
 * no coincidieran, habria un segundo en el que el panel diria «activa» y el
 * token responderia `401`, y esa discrepancia es la que hace que alguien crea
 * que el producto esta roto—.
 */
final class SupportGrant
{
    /** Lo que exige el contrato: `reason` de 3 a 200 caracteres. */
    public const int REASON_MIN_LENGTH = 3;

    public const int REASON_MAX_LENGTH = 200;

    private function __construct(
        /**
         * Clave interna, o `null` mientras la concesion no se haya persistido.
         *
         * Es lo que va a `audit_log.actor_id` cuando la concesion actua, asi que
         * el caso de uso reconstruye la entidad con su `id` despues de guardar y
         * antes de publicar los eventos.
         */
        public readonly ?int $id,
        /** Identificador publico: el que viaja en la URL y en la respuesta. */
        public readonly string $uuid,
        public readonly SupportGrantAuthor $grantedBy,
        public readonly string $reason,
        public readonly SupportScope $scope,
        public readonly DateTimeImmutable $grantedAt,
        public readonly DateTimeImmutable $expiresAt,
        private ?DateTimeImmutable $revokedAt,
        private ?int $revokedByUserId,
        private ?DateTimeImmutable $accessedAt,
    ) {}

    /**
     * Concede el acceso.
     *
     * **`reason` y `hours` se validan aqui y tambien en el `FormRequest`**, y no
     * es duplicidad ociosa: por la API llega un `422` legible y por la consola no
     * hay `FormRequest` que valga. Lo que no puede existir es una concesion sin
     * motivo o de duracion arbitraria por ninguno de los dos caminos.
     *
     * @param  int  $hours  Duracion. El maximo lo resuelve quien llama, desde la
     *                      configuracion (regla dura 14): el dominio no consulta
     *                      `config()`.
     * @param  int  $maximumHours  El tope ya resuelto.
     *
     * @throws InvalidSupportGrant
     */
    public static function grant(
        string $uuid,
        SupportGrantAuthor $grantedBy,
        string $reason,
        SupportScope $scope,
        int $hours,
        int $maximumHours,
        DateTimeImmutable $grantedAt,
    ): self {
        $trimmed = trim($reason);
        $length = mb_strlen($trimmed);

        if ($length < self::REASON_MIN_LENGTH || $length > self::REASON_MAX_LENGTH) {
            // El motivo no es burocracia: es lo unico que permite ver el abuso
            // que ADR-020 describe —usar el acceso para un incidente distinto
            // del que lo motivo—.
            throw InvalidSupportGrant::reason($length);
        }

        if ($hours < 1 || $hours > $maximumHours) {
            throw InvalidSupportGrant::duration($hours, $maximumHours);
        }

        return new self(
            id: null,
            uuid: $uuid,
            grantedBy: $grantedBy,
            reason: $trimmed,
            scope: $scope,
            grantedAt: $grantedAt,
            expiresAt: $grantedAt->modify('+'.$hours.' hours'),
            revokedAt: null,
            revokedByUserId: null,
            accessedAt: null,
        );
    }

    /**
     * Reconstruye la concesion desde la fila persistida.
     *
     * No valida lo que ya valida el esquema: su trabajo es reproducir el estado
     * exacto que se guardo, incluida una fila que un `psql` dejo rara. Lo que se
     * decide aqui —si vale o no vale— lo decide {@see self::statusAt()} con el
     * reloj, no la forma de la fila.
     */
    public static function fromStorage(
        int $id,
        string $uuid,
        SupportGrantAuthor $grantedBy,
        string $reason,
        SupportScope $scope,
        DateTimeImmutable $grantedAt,
        DateTimeImmutable $expiresAt,
        ?DateTimeImmutable $revokedAt,
        ?int $revokedByUserId,
        ?DateTimeImmutable $accessedAt,
    ): self {
        return new self(
            id: $id,
            uuid: $uuid,
            grantedBy: $grantedBy,
            reason: $reason,
            scope: $scope,
            grantedAt: $grantedAt,
            expiresAt: $expiresAt,
            revokedAt: $revokedAt,
            revokedByUserId: $revokedByUserId,
            accessedAt: $accessedAt,
        );
    }

    /** La misma concesion, ya persistida y con su clave interna. */
    public function withId(int $id): self
    {
        return new self(
            id: $id,
            uuid: $this->uuid,
            grantedBy: $this->grantedBy,
            reason: $this->reason,
            scope: $this->scope,
            grantedAt: $this->grantedAt,
            expiresAt: $this->expiresAt,
            revokedAt: $this->revokedAt,
            revokedByUserId: $this->revokedByUserId,
            accessedAt: $this->accessedAt,
        );
    }

    /**
     * Retira el acceso **en el acto**.
     *
     * No borra nada (regla dura 5): marca el instante y quien lo hizo. Quien
     * llama se encarga ademas de borrar los tokens de la fila, que es lo que
     * hace que el efecto sea inmediato y no dependa de la caducidad.
     *
     * @param  int|null  $byUserId  Nulo desde la consola: ahi no hay sesion que
     *                              atribuir, y decir «lo hizo el sistema» seria
     *                              mas honesto que inventar una cuenta.
     *
     * @throws SupportGrantAlreadyClosed si ya estaba revocada
     */
    public function revoke(DateTimeImmutable $at, ?int $byUserId): void
    {
        if ($this->revokedAt !== null) {
            // Quien llama traduce esto a un `204` idempotente: la segunda
            // pulsacion de un boton no es un hecho nuevo y no vuelve a auditar.
            throw new SupportGrantAlreadyClosed($this->uuid);
        }

        $this->revokedAt = $at;
        $this->revokedByUserId = $byUserId;
    }

    /** Anota un uso efectivo del token. */
    public function markAccessed(DateTimeImmutable $at): void
    {
        $this->accessedAt = $at;
    }

    /**
     * Si el token de esta concesion sigue autenticando en ese instante.
     *
     * Las dos condiciones son las mismas que comprueba
     * `IdentityServiceProvider` en cada peticion, y estan escritas dos veces a
     * proposito: alli protegen la sesion y aqui explican el estado. Una prueba
     * de integracion comprueba que dicen lo mismo.
     */
    public function isActiveAt(DateTimeImmutable $now): bool
    {
        return $this->statusAt($now) === SupportGrantStatus::Active;
    }

    /** `Revoked` gana a `Expired`. Ver {@see SupportGrantStatus}. */
    public function statusAt(DateTimeImmutable $now): SupportGrantStatus
    {
        if ($this->revokedAt !== null) {
            return SupportGrantStatus::Revoked;
        }

        // `>` y no `>=`: en el instante exacto de `expires_at` ya no vale.
        return $this->expiresAt > $now
            ? SupportGrantStatus::Active
            : SupportGrantStatus::Expired;
    }

    public function revokedAt(): ?DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function revokedByUserId(): ?int
    {
        return $this->revokedByUserId;
    }

    public function accessedAt(): ?DateTimeImmutable
    {
        return $this->accessedAt;
    }
}
