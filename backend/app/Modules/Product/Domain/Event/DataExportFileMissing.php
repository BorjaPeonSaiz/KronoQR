<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * El ZIP de una exportacion integra ha desaparecido **antes de caducar**
 * (**RF-PD-14**, RL-15, RL-20; ADR-045 §d, condicion C5).
 *
 * ## Por que es un hecho con relevancia legal
 *
 * El fichero lleva la plantilla entera y sus fichajes. Si deja de estar antes de
 * su `expires_at`, alguien lo borro a mano o se lo llevo con un `mv`, y RL-15
 * pide poder acotar cuando ocurrio. La purga lo detecta en la pasada horaria,
 * marca la fila `purged` y lo publica; `Compliance` lo sella como
 * `data_export.file_missing`.
 *
 * Tras una restauracion es lo esperado —el volumen no entra en la copia— y el
 * informe de `restore.sh` lo anuncia para que nadie lo confunda con una brecha.
 *
 * ## Lo que lleva, y lo que no
 *
 * El `uuid` de la exportacion y su `expires_at`; el momento de la deteccion es
 * `occurredAt()`. **Nunca la ruta ni el nombre del fichero** (regla dura 21).
 */
final readonly class DataExportFileMissing implements DomainEvent
{
    public function __construct(
        public string $uuid,
        /** RFC 3339 en UTC, como lo guarda la fila. */
        public string $expiresAt,
        private DateTimeImmutable $detectedAt,
    ) {}

    public function eventName(): string
    {
        return 'product.data_export_file_missing';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->detectedAt;
    }
}
