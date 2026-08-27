<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Model;

use App\Modules\Identity\Domain\Exception\CredentialAlreadyDelivered;
use App\Modules\Identity\Domain\Exception\CredentialAlreadyPrinted;
use App\Modules\Identity\Domain\Exception\CredentialAlreadyRevoked;
use App\Modules\Identity\Domain\Exception\CredentialNotPrintedYet;
use App\Modules\Identity\Domain\Exception\CredentialRevocationNeedsReason;
use App\Modules\Identity\Domain\ValueObject\CredentialSecret;
use App\Modules\Identity\Domain\ValueObject\QrSigningKey;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * La tarjeta fisica de un empleado (doc 01 §5.2 y §5.5, ADR-014, ADR-034).
 *
 * **Tres actos, no uno** (doc 02 §5.5). Emitir crea el derecho a una tarjeta;
 * imprimir **acuña** su QR; entregar registra que llego a su titular. Son
 * momentos distintos porque en la operacion real lo son: se da de alta a
 * cuarenta personas de temporada una tarde, se imprimen en A4 al dia siguiente
 * y se entregan en mano el dia de la incorporacion.
 *
 * **El token nace al imprimir, no al emitir** (ADR-034).
 * De las cuatro partes del payload `FH1.<key_id>.<token>.<sig>` aqui viven dos,
 * y solo desde la impresion: el `key_id` —que dice **con que clave** se firmo y
 * es lo que permite rotar sin reimprimir de golpe (§5.3)— y el **hash** del
 * token. El token en claro no esta, ni puede estar: existe el rato que se tarda
 * en dibujar el PDF y se olvida. La firma tampoco se guarda, porque se
 * recalcula.
 *
 * Antes de imprimir, `keyId` y `secretHash` son `null` y la credencial **no es
 * escaneable**: no puede resolverla ninguna busqueda por hash, porque no hay
 * hash. Eso es exactamente lo que significa «pendiente de imprimir» en el panel
 * de RF-QR-08 y en la metrica `credentials_pending_print`.
 *
 * **Inmutable, como el resto del dominio de este producto.** Ninguna de las tres
 * transiciones muta: todas devuelven otra credencial. Es lo que hace que no
 * exista ningun camino por el que una revocacion se pierda a medias, y encaja
 * con la regla dura 5 —nada se borra ni se sobrescribe—: la credencial revocada
 * sigue en la tabla, con su momento y su motivo, porque es parte del registro de
 * por que alguien dejo de poder fichar.
 *
 * **Una credencial activa por empleado** (doc 01 §5.2). La invariante la declara
 * ademas el indice parcial `one_active_credential_per_employee`: reemitir obliga
 * a revocar la anterior, no a dejar dos vivas. Aqui no se puede comprobar
 * —haria falta ver las demas filas, que es justo lo que un agregado no hace— y
 * por eso la comprueba el caso de uso y, sobre todo, la base de datos.
 *
 * **`revoked_at` es la unica frontera entre activa y no activa.** No hay columna
 * `status`: un estado derivado y otro almacenado acaban discrepando, y aqui
 * discrepar significa que alguien ficha con una tarjeta revocada. Lo mismo vale
 * para «impresa» y «entregada», que se leen de `printed_at` y `delivered_at`.
 */
