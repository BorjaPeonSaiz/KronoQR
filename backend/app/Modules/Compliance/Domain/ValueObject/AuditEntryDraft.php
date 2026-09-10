<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

use App\Modules\Compliance\Domain\Exception\AuditActorNotAllowedForAction;
use App\Modules\Compliance\Domain\Exception\AuditInstantIsNotUtc;
use DateTimeImmutable;

/**
 * Una entrada de auditoria **antes** de entrar en la cadena: tiene todo lo que
 * describe el hecho y todavia no tiene `prev_hash` ni `hash`.
 *
 * Existen dos objetos y no uno porque encadenar es una operacion con estado
 * externo —hace falta saber cual fue la ultima entrada— y el dominio no puede
 * consultarlo. El modulo que registra el hecho construye el borrador; el
 * adaptador lo encadena dentro de la transaccion. Sin la separacion, el objeto
 * de dominio tendria que nacer a medias y admitir un `hash` nulo, que es la
 * clase de estado invalido que un objeto de valor existe para hacer imposible.
 *
 * `occurred_at` es el momento del hecho, en UTC (regla dura 3 y 9). `ip` y
 * `userAgent` son opcionales: el scheduler no tiene ninguno de los dos.
 *
 * **La accion se guarda como {@see AuditActionName} y no como {@see AuditAction}.**
 * Quien escribe sigue entregando el caso del catalogo —el constructor lo acepta
 * y lo envuelve—, pero el borrador que se reconstruye al *leer* una fila puede
 * llevar una accion que esta version no conoce, porque las acciones nuevas las
 * estrena siempre la version siguiente. El motivo completo esta en el docblock
 * de `AuditActionName`.
 */
final readonly class AuditEntryDraft
{
    public AuditActionName $action;

    public function __construct(
        public DateTimeImmutable $occurredAt,
        public AuditActor $actor,
        AuditAction|AuditActionName $action,
        public AuditSubject $subject,
        public AuditPayload $payload,
        public ?string $ip = null,
        public ?string $userAgent = null,
    ) {
        $this->action = $action instanceof AuditAction ? AuditActionName::of($action) : $action;

        if ($occurredAt->getOffset() !== 0) {
            throw AuditInstantIsNotUtc::forField('occurred_at', $occurredAt);
        }

        // El estado imposible se rechaza al construir y no se valida en cada
        // camino de escritura (doc 02 §3.5): un asiento del ciclo de vida de la
        // instalacion firmado por una persona no puede llegar a existir, porque
        // no lo escribe una persona. Ver `AuditAction::requiresSystemActor()`.
        if ($this->action->requiresSystemActor() && $actor->type !== AuditActorType::System) {
            throw new AuditActorNotAllowedForAction($this->action->value, $actor->type->value);
        }
    }

    /**
     * Forma canonica del componente `occurred_at` de la formula del §7.4.
     *
     * Precision de microsegundo y desplazamiento explicito, siempre `+00:00`.
     * Longitud fija, asi que no puede haber frontera ambigua con el componente
     * siguiente; el separador de registro se añade igual, por uniformidad con
     * los demas componentes.
     *
     * La columna `occurred_at` se declara `TIMESTAMPTZ(6)` justo por esto: con
     * la precision por defecto de Laravel —0— la base de datos redondearia al
     * segundo y el verificador recalcularia un hash distinto del escrito.
     */
    public function canonicalOccurredAt(): string
    {
        return $this->occurredAt->format('Y-m-d\TH:i:s.uP')."\x1e";
    }
}
