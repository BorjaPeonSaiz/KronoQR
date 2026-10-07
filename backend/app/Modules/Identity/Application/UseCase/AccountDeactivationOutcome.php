<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\UseCase;

/**
 * Como termino una baja de cuenta de gestion
 * ({@see DeactivateManagementAccountHandler}).
 *
 * **Cinco desenlaces y no un `bool`.** La consola dice cosas distintas para
 * cada uno —«esa cuenta ya estaba de baja» no es un error del operador, es la
 * confirmacion de que no hacia falta nada—, y la API colapsa los dos «no» en un
 * unico `404` (RS-03) y las dos negativas de la invariante en `409`. Quien
 * colapsa es la capa de entrada, no el caso de uso: el caso de uso dice lo que
 * paso.
 *
 * **Solo `Deactivated` escribe algo**, y es deliberado: el trail cuenta hechos.
 * Repetir la baja, o intentar la que la invariante prohibe, no cambia nada y no
 * deja asiento — si lo dejara, un bucle llenaria la cadena de ADR-010, por la
 * que pasa cada fichaje, con asientos que no dicen nada nuevo.
 */
enum AccountDeactivationOutcome
{
    /** Se dio de baja: `is_active` a `false`, tokens revocados y asiento escrito. */
    case Deactivated;

    /** No hay ninguna cuenta con ese `uuid`. */
    case NotFound;

    /** La cuenta existe y ya estaba desactivada. No se ha escrito nada. */
    case AlreadyInactive;

    /** Es la cuenta de quien la da de baja. No se ha escrito nada. */
    case OwnAccount;

    /** Es la ultima cuenta `admin` activa. No se ha escrito nada. */
    case LastActiveAdmin;
}
