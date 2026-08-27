<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Command;

use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditActor;
use App\Modules\Compliance\Domain\ValueObject\AuditPayload;
use App\Modules\Compliance\Domain\ValueObject\AuditSubject;
use DateTimeImmutable;

/**
 * La orden de dejar traza de un hecho auditable.
 *
 * `occurredAt` es opcional y casi siempre debe serlo: si no se indica, el caso
 * de uso lo pide al puerto `Clock`. Se puede indicar cuando el hecho tiene un
 * momento propio distinto del de registro —un fichaje que llega de la cola
 * offline del quiosco (regla dura 9)—, y entonces es responsabilidad de quien
 * lo pasa que sea el momento real y este en UTC.
 */
final readonly class RecordAuditEntryCommand
{
    public function __construct(
        public AuditActor $actor,
        public AuditAction $action,
        public AuditSubject $subject,
        public AuditPayload $payload,
        public ?DateTimeImmutable $occurredAt = null,
        public ?string $ip = null,
        public ?string $userAgent = null,
    ) {}
}
