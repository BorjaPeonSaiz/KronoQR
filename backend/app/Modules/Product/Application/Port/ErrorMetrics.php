<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Port;

use App\Modules\Shared\Domain\ValueObject\ErrorLevel;
use App\Modules\Shared\Domain\ValueObject\ErrorSource;

/**
 * `application_errors_total{source,level}` (doc 02 §8.2, decision 11 de la ficha
 * 5.12).
 *
 * ## Por que hay una metrica ademas de la tabla
 *
 * Porque responden a preguntas distintas y en momentos distintos. La tabla
 * responde «¿que esta fallando y desde cuando?» cuando alguien abre el panel; la
 * metrica responde «esta pasando **ahora**» sin que nadie mire, que es lo que
 * necesita la alerta del doc 01 §9.3: *«errores nuevos de severidad critica,
 * cualquiera en 5 min, Alta, IT del cliente»*. Un contador no puede decir cual
 * fue el error, y una tabla no puede despertar a nadie.
 *
 * **El destinatario es el IT del cliente y nunca el fabricante** (ADR-020, doc
 * 02 §9.3): el fabricante no tiene acceso a este servidor y no puede intervenir.
 *
 * ## Dos etiquetas y ninguna mas
 *
 * `source` y `level`, catorce combinaciones posibles en total. Ni `module`, ni
 * `code`, ni desde luego `employee_uuid`: una serie temporal por persona seria un
 * registro de presencia paralelo sin retencion ni control de acceso (regla dura
 * 21). Lo que hace falta para alertar es «hay criticos nuevos», y el detalle esta
 * a un clic en la tabla.
 *
 * ## No puede romper nada
 *
 * El adaptador traga cualquier fallo de Redis: se llama desde el camino de un
 * error que ya ocurrio, y perder un contador es infinitamente mas barato que
 * convertir un error en dos (regla dura 19). El endpoint `/metrics` que publica
 * la serie es de la tarea 3.1; hasta entonces se acumula y la leen el paquete de
 * diagnostico y las pruebas.
 */
interface ErrorMetrics
{
    /**
     * Una **ocurrencia** mas: `application_errors_total{source,level}`.
     *
     * Sube cada vez que algo se guarda, sea un grupo nuevo o la aparicion mil de
     * uno que ya estaba. Responde a «¿cuanto esta pasando?».
     */
    public function errorRecorded(ErrorSource $source, ErrorLevel $level): void;

    /**
     * Un **grupo** nuevo o reabierto:
     * `application_error_groups_opened_total{source,level}` (decision 14).
     *
     * ## Es la serie de la alerta, y la de arriba no
     *
     * Una camara de quiosco rota emite el mismo error cada pocos segundos
     * durante dias. Con la serie de ocurrencias,
     * `increase(...[5m]) > 0` mantendria `ErroresCriticosNuevos` encendida
     * indefinidamente por un problema que el IT del cliente ya conoce y ya ha
     * dado por atendido — y una alerta que nunca se apaga deja de leerse, que es
     * la peor forma de no tener alerta.
     *
     * Con esta, salta cuando aparece algo **nuevo**, o cuando algo dado por
     * arreglado **vuelve**. Las dos son exactamente los momentos en los que hay
     * que mirar.
     */
    public function groupOpened(ErrorSource $source, ErrorLevel $level): void;
}