final readonly class Credential
{
    /** Tope de `credentials.revoked_reason`. */
    public const int MAX_REASON_LENGTH = 190;

    public function __construct(
        /** Identificador publico (`credentials.uuid`). El BIGINT interno no sale de la base de datos. */
        public string $uuid,
        /** Titular. Es la clave interna porque es la que declara la clave ajena de la tabla. */
        public int $employeeId,
        public DateTimeImmutable $issuedAt,
        /**
         * Con que clave se firmo esta tarjeta (§5.3).
         *
         * `null` hasta que se imprime: antes no hay nada firmado.
         */
        public ?string $keyId = null,
        /** Hash del token. **Nunca el token** (§5.2, paso 4). `null` hasta que se imprime. */
        public ?string $secretHash = null,
        /**
         * Cuando se acuño el token y se dibujo el PDF de la tarjeta.
         *
         * **No es «cuando salio el papel de la impresora».** Es el instante en
         * que este sistema genero el QR: lo que ocurra despues con el papel es
         * fisico y se resuelve revocando y reemitiendo.
         */
        public ?DateTimeImmutable $printedAt = null,
        public ?DateTimeImmutable $deliveredAt = null,
        public ?int $deliveredByUserId = null,
        public ?DateTimeImmutable $revokedAt = null,
        public ?string $revokedReason = null,
        /**
         * Clave interna, cuando la credencial viene de la base de datos.
         *
         * Es `null` en una credencial recien construida y todavia no
         * persistida. Esta aqui —y no solo en el repositorio— porque
         * `audit_log.subject_id` es un entero: sin ella, el asiento de una
         * revocacion no podria apuntar a la fila que revoco. No se usa para
         * ninguna decision de negocio y no sale nunca por la API, que habla
         * siempre en UUID.
         */
        public ?int $id = null,
    ) {
        // Cada invariante en su metodo, y cada metodo con el nombre del CHECK de
        // PostgreSQL que declara lo mismo: son dos guardianes de la misma regla y
        // conviene que se lean como tales.
        $this->guardItBelongsToSomebody();
        $this->guardMintIsAllOrNothing();
        $this->guardRevocationIsComplete();
        $this->guardDeliveryIsComplete();
        $this->guardLifecycleOrder();
    }

    private function guardItBelongsToSomebody(): void
    {
        if ($this->uuid === '') {
            throw new InvalidArgumentException('Una credencial necesita su UUID publico.');
        }

        if ($this->employeeId < 1) {
            throw new InvalidArgumentException('Una credencial necesita el empleado al que pertenece.');
        }
    }

    /**
     * Las tres marcas de la impresion van juntas o no va ninguna, igual que en
     * el CHECK `credentials_chk_minted_at_print`.
     *
     * Es lo que impide construir una credencial impresa sin QR —que nadie podria
     * usar— o un hash sin fecha de impresion, que dejaria la tarjeta escaneable y
     * a la vez «pendiente de imprimir» en el panel de RF-QR-08.
     */
    private function guardMintIsAllOrNothing(): void
    {
        if ($this->keyId === '' || $this->secretHash === '') {
            throw new InvalidArgumentException('Ni el key_id ni el hash del token pueden ser la cadena vacia.');
        }

        $parts = (int) ($this->keyId !== null)
            + (int) ($this->secretHash !== null)
            + (int) ($this->printedAt instanceof DateTimeImmutable);

        if ($parts !== 0 && $parts !== 3) {
            throw new InvalidArgumentException('La impresion escribe a la vez key_id, hash del token y printed_at.');
        }
    }

    /** Igual que el CHECK `credentials_chk_revocation_has_reason`. */
    private function guardRevocationIsComplete(): void
    {
        if (($this->revokedAt instanceof DateTimeImmutable) !== ($this->revokedReason !== null)) {
            throw new InvalidArgumentException('La revocacion lleva siempre momento y motivo, o ninguno de los dos.');
        }
    }

    /** Igual que el CHECK `credentials_chk_delivery_is_signed`. */
    private function guardDeliveryIsComplete(): void
    {
        if (($this->deliveredAt instanceof DateTimeImmutable) !== ($this->deliveredByUserId !== null)) {
            throw new InvalidArgumentException('La entrega lleva siempre momento y responsable, o ninguno de los dos.');
        }

        if ($this->deliveredAt instanceof DateTimeImmutable && ! $this->printedAt instanceof DateTimeImmutable) {
            throw new InvalidArgumentException('No se puede entregar una credencial que no se ha impreso.');
        }
    }

    /** Mismo orden que exige el CHECK `credentials_chk_lifecycle_order`. */
    private function guardLifecycleOrder(): void
    {
        if ($this->printedAt instanceof DateTimeImmutable && $this->printedAt < $this->issuedAt) {
            throw new InvalidArgumentException('Una credencial no puede imprimirse antes de emitirse.');
        }

        // `printedAt` no puede ser nulo aqui: si hay entrega, ya lo ha exigido
        // `guardDeliveryIsComplete()`.
        if ($this->deliveredAt instanceof DateTimeImmutable && $this->deliveredAt < $this->printedAt) {
            throw new InvalidArgumentException('Una credencial no puede entregarse antes de imprimirse.');
        }

        if ($this->revokedAt instanceof DateTimeImmutable && $this->revokedAt < $this->issuedAt) {
            throw new InvalidArgumentException('Una credencial no puede revocarse antes de emitirse.');
        }
    }

    /**
     * Emite una credencial: crea el derecho a una tarjeta y la deja **pendiente
     * de imprimir** (RF-QR-01).
     *
     * **No hay ningun secreto aqui.** El token y la firma los acuña
     * {@see self::printedWith()}, en el mismo acto que dibuja el PDF, para que
     * el unico sitio por el que el token sale de este proceso sea la tarjeta
     * (ADR-034). Emitir es, hasta ese momento, una anotacion administrativa: no
     * hay nada que robar en esta fila.
     *
     * El UUID y el instante llegan de fuera —los produce el caso de uso con el
     * puerto `Clock` (regla dura 2)— para que el dominio siga siendo
     * deterministico y una prueba pueda fijar los dos.
     */
    public static function issue(
        string $uuid,
        int $employeeId,
        DateTimeImmutable $issuedAt,
    ): self {
        return new self(
            uuid: $uuid,
            employeeId: $employeeId,
            issuedAt: $issuedAt,
        );
    }

    /**
     * **Acuña el QR**: firma un token con la clave indicada, se queda con su
     * hash y marca la credencial como impresa (RF-QR-04, ADR-034).
     *
     * Quien llama ya tiene el token en la mano —lo necesita para dibujar el
     * PDF—; lo que entra aqui es el `CredentialSecret` para que el agregado
     * guarde **solo** su hash y no haya ninguna ruta por la que el token en
     * claro llegue a la persistencia.
     *
     * **La clave es la vigente en el momento de imprimir, no la de la emision.**
     * Es lo que hace que una tarjeta emitida antes de una rotacion y llevada a
     * imprenta despues salga firmada con la clave nueva, y no con una que ya se
     * va a retirar (§5.3).
     *
     * **Una sola vez.** Volver a llamar es acuñar otro token distinto y matar la
     * tarjeta anterior, que puede estar ya en un bolsillo: eso solo se hace
     * revocando y reemitiendo, con su motivo en `audit_log`.
     *
     * @throws CredentialAlreadyPrinted
     * @throws CredentialAlreadyRevoked
     */
    public function printedWith(QrSigningKey $key, CredentialSecret $secret, DateTimeImmutable $at): self
    {
        if ($this->printedAt instanceof DateTimeImmutable) {
            throw CredentialAlreadyPrinted::forCredential($this->uuid);
        }

        if ($this->revokedAt instanceof DateTimeImmutable) {
            // Imprimir una tarjeta ya retirada produciria un QR que el quiosco
            // rechaza en el primer escaneo, y una persona delante de la tablet
            // sin entender por que.
            throw CredentialAlreadyRevoked::forCredential($this->uuid);
        }

        return $this->with(
            keyId: $key->id,
            secretHash: $secret->hash(),
            printedAt: $at,
        );
    }

    /**
     * Registra que la tarjeta llego a manos de su titular, con momento y
     * responsable (RF-QR-06).
     *
     * No es burocracia: es lo que distingue «se perdio antes de darsela» de «la
     * perdio el empleado», que son incidencias distintas (doc 02 §5.5).
     *
     * @throws CredentialNotPrintedYet
     * @throws CredentialAlreadyDelivered
     * @throws CredentialAlreadyRevoked
     */
    public function deliveredBy(int $userId, DateTimeImmutable $at): self
    {
        if (! $this->printedAt instanceof DateTimeImmutable) {
            throw CredentialNotPrintedYet::forCredential($this->uuid);
        }

        if ($this->deliveredAt instanceof DateTimeImmutable) {
            throw CredentialAlreadyDelivered::forCredential($this->uuid);
        }

        if ($this->revokedAt instanceof DateTimeImmutable) {
            throw CredentialAlreadyRevoked::forCredential($this->uuid);
        }

        if ($userId < 1) {
            throw new InvalidArgumentException('La entrega necesita el usuario que la registra.');
        }

        return $this->with(deliveredAt: $at, deliveredByUserId: $userId);
    }

    /**
     * Revoca la credencial: perdida, robo, deterioro, reemision o baja
     * (RF-QR-03, RN-14).
     *
     * Se puede revocar en cualquiera de los tres estados, incluida una
     * credencial **pendiente de imprimir**: dar de alta a alguien que al final
     * no se incorpora deja un derecho a tarjeta que hay que retirar, y hacerlo
     * con su motivo es mas honesto que borrar la fila (regla dura 5).
     *
     * @throws CredentialAlreadyRevoked
     * @throws CredentialRevocationNeedsReason
     */
    public function revoke(string $reason, DateTimeImmutable $at): self
    {
        if ($this->revokedAt instanceof DateTimeImmutable) {
            throw CredentialAlreadyRevoked::forCredential($this->uuid);
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw CredentialRevocationNeedsReason::make();
        }

        return $this->with(
            revokedAt: $at,
            revokedReason: mb_substr($reason, 0, self::MAX_REASON_LENGTH),
        );
    }

    /** Sigue en vigor: no se ha revocado. */
    public function isActive(): bool
    {
        return ! $this->revokedAt instanceof DateTimeImmutable;
    }

    /** Su QR ya se acuño. Antes de esto la fila existe, pero la tarjeta no. */
    public function isPrinted(): bool
    {
        return $this->printedAt instanceof DateTimeImmutable;
    }

    public function isDelivered(): bool
    {
        return $this->deliveredAt instanceof DateTimeImmutable;
    }

    /**
     * Si un escaneo de esta tarjeta puede llegar a aceptarse.
     *
     * Es la pregunta que importa de verdad: impresa —hay QR— y no revocada. Una
     * credencial pendiente de imprimir es inalcanzable por definicion, porque no
     * tiene hash por el que buscarla.
     */
    public function isScannable(): bool
    {
        return $this->isPrinted() && $this->isActive();
    }

    /**
     * Si esta credencial se firmo con la clave indicada.
     *
     * Lo pregunta el panel de estado durante una rotacion (RF-QR-08,
     * `credentials:status --key-id=`): la clave anterior no se retira hasta que
     * no queda ninguna credencial activa firmada con ella. Una credencial sin
     * imprimir no esta firmada con ninguna, y por eso nunca cuenta.
     */
    public function signedWithKey(string $keyId): bool
    {
        return $this->keyId !== null && $this->keyId === $keyId;
    }

    /**
     * Copia con los campos indicados sustituidos.
     *
     * Existe para que cada transicion diga **solo lo que cambia** y no repita
     * los once argumentos: la version anterior de esta clase los copiaba a mano
     * en cada metodo, y ese es el sitio exacto donde una transicion nueva se
     * deja un campo por el camino.
     */
    private function with(
        ?string $keyId = null,
        ?string $secretHash = null,
        ?DateTimeImmutable $printedAt = null,
        ?DateTimeImmutable $deliveredAt = null,
        ?int $deliveredByUserId = null,
        ?DateTimeImmutable $revokedAt = null,
        ?string $revokedReason = null,
    ): self {
        return new self(
            uuid: $this->uuid,
            employeeId: $this->employeeId,
            issuedAt: $this->issuedAt,
            keyId: $keyId ?? $this->keyId,
            secretHash: $secretHash ?? $this->secretHash,
            printedAt: $printedAt ?? $this->printedAt,
            deliveredAt: $deliveredAt ?? $this->deliveredAt,
            deliveredByUserId: $deliveredByUserId ?? $this->deliveredByUserId,
            revokedAt: $revokedAt ?? $this->revokedAt,
            revokedReason: $revokedReason ?? $this->revokedReason,
            id: $this->id,
        );
    }
}
