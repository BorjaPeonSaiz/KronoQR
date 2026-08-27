<?php

declare(strict_types=1);

namespace Tests\Support\Workforce;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * El PIN de un empleado y el sobre con el que viaja, para las pruebas del
 * fichaje de respaldo (RF-AT-11, RS-12).
 *
 * **Escribe `pin_hash` por la tabla y no por el caso de uso** a proposito: el
 * generador de la tarea 1.13 produce un PIN aleatorio y no deja elegirlo, y una
 * prueba que quiere afirmar «este PIN acierta y este otro no» necesita saber
 * cual es. `pin_issued_at` se rellena porque hay una restriccion que exige que
 * vayan juntos.
 *
 * **El par de claves se genera por prueba.** Ni uno fijo en el repositorio —seria
 * un secreto real en el control de versiones, RS-08— ni el de la instalacion, que
 * en la suite esta vacio a proposito.
 */
final class EmployeePins
{
    /**
     * Fija el PIN de un empleado, saltandose el generador.
     */
    public static function issue(string $employeeUuid, string $pin): void
    {
        $affected = DB::table('employees')
            ->where('uuid', $employeeUuid)
            ->update([
                'pin_hash' => Hash::make($pin),
                'pin_issued_at' => now(),
            ]);

        if ($affected === 0) {
            throw new RuntimeException('No existe ningun empleado con UUID '.$employeeUuid.'.');
        }
    }

    /**
     * Codigo publico del empleado, que es lo que viaja en la peticion.
     */
    public static function codeOf(string $employeeUuid): string
    {
        $code = DB::table('employees')->where('uuid', $employeeUuid)->value('employee_code');

        return \is_string($code)
            ? $code
            : throw new RuntimeException('No existe ningun empleado con UUID '.$employeeUuid.'.');
    }

    /**
     * Configura la instalacion con un par de claves nuevo y devuelve su clave
     * publica en base64.
     *
     * Es lo que hace el instalador en el servidor del cliente (§7.7), hecho aqui
     * en una linea.
     */
    public static function configureSealing(): string
    {
        $keypair = sodium_crypto_box_keypair();

        config()->set(
            'identity.pin.sealing.secret_key',
            base64_encode(sodium_crypto_box_secretkey($keypair)),
        );

        return base64_encode(sodium_crypto_box_publickey($keypair));
    }

    /**
     * Cierra un PIN como lo hara el quiosco: `crypto_box_seal` y base64.
     *
     * Que la prueba lo selle con la misma primitiva que documenta el contrato
     * —y no llamando al adaptador— es lo que hace que este ejercitando el
     * formato publicado y no una implementacion privada.
     */
    public static function seal(string $pin, string $publicKeyBase64): string
    {
        $publicKey = base64_decode($publicKeyBase64, true);

        if (! \is_string($publicKey)) {
            throw new RuntimeException('La clave publica de sellado no es base64 valido.');
        }

        return base64_encode(sodium_crypto_box_seal($pin, $publicKey));
    }
}
