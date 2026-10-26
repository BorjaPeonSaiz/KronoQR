<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics\Probe;

use App\Modules\Product\Application\Exception\LicenseUnavailable;
use App\Modules\Product\Application\Port\DoctorProbe;
use App\Modules\Product\Application\UseCase\DescribeLicenseHandler;
use App\Modules\Product\Application\UseCase\GetSettingsHandler;
use App\Modules\Product\Domain\ValueObject\DoctorFinding;
use App\Modules\Product\Domain\ValueObject\LicenseOverview;
use App\Modules\Product\Domain\ValueObject\LicenseState;
use App\Modules\Product\Domain\ValueObject\LicenseStatus;
use App\Modules\Product\Domain\ValueObject\SettingKey;
use App\Modules\Shared\Domain\ValueObject\Feature;
use App\Modules\Shared\Domain\ValueObject\FeatureRestriction;

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
 * ## `license.branding_not_applied`
 *
 * El caso concreto de un cliente que configuro su color o su logotipo y la
 * licencia no los cubre: el panel vuelve al aspecto del producto y el cliente
 * llama pensando que se ha perdido su logotipo. Un `warning` con la explicacion
 * lo resuelve sin llamada.
 *
 * **El nombre no cuenta**. `LicensedBrandingProvider` no lo degrada
 * nunca —encabeza la exportacion para la Inspeccion—, asi que un nombre
 * configurado sin el plan no es una marca que se este dejando de mostrar, y el
 * aviso no puede aconsejar borrarlo. Y el texto distingue **por que** no se
 * aplica: sin el plan hay que ampliarlo; con la licencia caducada, ausente o sin
 * verificar basta con renovarla, y la marca vuelve sola. Hasta la 2.2.1 esta
 * comprobacion se llamaba `license.white_label_without_plan`.
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
        try {
            $overview = $this->licenses->handle();
        } catch (LicenseUnavailable) {
            // Con la base caida no se sabe si hay licencia, y decir
            // «no hay ninguna» mandaba a reactivarla en plena caida. Un
            // `warning` como cualquier otro estado (regla dura 15) y ninguna
            // comprobacion de marca, que tambien depende de la licencia.
            return [DoctorFinding::warning('license.state', 'unknown', details: ['state' => 'unknown'])];
        }

        return [
            $this->state($overview),
            $this->branding($overview->status->availabilityOf(Feature::WhiteLabel)->restriction),
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

    /**
     * @param  FeatureRestriction|null  $restriction  Por que la marca propia no se aplica; `null` si se aplica.
     */
    private function branding(?FeatureRestriction $restriction): DoctorFinding
    {
        $resolved = $this->settings->handle();

        // Solo el aspecto: el nombre se sirve siempre (ver el docblock).
        $configured = $resolved->get(SettingKey::BRANDING_LOGO_PATH)->isProductDefault === false
            || $resolved->get(SettingKey::BRANDING_ACCENT_COLOR)->isProductDefault === false;

        if (! $configured || ! $restriction instanceof FeatureRestriction) {
            return DoctorFinding::ok('license.branding_not_applied', [
                'configured' => $configured,
                'allowed' => ! $restriction instanceof FeatureRestriction,
            ]);
        }

        return DoctorFinding::warning(
            'license.branding_not_applied',
            // Ampliar el plan o renovar: son dos remedios distintos.
            $restriction === FeatureRestriction::NotInPlan ? null : 'license_inactive',
            details: ['configured' => true, 'allowed' => false, 'restriction' => $restriction->value],
        );
    }
}
