<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\UseCase;

use App\Modules\Product\Domain\ValueObject\SettingKey;
use App\Modules\Product\Domain\ValueObject\SettingValue;
use App\Modules\Shared\Application\Port\BrandingLogoReader;
use App\Modules\Shared\Application\Port\BrandingProvider;
use App\Modules\Shared\Application\Port\LocalePolicyProvider;
use App\Modules\Shared\Domain\ValueObject\LocalePolicy;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Compone la marca que reciben las tres aplicaciones (**RF-PD-08**).
 *
 * ## Recibe la marca YA RESUELTA, y eso no es un detalle
 *
 * Este caso de uso **no lee `installation_settings`**: pide
 * {@see BrandingProvider}, que es el mismo puerto que consumen la tarjeta
 * impresa, el informe sellado y la exportacion legal. Se escribio primero al
 * reves —leyendo el catalogo aqui para poder distinguir el color de serie— y
 * eso abrio un agujero real: el endpoint publico se saltaba el decorador que
 * aplica el plan (ADR-023) y seguia publicando la marca del cliente con la
 * licencia vencida, mientras los PDF ya salian con la del producto. **Dos
 * caminos hacia el mismo dato son dos respuestas distintas**, que es
 * literalmente lo que ADR-023 previene.
 *
 * Con el puerto, el gating se aplica en un unico sitio y aqui no hay nada que
 * recordar.
 *
 * ## Sigue siendo una lista blanca
 *
 * De `installation_settings` salen por aqui **cinco claves y ninguna mas**: las
 * tres `BRANDING_*` que trae el puerto y las dos `LOCALE_*` que trae
 * {@see LocalePolicyProvider}. Y ahora ni siquiera hace falta disciplina para
 * mantenerlo: este objeto **no tiene forma de ver** el resto del catalogo, asi
 * que una clave nueva no puede filtrarse a una respuesta publica ni por
 * descuido.
 *
 * Lo que si sale es el nombre del hotel y su color, que es exactamente lo que
 * revela la tarjeta impresa que cada empleado lleva en el bolsillo.
 *
 * ## El color del producto viaja como `null`
 *
 * Y no como su valor. La diferencia importa en el otro lado: con `null` las SPA
 * no tocan ningun token y el sistema visual del doc 06 queda intacto; con un
 * color, derivan tonos y comprueban contrastes.
 *
 * **Se compara con el valor de serie del catalogo**, en vez de preguntar de
 * donde sale la fila. Es lo que hace que «no lo ha configurado» y «su plan no
 * incluye la marca blanca» se vean exactamente igual desde fuera —que es lo que
 * ADR-023 promete— sin que este caso de uso tenga que enterarse de que existe
 * una licencia. La comparacion es **insensible a mayusculas** porque el catalogo
 * admite `#B8542A` tanto como `#b8542a`: sin eso, escribir el color de serie en
 * mayusculas se publicaria como un color «propio» y las SPA derivarian tonos
 * sobre el.
 *
 * ## El logotipo llega ya leido y ya comprobado
 *
 * Por {@see BrandingLogoReader}, que nunca lanza: sin logotipo utilizable,
 * `null`. **La ruta configurada no sale de la aplicacion**, ni en la respuesta
 * ni en un error.
 *
 * ## Y esto NO PUEDE FALLAR
 *
 * `GET /api/v1/branding` lo piden el quiosco y el portal **antes de identificar
 * a nadie**, y es lo primero que pasa al abrir la tablet por la mañana. Un `500`
 * aqui —una base de datos que no responde, Redis caido, una fila ilegible— seria
 * una pantalla de espera rota justo cuando entra el turno. Asi que cualquier
 * fallo se traduce en **la marca del producto** y un aviso en el log, que es la
 * misma tolerancia que ya aplican `DbLocalePolicyProvider` y
 * `LicensedFeatureGate`. Nadie se queda sin fichar por no poder pintar un color.
 */
final readonly class GetBrandingHandler
{
    public function __construct(
        private BrandingProvider $branding,
        private LocalePolicyProvider $locales,
        private BrandingLogoReader $logos,
        private LoggerInterface $logger,
    ) {}

    public function handle(): InstallationBranding
    {
        try {
            $branding = $this->branding->current();

            return new InstallationBranding(
                applicationName: $branding->applicationName,
                accentColor: self::isProductAccent($branding->accentColor) ? null : $branding->accentColor,
                logo: $this->logos->current(),
                locales: $this->locales->current(),
            );
        } catch (Throwable $failure) {
            // Sin PII y sin valores: la clase de la excepcion y nada mas (regla
            // dura 21). Este log viaja al paquete de diagnostico, que sale de la
            // instalacion (ADR-020).
            $this->logger->warning('product.branding_unresolved', [
                'reason' => $failure::class,
            ]);

            return self::productBranding();
        }
    }

    /**
     * La marca del producto, tomada del catalogo, para cuando no se puede leer
     * nada.
     *
     * `accentColor` va en `null` —no en el valor de serie— por lo mismo que en el
     * camino normal: significa «no toques ningun token», que es exactamente lo
     * que hay que hacer cuando no se sabe lo que el cliente configuro.
     */
    private static function productBranding(): InstallationBranding
    {
        return new InstallationBranding(
            applicationName: SettingValue::productDefault(SettingKey::BRANDING_APP_NAME)->asText(),
            accentColor: null,
            logo: null,
            locales: new LocalePolicy(
                default: SettingValue::productDefault(SettingKey::LOCALE_DEFAULT)->asText(),
                available: SettingValue::productDefault(SettingKey::LOCALE_AVAILABLE)->asTextList(),
            ),
        );
    }

    /**
     * Si ese color es el de serie, mirando el valor y no de donde viene.
     *
     * El valor de serie sale del catalogo y no esta escrito aqui: con una copia,
     * cambiar el color del producto obligaria a acordarse de este fichero, y el
     * sintoma de no hacerlo seria que todas las instalaciones empezarian a
     * derivar tonos sobre el color nuevo.
     */
    private static function isProductAccent(string $accent): bool
    {
        $product = SettingValue::productDefault(SettingKey::BRANDING_ACCENT_COLOR)->asText();

        return strtolower($accent) === strtolower($product);
    }
}
