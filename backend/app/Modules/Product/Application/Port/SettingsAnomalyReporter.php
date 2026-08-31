<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Port;

use App\Modules\Product\Domain\ValueObject\ResolvedSettings;

/**
 * Deja constancia de que la configuracion guardada tiene algo que nadie puede
 * aplicar (RF-PD-01, regla dura 19).
 *
 * ## Por que existe
 *
 * La lectura de la configuracion es **tolerante**: una fila corrupta se descarta
 * y rige el valor de serie, para que un color de marca mal escrito no impida
 * fichar. Descartarla en silencio seria la otra forma de equivocarse — una clave
 * de impacto `worked_hours` con valor corrupto cambia los minutos que se
 * calculan—, asi que el descarte se anuncia. Este puerto es ese anuncio.
 *
 * **No decide como se anuncia.** El adaptador registra un `warning` sin datos
 * personales y lo agrupa por ventana, porque quien lee la configuracion es el
 * camino de fichaje y un aviso por escaneo seria cincuenta por segundo. El
 * paquete de diagnostico (ADR-020) y `doctor` (tarea 5.9) beben de ahi.
 *
 * **No puede romper nada.** Si el adaptador falla, la lectura sigue: perder un
 * aviso es infinitamente mas barato que perder un fichaje.
 */
interface SettingsAnomalyReporter
{
    public function report(ResolvedSettings $settings): void;
}
