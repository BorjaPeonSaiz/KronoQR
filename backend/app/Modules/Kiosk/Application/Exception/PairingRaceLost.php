<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Application\Exception;

use RuntimeException;

/**
 * Otra peticion se adelanto y cambio el estado de la solicitud entre la lectura
 * del agregado y la escritura condicional (**RF-PD-06**).
 *
 * **No sale nunca al cliente.** Existe para una sola cosa: deshacer la
 * transaccion desde dentro del `closure` de `DB::transaction()`. El caso de uso
 * la captura inmediatamente fuera y devuelve el rechazo generico de siempre, que
 * es lo unico que el borde conoce (regla dura 17).
 *
 * Es una excepcion y no un `return` porque la fila de `devices` ya se ha creado
 * cuando esto ocurre: hace falta que se deshaga, y en este framework eso se pide
 * lanzando. Un `rollBack()` a mano dentro del `closure` dejaria a
 * `DB::transaction()` intentando confirmar una transaccion que ya no existe.
 */
final class PairingRaceLost extends RuntimeException {}
