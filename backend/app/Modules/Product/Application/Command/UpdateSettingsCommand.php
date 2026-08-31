<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Command;

/**
 * La orden de cambiar una o varias claves de la configuracion de la instalacion
 * (RF-PD-01).
 *
 * **Las claves llegan como cadenas y sin validar.** Es intencionado: convertir
 * una cadena de fuera en `SettingKey` es lo unico que puede fallar con «esa
 * clave no existe», y ese fallo tiene que ocurrir en un sitio donde se sepa
 * **cual** es la clave mala para que el `422` la señale. Ese sitio es el caso de
 * uso, no este objeto.
 *
 * **El autor no viaja en el cuerpo de la peticion, sale de la sesion.**
 * Aceptarlo del cliente permitiria firmar un cambio de umbral a nombre de otra
 * persona, que es lo que un registro con valor probatorio no puede admitir.
 */
final readonly class UpdateSettingsCommand
{
    /**
     * @param  array<string, mixed>  $values  clave del catalogo => valor sin validar
     * @param  int  $actorUserId  `users.id` de quien firma el cambio
     */
    public function __construct(
        public array $values,
        public int $actorUserId,
    ) {}
}
