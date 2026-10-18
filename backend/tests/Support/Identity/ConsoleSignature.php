<?php

declare(strict_types=1);

namespace Tests\Support\Identity;

use App\Modules\Identity\Infrastructure\Persistence\User;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * La firma de una orden de consola sin terminal, como la escribe un script
 * desde la 2.3.0 (bloque 3, doc 01 §7.2 «Firmas por consola»): la cuenta por
 * su correo y la confirmacion con el mismo correo.
 *
 * Existe para que las pruebas de las ordenes que firman un asiento se lean
 * como lo que comprueban y no como la mecanica de la firma, y para que el dia
 * que cambie la firma cambie en un solo sitio.
 */
final class ConsoleSignature
{
    /**
     * @return array{'--responsible': string, '--responsible-confirm': string, '--no-interaction': true}
     */
    public static function for(User|int $account): array
    {
        $email = $account instanceof User
            ? $account->email
            : DB::table('users')->where('id', $account)->value('email');

        if (! \is_string($email)) {
            throw new LogicException('No hay ninguna cuenta con ese id.');
        }

        return ['--responsible' => $email, '--responsible-confirm' => $email, '--no-interaction' => true];
    }
}
