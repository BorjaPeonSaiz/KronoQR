<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\UseCase;

use App\Modules\Reporting\Application\Port\ReportExportProgressBoard;
use App\Modules\Reporting\Application\Port\ReportExportRepository;
use App\Modules\Reporting\Application\Port\ReportingEventPublisher;
use App\Modules\Reporting\Domain\Event\ReportExportCancelled;
use App\Modules\Reporting\Domain\Exception\ReportExportNotCancellable;
use App\Modules\Reporting\Domain\Model\ReportExport;
use App\Modules\Reporting\Domain\ValueObject\ReportExportKind;
use App\Modules\Reporting\Domain\ValueObject\ReportExportStatus;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Support\UseCaseInstrumentation;
use Illuminate\Database\ConnectionInterface;

/**
 * Detiene un informe en diferido que sigue en curso (RF-IN-06).
 *
 * ## La fila cambia ya; el trabajo se entera despues
 *
 * Una transaccion: bloquear la fila **del solicitante**, pasarla a
 * `cancelled`, dejar el asiento. Una `pending` libera en el acto el turno de
 * esa persona; una `running` lo sigue ocupando —el indice unico parcial cuenta
 * las canceladas sin `stopped_at`— hasta que el trabajador de cola lo descubre
 * en su siguiente comprobacion, borra el fichero a medias, confirma que paro y
 * termina sin publicar nada (`GenerateReportExportHandler`). No se borra nada
 * desde aqui: el fichero lo esta escribiendo otro proceso, y quien lo escribe
 * es quien sabe cuando ha dejado de hacerlo.
 *
 * ## Idempotente
 *
 * Una fila ya `cancelled` se devuelve tal cual, sin un segundo asiento: un
 * doble clic o un reintento tras un corte de red no es un error.
 *
 * ## Lo que no se cancela
 *
 * Una `completed`, `failed` o `purged`: {@see ReportExportNotCancellable},
 * con la fila dentro. Si el trabajo cerro la fila un instante antes, gana el
 * cierre: hay un fichero generado y anunciado en `audit_log`, y tirarlo no es
 * cancelar (regla dura 5). Caduca solo.
 *
 * ## `null` para la de otra persona
 *
 * El dueño entra en la consulta del bloqueo, como en la consulta del estado.
 * El borde responde `404`, no `403`: un `403` confirmaria que existe.
 */
final readonly class CancelReportExport
{
    public function __construct(
        private ReportExportRepository $exports,
        private ReportExportProgressBoard $progress,
        private ReportingEventPublisher $events,
        private Clock $clock,
        private ConnectionInterface $connection,
    ) {}

    /**
     * @param  list<ReportExportKind>|null  $kinds  Las clases que alcanza quien pregunta, o todas. Una de
     *                                              otra clase no existe para el, como la de otra persona.
     *
     * @throws ReportExportNotCancellable si ya termino
     */
    public function handle(string $uuid, int $requestedByUserId, ?array $kinds = null): ?ReportExport
    {
        return UseCaseInstrumentation::run(
            'reporting.cancel_report_export',
            fn (): ?ReportExport => $this->doHandle($uuid, $requestedByUserId, $kinds),
        );
    }

    /**
     * @param  list<ReportExportKind>|null  $kinds
     */
    private function doHandle(string $uuid, int $requestedByUserId, ?array $kinds): ?ReportExport
    {
        /** @var ReportExport|null $cancelled */
        $cancelled = $this->connection->transaction(function () use ($uuid, $requestedByUserId, $kinds): ?ReportExport {
            $locked = $this->exports->lockByUuidFor($uuid, $requestedByUserId, $kinds);

            if ($locked === null || $locked->wasCancelled()) {
                return $locked;
            }

            if (! $locked->isInProgress()) {
                throw new ReportExportNotCancellable($locked);
            }

            $now = $this->clock->now();
            $cancelled = $locked->cancel($now);

            $this->exports->save($cancelled);

            if ($locked->status === ReportExportStatus::Pending) {
                // Nadie la estaba escribiendo: el trabajo, al recogerla, la vera
                // cancelada y no arrancara (lo decide bajo este mismo bloqueo).
                // Una `running`, en cambio, sigue ocupando el turno hasta que el
                // trabajador confirme que paro: si no, pedir-cancelar-pedir ponia
                // a trabajar tantas consultas como clics.
                $this->exports->confirmStopped($cancelled->uuid, $now);
            }

            // Releida: lo que se devuelve es lo que quedo guardado —con la
            // precision de la columna—, igual que lo que devolvera la siguiente
            // consulta. Si no, un segundo «cancelar» idempotente responderia otra
            // hora para el mismo hecho.
            $cancelled = $this->exports->findByUuidFor($uuid, $requestedByUserId) ?? $cancelled;

            // Sincrono y dentro de la transaccion: si el asiento no se puede
            // escribir, la fila no se cancela (regla dura 6, ADR-027).
            $this->events->publish(new ReportExportCancelled(
                uuid: $cancelled->uuid,
                kind: $cancelled->kind->value,
                format: $cancelled->format,
                previousStatus: $locked->status->value,
                requestedByUserId: $cancelled->requestedByUserId,
                occurredAt: $now,
            ));

            return $cancelled;
        });

        if ($cancelled instanceof ReportExport) {
            // El avance de una fila cancelada ya no describe nada. Si el
            // trabajador todavia escribe uno antes de enterarse, la lista no lo
            // enseña: solo lee el avance de las filas `running`.
            $this->progress->forget($cancelled->uuid);
        }

        return $cancelled;
    }
}
