<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

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
    private const string DURABLE = 'durable';

    public function __construct(
        public string $deviceUuid,
        /** La `app_version` del ultimo latido; `null` si no la declaro. */
        public ?string $appVersion,
        /** `durable`, `memory` o `unavailable` (ADR-047), tal y como lo declaro la tablet. */
        public string $queueStorage,
        /** Fichajes pendientes declarados; `null` si la tablet no lo sabe. */
        public ?int $pendingQueueSize,
    ) {}

    /** Cola en disco y vacia: recargar no se lleva ningun fichaje. */
    public function isReadyToReload(): bool
    {
        return $this->queueStorage === self::DURABLE && $this->pendingQueueSize === 0;
    }
}
