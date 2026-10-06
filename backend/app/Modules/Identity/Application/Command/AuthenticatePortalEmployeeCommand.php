<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Command;

use SensitiveParameter;

/**
 * Lo que hace falta para abrir una sesion de portal: las dos mitades de la
 * credencial del empleado (RF-ID-06, ADR-015) y la direccion desde la que llega.
 *
 * **No lleva `throttleKey`**, al contrario que el acceso al panel. Y no es una
 * omision: alli el contador de fallos es del caso de uso y se lleva por «cuenta +
 * origen»; aqui lo lleva `Shared\Application\Port\PinAttempts` por **empleado y
 * canal** (§7.5), que es lo que permite que restablecer el PIN desbloquee las
 * dos puertas a la vez (RF-ID-09). El limite por IP existe ademas, y es del
 * middleware `throttle:portal`, no de este objeto.
 *
 * **`remoteAddress` es para el bloqueo por origen** (ADR-050 §2): la direccion
 * que nginx entrega a PHP-FPM, nunca `X-Forwarded-For`. El caso de uso la
 * convierte en `RequestOrigin` y no la escribe en ningun rastro en claro.
 *
 * **El PIN va marcado como parametro sensible.** Un volcado de excepcion que lo
 * incluyera acabaria en `error_events` y de ahi en el paquete de diagnostico que
 * viaja al fabricante (ADR-020, regla dura 21).
 */
final readonly class AuthenticatePortalEmployeeCommand
{
    public function __construct(
        public string $employeeCode,
        #[SensitiveParameter] public string $pin,
        public ?string $remoteAddress = null,
    ) {}
}
