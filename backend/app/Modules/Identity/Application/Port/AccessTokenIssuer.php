<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Port;

use App\Modules\Identity\Domain\ValueObject\AuthenticatedUser;
use App\Modules\Identity\Domain\ValueObject\IssuedAccessToken;

/**
 * Emision y revocacion de los tokens de sesion del panel.
 *
 * Lo implementa un adaptador sobre Laravel Sanctum (doc 02 §3.1). El puerto
 * existe porque el caso de uso no puede conocer el mecanismo: el dia que la
 * sesion de gestion pase a llevar segundo factor (tarea 2.1) lo que cambia es
 * quien emite el token, no que se emita uno al autenticarse.
 */
interface AccessTokenIssuer
{
    /**
     * Emite un token para la cuenta, con **los ambitos de su rol** (§7.3) y con
     * caducidad explicita.
     *
     * `$deviceName` es el nombre con el que se listara y se podra revocar esa
     * sesion concreta. No lleva PII: es «Panel de gestion», no el nombre de una
     * persona.
     */
    public function issueFor(AuthenticatedUser $user, string $deviceName): IssuedAccessToken;

    /**
     * Revoca **un** token por su identificador.
     *
     * Uno y no todos: cerrar sesion en el portatil no puede echar a la misma
     * persona de la tablet donde estaba revisando incidencias.
     */
    public function revoke(int|string $tokenId): void;
}
