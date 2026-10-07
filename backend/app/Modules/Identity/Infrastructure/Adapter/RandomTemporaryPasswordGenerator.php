<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Adapter;

use App\Modules\Identity\Application\Port\TemporaryPasswordGenerator;

/**
 * Contrasenas temporales que cumplen la politica de RF-ID-01 **por
 * construccion** (RF-ID-10). Era un metodo privado de `identity:reset-password`;
 * desde la 2.2.0 lo usan tambien el alta y la API.
 */
final readonly class RandomTemporaryPasswordGenerator implements TemporaryPasswordGenerator
{
    /**
     * Por encima del minimo de la politica a proposito: esta contrasena no la
     * elige una persona, asi que no hay ningun coste en hacerla mas larga.
     */
    public const int MIN_GENERATED_LENGTH = 20;

    /**
     * Las cuatro clases que exige la politica de RF-ID-01, **sin caracteres que
     * se confunden al leerlos de una pantalla y teclearlos a mano**: fuera `l`,
     * `I`, `O`, `0` y `1`. Todo ASCII: la contrasena nunca pasa de los 72 bytes
     * que lee `bcrypt`.
     *
     * @var list<string>
     */
    public const array POOLS = [
        'abcdefghijkmnopqrstuvwxyz',
        'ABCDEFGHJKLMNPQRSTUVWXYZ',
        '23456789',
        '!#$%&*+-=?@',
    ];

    /** Lo que lee `bcrypt`: mas alla, la contrasena se truncaria en silencio. */
    private const int MAX_LENGTH = 72;

    public function generate(int $minLength): string
    {
        $length = min(self::MAX_LENGTH, max(self::MIN_GENERATED_LENGTH, $minLength));

        // Primero un caracter de cada clase y despues el relleno: un muestreo
        // uniforme sobre el alfabeto entero puede no dar ni una mayuscula en
        // veinte tiradas. `random_int` y nunca `rand()`: esto es una credencial.
        $characters = array_map(
            static fn (string $pool): string => $pool[random_int(0, \strlen($pool) - 1)],
            self::POOLS,
        );

        $alphabet = implode('', self::POOLS);

        while (\count($characters) < $length) {
            $characters[] = $alphabet[random_int(0, \strlen($alphabet) - 1)];
        }

        return $this->shuffled($characters);
    }

    /**
     * Fisher-Yates con `random_int` y no `shuffle()`, que no es criptografico.
     * Sin barajar, las cuatro primeras posiciones serian siempre minuscula,
     * mayuscula, cifra y simbolo.
     *
     * @param  list<string>  $characters
     */
    private function shuffled(array $characters): string
    {
        for ($i = \count($characters) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);

            [$characters[$i], $characters[$j]] = [$characters[$j], $characters[$i]];
        }

        return implode('', $characters);
    }
}
