<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Port;

use DateTimeImmutable;

/**
 * Los fichajes irreconciliables registrados dentro de una ventana (RN-18).
 *
 * **Lee hacia atras la columna que el fichaje ya escribe**, exactamente como
 * {@see FlaggedScans} hace con el desfase de reloj: el camino de fichaje decidio
 * y escribio `result = 'rejected_out_of_order'`, y la revision diaria lo
 * convierte en incidencia sin que haya evento, listener ni proceso nuevos. Un
 * evento propio habria obligado a que el rechazo publicara algo mas dentro de la
 * transaccion del fichaje, que es lo ultimo que conviene alargar.
 *
 * **Es un puerto aparte y no un campo mas de `FlaggedScans`.** Aquel responde
 * «¿que escaneos pidieron validacion humana?» y trae el desfase medido; este
 * responde «¿que escaneos no se pudieron cuadrar?» y trae con que identificarlos.
 * Un solo puerto con campos nulos segun el caso obligaria a cada llamador a saber
 * cuales valen para el suyo.
 */
interface OutOfOrderScans
{
    /**
     * Los escaneos irreconciliables que el servidor **registro** dentro de la
     * ventana —`recorded_at`—, ordenados **del mas antiguo al mas reciente por su
     * `occurred_at`**.
     *
     * Las dos columnas hacen cosas distintas y por eso se nombran las dos: una
     * acota que entra (ver abajo) y la otra ordena lo que entro. El orden no es
     * una preferencia: el `context` de la incidencia lleva el **primero** de la
     * jornada, que es por donde empieza a mirar quien la trabaja, y «el primero»
     * solo significa algo medido en el momento real.
     *
     * **La ventana es de `recorded_at`, y aqui se aparta de {@see FlaggedScans}**
     * a proposito. Aquel mira fichajes que se registraron con su tramo, asi que
     * preguntar por el momento real es preguntar por la jornada. Este mira
     * justo lo contrario: escaneos que llegaron de una cola atascada, cuyo
     * `occurred_at` puede ser de hace semanas. Medir su ventana sobre el momento
     * real dejaria fuera —para siempre— al elemento que mas necesita que alguien
     * lo revise, que es el que mas tardo en llegar.
     *
     * Lo que se promete con esto es lo unico que una pasada diaria puede
     * prometer: **todo lo que el servidor supo dentro de la ventana se mira**. La
     * jornada a la que se atribuye el hallazgo sigue saliendo del `occurred_at`
     * en la zona del centro (RN-05, regla dura 9): el hecho ocurrio cuando
     * ocurrio.
     *
     * @param  DateTimeImmutable  $from  inicio de la ventana, medido sobre `recorded_at`
     * @param  DateTimeImmutable  $to  fin de la ventana, medido sobre `recorded_at`
     * @return list<RejectedOutOfOrderScan>
     */
    public function outOfOrderBetween(DateTimeImmutable $from, DateTimeImmutable $to): array;
}
