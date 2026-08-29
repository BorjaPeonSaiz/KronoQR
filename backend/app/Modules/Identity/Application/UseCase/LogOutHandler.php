<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\UseCase;

use App\Modules\Identity\Application\Port\AccessTokenIssuer;
use App\Modules\Shared\Application\Port\AuthenticationJournal;
use App\Modules\Shared\Domain\ValueObject\AuthChannel;

/**
 * Cierre de sesion: revoca **el token con el que se llamo** y ninguno mas.
 *
 * Es un caso de uso de cuatro lineas y aun asi existe como clase, en lugar de
 * resolverse en el controlador, por un motivo concreto: es una accion con
 * relevancia legal —la revocacion de una credencial de acceso se audita (RS-05,
 * OWASP A09)— y por tanto necesita un punto donde enganchar el escritor de
 * auditoria sin reescribir el controlador. Ese enganche ya no es una promesa:
 * es la llamada a {@see AuthenticationJournal::loggedOut()} de abajo.
 *
 * **El asiento va despues de revocar**, no antes. Si la revocacion fallara, un
 * apunte previo diria que la sesion esta cerrada cuando el token sigue vivo, y
 * eso es peor que no tener apunte: en una investigacion se leeria como que a
 * partir de ese instante ese token ya no podia hacer nada.
 *
 * **Que canales dejan asiento lo decide {@see AuthChannel}**, no este metodo. Hoy
 * solo el panel; el portal no, porque `audit_log` no tiene un tipo de actor para
 * un empleado y atribuirselo a `system` seria falso (ADR-037). El dia que exista,
 * este fichero no cambia.
 */
final readonly class LogOutHandler
{
    public function __construct(
        private AccessTokenIssuer $tokens,
        private AuthenticationJournal $journal,
    ) {}

    /**
     * @param  string|null  $subjectUuid  UUID publico de quien cierra sesion, o `null` si el
     *                                    token no cuelga de nadie identificable. Nunca su
     *                                    correo ni su nombre (regla dura 21).
     */
    public function handle(int|string $tokenId, AuthChannel $channel, ?string $subjectUuid): void
    {
        $this->tokens->revoke($tokenId);

        $this->journal->loggedOut($channel, $subjectUuid);
    }
}
