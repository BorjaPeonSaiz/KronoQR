<?php

declare(strict_types=1);

namespace Tests\Support\Identity;

use RuntimeException;
use Tests\Support\Http\Api;
use Tests\Support\Workforce\EmployeePins;

/**
 * Sesiones del portal del empleado para las pruebas de feature (RF-ID-05,
 * RF-ID-07, tarea 1.11).
 *
 * **Entra por el endpoint real y no fabrica el token a mano**, al contrario que
 * {@see ManagementUsers::tokenFor()}. Es deliberado: la mitad de lo que hay que
 * demostrar de este portal es que el token que emite `POST /api/v1/me/login`
 * lleva `self:read` **y nada mas**, y un token construido en la prueba con la
 * lista de ambitos escrita a mano probaria la lista de la prueba, no la del
 * emisor.
 *
 * El PIN se fija saltandose el generador —igual que en el fichaje de respaldo—
 * porque una prueba que quiere afirmar «este PIN acierta y este otro no»
 * necesita saber cual es.
 */
final class PortalLogins
{
    /**
     * PIN de las pruebas. No esta en la lista de prohibidos de
     * `config/identity.php`, a proposito: si lo estuviera, la prueba dependeria
     * de que nadie amplie esa lista.
     */
    public const string PIN = '374195';

    /**
     * Emite el PIN de ese empleado y abre su sesion de portal.
     *
     * @return string El token `Bearer` con ambito `self:read`.
     */
    public static function open(string $employeeUuid, string $pin = self::PIN): string
    {
        EmployeePins::issue($employeeUuid, $pin);

        return self::tokenFor(EmployeePins::codeOf($employeeUuid), $pin);
    }

    /**
     * Abre sesion con unas credenciales ya provisionadas.
     */
    public static function tokenFor(string $employeeCode, string $pin = self::PIN): string
    {
        $response = Api::guest()->post('/api/v1/me/login', [
            'employee_code' => $employeeCode,
            'pin' => $pin,
        ]);

        $token = $response->json('token');

        if (! \is_string($token) || $token === '') {
            throw new RuntimeException(
                'No se pudo abrir la sesion de portal: el endpoint respondio '.$response->getStatusCode().'.',
            );
        }

        return $token;
    }
}
