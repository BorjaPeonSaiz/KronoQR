<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * El asiento `diagnostics.bundle_generated` de un paquete, buscado por su
 * huella (`product:diagnostics --verify`; ADR-020, enmienda del 01-11-2026).
 *
 * Es lo que hace util `--verify` sobre un paquete cifrado: su contenido no se
 * puede comprobar sin la clave privada del fabricante, pero su cabecera y el
 * fichero entero si se pueden comparar con lo que la instalacion dejo escrito
 * al generarlo. La referencia de que el sobre salio de aqui es ese asiento.
 *
 * Solo lectura, y solo `occurred_at` y dos campos del `payload`: nunca el
 * actor ni el resto. Lee la tabla por su nombre, como `ServiceInspector`, sin
 * arista hacia `Compliance`.
 */
final readonly class DiagnosticsAuditTrail
{
    private const string ACTION = 'diagnostics.bundle_generated';

    public function __construct(private ConnectionInterface $database) {}

    /**
     * El asiento mas reciente con esa huella del texto en claro, o nulo si no
     * hay ninguno. Lanza si la base no responde: quien llama lo dice asi.
     *
     * @return array{occurred_at: DateTimeImmutable, sealed_sha256: string|null}|null
     */
    public function generation(string $sha256): ?array
    {
        $row = $this->database->table('audit_log')
            ->where('action', self::ACTION)
            ->where('payload->sha256', $sha256)
            ->orderByDesc('id')
            ->first(['occurred_at', 'payload']);

        if ($row === null) {
            return null;
        }

        $values = (array) $row;

        if (! \is_string($values['occurred_at'] ?? null)) {
            return null;
        }

        $payload = \is_string($values['payload'] ?? null) ? json_decode($values['payload'], true) : null;

        return [
            'occurred_at' => new DateTimeImmutable($values['occurred_at']),
            'sealed_sha256' => \is_array($payload) && \is_string($payload['sealed_sha256'] ?? null)
                ? $payload['sealed_sha256']
                : null,
        ];
    }
}
