<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

/**
 * Si una persona esta dentro ahora mismo (**RF-PA-01**, RF-PA-02).
 *
 * **No es un estado almacenado.** No existe ninguna columna `presence` y no debe
 * existir: se deriva de `shift_entries` —hay un tramo vigente sin
 * `clocked_out_at`, o no lo hay— y esa derivacion se apoya en el indice parcial
 * `one_open_shift_per_employee`. Un estado guardado seria una segunda verdad que
 * habria que mantener sincronizada con la primera, y el dia que se desviara
 * ganaria la equivocada: la que se pinta en la pantalla.
 *
 * **Solo dos valores, y no hay «todos».** La vista de presencia enseña una de
 * las dos listas y los dos recuentos; un tercer valor obligaria a que cada fila
 * declarara a que grupo pertenece en una tabla que no tiene esa columna.
 *
 * **Quien esta de baja no es un ausente.** No aparece en ninguno de los dos
 * conjuntos: la pregunta de esta vista es quien esta trabajando, y alguien que
 * ya no pertenece a la plantilla no esta fuera, es que no esta. Es lo contrario
 * del criterio de `GET /employees`, donde el historico se conserva a la vista
 * (RF-GP-03, RL-02), y la diferencia es deliberada.
 */
enum PresenceStatus: string
{
    /** Tramo vigente abierto: entrada fichada y salida pendiente. */
    case Present = 'present';

    /** De alta y sin ningun tramo abierto. */
    case Absent = 'absent';
}
