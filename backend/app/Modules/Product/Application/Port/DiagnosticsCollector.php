<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Port;

use App\Modules\Product\Domain\ValueObject\DiagnosticsOptions;
use App\Modules\Product\Domain\ValueObject\FieldAllowlist;

/**
 * Una seccion del paquete de diagnostico (**RF-PD-09**, doc 02 §11.6.6).
 *
 * ## Cada recolector es responsable de su propia lista de permitidos
 *
 * Y no hay un filtro central que limpie al final. Es deliberado: un filtro
 * posterior tendria que conocer la forma de todas las secciones, y el dia que
 * una cambiara, el filtro seguiria dejando pasar lo que ya no debe. Aqui, quien
 * sabe que campos existen es quien decide cuales viajan, con
 * {@see FieldAllowlist} para que la
 * decision sea una lista y no un `unset()` (regla dura 21, RL-19).
 *
 * ## Un recolector NUNCA lanza y NUNCA falta
 *
 * El contrato declara obligatorias diez secciones. Una que no se pueda construir
 * —porque su tabla no existe todavia, porque el servicio no responde— devuelve
 * `{"status": "unavailable", "reason": "..."}` o `{"status": "not_installed"}`,
 * nunca `[]` y nunca nada. Un paquete que afirmase «cero errores» sobre una
 * tabla que no existe seria falso, y el falso es peor que el hueco.
 */
interface DiagnosticsCollector
{
    /** Nombre de la seccion en el documento: `installation`, `services`... */
    public function section(): string;

    /**
     * @return array<array-key, mixed>
     */
    public function collect(DiagnosticsOptions $options): array;
}
