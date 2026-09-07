<?php

declare(strict_types=1);

namespace Tests\Support\Kiosk;

use App\Modules\Kiosk\Application\Port\KioskMetrics;

/**
 * Doble de {@see KioskMetrics} que recuerda lo que se midio.
 *
 * Es la segunda implementacion del puerto que justifica que el puerto exista
 * (doc 02 §3.5): sin ella, comprobar que un emparejamiento rechazado se cuenta
 * obligaria a levantar Redis y a leer una clave, y la prueba hablaria de
 * infraestructura en vez de del hecho.
 *
 * **Lo que se afirma con esto es la instrumentacion, no la metrica**: que Redis
 * guarde bien un `HINCRBY` es cosa de Redis. Lo que puede romperse sin que nadie
 * lo note es que alguien anada un camino de rechazo y se olvide de contarlo, y
 * entonces un pico de intentos fallidos no aparece en ningun panel.
 */
final class RecordingKioskMetrics implements KioskMetrics
{
    /** @var list<array{device: string, seen_at: int, queue: int}> */
    public array $heartbeats = [];

    public int $requested = 0;

    public int $confirmed = 0;

    public int $claimed = 0;

    /** @var list<string> Motivos, en orden. */
    public array $rejected = [];

    public function heartbeat(string $deviceUuid, int $seenAtUnixSeconds, int $pendingQueueSize): void
    {
        $this->heartbeats[] = [
            'device' => $deviceUuid,
            'seen_at' => $seenAtUnixSeconds,
            'queue' => $pendingQueueSize,
        ];
    }

    public function pairingRequested(): void
    {
        $this->requested++;
    }

    public function pairingConfirmed(): void
    {
        $this->confirmed++;
    }

    public function pairingClaimed(): void
    {
        $this->claimed++;
    }

    public function pairingRejected(string $reason): void
    {
        $this->rejected[] = $reason;
    }
}
