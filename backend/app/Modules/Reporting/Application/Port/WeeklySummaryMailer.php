<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Port;

use App\Modules\Reporting\Domain\ValueObject\WeeklySummary;

/**
 * Entrega el resumen semanal a la direccion del responsable (**RF-PR-05**).
 *
 * ## Devuelve si se entrego, y ese booleano decide una transaccion
 *
 * El envio es **sincrono** y el adaptador atrapa el fallo, igual que en el aviso
 * de incidencias y por la misma razon: con la notificacion encolada, `notify()`
 * solo mete un trabajo en Redis, el llamante ve un exito siempre y la fila de
 * `weekly_summary_deliveries` quedaria escrita sobre un correo que nadie
 * recibio. Aqui ese `false` deshace la transaccion y la semana vuelve a estar
 * pendiente.
 *
 * ## El correo SI lleva nombres; el log del correo, no
 *
 * Va dirigido al responsable del departamento de esas personas, que ya ve sus
 * jornadas en el panel: es una comunicacion interna legitima por su finalidad
 * (RL-15) y un resumen que dijera «el empleado 018f…c3 trabajo 38 h» obligaria a
 * buscar un UUID a mano para saber de quien se habla. La regla dura 21 gobierna
 * los **logs tecnicos** y `error_events`, que viajan al fabricante, no un correo
 * a quien ya esta autorizado.
 */
interface WeeklySummaryMailer
{
    /** `false` si no se pudo entregar. Nunca lanza. */
    public function send(WeeklySummaryRecipient $recipient, WeeklySummary $summary): bool;
}
