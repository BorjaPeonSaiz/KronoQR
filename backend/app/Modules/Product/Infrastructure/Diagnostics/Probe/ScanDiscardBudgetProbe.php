<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics\Probe;

use App\Modules\Product\Application\Port\DoctorProbe;
use App\Modules\Product\Domain\ValueObject\DoctorFinding;

/**
 * **El cupo de descartes del servidor, por encima de su techo** (RN-22, ADR-061,
 * A6 del 24-10-2026).
 *
 * `SCAN_DISCARD_DAILY_BUDGET` admite como mucho 20 descartes por quiosco en
 * 24 h. Un valor mayor no impide arrancar ni fichar —el servidor aplica 20—,
 * pero quien lo puso cree tener otro numero, y eso se dice aqui. **Aviso y
 * nunca fallo**: no hay nada roto.
 *
 * Recibe los dos valores ya leidos de la configuracion: el techo es el de
 * `ScanDiscardThreshold::BUDGET_CEILING`, que `config/attendance.php` expone para
 * que esta sonda no nombre el dominio de otro modulo.
 */
final readonly class ScanDiscardBudgetProbe implements DoctorProbe
{
    public function __construct(
        private mixed $configuredBudget,
        private int $ceiling,
    ) {}

    public function family(): string
    {
        return 'kiosk';
    }

    public function run(): array
    {
        $text = \is_int($this->configuredBudget) ? (string) $this->configuredBudget : (\is_string($this->configuredBudget) ? trim($this->configuredBudget) : '');

        if (preg_match('/^\d{1,6}$/', $text) === 1 && (int) $text > $this->ceiling) {
            return [DoctorFinding::warning(
                'kiosk.scan_discard_budget',
                params: ['configured' => (int) $text, 'ceiling' => $this->ceiling],
                details: ['configured' => (int) $text, 'ceiling' => $this->ceiling],
            )];
        }

        return [DoctorFinding::ok('kiosk.scan_discard_budget', ['ceiling' => $this->ceiling], ['ceiling' => $this->ceiling])];
    }
}
