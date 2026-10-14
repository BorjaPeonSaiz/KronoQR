<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics;

use App\Modules\Product\Application\Port\PendingDiagnosticsAudits;
use App\Modules\Product\Domain\Exception\DiagnosticsAuditNotDeferred;
use App\Modules\Product\Domain\ValueObject\PendingDiagnosticsAudit;
use RuntimeException;
use Throwable;

/**
 * Los asientos pendientes de paquetes de diagnostico, como un fichero JSON por
 * paquete (ADR-020, enmienda del 14-10-2026).
 *
 * ## Donde, y por que ahi
 *
 * `storage/app/diagnostics-audit-pending`, fijo y sin variable de entorno:
 *
 * - **Dentro del volumen de ficheros generados** (`app-storage`, ADR-045):
 *   sobrevive a recrear el contenedor y lo ven `app` —que genera el paquete— y
 *   `scheduler` —que escribe el asiento al volver la base—.
 * - **Fuera de `storage/app/diagnostics`**: la purga de paquetes caducados
 *   (`SweepExpiredDiagnosticsBundles`) no lo puede alcanzar. Y el nombre,
 *   `<sha256>.json`, no casa con ninguna clase de fichero generado, asi que
 *   tampoco lo cuenta como huerfano la comprobacion `files.stray_entries`.
 *
 * ## `0600`, escrito entero o no escrito
 *
 * Se escribe a un temporal con `umask` cerrada y se renombra: un pendiente a
 * medias no es un pendiente, y {@see PendingDiagnosticsAudit::fromArray()} lo
 * rechazaria. Lleva solo claves cerradas y ningun texto libre, pero vive en el
 * volumen del cliente y puede acabar en una copia: cerrado igual que el
 * paquete.
 */
final readonly class FilePendingDiagnosticsAudits implements PendingDiagnosticsAudits
{
    private const string NAME = '/^[0-9a-f]{64}\.json$/D';

    /**
     * Tope al releer. Un pendiente legitimo ocupa menos de 1 KiB; cualquier
     * cosa mayor no lo es, y no se carga en memoria.
     */
    private const int MAX_BYTES = 64 * 1024;

    public function __construct(private string $directory) {}

    public function save(PendingDiagnosticsAudit $pending): void
    {
        $directory = rtrim($this->directory, '/');

        if (! is_dir($directory) && ! @mkdir($directory, 0o700, true) && ! is_dir($directory)) {
            throw DiagnosticsAuditNotDeferred::in($directory);
        }

        $target = $directory.'/'.$pending->sha256.'.json';
        $temporary = $target.'.'.bin2hex(random_bytes(4)).'.tmp';
        $previous = umask(0o077);

        try {
            $json = json_encode($pending->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
            $written = @file_put_contents($temporary, $json) === \strlen($json)
                && @chmod($temporary, 0o600)
                && @rename($temporary, $target);
        } catch (Throwable) {
            $written = false;
        } finally {
            umask($previous);
        }

        if (! $written) {
            @unlink($temporary);

            throw DiagnosticsAuditNotDeferred::in($directory);
        }
    }

    public function all(): array
    {
        if (! is_dir($this->directory)) {
            return [];
        }

        $pending = [];

        foreach (@scandir($this->directory) ?: [] as $name) {
            $path = $this->directory.'/'.$name;

            if (preg_match(self::NAME, $name) !== 1 || is_link($path) || ! is_file($path)) {
                continue;
            }

            $entry = $this->read($path);

            if ($entry instanceof PendingDiagnosticsAudit && $entry->sha256.'.json' === $name) {
                $pending[] = $entry;
            }
        }

        usort(
            $pending,
            static fn (PendingDiagnosticsAudit $a, PendingDiagnosticsAudit $b): int => $a->generatedAt <=> $b->generatedAt,
        );

        return $pending;
    }

    public function remove(string $sha256): void
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $sha256) !== 1) {
            return;
        }

        $path = rtrim($this->directory, '/').'/'.$sha256.'.json';

        if (is_file($path) && ! @unlink($path) && is_file($path)) {
            throw new RuntimeException('El asiento ya esta en la auditoria, pero el unlink del pendiente '
                .$path.' no se pudo. No se repite el asiento; comprueba los permisos del directorio.');
        }
    }

    private function read(string $path): ?PendingDiagnosticsAudit
    {
        $size = @filesize($path);

        if ($size === false || $size > self::MAX_BYTES) {
            return null;
        }

        $raw = @file_get_contents($path, length: self::MAX_BYTES);

        if ($raw === false) {
            return null;
        }

        try {
            $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($data) ? PendingDiagnosticsAudit::fromArray($data) : null;
    }
}
