<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Port;

/**
 * `manual_corrections_total{reason_code}` (doc 02 §8.2, RF-PA-04).
 *
 * **Por que esta metrica y no otra.** No mide rendimiento: mide **cuanto hay que
 * corregir a mano y por que**. Una instalacion sana tiene pocas correcciones, y
 * un pico de `FALLO_TECNICO_QUIOSCO` en un centro concreto es una tablet que hay
 * que ir a mirar antes de que la gente se acostumbre a no fichar. Un pico de
 * `OLVIDO_FICHAJE_SALIDA` los viernes es un problema de proceso, no de software.
 *
 * **La unica etiqueta es el motivo**, tal y como lo fija el catalogo del §8.2. No
 * lleva `employee_uuid` ni nada que lo identifique (regla dura 21, RGPD): una
 * serie temporal por persona seria un registro paralelo de quien tiene los
 * fichajes mal, sin retencion ni control de acceso. La cardinalidad de esta
 * metrica es nueve, la del Anexo C, y no crece nunca.
 *
 * **Se cuenta la correccion aplicada, no la intentada.** Una peticion rechazada
 * —sin permiso, con un motivo invalido, sobre un tramo ya sustituido— no cambio
 * el registro horario de nadie y no tiene sitio en un contador que responde
 * «cuanto se ha rectificado».
 */
interface CorrectionMetrics
{
    /**
     * Suma uno al contador del motivo indicado.
     *
     * `$reasonCode` es un valor del Anexo C —el respaldo de
     * `CorrectionReasonCode`— y llega como cadena porque un puerto de metricas
     * no tiene por que conocer el enum: lo que sale por `/metrics` es texto.
     *
     * **Medir no puede romper una correccion**, igual que no puede romper un
     * fichaje: quien lo implemente traga sus propios fallos.
     */
    public function correctionRecorded(string $reasonCode): void;
}
