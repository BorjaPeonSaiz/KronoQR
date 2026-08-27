<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\ValueObject;

use App\Modules\Workforce\Domain\Exception\InvalidEmployeeCode;

/**
 * El codigo de empleado: **opaco y aleatorio** (doc 01 §5.5, RF-ID-06).
 *
 * **Por que opaco.** Este codigo va impreso en la tarjeta y es la mitad publica
 * de la credencial del portal del empleado. Uno secuencial —`E001`, `E002`—
 * revelaria cuanta gente trabaja en el hotel y en que orden entro cada una, y
 * permitiria adivinar codigos ajenos contando. Uno derivado del nombre seria un
 * dato personal impreso en un trozo de plastico que se pierde en un vestuario.
 *
 * **Por que un alfabeto sin caracteres ambiguos.** Alguien teclea este codigo en
 * el portal para consultar su registro (RF-ID-06), a veces desde el movil y con
 * la tarjeta desgastada. Sin `0`/`O` ni `1`/`I`/`L` no hay forma de teclear mal
 * un codigo bien leido, y se ahorran las llamadas al responsable que ese
 * problema genera.
 *
 * **Generacion y lectura tienen reglas distintas, a proposito.** `generate()`
 * produce la forma canonica; `fromString()` acepta cualquier codigo alfanumerico
 * en mayusculas, porque tiene que poder leer los que ya existen en la base de
 * datos de una instalacion —importados de otro sistema o creados por una version
 * anterior— sin declararlos invalidos. Endurecer la lectura equivaldria a dejar
 * a alguien sin poder fichar por un cambio de formato.
 */
final readonly class EmployeeCode
{
    /**
     * Sin `0`, `O`, `1`, `I` ni `L`: los cinco caracteres que se confunden al
     * leer una tarjeta impresa.
     */
    private const string ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /** Longitud de la parte aleatoria. 31^9 combinaciones: ~2,6·10^13. */
    private const int RANDOM_LENGTH = 9;

    private const int MAX_LENGTH = 32;

    private function __construct(public string $value) {}

    /**
     * @throws InvalidEmployeeCode
     */
    public static function fromString(string $value): self
    {
        $normalized = mb_strtoupper(trim($value));

        if ($normalized === '') {
            throw InvalidEmployeeCode::empty();
        }

        if (mb_strlen($normalized) > self::MAX_LENGTH) {
            throw InvalidEmployeeCode::tooLong($normalized, self::MAX_LENGTH);
        }

        if (preg_match('/^[A-Z0-9]+$/', $normalized) !== 1) {
            throw InvalidEmployeeCode::malformed($normalized);
        }

        return new self($normalized);
    }

    /**
     * Un codigo nuevo, aleatorio y sin relacion con ningun dato de la persona.
     *
     * Usa `random_int`, que es el generador criptografico del sistema y no
     * `rand()`: un codigo predecible se puede enumerar, y con el se puede pedir
     * el registro horario de otra persona en el portal antes de que el PIN entre
     * en juego.
     *
     * **No consulta si el codigo ya existe.** La unicidad la garantiza el UNIQUE
     * de `employees.employee_code`; un `SELECT` previo seria una condicion de
     * carrera con aspecto de comprobacion. Quien llama reintenta si la base de
     * datos rechaza el choque.
     */
    public static function generate(): self
    {
        $alphabet = self::ALPHABET;
        $last = mb_strlen($alphabet) - 1;
        $code = 'E';

        for ($i = 0; $i < self::RANDOM_LENGTH; $i++) {
            $code .= $alphabet[random_int(0, $last)];
        }

        return new self($code);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
