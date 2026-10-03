<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

use InvalidArgumentException;

/**
 * La marca de la instalacion, ya resuelta (RF-PD-08, RF-PD-01).
 *
 * La consumen quienes dibujan algo que ve una persona: las tarjetas de
 * credencial y los informes sellados de `Identity` y `Compliance`, la
 * exportacion legal, y las tres SPA a traves del endpoint de marca de `Product`
 * (tarea 5.8). Ninguno de ellos lee `installation_settings`: reciben esto por el
 * puerto `BrandingProvider`, que vive en `Shared/Application/Port/`. El dominio
 * no lo nombra con un `use`: recibe la marca ya construida.
 *
 * **Vive en `Shared` y no en `Product`** por el criterio de admision de ADR-021
 * y ADR-025: lo consumen varios modulos, no es regla de negocio de ninguno, y lo
 * implementa quien tiene el dato.
 *
 * **No hay marca por defecto aqui.** El valor de serie es del catalogo de claves
 * (`SettingKey`), donde el cliente puede cambiarlo sin desplegar nada; escribir
 * el nombre del producto en este objeto de valor lo convertiria en un valor de
 * respaldo escondido, indistinguible de uno configurado.
 */
final readonly class Branding
{
    public function __construct(
        /** Lo que se lee en pantalla y en la cabecera de los PDF. Nunca vacio: sin marca configurada, es el nombre del producto. */
        public string $applicationName,
        /**
         * Ruta del logotipo en el servidor del cliente, o `null` si no hay.
         *
         * Del sistema de ficheros y no una URL: el PDF se genera en un Chromium
         * sin salida a internet (ADR-016). Que el fichero exista **no** se
         * comprueba aqui: nadie se queda sin poder fichar porque falte una
         * imagen, asi que quien dibuja se lo salta y sigue.
         */
        public ?string $logoPath,
        /** Color de acento en notacion CSS de seis digitos (`#0f172a`). */
        public string $accentColor,
        /**
         * El aviso de privacidad del quiosco (RF-KI-09, RL-09, 2.2.0). **No es
         * aspecto**: es informacion que el art. 13 RGPD obliga a dar, y por eso
         * nunca se degrada con la licencia (ADR-019). De serie, la redaccion
         * generica.
         */
        public PrivacyNotice $privacyNotice = new PrivacyNotice,
    ) {
        if (trim($applicationName) === '') {
            throw new InvalidArgumentException('La marca de la instalacion no puede tener el nombre de aplicacion vacio.');
        }

        if ($logoPath !== null && trim($logoPath) === '') {
            // Una ruta en blanco no es «sin logotipo»: es una ruta mal
            // construida que acabaria buscando el fichero del directorio actual.
            throw new InvalidArgumentException('La ruta del logotipo es una cadena en blanco; para no tener logotipo se usa null.');
        }

        if (preg_match('/^#[0-9a-fA-F]{6}$/', $accentColor) !== 1) {
            throw new InvalidArgumentException('El color de acento de la marca debe ser #rrggbb, y es «'.$accentColor.'».');
        }
    }

    /**
     * El acento tal como se imprime en texto sobre papel blanco (MB3, DC3).
     *
     * El color del cliente, oscurecido lo justo para leerse como texto normal
     * (4,5:1, WCAG 1.4.3) sobre blanco; si ya llega, sale intacto. Lo usan los
     * escritores de los PDF sellados para el nombre de la cabecera: el panel
     * oscurece igual en pantalla, pero un PDF no pasa por ningun navegador y un
     * amarillo palido como texto de 10 pt quedaria a 1,3:1 en un documento con
     * valor probatorio.
     *
     * **No sustituye a {@see self::$accentColor}**: el filete decorativo de la
     * tarjeta lleva el color tal cual, porque ahi no hay nada que leer.
     */
    public function accentForTextOnWhite(): string
    {
        return HexColor::fromHex($this->accentColor)
            ->darkenedUntil(HexColor::white(), HexColor::WCAG_TEXT_MINIMUM)
            ->toHex();
    }
}
