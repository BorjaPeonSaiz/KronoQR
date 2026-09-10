<?php

declare(strict_types=1);

namespace Tests\Support\Observability;

use App\Support\Observability\Logging\LokiHandler;
use App\Support\Observability\Logging\LokiTransport;
use RuntimeException;

/**
 * El transporte de {@see LokiHandler} sin
 * Loki al otro lado.
 *
 * Guarda cada envio —URL, cuerpo y tiempo maximo— para poder afirmar sobre los
 * tres, y sabe fallar de las dos formas en que un colector falla de verdad:
 * respondiendo que no (`false`) y reventando (`Throwable`). La segunda es la que
 * importa: el handler promete que un fallo de red no se propaga a quien escribio
 * la linea de log.
 */
final class RecordingLokiTransport implements LokiTransport
{
    /** @var list<array{url: string, payload: string, timeout: float}> */
    public array $pushes = [];

    private function __construct(
        private readonly bool $accepts,
        private readonly bool $explodes,
    ) {}

    public static function accepting(): self
    {
        return new self(true, false);
    }

    /** Loki responde, pero rechaza el envio. */
    public static function rejecting(): self
    {
        return new self(false, false);
    }

    /** Loki no responde: el cliente HTTP lanza. */
    public static function exploding(): self
    {
        return new self(false, true);
    }

    public function push(string $url, string $payload, float $timeoutSeconds): bool
    {
        $this->pushes[] = ['url' => $url, 'payload' => $payload, 'timeout' => $timeoutSeconds];

        if ($this->explodes) {
            throw new RuntimeException('Loki no responde.');
        }

        return $this->accepts;
    }

    /**
     * El cuerpo del ultimo envio, ya decodificado.
     *
     * @return array{streams: list<array{stream: array<string, string>, values: list<array{0: string, 1: string}>}>}
     */
    public function lastPayload(): array
    {
        $last = $this->pushes[count($this->pushes) - 1] ?? throw new RuntimeException('No se ha enviado nada.');

        /** @var array{streams: list<array{stream: array<string, string>, values: list<array{0: string, 1: string}>}>} $decoded */
        $decoded = json_decode($last['payload'], true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
