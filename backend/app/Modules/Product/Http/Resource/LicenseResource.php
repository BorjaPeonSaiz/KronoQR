<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Resource;

use App\Modules\Product\Domain\ValueObject\License;
use App\Modules\Product\Domain\ValueObject\LicenseOverview;
use App\Modules\Product\Domain\ValueObject\PlanUsage;
use App\Modules\Product\Domain\ValueObject\StoredLicense;
use App\Modules\Shared\Domain\ValueObject\Feature;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializa el `200` de `GET /api/v1/license` y de
 * `POST /api/v1/license/activate`: el esquema `License` del contrato
 * (RF-PD-04, RF-PD-05, ADR-028).
 *
 * ## Los dos endpoints devuelven lo mismo, y entero
 *
 * Activar responde el estado completo resultante, con el mismo criterio que la
 * configuracion y el perfil de cumplimiento: asi el panel no recompone el estado
 * a partir de lo que envio —que es donde nacen las pantallas que enseñan un
 * valor y guardan otro— y lo que se pinta es lo que quedo escrito.
 *
 * ## Degradacion honesta, servida
 *
 * `degraded_features` no es una lista de nombres: cada entrada dice **que**
 * funcionalidad, **por que** y **desde cuando**, que son las tres cosas que
 * ADR-019 exige de una degradacion honesta. El «que hacer» lo pone el panel en
 * su idioma; el servidor da los hechos.
 *
 * `implemented` distingue lo que se apagara de verdad hoy de lo que se apagara
 * cuando exista (las funcionalidades de la Fase 3, la marca blanca de la 5.8 y
 * la telemetria de la 5.10). Sin ese matiz, la pantalla le anunciaria al cliente
 * la perdida de cuatro cosas que todavia no ha visto nunca.
 *
 * ## Lo que nunca sale de aqui
 *
 * **La clave firmada.** Sale su huella corta, que es lo que sirve para
 * confirmar por telefono que la clave activada es la que se envio. Y **no sale
 * ningun dato de empleado**: las cifras de plantilla son recuentos (regla dura
 * 21).
 *
 * @property-read LicenseOverview $resource
 */
final class LicenseResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var LicenseOverview $overview */
        $overview = $this->resource;
        $status = $overview->status;
        $license = $status->license;

        return [
            'data' => [
                'state' => $status->state->value,
                'severity' => $overview->severity()->value,
                // Solo cuando el estado es `unverifiable`. Es lo que distingue
                // «vuelve a copiar la clave» de «pide una nueva» de «revisa el
                // despliegue», y por eso viaja.
                'rejection_reason' => $status->rejection?->value,
                'customer_name' => $license?->customerName,
                'plan' => $license?->plan,
                'license_id' => $license?->licenseId,
                'valid_from' => self::instant($license?->validFrom),
                'valid_until' => self::instant($license?->validUntil),
                'issued_at' => self::instant($license?->issuedAt),
                'days_until_expiry' => $status->daysUntilExpiry(),
                'days_since_expiry' => $status->daysSinceExpiry(),
                'features' => $license instanceof License ? $license->featureNames() : [],
                'degraded_features' => array_map(
                    static function (Feature $feature) use ($status): array {
                        $availability = $status->availabilityOf($feature);

                        return [
                            'feature' => $feature->value,
                            'restriction' => $availability->restriction?->value,
                            'since' => self::instant($availability->since),
                            'implemented' => $feature->isImplemented(),
                        ];
                    },
                    $status->degradedFeatures(),
                ),
                'limits' => array_map(
                    static fn (PlanUsage $usage): array => [
                        'limit' => $usage->limit->value,
                        'contracted' => $usage->contracted,
                        'actual' => $usage->actual,
                        'exceeded' => $usage->isExceeded(),
                        'excess' => $usage->excess(),
                    ],
                    $overview->usage,
                ),
                'activated_at' => self::instant($overview->stored?->activatedAt),
                'last_verified_at' => self::instant($overview->stored?->lastVerifiedAt),
                // La huella, nunca la clave.
                'key_fingerprint' => $overview->stored instanceof StoredLicense
                    ? $overview->stored->fingerprint()
                    : null,
            ],
            'meta' => [
                // Con cuanta antelacion avisa esta instalacion. Viaja para que
                // el panel pueda decir «avisamos 30 dias antes» sin llevar el
                // numero compilado dentro (ADR-017, regla dura 13).
                'expiry_warning_days' => $status->expiryWarningDays,
                'needs_notice' => $overview->needsNotice(),
                // El instante con el que se calculo el estado, que es el del
                // servidor y no el del navegador (regla dura 3).
                'evaluated_at' => (string) self::instant($status->evaluatedAt),
            ],
        ];
    }

    private static function instant(?\DateTimeImmutable $instant): ?string
    {
        return $instant?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
