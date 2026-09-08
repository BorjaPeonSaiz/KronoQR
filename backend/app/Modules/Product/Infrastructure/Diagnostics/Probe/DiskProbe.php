<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics\Probe;

use App\Modules\Product\Application\Port\DoctorProbe;
use App\Modules\Product\Domain\ValueObject\DoctorFinding;
use App\Modules\Shared\Infrastructure\Format\ByteSize;

/**
 * Sondas `disk.*` de `product:doctor` (RF-PD-13).
 *
 * ## Es la averia que para el sistema entero, y avisa con dias de antelacion
 *
 * Postgres con el disco lleno deja de aceptar escrituras: **no se puede fichar**.
 * Y el disco no se llena de golpe, se llena a lo largo de semanas con WAL,
 * copias y logs. Dos comprobaciones de tres lineas dan ese aviso.
 *
 * ## Dos umbrales, y el segundo tiene un suelo absoluto
 *
 * Menos del 10 % libre avisa; menos del 5 % **o menos de 1 GiB** falla. El suelo
 * absoluto existe porque el porcentaje engaña en los dos extremos: el 6 % de un
 * disco de 8 TB son 480 GB y no hay ningun problema, y el 6 % de un disco de 20
 * GB son 1,2 GB, que se comen dos copias.
 */
final readonly class DiskProbe implements DoctorProbe
{
    private const float WARNING_RATIO = 0.10;

    private const float FAILURE_RATIO = 0.05;

    /** Suelo absoluto: por debajo de esto da igual el porcentaje. */
    private const int FAILURE_FLOOR_BYTES = 1024 * 1024 * 1024;

    public function __construct(
        private string $storagePath,
        private string $backupPath,
    ) {}

    public function family(): string
    {
        return 'disk';
    }

    public function run(): array
    {
        return [
            $this->space('disk.storage', $this->storagePath),
            $this->space('disk.backup', $this->backupPath),
        ];
    }

    private function space(string $id, string $path): DoctorFinding
    {
        if (! is_dir($path)) {
            return DoctorFinding::warning($id, 'missing', params: ['path' => $path], details: ['path' => $path]);
        }

        $free = @disk_free_space($path);
        $total = @disk_total_space($path);

        if ($free === false || $total === false || $total <= 0.0) {
            return DoctorFinding::warning($id, 'unknown', params: ['path' => $path], details: ['path' => $path]);
        }

        $ratio = $free / $total;

        $details = [
            'path' => $path,
            'free_bytes' => (int) $free,
            'total_bytes' => (int) $total,
            'free_percent' => round($ratio * 100, 1),
        ];

        $params = [
            'path' => $path,
            'free' => ByteSize::human((int) $free),
            'percent' => round($ratio * 100, 1),
        ];

        if ($ratio < self::FAILURE_RATIO || $free < self::FAILURE_FLOOR_BYTES) {
            return DoctorFinding::failure($id, params: $params, details: $details);
        }

        if ($ratio < self::WARNING_RATIO) {
            return DoctorFinding::warning($id, params: $params, details: $details);
        }

        return DoctorFinding::ok($id, $details, $params);
    }
}
