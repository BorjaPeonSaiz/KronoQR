<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Adapter;

use App\Modules\Product\Application\UseCase\GetSettingsHandler;
use App\Modules\Product\Domain\ValueObject\SettingKey;
use App\Modules\Shared\Application\Port\BrandingProvider;
use App\Modules\Shared\Domain\ValueObject\Branding;

/**
 * La marca de la instalacion, resuelta desde `installation_settings`
 * (RF-PD-08, RF-PD-01, ADR-025).
 *
 * Adaptador del puerto {@see BrandingProvider}, que vive en `Shared` porque lo
 * consumen varios modulos —la tarjeta de `Identity`, los documentos de
 * `Compliance`, las tres SPA a traves de `Product`— y no es regla de negocio de
 * ninguno. Su implementacion esta aqui porque aqui estan las tablas.
 *
 * ## Nunca falla y nunca devuelve nada vacio
 *
 * Sin ninguna fila, el catalogo entrega la marca del **producto**: `KronoQR`,
 * sin logotipo y con el gris del sistema visual del doc 06. Una instalacion
 * recien puesta en marcha tiene que poder imprimir tarjetas, y el valor por
 * defecto **es** el producto — nunca la marca de otro cliente (regla dura 13).
 *
 * ## La ruta vacia significa «el logotipo del producto», y por eso se traduce
 *
 * El catalogo guarda `''` porque una clave de texto no admite `null` en JSONB
 * sin ambiguedad; {@see Branding} exige `null` porque una ruta en blanco no es
 * «sin logotipo», es una ruta mal construida que acabaria buscando un fichero en
 * el directorio actual. La traduccion entre las dos formas ocurre aqui, en el
 * borde, y no en los cuatro sitios que dibujan algo.
 *
 * **Que el fichero exista no se comprueba**: nadie se queda sin poder fichar
 * porque falte una imagen, y quien dibuja ya sabe seguir sin ella. Quien si lo
 * comprueba es `doctor` (tarea 5.9), que es donde un aviso sirve para algo.
 *
 * ## Tolerante, como todo lo que lee configuracion
 *
 * Si la fila del color estuviera corrupta, rige el color de serie y la marca se
 * sirve igual: nadie se queda sin imprimir una tarjeta —ni sin fichar— por un
 * `#rrggbb` mal escrito. El descarte se anuncia por el puerto de anomalias y
 * viaja en `meta.invalid_keys` de `GET /api/v1/settings`.
 *
 * **Y solo se toman las tres claves que se consumen**: del conjunto resuelto
 * salen `BRANDING_*` y nada mas.
 *
 * ## Memoria por peticion
 *
 * La marca se pide una vez por documento y varias veces por pantalla. La cache
 * de {@see CachedSettingsRepository} ya evita la consulta; esto evita ademas
 * resolver la cascada en cada llamada. Un cambio desde el panel se ve en la
 * peticion siguiente.
 */
final class DbBrandingProvider implements BrandingProvider
{
    private ?Branding $branding = null;

    public function __construct(private readonly GetSettingsHandler $settings) {}

    public function current(): Branding
    {
        if ($this->branding instanceof Branding) {
            return $this->branding;
        }

        $resolved = $this->settings->handle();

        $logoPath = trim($resolved->text(SettingKey::BRANDING_LOGO_PATH));

        return $this->branding = new Branding(
            applicationName: $resolved->text(SettingKey::BRANDING_APP_NAME),
            logoPath: $logoPath === '' ? null : $logoPath,
            accentColor: $resolved->text(SettingKey::BRANDING_ACCENT_COLOR),
        );
    }
}
