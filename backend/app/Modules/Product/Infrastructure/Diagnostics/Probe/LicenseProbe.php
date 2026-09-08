<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics\Probe;

use App\Modules\Product\Application\Port\DoctorProbe;
use App\Modules\Product\Application\UseCase\DescribeLicenseHandler;
use App\Modules\Product\Application\UseCase\GetSettingsHandler;
use App\Modules\Product\Domain\ValueObject\DoctorFinding;
use App\Modules\Product\Domain\ValueObject\LicenseOverview;
use App\Modules\Product\Domain\ValueObject\LicenseState;
use App\Modules\Product\Domain\ValueObject\LicenseStatus;
use App\Modules\Product\Domain\ValueObject\SettingKey;
use App\Modules\Shared\Domain\ValueObject\Feature;

/**
 * Sondas `license.*` de `product:doctor` (RF-PD-04, RF-PD-05).
 *
 * ## Nunca pasan de `warning`. Nunca. (regla dura 15, ADR-019)
 *
 * Es la decision mas importante de esta clase y no admite matices. Un `failure`
 * por licencia devolveria `2`, `install.sh` y `update.sh` lo traducirian al `6`
 * de su tabla comun y **una actualizacion se abortaria por una licencia
 * vencida**. Eso dejaria a un cliente sin poder actualizar —y potencialmente sin
 * poder arreglar otra cosa— por una razon comercial, que es exactamente lo que
 * ADR-019 prohibe: el registro legal no es rehen del negocio.
 *
 * Lo que hace esta sonda es **informar**: dice el estado, dice que sigue
 * funcionando y dice como renovar. Nada mas.
 *
 * ## `license.white_label_without_plan`
 *
 * El caso concreto de un cliente que configuro su marca y despues dejo de tener
 * el plan que la cubre: el panel vuelve a la marca del producto y el cliente
 * llama pensando que se ha perdido su logotipo. Un `warning` con la explicacion
 * lo resuelve sin llamada.
 */
final readonly class LicenseProbe implements DoctorProbe
{
    public function __construct(
        private DescribeLicenseHandler $licenses,
        private GetSettingsHandler $settings,
    ) {}

    public function family(): string
    {
        return 'license';
    }

    public function run(): array
    {
        $overview = $this->licenses->handle();

        return [
            $this->state($overview),
            $this->whiteLabel($overview->status->allows(Feature::WhiteLabel)),
        ];
    }

    private function state(LicenseOverview $overview): DoctorFinding
    {
        $status = $overview->status;

        $details = [
            'state' => $status->state->value,
            'days_until_expiry' => $status->daysUntilExpiry(),
            'days_since_expiry' => $status->daysSinceExpiry(),
            'exceeded_limits' => \count($overview->exceeded()),
        ];

        if ($status->state === LicenseState::Valid) {
            return $overview->exceeded() === []
                ? DoctorFinding::ok('license.state', $details)
                : DoctorFinding::warning('license.state', 'plan_exceeded', details: $details);
        }

        // **Todo lo que no sea `Valid` es `warning` y ni un grado mas.** Es la
        // regla dura 15 escrita en una sola llamada: un `failure` aqui
        // devolveria `2`, y `update.sh` abortaria una actualizacion por una
        // licencia vencida (ADR-019). Que el estado no participe en elegir la
        // gravedad es lo que hace imposible ese error.
        [$variant, $params] = $this->notice($status);

        return DoctorFinding::warning('license.state', $variant, $params, $details);
    }

    /**
     * @return array{0: string, 1: array<string, string|int>}
     */
    private function notice(LicenseStatus $status): array
    {
        return match ($status->state) {
            LicenseState::ExpiringSoon => ['expiring_soon', ['days' => $status->daysUntilExpiry() ?? 0]],
            LicenseState::Expired => ['expired', ['days' => $status->daysSinceExpiry() ?? 0]],
            LicenseState::Absent => ['absent', []],
            LicenseState::NotYetValid => ['not_yet_valid', []],
            LicenseState::Unverifiable => ['unverifiable', ['reason' => $status->rejection->value ?? 'unknown']],
            // Inalcanzable: `state()` lo resuelve antes. Esta rama existe para
            // que el `match` no necesite `default`, que es lo que hace que un
            // estado nuevo del enum rompa aqui en la primera prueba.
            LicenseState::Valid => ['plan_exceeded', []],
        };
    }

    private function whiteLabel(bool $allowed): DoctorFinding
    {
        $resolved = $this->settings->handle();

        $configured = $resolved->get(SettingKey::BRANDING_APP_NAME)->isProductDefault === false
            || $resolved->get(SettingKey::BRANDING_LOGO_PATH)->isProductDefault === false
            || $resolved->get(SettingKey::BRANDING_ACCENT_COLOR)->isProductDefault === false;

        if (! $configured || $allowed) {
            return DoctorFinding::ok('license.white_label_without_plan', [
                'configured' => $configured,
                'allowed' => $allowed,
            ]);
        }

        return DoctorFinding::warning(
            'license.white_label_without_plan',
            details: ['configured' => true, 'allowed' => false],
        );
    }
}
