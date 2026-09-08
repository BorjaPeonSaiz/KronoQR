<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Listener;

use App\Modules\Compliance\Application\Command\RecordAuditEntryCommand;
use App\Modules\Compliance\Application\UseCase\RecordAuditEntry;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditPayload;
use App\Modules\Compliance\Domain\ValueObject\AuditSubject;
use App\Modules\Compliance\Infrastructure\Audit\CurrentAuditContext;
use App\Modules\Product\Domain\Event\PersonalDataIncludedInDiagnostics;

/**
 * Sella en `audit_log` que un paquete de diagnostico salio **con datos
 * personales dentro** (**RL-19**, RL-04, regla dura 6, ADR-020).
 *
 * ## Asiento propio, y esa es la decision
 *
 * RL-19 dice que incluir datos personales es *una accion distinta*. Este
 * listener es lo que hace que esa frase signifique algo: se escribe **ademas**
 * del asiento de generacion, con accion propia, para que la pregunta «¿cuando
 * han salido de aqui datos de mi plantilla?» se responda con un `WHERE action =`
 * y no recorriendo todos los paquetes generados en cuatro años.
 *
 * Ante una brecha, la capacidad de determinar el alcance es del cliente (RL-15)
 * y el producto se lo tiene que dar hecho.
 *
 * ## Lo que va y lo que no
 *
 * Van `period_days`, que colecciones se incluyeron y la huella del paquete. **No
 * va ni un dato de ninguna persona**: seria absurdo que el asiento que registra
 * la salida de datos personales los copiara otra vez en una tabla que se
 * exporta (regla dura 21).
 *
 * ## Sincrono y dentro de la transaccion
 *
 * Si este asiento no se puede escribir, el paquete no se entrega. Es la
 * condicion que hace legitima la funcionalidad entera.
 */
final readonly class RecordPersonalDataIncludedInDiagnostics
{
    public function __construct(
        private RecordAuditEntry $audit,
        private CurrentAuditContext $context,
    ) {}

    public function handle(PersonalDataIncludedInDiagnostics $event): void
    {
        $this->audit->handle(new RecordAuditEntryCommand(
            actor: $this->context->actor(),
            action: AuditAction::DiagnosticsPersonalDataIncluded,
            subject: AuditSubject::of('diagnostics'),
            payload: AuditPayload::of([
                'period_days' => $event->periodDays,
                'collections' => $event->collections,
                'sha256' => $event->sha256,
                'generated_by' => $event->generatedBy,
            ]),
        ));
    }
}
