<?php

declare(strict_types=1);

namespace Tests\Support\Security;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\Hash;

/**
 * Espia del hasher para las pruebas de tiempo constante del PIN (RS-03, regla
 * dura 17).
 *
 * Cuenta el TRABAJO y no el reloj: la suite hashea con `BCRYPT_ROUNDS=4`, asi
 * que un camino que se saltara la comparacion tardaria casi lo mismo que uno
 * que la paga, y una medida de tiempo no lo distinguiria. Lo que si lo
 * distingue es cuantas comparaciones se hacen y contra que hash.
 *
 * Envuelve el hasher real —la comparacion se sigue haciendo y su resultado es
 * el de verdad— y lo restaura al terminar, tambien si la llamada lanza.
 */
final class HashCheckSpy
{
    /**
     * Los hashes contra los que se compara durante `$work`, en orden.
     *
     * @param  callable(): mixed  $work
     * @return list<string>
     */
    public static function during(callable $work): array
    {
        /** @var Hasher $real */
        $real = app('hash');
        $compared = [];

        Hash::partialMock()
            ->shouldReceive('check')
            ->andReturnUsing(static function (string $value, string $hashedValue, array $options = []) use ($real, &$compared): bool {
                $compared[] = $hashedValue;

                return $real->check($value, $hashedValue, $options);
            });

        try {
            $work();
        } finally {
            Hash::swap($real);
        }

        return $compared;
    }
}
