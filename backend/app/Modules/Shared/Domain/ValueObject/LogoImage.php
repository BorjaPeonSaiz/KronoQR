<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

use InvalidArgumentException;

/**
 * El logotipo de la instalacion, ya leido y ya comprobado (RF-PD-08, tarea 5.8).
 *
 * **Bytes y formato, nunca una ruta.** Quien recibe esto ya no puede equivocarse
 * de fichero, no puede volver a leer el disco y no puede filtrar donde vive el
 * logotipo del cliente: la ruta se queda en el adaptador que lo leyo. Es lo que
 * permite que `GET /api/v1/branding/logo` sirva la imagen sin que la ruta
 * configurada aparezca en ninguna respuesta ni en ningun log.
 *
 * **Existir es haber pasado la comprobacion.** No hay ningun camino que
 * construya un `LogoImage` con un fichero que no sea PNG o SVG, o que pase de
 * los limites de tamano: `LogoFileInspector` es el unico que lo fabrica. Por eso
 * quien lo tiene delante puede incrustarlo o servirlo sin comprobar nada mas.
 *
 * **Puro y sin I/O.** Vive en `Shared/Domain` y lo consumen la tarjeta impresa
 * (`Identity`), el informe sellado (`Reporting`) y el endpoint publico
 * (`Product`). Ninguno de los tres lee ficheros: reciben esto por el puerto
 * `BrandingLogoReader`, de `Shared/Application/Port` — que este objeto no nombra
 * con un `use`, porque el dominio no depende de la capa de aplicacion.
 */
final readonly class LogoImage
{
    public function __construct(
        public LogoFormat $format,
        /** El contenido del fichero, tal cual. Nunca vacio. */
        public string $bytes,
    ) {
        if ($bytes === '') {
            // Un fichero de cero bytes no es «sin logotipo»: es un logotipo roto,
            // y tratarlos igual esconderia un fallo de copia detras de una
            // tarjeta que sale sin marca y nadie sabe por que.
            throw new InvalidArgumentException('El logotipo de la instalacion no puede tener cero bytes.');
        }
    }

    public function mimeType(): string
    {
        return $this->format->mimeType();
    }

    /**
     * El logotipo incrustado, para los PDF.
     *
     * **Base64 y no una URL, y no es negociable**: el PDF lo dibuja un Chromium
     * sin salida a internet (ADR-016), asi que una referencia externa dejaria la
     * impresion de tarjetas a merced de la red del cliente — y produciria
     * documentos sin logotipo justo el dia de la inauguracion.
     */
    public function dataUri(): string
    {
        return 'data:'.$this->mimeType().';base64,'.base64_encode($this->bytes);
    }

    /**
     * Huella del CONTENIDO, en hexadecimal.
     *
     * Es lo que hace cacheable el logotipo para siempre: la URL que publica
     * `GET /api/v1/branding` lleva los doce primeros digitos, asi que cambiar el
     * fichero cambia la URL y la copia vieja puede quedarse en la cache del
     * navegador —y en la del service worker del quiosco— sin que nadie llegue a
     * verla.
     */
    public function sha256(): string
    {
        return hash('sha256', $this->bytes);
    }

    /**
     * Los doce primeros digitos de la huella, que es lo que viaja en `?v=`.
     *
     * Doce y no sesenta y cuatro porque la URL la lee una persona cuando algo va
     * mal, y porque esto no es un control de integridad: es un discriminante de
     * cache. El contrato lo fija con `^[0-9a-f]{12}$`.
     */
    public function cacheTag(): string
    {
        return substr($this->sha256(), 0, 12);
    }
}
