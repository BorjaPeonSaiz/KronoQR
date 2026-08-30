<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Command;

/**
 * Abrir una rotacion de la clave de firma con solape (RF-QR-07, doc 02 §5.3).
 *
 * **No lleva ninguna clave, y esa es la decision.** El material criptografico lo
 * pone el operador en el gestor de secretos del servidor (regla dura 13, §7.7);
 * la aplicacion no lo genera, no lo guarda y no lo transporta. Lo unico que
 * viaja aqui es la orden de reemitir las tarjetas que la clave saliente
 * todavia firma.
 *
 * `dryRun` informa sin escribir: es lo que se ejecuta antes de tocar nada para
 * saber cuantas tarjetas hay que reimprimir.
 */
final readonly class RotateSigningKeyCommand
{
    public function __construct(
        public bool $dryRun = false,
        /** Quien la ejecuta, o `null` desde la consola, que no tiene sesion. */
        public ?int $actorUserId = null,
    ) {}
}
