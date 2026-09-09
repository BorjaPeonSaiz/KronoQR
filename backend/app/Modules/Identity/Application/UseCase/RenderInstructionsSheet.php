<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\UseCase;

use App\Modules\Identity\Application\Port\InstructionsSheetRenderer;
use App\Modules\Identity\Application\Port\PortalAddressProvider;
use App\Modules\Identity\Application\Support\CredentialTelemetry;
use App\Modules\Shared\Application\Port\BrandingProvider;
use App\Modules\Shared\Application\Port\LocalePolicyProvider;

/**
 * **La hoja que se entrega con la tarjeta** (tarea 5.11b, **RL-05**, RF-QR-06,
 * ficha 5.11b decision 1).
 *
 * ## Que hace, y por que es tan poco
 *
 * Resuelve tres cosas —idioma, marca y direccion del portal— y se las pasa al
 * dibujante. No hay mas, y es deliberado: la hoja **es la misma para toda la
 * plantilla**, no lleva ningun dato personal ni ningun secreto y no cambia el
 * estado de nada.
 *
 * De ahi las tres ausencias que llaman la atencion frente al resto de casos de
 * uso del modulo:
 *
 * - **Sin transaccion**, porque no escribe.
 * - **Sin asiento en `audit_log`**, porque no hay acto con relevancia legal que
 *   registrar: descargar este PDF no dice nada de nadie. Lo que si se audita es
 *   la ENTREGA de la credencial (`credential.delivered`), que es el acto en el
 *   que esta hoja cambia de manos (tarea 1.10).
 * - **Sin evento de dominio**, por lo mismo.
 *
 * Si alguna vez llevara el nombre de alguien, las tres ausencias dejarian de
 * estar justificadas a la vez.
 *
 * ## El idioma
 *
 * El que se pida, si es uno de los **activos de la instalacion**
 * (`LOCALE_AVAILABLE`); si no se pide ninguno, `LOCALE_DEFAULT`. El `422` del
 * idioma que no esta activo lo da el `FormRequest`, que es donde hay una
 * peticion a la que responder; aqui la comprobacion se repite porque este caso
 * de uso tambien es alcanzable desde el contenedor y una hoja en un idioma que
 * la instalacion no ofrece es una hoja con frases sin traducir.
 *
 * ## La direccion del portal
 *
 * Llega por {@see PortalAddressProvider}, resuelta en `Infrastructure`. Es lo
 * unico de la hoja que no puede saber un PDF estatico del paquete de
 * documentacion, y la razon por la que este endpoint existe.
 */
final readonly class RenderInstructionsSheet
{
    public function __construct(
        private InstructionsSheetRenderer $renderer,
        private BrandingProvider $branding,
        private LocalePolicyProvider $locales,
        private PortalAddressProvider $portal,
        private CredentialTelemetry $telemetry,
    ) {}

    public function handle(?string $requestedLocale = null): InstructionsSheet
    {
        $locale = $this->resolveLocale($requestedLocale);
        $portalUrl = $this->portal->current();

        return $this->telemetry->measure(
            'identity.instructions_sheet',
            // Ningun dato de persona, y ninguno posible: lo que se mide es el
            // idioma del documento (regla dura 21).
            ['locale' => $locale],
            fn (): InstructionsSheet => new InstructionsSheet(
                pdf: $this->renderer->render($locale, $this->branding->current(), $portalUrl),
                locale: $locale,
            ),
        );
    }

    /**
     * @return non-empty-string
     */
    private function resolveLocale(?string $requested): string
    {
        $policy = $this->locales->current();

        $default = $policy->default !== '' ? $policy->default : 'es';

        if ($requested === null || ! \in_array($requested, $policy->available, true)) {
            return $default;
        }

        return $requested !== '' ? $requested : $default;
    }
}
