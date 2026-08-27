<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Port;

/**
 * `legal_exports_total{scope}` (doc 02 §8.2).
 *
 * **Que responde esta metrica.** «¿Cuantas veces se ha descargado el registro
 * horario de esta instalacion y con que alcance?» Una exportacion legal es un
 * hecho raro —unas pocas al año— y por eso un cambio de ritmo es informacion:
 * una instalacion que empieza a exportar la plantilla completa todas las semanas
 * esta haciendo otra cosa distinta de contestar a un requerimiento.
 *
 * **La etiqueta es `all` o `employee`, jamas el identificador de nadie.** Un
 * UUID en una etiqueta de Prometheus crea una serie temporal por persona
 * exportada: una fuga hacia el sistema de metricas y una explosion de
 * cardinalidad a la vez (regla dura 21).
 *
 * **Medir no puede romper una exportacion.** Se llega aqui con el fichero ya
 * escrito y el asiento de auditoria cerrado; un fallo del contador no puede
 * convertir eso en un error, porque quien exporto volveria a intentarlo y
 * duplicaria el asiento.
 */
interface LegalExportMetrics
{
    public function exportGenerated(string $scope): void;
}
