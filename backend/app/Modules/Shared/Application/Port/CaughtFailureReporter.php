<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Port;

use Throwable;

/**
 * Lleva al historico de errores (`error_events`, RF-PD-15) un fallo que se
 * captura **a proposito** y no se propaga.
 *
 * ## El hueco que cierra
 *
 * `error_events` se alimenta del `reportable` del manejador de excepciones, asi
 * que solo ve lo que se escapa. Pero hay sitios que capturan cualquier
 * `Throwable` por diseno, porque un fallo no puede llevarse por delante a los
 * demas: el elemento de un lote de escaneos (ADR-047), el informe en diferido,
 * la exportacion integra, una incidencia que no se pudo abrir, una jornada que
 * no se pudo reconciliar o el resumen de un responsable. Esos fallos dejaban un
 * apunte con la clase en el log tecnico y ninguna fila en la tabla que el IT del
 * cliente mira y que viaja en el paquete de diagnostico.
 *
 * ## Por que no `report()` de Laravel
 *
 * Porque `report()` ademas escribe la excepcion entera en Monolog —mensaje y
 * traza—, y estos sitios ya dejan su propio apunte con solo la clase (regla dura
 * 21). Este puerto va **solo** al historico, que sanea el mensaje y calcula la
 * huella en el servidor.
 *
 * ## Por que en `Shared`
 *
 * La tabla es de `Product`, y quien captura es `Attendance`, `Compliance`,
 * `Reporting`… La misma regla de ADR-025 que {@see ErrorEventSink}: el puerto en
 * `Shared` y el adaptador en el modulo que tiene la tabla.
 *
 * ## NUNCA lanza
 *
 * Es parte del contrato: quien llama esta ya en el camino de un fallo, y un
 * error al guardarlo no puede convertirse en un segundo error ni cortar la
 * pasada que el `catch` protege.
 */
interface CaughtFailureReporter
{
    /**
     * Guarda el fallo en el historico, agrupado por huella y sin datos
     * personales. Lo que el adaptador considere un desenlace esperado —un `4xx`
     * dentro de una peticion— no entra.
     */
    public function report(Throwable $failure): void;
}
