<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * Hasta donde mira una pasada de la conciliacion entre el registro horario y su
 * auditoria (ADR-057 §4, RL-04, RS-07).
 *
 * **Dos alcances, y hacen falta los dos.**
 *
 * - `recent`, a diario: los tramos de los ultimos dias y todo lo que la
 *   auditoria haya apuntado en ese plazo. Es lo que hace verdad que una edicion
 *   directa de un fichaje reciente se detecta al dia siguiente.
 * - `full`, semanal: todo el registro y toda la auditoria que siga viva. Es el
 *   unico que ve un borrado o una edicion de un tramo antiguo, cuyos asientos ya
 *   quedaron fuera de la ventana diaria.
 *
 * El valor es la etiqueta `scope` de las metricas y el sufijo de su fichero: una
 * pasada no pisa el resultado de la otra, y la alerta de la semanal no se apaga
 * porque la diaria del dia siguiente no mire tan atras.
 */
enum WorkRecordReconciliationScope: string
{
    case Recent = 'recent';
    case Full = 'full';
}
