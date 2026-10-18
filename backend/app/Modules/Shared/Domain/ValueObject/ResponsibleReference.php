<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

use App\Modules\Shared\Domain\Exception\InvalidResponsibleReference;

/**
 * Como se nombra, en una orden de consola, la cuenta de gestion que firma un
 * asiento con valor legal: su `uuid` o su correo (V21-7-SC-1, V21-6-PL-03,
 * 2.3.0 bloque 3; doc 01 §7.2, «Firmas por consola»).
 *
 * **Nunca el id interno.** Hasta la 2.2.1 `--responsible` era `users.id`: un
 * numero que nadie ve en el panel, que se teclea de memoria y que, mal
 * tecleado, firma con otra cuenta sin que nada lo delate. El `uuid` es el
 * identificador publico de la cuenta (la API y la auditoria lo usan) y el
 * correo es lo que la persona sabe de si misma; los dos son unicos.
 *
 * **Un valor solo de cifras se rechaza aunque fuera un id valido**, con su
 * propia excepcion: es exactamente el ejemplo de los runbooks antiguos y quien
 * lo escribe tiene que saber por que ya no vale.
 *
 * Normaliza lo que la base tambien normaliza: el `uuid` en minusculas y el
 * correo en minusculas (`users.email` es `citext`). No comprueba que la cuenta
 * exista: eso lo dice el puerto `ResponsibleAccounts`.
 */
final readonly class ResponsibleReference
{
    /** Tope de `users.email`. */
    private const int EMAIL_MAX_LENGTH = 190;

    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /**
     * Una arroba, algo a cada lado, un punto en el dominio y nada de espacios
     * ni de caracteres de control. No pretende validar RFC 5322: el correo
     * tiene que coincidir con el de una cuenta, y esa comparacion es la que
     * manda.
     */
    private const string EMAIL = '/^[^@\s\x00-\x1F\x7F]+@[^@\s\x00-\x1F\x7F]+\.[^@\s\x00-\x1F\x7F]+$/u';

    private function __construct(
        public ?string $uuid,
        public ?string $email,
    ) {}

    /**
     * @throws InvalidResponsibleReference
     */
    public static function parse(string $value): self
    {
        $value = trim($value);

        if ($value === '') {
            throw InvalidResponsibleReference::empty();
        }

        if (ctype_digit($value)) {
            throw InvalidResponsibleReference::numericId();
        }

        $lower = mb_strtolower($value);

        if (preg_match(self::UUID, $lower) === 1) {
            return new self($lower, null);
        }

        if (mb_strlen($lower) <= self::EMAIL_MAX_LENGTH && preg_match(self::EMAIL, $lower) === 1) {
            return new self(null, $lower);
        }

        throw InvalidResponsibleReference::malformed();
    }

    public function isUuid(): bool
    {
        return $this->uuid !== null;
    }

    /** El `uuid` o el correo, ya normalizado. */
    public function value(): string
    {
        return $this->uuid ?? (string) $this->email;
    }
}
