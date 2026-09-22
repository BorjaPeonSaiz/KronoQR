<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Listener;

use App\Modules\Compliance\Application\Command\RecordAuditEntryCommand;
use App\Modules\Compliance\Application\UseCase\RecordAuditEntry;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditPayload;
use App\Modules\Compliance\Domain\ValueObject\AuditSubject;
use App\Modules\Compliance\Infrastructure\Audit\CurrentAuditContext;
use App\Modules\Product\Domain\Event\PlanLimitExceeded;

/**
 * Sella en `audit_log` que un alta ha dejado la instalacion por encima del plan
 * (**ADR-028**, RF-PD-04, RL-04).
 *
 * ## Este asiento describe algo que SI ocurrio
 *
 * Conviene decirlo antes que nada, porque el nombre invita a leerlo al reves:
 * `license.plan_exceeded` **no registra un rechazo**. La persona quedo dada de
 * alta, o el quiosco quedo emparejado. Lo que se registra es la cifra y la
 * fecha, que segun ADR-028 es *«la prueba que sostiene la reclamacion comercial:
 * la fecha exacta desde la que el cliente opera por encima del plan»*.
 *
 * Bloquear en su lugar dejaria a alguien trabajando sin registro horario —una
 * infraccion del art. 34.9 ET imputable al cliente y causada por el producto— o
 * a un centro sin punto de fichaje justo al sustituir un quiosco averiado.
 *
 * ## `first_crossing` separa las dos preguntas
 *
 * Quien reclama pregunta «¿desde cuando?» y mira el primer asiento; quien
 * negocia una ampliacion pregunta «¿cuanto?» y mira el ultimo. Sin este campo
 * habria que deducir cual fue el primero ordenando por fecha y confiando en que
 * no falte ninguno.
 *
 * ## Un asiento por OPERACION, y la importacion es una sola
 *
 * Una carga masiva de plantilla (RF-GP-05) da de alta a cientos de personas de
 * una vez y deja **un** asiento, con el recuento final y `added_in_excess`
 * (H-04, tarea 3.8). Antes escribia uno por fila: trescientas escrituras casi
 * identicas bajo el candado global de `audit_log` (ADR-010), que es el mismo por
 * el que pasa cada fichaje del hotel.
 *
 * ## Sin datos personales
 *
 * Cifras y nombres de limite. Quien se dio de alta ya tiene su propio asiento
 * (regla dura 21).
 *
 * ## Sincrono, y aun asi no puede tumbar el alta
 *
 * Sin `ShouldQueue`, como el resto de los asientos: la evidencia no puede
 * depender de que la cola este viva. Pero **quien publica el evento lo hace
 * fuera de la transaccion del alta y bajo `try`** ({@see
 * \App\Modules\Product\Infrastructure\Listener\ObservePlanLimits}), de modo que
 * un fallo aqui pierde este asiento y **nunca** el alta. Es la unica
 * combinacion compatible con ADR-028.
 */
final readonly class RecordPlanLimitExcess
{
    public function __construct(
        private RecordAuditEntry $audit,
        private CurrentAuditContext $context,
    ) {}

    public function handle(PlanLimitExceeded $event): void
    {
        $this->audit->handle(new RecordAuditEntryCommand(
            actor: $this->context->actor(),
            action: AuditAction::LicensePlanExceeded,
            subject: AuditSubject::of('license'),
            payload: AuditPayload::of([
                'license_id' => $event->licenseId,
                // El nombre del campo de la clave firmada: `max_employees` o
                // `max_devices`. La clave, el trail y `license:show` usan la
                // misma palabra a proposito.
                'limit' => $event->limit,
                'contracted' => $event->contracted,
                'reached' => $event->reached,
                'excess' => $event->reached - $event->contracted,
                // Cuantas de las altas de ESTA operacion quedaron por encima del
                // plan. En un alta de una en una vale 1 siempre; existe por la
                // importacion de plantilla, que entra entera y deja un solo
                // asiento (H-04, tarea 3.8): sin esta cifra, ese asiento diria
                // cuanta gente sobra pero no cuanta metio el fichero.
                'added_in_excess' => $event->addedInExcess,
                'first_crossing' => $event->firstCrossing,
                // Lo que este asiento NO significa, escrito dentro del propio
                // asiento: dentro de dos años, quien lo lea no tendra este
                // docblock delante.
                'operation_blocked' => false,
            ]),
        ));
    }
}
