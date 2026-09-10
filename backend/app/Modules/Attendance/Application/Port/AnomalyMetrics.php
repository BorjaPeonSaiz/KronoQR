<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Port;

/**
 * `anomalous_patterns_detected_total{pattern}` (doc 02 §8.2, RF-PR-01).
 *
 * **Que pregunta contesta.** No «cuantas incidencias hay abiertas» —eso es
 * `incidents_open`, un *gauge* que baja cuando alguien las resuelve— sino
 * **cuantas ha encontrado la revision automatica, y de que tipo**. Las dos
 * hacen falta y no se sustituyen: un contador que sube todas las noches con
 * `TURNO_ABIERTO` en el mismo centro es un problema de proceso aunque la
 * bandeja este vacia porque alguien las cierra cada mañana.
 *
 * **La unica etiqueta es el tipo de hallazgo**, que es un enum del dominio con
 * media docena de valores: cardinalidad fija que no crece con la plantilla ni
 * con los años. No lleva `employee_uuid`, ni centro, ni departamento (regla
 * dura 21, RGPD): una serie temporal por persona seria un registro de quien
 * ficha mal, con retencion indefinida y sin control de acceso — exactamente el
 * fichero que este producto no puede tener.
 *
 * **Se cuenta lo DETECTADO, no lo publicado.** La pasada aisla el fallo de cada
 * hallazgo para que uno que no se pueda abrir no aborte los demas; el contador
 * refleja lo que la revision encontro, que es lo que describe el estado del
 * registro horario. Cuantos se pudieron escribir es otra pregunta, y la
 * contesta el codigo de salida del comando.
 */
interface AnomalyMetrics
{
    /**
     * Suma los hallazgos de una pasada, ya agrupados por tipo.
     *
     * **De golpe y no uno a uno**, al contrario que el resto de puertos de
     * metricas del modulo: aqui no hay un acontecimiento por hallazgo que
     * medir, hay una revision nocturna que termina con un recuento. Pasarlo
     * agregado evita cuarenta viajes a Redis en el mismo segundo y deja la
     * decision de como escribirlo donde tiene que estar, en el adaptador.
     *
     * **Medir no puede romper la revision.** Se llega aqui con las incidencias
     * ya abiertas; quien lo implemente traga sus propios fallos.
     *
     * @param  array<string, int>  $byPattern  Tipo de anomalia -> cuantas. Un mapa vacio
     *                                         —la noche en la que no se encontro nada, que
     *                                         es lo normal— no escribe nada.
     */
    public function anomaliesDetected(array $byPattern): void;
}
