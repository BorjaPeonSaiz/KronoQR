<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Application\Exception;

use RuntimeException;

/**
 * **Ahora mismo no se puede crear una solicitud de emparejamiento**
 * (RF-PD-06, tarea 5.6).
 *
 * Raiz de las dos causas —la instalacion tiene demasiadas solicitudes vivas, o
 * el sorteo de un codigo libre falla una y otra vez— porque el borde las trata
 * igual: `503` con `urn:kronoqr:problem:service-unavailable`. **Desde fuera son
 * el mismo sintoma** y quien la recibe no tiene por que distinguirlas: en las dos
 * la accion siguiente es la misma, esperar y reintentar.
 *
 * **`503` y no `429`**, aunque las dos las provoque el mismo tipo de abuso. Un
 * `429` dice «vas demasiado rapido **tu**» y lo arregla quien lo recibe bajando
 * el ritmo; esto dice «no puedo atenderte ahora», y la tablet que lo recibe casi
 * nunca es la culpable — el limitador por IP ya se encargo de esa. Ademas el
 * `429` lleva `Retry-After` y aqui no hay ninguna espera que prometer.
 *
 * **Nunca deja a la tablet en un callejon sin salida** (regla dura 19): la PWA
 * reintenta sola y el hueco aparece solo, porque cada peticion purga antes las
 * solicitudes ya caducadas.
 *
 * El motivo real se distingue en el log del servidor, no en la respuesta.
 */
abstract class PairingUnavailable extends RuntimeException {}
