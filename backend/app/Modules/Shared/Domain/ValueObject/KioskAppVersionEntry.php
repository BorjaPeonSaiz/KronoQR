<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Una tablet de {@see KioskAppVersionSurvey}: su `uuid`, la version que declara
 * y el estado de su cola segun su ultimo latido (RF-KI-07, ADR-047).
 *
 * **La cola viaja con la version porque decide el remedio.** Recargar la PWA
 * —o desregistrar su *service worker*— en una tablet con la cola en memoria
 * (`queue_storage` distinto de `durable`) borra los fichajes que no ha
 * enviado. Solo una tablet con la cola duradera y vacia esta lista para que
 * alguien la toque.
 *
 * Ni un dato personal: viaja en el paquete de diagnostico (regla dura 21).
 */
final readonly class KioskAppVersionEntry
{
    public function __construct(
        public string $deviceUuid,
        /** La `app_version` del ultimo latido; `null` si no la declaro. */
        public ?string $appVersion,
        /** Donde guarda la cola, tal y como lo declaro la tablet (ADR-047). */
        public QueueStorage $queueStorage,
        /** Fichajes pendientes declarados; `null` si la tablet no lo sabe. */
        public ?int $pendingQueueSize,
    ) {
        // Una tablet sin `uuid` no se encuentra en el panel, y una cola de
        // menos uno no esta «vacia»: diria que se puede recargar (V21-1-AR-4).
        if ($deviceUuid === '') {
            throw new InvalidArgumentException('La tablet del sondeo de versiones lleva su uuid.');
        }

        if ($pendingQueueSize !== null && $pendingQueueSize < 0) {
            throw new InvalidArgumentException('Una tablet no puede tener '.$pendingQueueSize.' fichajes pendientes.');
        }
    }

    /** Cola en disco y vacia: recargar no se lleva ningun fichaje. */
    public function isReadyToReload(): bool
    {
        return $this->queueStorage->isDurable() && $this->pendingQueueSize === 0;
    }
}
