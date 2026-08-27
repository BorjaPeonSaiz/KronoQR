<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\UseCase;

use App\Modules\Identity\Application\Port\AccessTokenIssuer;

/**
 * Cierre de sesion: revoca **el token con el que se llamo** y ninguno mas.
 *
 * Es un caso de uso de tres lineas y aun asi existe como clase, en lugar de
 * resolverse en el controlador, por dos motivos concretos: es una accion con
 * relevancia legal —la revocacion de una credencial de acceso se audita
 * (RS-05)— y por tanto necesita un punto donde enganchar el escritor de
 * auditoria de la tarea 1.14 sin reescribir el controlador.
 */
final readonly class LogOutHandler
{
    public function __construct(private AccessTokenIssuer $tokens) {}

    public function handle(int|string $tokenId): void
    {
        $this->tokens->revoke($tokenId);
    }
}
