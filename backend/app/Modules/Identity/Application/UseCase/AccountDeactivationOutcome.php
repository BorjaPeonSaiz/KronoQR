<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\UseCase;

/**
 * Como termino una baja de cuenta de gestion
 * ({@see DeactivateManagementAccountHandler}).
 *
 * **Tres desenlaces y no un `bool`**, porque el comando tiene que decir tres
 * cosas distintas y la de en medio es la que importa: «esa cuenta ya estaba de
 * baja» no es un error del operador, es la confirmacion de que no hace falta
 * hacer nada — y confundirla con «no existe» le haria buscar una errata en el
 * correo que no hay.
 *
 * Que ya estar de baja **no escriba un asiento** tambien es deliberado: el trail
 * cuenta hechos, y repetir el comando no cambia nada. Sin esto, quien ejecutara
 * el comando en un bucle llenaria la cadena de ADR-010 —por la que pasa cada
 * fichaje— con asientos que no dicen nada nuevo.
 */
enum AccountDeactivationOutcome
{
    /** Se dio de baja: `is_active` a `false`, tokens revocados y asiento escrito. */
    case Deactivated;

    /** No hay ninguna cuenta con ese correo. */
    case NotFound;

    /** La cuenta existe y ya estaba desactivada. No se ha escrito nada. */
    case AlreadyInactive;
}
