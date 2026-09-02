<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Port;

/**
 * Cuenta los cambios de configuracion de la instalacion
 * (`installation_setting_changes_total`, doc 02 §8.2).
 *
 * ## Que responde esta serie
 *
 * «¿Se ha tocado algo que mueva las horas, y cuando?». Es la pregunta que se
 * hace quien investiga una discrepancia de nomina o un salto en un informe, y la
 * responde antes de abrir `audit_log`: un pico en la serie con
 * `affects_worked_hours="true"` dice donde mirar. El asiento sigue siendo la
 * prueba —con actor, antes y despues—; esto es la señal que hace que alguien
 * vaya a buscarlo.
 *
 * ## La etiqueta es el impacto, no la clave
 *
 * Una etiqueta por clave daria nueve series de cardinalidad fija que casi
 * siempre valen cero y que hay que ampliar cada vez que crece el catalogo. Lo
 * que cambia la conducta de quien mira el cuadro de mando es la division entre
 * «esto pudo cambiar los minutos» y «esto cambio un color», que es exactamente
 * el booleano que ya lleva el asiento.
 *
 * ## Contar no puede romper un cambio ya guardado
 *
 * Se observa **despues** de confirmar la transaccion. El adaptador se traga
 * cualquier fallo del soporte: convertir un cambio ya escrito en un `500` por no
 * poder incrementar un contador invitaria a repetirlo.
 */
interface SettingsMetrics
{
    /**
     * @param  int  $changes  cuantas claves han cambiado de valor en esta operacion
     */
    public function settingsChanged(bool $affectsWorkedHours, int $changes): void;
}
