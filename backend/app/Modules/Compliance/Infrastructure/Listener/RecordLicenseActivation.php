<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Listener;

use App\Modules\Compliance\Application\Command\RecordAuditEntryCommand;
use App\Modules\Compliance\Application\UseCase\RecordAuditEntry;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditPayload;
use App\Modules\Compliance\Domain\ValueObject\AuditSubject;
use App\Modules\Compliance\Infrastructure\Audit\CurrentAuditContext;
use App\Modules\Product\Domain\Event\LicenseActivated;
use DateTimeInterface;

/**
 * Sella en `audit_log` la activacion de una clave de licencia (**RF-PD-04**,
 * RL-04, regla dura 6).
 *
 * ## Que pregunta responde este asiento
 *
 * «¿Que se contrato, desde cuando y quien lo metio en el sistema?». Es la unica
 * fuente que lo responde: la tabla `license` guarda **la clave de hoy**, no su
 * historia, y una renovacion escribe encima de la anterior. Sin este asiento, la
 * respuesta a «¿desde cuando tiene este hotel el plan de cien personas?» seria
 * mirar la fecha de un correo.
 *
 * ## `subject_id` es nulo y el identificador viaja en el payload
 *
 * Porque el sujeto no es una fila con identificador estable: la tabla tiene una
 * fila cuyo `id` no significa nada. Lo que identifica a la licencia es su
 * `license_id`, que lo pone el fabricante al emitir, y ese va en el payload —el
 * mismo criterio que la configuracion de instalacion, cuyo sujeto es una clave
 * de texto.
 *
 * ## Lo que NO va en el payload
 *
 * **La clave firmada entera.** Va su huella corta. El asiento acaba en el trail
 * y el trail se exporta; difundir ahi 400 caracteres que repiten el nombre del
 * cliente no aporta nada que la huella no aporte, y una huella se lee por
 * telefono.
 *
 * ## Sincrono y dentro de la transaccion
 *
 * Sin `ShouldQueue` y sin `afterCommit`: si el asiento falla, la activacion no
 * se guarda (ADR-027). Es la unica evidencia de un hecho comercial, y una
 * licencia activada sin traza deja la conversacion posterior sin apoyo.
 *
 * **Por un listener y no por una llamada desde `Product`**: el §1.6 no concede
 * la arista `Product -> Compliance`. Misma via que la configuracion (5.1), el
 * perfil de cumplimiento (5.2), el alta de empleado, la credencial y el PIN.
 */
final readonly class RecordLicenseActivation
{
    public function __construct(
        private RecordAuditEntry $audit,
        private CurrentAuditContext $context,
    ) {}

    public function handle(LicenseActivated $event): void
    {
        $this->audit->handle(new RecordAuditEntryCommand(
            // Quien lo hizo lo resuelve la sesion en curso, no el evento. Por
            // consola no hay sesion, y eso tambien es informacion: distingue
            // «lo activo Marta» de «lo activo el instalador».
            actor: $this->context->actor(),
            action: AuditAction::LicenseActivated,
            subject: AuditSubject::of('license'),
            payload: AuditPayload::of([
                'license_id' => $event->licenseId,
                // La huella, nunca la clave. Ver el docblock.
                'key_fingerprint' => $event->fingerprint,
                'customer_name' => $event->customerName,
                'plan' => $event->plan,
                'max_employees' => $event->maxEmployees,
                'max_devices' => $event->maxDevices,
                // Las accesorias habilitadas por el plan. El conjunto legal no
                // aparece y no puede aparecer (ADR-023).
                'features' => $event->features,
                'valid_from' => $event->validFrom->format(DateTimeInterface::ATOM),
                'valid_until' => $event->validUntil->format(DateTimeInterface::ATOM),
                // Que estado quedo al activarla. Se puede activar una clave ya
                // caducada —un cliente que renueva tarde— y conviene que conste
                // que se activo asi y no que caduco despues.
                'resulting_state' => $event->resultingState,
            ]),
        ));
    }
}
