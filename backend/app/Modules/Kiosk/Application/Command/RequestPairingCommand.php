<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Application\Command;

/**
 * La orden de crear una solicitud de emparejamiento (**RF-PD-06**, paso 1).
 *
 * **Un solo campo, y es deliberado.** Lo unico que una tablet sin vincular puede
 * declarar es con que version de la PWA corre. Ni centro —la instalacion tiene
 * uno solo y lo resuelve el servidor (ADR-040)—, ni nombre —lo pone quien
 * confirma—, ni identificador de aparato. Es una peticion **publica**: todo lo
 * que aceptara seria algo que un extraño puede escribir.
 */
final readonly class RequestPairingCommand
{
    public function __construct(public ?string $appVersion = null) {}
}
