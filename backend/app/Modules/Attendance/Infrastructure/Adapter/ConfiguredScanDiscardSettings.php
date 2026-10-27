<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Infrastructure\Adapter;

use App\Modules\Attendance\Application\Port\ScanDiscardSettings;
use App\Modules\Attendance\Domain\ValueObject\ScanDiscardThreshold;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

/**
 * El umbral del descarte por el servidor (RN-22, ADR-061 §3), leido de
 * `config/attendance.php` → `SCAN_DISCARD_MIN_ATTEMPTS`,
 * `SCAN_DISCARD_MIN_MINUTES` y `SCAN_DISCARD_DAILY_BUDGET` (regla dura 13,
 * ADR-029). De serie: 10 intentos, 60 minutos y 3 descartes por quiosco y 24 h.
 *
 * **No corrige nada en silencio.** Un valor que no es un entero lanza aqui, y uno
 * por debajo de los suelos lanza al construir {@see ScanDiscardThreshold}. Quien
 * lo usa —el guardian del descarte— trata ese fallo como cualquier otro de su
 * contabilidad: conserva el fichaje como antes de ADR-061 y lo deja en el log.
 * Ante la duda, conservar.
 */
final readonly class ConfiguredScanDiscardSettings implements ScanDiscardSettings
{
    public function threshold(): ScanDiscardThreshold
    {
        return new ScanDiscardThreshold(
            minAttempts: $this->integer('min_attempts'),
            minSpanMinutes: $this->integer('min_minutes'),
            // Por encima del techo se aplica el techo y `product:doctor` lo
            // avisa (`ScanDiscardBudgetProbe`). Nunca un error que impida arrancar.
            dailyBudget: min($this->integer('daily_budget'), ScanDiscardThreshold::BUDGET_CEILING),
        );
    }

    private function integer(string $key): int
    {
        $value = Config::get('attendance.scan_discard.'.$key);

        if (\is_int($value)) {
            return $value;
        }

        $text = \is_string($value) ? trim($value) : '';

        if (preg_match('/^-?\d{1,6}$/', $text) !== 1) {
            throw new InvalidArgumentException(
                'attendance.scan_discard.'.$key.' tiene que ser un numero entero (RN-22, ADR-061).'
            );
        }

        return (int) $text;
    }
}
