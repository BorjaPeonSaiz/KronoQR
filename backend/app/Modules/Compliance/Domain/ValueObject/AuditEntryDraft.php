<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

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
 */
final readonly class AuditEntryDraft
{
    public function __construct(
        public DateTimeImmutable $occurredAt,
        public AuditActor $actor,
        public AuditAction $action,
        public AuditSubject $subject,
        public AuditPayload $payload,
        public ?string $ip = null,
        public ?string $userAgent = null,
    ) {
        if ($occurredAt->getOffset() !== 0) {
            throw AuditInstantIsNotUtc::forField('occurred_at', $occurredAt);
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
