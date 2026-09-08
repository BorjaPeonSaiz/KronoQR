<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics\Collector;

use App\Modules\Product\Application\Port\DiagnosticsCollector;
use App\Modules\Product\Domain\ValueObject\DiagnosticsOptions;
use App\Modules\Product\Domain\ValueObject\FieldAllowlist;
use App\Modules\Shared\Domain\ValueObject\UtcInstant;
use Illuminate\Database\ConnectionInterface;

/**
 * Seccion `kiosks`: la flota de tablets, **sin el nombre de ninguna**
 * (RF-PD-09, contrato `DiagnosticsBundle.kiosks`).
 *
 * ## Por que el nombre del quiosco no sale
 *
 * Porque `devices.name` lo escribe el cliente y lo escribe como le viene: la
 * mitad de las instalaciones lo llaman «Recepcion» y la otra mitad «Tablet de
 * Marta». Un nombre libre es un campo de texto que **puede llevar un nombre de
 * persona**, y la regla dura 21 no admite ese «puede». El `uuid` identifica el
 * dispositivo en el resto del paquete —las metricas `kiosk_*` van etiquetadas
 * por el— y es lo unico que soporte necesita para seguirle la pista.
 *
 * ## La lista de permitidos esta escrita aqui y la impone `FieldAllowlist`
 *
 * Cinco columnas, no «todas menos el nombre». El dia que `devices` gane una
 * columna nueva —un campo de notas, una direccion MAC—, no aparecera en el
 * paquete hasta que alguien la añada a esta lista mirandola.
 */
final readonly class KioskCollector implements DiagnosticsCollector
{
    /** Tope de filas. Un hotel tiene unidades de quioscos; mil serian un fallo. */
    private const int MAX_ROWS = 200;

    public function __construct(private ConnectionInterface $database) {}

    public function section(): string
    {
        return 'kiosks';
    }

    public function collect(DiagnosticsOptions $options): array
    {
        $allowlist = new FieldAllowlist('uuid', 'status', 'app_version', 'last_seen_at', 'pending_queue_size');

        $rows = $this->database->table('devices')
            ->select('uuid', 'status', 'app_version', 'last_seen_at', 'pending_queue_size')
            ->orderBy('uuid')
            ->limit(self::MAX_ROWS)
            ->get();

        $kiosks = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $values */
            $values = (array) $row;

            $allowed = $allowlist->apply($values);

            $pending = $allowed['pending_queue_size'] ?? null;
            $allowed['pending_queue_size'] = is_numeric($pending) ? (int) $pending : 0;

            // Postgres devuelve `2026-09-08 09:07:33.123456+00` y el esquema
            // `UtcTimestamp` del contrato exige la forma con `T` y `Z`. Sin esta
            // conversion la respuesta no valida y el cliente TypeScript generado
            // recibe algo que su tipo no describe.
            $allowed['last_seen_at'] = UtcInstant::fromDatabase($allowed['last_seen_at'] ?? null);

            $kiosks[] = $allowed;
        }

        return $kiosks;
    }
}
