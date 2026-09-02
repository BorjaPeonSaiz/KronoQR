<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Port;

/**
 * Cuenta los cambios del perfil de cumplimiento
 * (`compliance_profile_changes_total`, doc 02 §8.2).
 *
 * ## Que responde esta serie
 *
 * «¿Alguien ha movido los umbrales legales, y cuando?». Es lo primero que hay que
 * descartar cuando la bandeja de incidencias cambia de volumen de un dia para
 * otro sin que haya cambiado la operacion: un pico aqui explica que ayer
 * saltaran cuarenta alertas de descanso y hoy ninguna. El asiento de `audit_log`
 * sigue siendo la prueba —con actor, antes y despues—; esto es la señal que hace
 * que alguien vaya a buscarlo.
 *
 * ## La etiqueta es la consecuencia, no el campo
 *
 * Una etiqueta por campo daria ocho series de las que siete valen cero casi
 * siempre. Lo que cambia la conducta de quien mira el cuadro de mando es separar
 * «esto cambia que alertas saltan» de «esto cambia que se puede borrar»: son las
 * dos preguntas que se hacen, y son exactamente los dos booleanos que ya lleva
 * el asiento.
 *
 * ## Contar no puede romper un cambio ya guardado
 *
 * Se observa **despues** de confirmar la transaccion, y el adaptador se traga
 * cualquier fallo del soporte.
 */
interface ComplianceProfileMetrics
{
    /**
     * @param  int  $changes  cuantos campos han cambiado de valor en esta operacion
     * @param  int  $affectingIncidentDetection  cuantos de ellos mueven RN-10, RN-11 o RN-12
     * @param  int  $affectingRetention  cuantos de ellos mueven el plazo de RL-02
     */
    public function profileChanged(int $changes, int $affectingIncidentDetection, int $affectingRetention): void;
}
