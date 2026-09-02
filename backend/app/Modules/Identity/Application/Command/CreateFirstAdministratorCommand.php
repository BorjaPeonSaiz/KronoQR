<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Command;

use SensitiveParameter;

/**
 * Alta del primer administrador de la instalacion (RF-PD-03, paso 1 del
 * asistente).
 *
 * **Sin `role`.** No hay eleccion que hacer: el primero es siempre `admin`, que
 * es el unico rol con `settings:*` y `license:*` y por tanto el unico capaz de
 * terminar la puesta en marcha. Aceptarlo del cliente abriria la puerta a crear
 * un `auditor` como primera cuenta y dejar la instalacion sin nadie que pueda
 * configurarla — un callejon sin salida que solo se sale con consola, que es
 * justo lo que RF-PD-03 prohibe.
 *
 * La contrasena va marcada como sensible: asi no aparece en una traza de PHP si
 * algo revienta entre el borde y el hash.
 */
final readonly class CreateFirstAdministratorCommand
{
    public function __construct(
        public string $name,
        public string $email,
        #[SensitiveParameter] public string $password,
        public string $locale,
        public string $deviceName,
    ) {}
}
