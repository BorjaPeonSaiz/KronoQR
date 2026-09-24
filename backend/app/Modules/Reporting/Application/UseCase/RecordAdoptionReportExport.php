<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\UseCase;

use App\Modules\Reporting\Application\Port\ReportingEventPublisher;
use App\Modules\Reporting\Domain\Event\AdoptionReportExported;
use App\Modules\Reporting\Domain\ValueObject\AdoptionReport;
use App\Modules\Shared\Application\Port\Clock;

/**
 * Deja constancia de que el cuadro de impacto ha salido como fichero
 * (**RF-IN-08**, regla dura 6).
 *
 * ## Por que existe una clase para tres lineas
 *
 * Porque publicar un evento de dominio no es trabajo de un controlador. El borde
 * recibe, autoriza y devuelve; **decidir que un hecho del negocio ha ocurrido** es
 * de la capa de aplicacion, y de ahi que el controlador de la descarga invoque esto
 * en lugar de llamar al publicador. Con la llamada en el controlador, el dia que la
 * descarga se pudiera pedir tambien desde un comando —un envio programado del
 * cuadro, que la decision 10 de la ficha deja fuera de alcance por ahora— habria que
 * acordarse de repetirla.
 *
 * ## No hay transaccion aqui, y es deliberado
 *
 * El unico suscriptor es el asiento de `audit_log`, que toma el candado de la cadena
 * de hash en **su propia** transaccion corta (ADR-010). Envolver esto en otra
 * transaccion solo alargaria el tiempo que ese candado —por el que pasa cada
 * fichaje— se queda retenido, que es exactamente el fallo que la decision 13 de la
 * ficha 3.12 corrigio en el resumen semanal. Aqui no hay nada mas que persistir: el
 * fichero no se guarda en el servidor.
 *
 * ## Se llama ANTES de entregar el fichero
 *
 * Si el asiento falla, la descarga no ocurre (ADR-027, regla dura 6). Es la razon
 * por la que el controlador compone los bytes en memoria primero: para poder decir
 * el tamaño en el asiento y para que la escritura de auditoria pase **antes** de que
 * empiece a salir un solo byte. Con una respuesta transmitida, un fallo del asiento
 * llegaria cuando el fichero ya esta en el navegador de quien lo pidio.
 *
 * ## El actor es quien lo pidio
 *
 * Y sale del token autenticado, no de un parametro: quien descarga no puede declarar
 * quien es.
 */
final readonly class RecordAdoptionReportExport
{
    public function __construct(
        private ReportingEventPublisher $events,
        private Clock $clock,
    ) {}

    /**
     * @param  string  $format  `csv`, `xlsx` o `pdf`.
     * @param  string  $sha256  Huella del **contenido** del cuadro, la misma para los tres
     *                          formatos: es lo que permite confirmar que el papel que alguien
     *                          tiene delante es el que salio de aqui.
     * @param  int  $sizeBytes  Tamaño del fichero entregado.
     * @param  int  $actorUserId  Cuenta de gestion que pidio la descarga.
     */
    public function handle(
        AdoptionReport $report,
        string $format,
        string $sha256,
        int $sizeBytes,
        int $actorUserId,
    ): void {
        $this->events->publish(new AdoptionReportExported(
            from: $report->range->isoFrom(),
            to: $report->range->isoTo(),
            format: $format,
            sha256: $sha256,
            sizeBytes: $sizeBytes,
            exportedByUserId: $actorUserId,
            occurredAt: $this->clock->now(),
        ));
    }
}
