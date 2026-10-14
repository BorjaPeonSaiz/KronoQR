<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Listener;

use App\Modules\Compliance\Application\Command\RecordAuditEntryCommand;
use App\Modules\Compliance\Application\UseCase\RecordAuditEntry;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditPayload;
use App\Modules\Compliance\Domain\ValueObject\AuditSubject;
use App\Modules\Compliance\Infrastructure\Audit\CurrentAuditContext;
use App\Modules\Compliance\Infrastructure\Persistence\AuditLogSchema;
use App\Modules\Product\Domain\Event\DiagnosticsBundleGenerated;
use App\Modules\Shared\Application\Port\SerializedLedgerWrite;
use App\Modules\Shared\Domain\ValueObject\UtcInstant;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Sella en `audit_log` la generacion de un paquete de diagnostico
 * (**RF-PD-09**, RL-19, regla dura 6, ADR-020).
 *
 * ## Que pregunta responde este asiento
 *
 * «¿Que ha salido de esta instalacion hacia el fabricante, y cuando?». El
 * paquete es el **unico** canal por el que informacion del cliente llega al
 * fabricante, y ADR-020 apoya toda su legitimidad en que el cliente lo controla
 * y lo ve. Sin este asiento, «lo controla» seria una intencion.
 *
 * ## `subject_id` nulo y el paquete identificado por su huella
 *
 * Porque el sujeto no es una fila: el paquete no se guarda en base de datos, se
 * entrega y se va. Lo que lo identifica es su `sha256`, que ademas es lo que
 * permite confirmar mas adelante que el fichero que soporte tiene delante es el
 * mismo que se genero aqui. Mismo criterio que la licencia, cuyo sujeto es una
 * tabla de una sola fila sin identificador con significado.
 *
 * ## Lo que NO va en el payload
 *
 * **El contenido.** El asiento acaba en el trail, el trail se exporta y nada de
 * lo que hay dentro del paquete tiene por que difundirse otra vez ahi. Van los
 * nombres de las secciones, no las secciones.
 *
 * ## Sincrono, y antes de entregar el paquete
 *
 * Sin `ShouldQueue` y sin `afterCommit`: el caso de uso publica el evento antes
 * de devolver el paquete, y si el asiento falla el paquete no se entrega. Un
 * paquete que sale sin dejar rastro rompe la unica promesa que hace ADR-020.
 *
 * **La unica excepcion es un aplazamiento, no una perdida** (enmienda de
 * ADR-020 del 14-10-2026): con la base caida, el paquete anonimizado de consola
 * se entrega con su asiento pendiente, y `Product` vuelve a publicar este evento
 * con `deferred = true` cuando la base vuelve. Entonces:
 *
 * - `payload.deferred = true` y `payload.generated_at` lleva el instante de la
 *   generacion; `occurred_at` es el de la escritura, porque la cadena (ADR-010)
 *   y el sellado anual (ADR-027) suponen `occurred_at` creciente.
 * - **Es idempotente por huella**: dentro del candado de la cadena (ADR-010) se
 *   comprueba que no exista ya un `diagnostics.bundle_generated` con ese
 *   `sha256`. Dos pasadas simultaneas o repetidas dejan una fila.
 *
 * **Por un listener y no por una llamada desde `Product`**: el §1.6 no concede
 * la arista `Product -> Compliance`. Misma via que la licencia y la
 * configuracion.
 */
final readonly class RecordDiagnosticsBundleGenerated
{
    public function __construct(
        private RecordAuditEntry $audit,
        private CurrentAuditContext $context,
        private SerializedLedgerWrite $ledger,
        private ConnectionInterface $database,
    ) {}

    public function handle(DiagnosticsBundleGenerated $event): void
    {
        if (! $event->deferred || ! $event->generatedAt instanceof DateTimeImmutable) {
            $this->record($event, []);

            return;
        }

        $generatedAt = $event->generatedAt;

        $this->ledger->withChainLock(function () use ($event, $generatedAt): void {
            if ($this->alreadyRecorded($event->sha256, $generatedAt)) {
                return;
            }

            $this->record($event, [
                'deferred' => true,
                'generated_at' => UtcInstant::of($generatedAt),
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $deferral
     */
    private function record(DiagnosticsBundleGenerated $event, array $deferral): void
    {
        $this->audit->handle(new RecordAuditEntryCommand(
            // Quien lo hizo lo resuelve la sesion en curso. Por consola no hay
            // sesion, y eso tambien es informacion: distingue «lo genero Marta
            // desde el panel» de «lo genero alguien por SSH».
            actor: $this->context->actor(),
            action: AuditAction::DiagnosticsBundleGenerated,
            subject: AuditSubject::of('diagnostics'),
            payload: AuditPayload::of([
                'anonymized' => $event->anonymized,
                'sections' => $event->sections,
                // La huella es lo que identifica al paquete: con ella se puede
                // confirmar por telefono que el fichero que soporte tiene
                // delante es el que salio de aqui.
                'sha256' => $event->sha256,
                'size_bytes' => $event->sizeBytes,
                'generated_by' => $event->generatedBy,
                ...$deferral,
            ]),
        ));
    }

    /**
     * Si ya hay un asiento de ese paquete. Desde su generacion y no en toda la
     * tabla: el asiento nunca es anterior al paquete, y asi la consulta solo
     * mira las particiones que pueden tenerlo (ADR-027).
     */
    private function alreadyRecorded(string $sha256, DateTimeImmutable $generatedAt): bool
    {
        return $this->database->table(AuditLogSchema::TABLE)
            ->where('action', AuditAction::DiagnosticsBundleGenerated->value)
            ->where('occurred_at', '>=', UtcInstant::of($generatedAt))
            ->where('payload->sha256', $sha256)
            ->exists();
    }
}
