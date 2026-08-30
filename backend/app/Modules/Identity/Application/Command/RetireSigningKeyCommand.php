<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Command;

/**
 * Cerrar el solape de una rotacion (RF-QR-07, doc 02 §5.3).
 *
 * El `key_id` se declara explicitamente y no se deduce del llavero: retirar una
 * clave es el acto que mata un lote de tarjetas, y quien lo ordena tiene que
 * escribir cual. Deducirlo convertiria un despiste de configuracion —la variable
 * cambiada antes de tiempo— en una retirada silenciosa de la clave equivocada.
 */
final readonly class RetireSigningKeyCommand
{
    public function __construct(
        public string $keyId,
        public ?int $actorUserId = null,
    ) {}
}
