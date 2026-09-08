<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Adapter;

use App\Modules\Product\Domain\ValueObject\SettingKey;
use App\Modules\Product\Domain\ValueObject\SettingValue;
use App\Modules\Shared\Application\Port\BrandingProvider;
use App\Modules\Shared\Application\Port\FeatureGate;
use App\Modules\Shared\Domain\ValueObject\Branding;
use App\Modules\Shared\Domain\ValueObject\Feature;

/**
 * El **aspecto** del cliente solo si su plan lo incluye; su **nombre**, siempre
 * (ADR-023, RF-PD-05, RF-PD-08).
 *
 * ## Por que la decision vive AQUI y no en cada consumidor
 *
 * Porque los consumidores son cuatro y crecen: la tarjeta impresa, el informe
 * sellado, la exportacion para la Inspeccion y el endpoint publico que pintan
 * las tres SPA. ADR-023 advierte exactamente de este riesgo —«que cada quien
 * decida en el sitio con un `if (license.expired)`»— y la unica defensa que
 * funciona es que **no haya nada que decidir en el sitio**: quien pide la marca
 * recibe la que corresponde y no se entera de que existe una licencia.
 *
 * Como decorador y no dentro de {@see DbBrandingProvider} para que las dos
 * responsabilidades sigan separadas: aquel resuelve la cascada de configuracion
 * y este aplica el plan. Quitar el gating el dia que la marca deje de ser
 * licenciable es retirar un enlace del contenedor, no editar una consulta.
 *
 * Y **se decora `BrandingProvider` y no el lector del logotipo**, aunque los dos
 * se degraden: `LocalBrandingLogoReader` recibe la ruta de este mismo puerto, asi
 * que al devolverla en `null` el logotipo desaparece solo. Un segundo decorador
 * seria un segundo sitio donde equivocarse.
 *
 * ## EL NOMBRE NO SE DEGRADA NUNCA, y esto es lo importante de esta clase
 *
 * Se degradan **el color de acento y el logotipo**, que son aspecto. El
 * `applicationName` **se respeta siempre**, tenga el cliente el plan que tenga y
 * aunque la licencia no se pueda verificar.
 *
 * La razon no es comercial, es legal. Ese nombre es lo que encabeza la
 * **exportacion normalizada para la Inspeccion de Trabajo** (RL-06, RF-IN-05) y
 * el **informe sellado** (RL-03): es la linea que dice DE QUIEN es el registro
 * horario que alguien tiene delante. Degradarlo significaria que un documento con
 * valor probatorio identifica peor al obligado por un motivo comercial —o, peor,
 * por un fallo transitorio de verificacion (`LicenseUnverifiable`), que es un
 * Redis caido—. Dos exportaciones del mismo mes podrian salir con encabezados
 * distintos sin que hubiera cambiado ningun dato, y explicarle eso a un inspector
 * es exactamente la situacion que la regla dura 15 existe para evitar.
 *
 * Un color y un logotipo no identifican a nadie: son «look and feel», se pueden
 * apagar sin tocar el valor del documento, y es lo que ADR-023 vende. El nombre
 * no es «look and feel».
 *
 * ## Que ve el cliente sin el plan, entonces
 *
 * Su nombre, el acento de serie y ningun logotipo. En consecuencia
 * `GET /api/v1/branding` publica `accent_color: null` y `logo_url: null`,
 * `GET /api/v1/branding/logo` responde `404`, y los tres documentos salen con el
 * nombre del cliente y sin imagen.
 *
 * **Los idiomas no pasan por aqui.** `LOCALE_DEFAULT` y `LOCALE_AVAILABLE` no
 * son marca: una instalacion cuya plantilla trabaja en ingles no puede quedarse
 * sin su idioma porque venza un plan. Van por `LocalePolicyProvider`, que no se
 * decora.
 *
 * **Y la configuracion no se cierra** (regla dura 15, ADR-019).
 * `GET` y `PATCH /api/v1/settings` siguen enseñando y aceptando las tres claves
 * `BRANDING_*` con normalidad, y las filas guardadas **no se tocan ni se borran**
 * (regla dura 5): se guardan, se auditan y **vuelven a aplicarse solas** en
 * cuanto la licencia las cubra. El cliente que renueva no tiene que volver a
 * configurar nada, y el que esta evaluando el producto puede dejar su marca
 * lista antes de comprarla.
 *
 * Donde se dice que la funcionalidad no esta en el plan es donde ya se decia:
 * `GET /api/v1/license` y `php artisan license:show`, que enumeran las
 * funcionalidades con su disponibilidad y su motivo.
 *
 * ## Denegar por error degrada; conceder por error regala
 *
 * {@see LicensedFeatureGate} responde «denegado» si no consigue resolver el
 * estado de la licencia. Con el nombre fuera del gating, eso ya solo significa
 * unos colores de serie mientras dure la averia — reversible y sin consecuencia
 * documental. **Nada de esto toca el registro**: nadie deja de fichar, ningun
 * documento deja de emitirse y la exportacion para la Inspeccion se genera igual,
 * con el mismo contenido y con el mismo encabezado.
 */
final class LicensedBrandingProvider implements BrandingProvider
{
    private ?Branding $branding = null;

    public function __construct(
        private readonly BrandingProvider $configured,
        private readonly FeatureGate $features,
    ) {}

    public function current(): Branding
    {
        if ($this->branding instanceof Branding) {
            return $this->branding;
        }

        $configured = $this->configured->current();

        if ($this->features->isEnabled(Feature::WhiteLabel)) {
            return $this->branding = $configured;
        }

        return $this->branding = new Branding(
            // El nombre del cliente, intacto. Ver el docblock: encabeza documentos
            // con valor probatorio y no es aspecto.
            applicationName: $configured->applicationName,
            // Sin logotipo. `LocalBrandingLogoReader` lee esta ruta, asi que con
            // `null` el endpoint publico responde 404 y los PDF salen sin imagen
            // sin que ninguno de los dos sepa que existe una licencia.
            logoPath: null,
            accentColor: self::productAccent(),
        );
    }

    /**
     * El acento de serie, tomado del **catalogo** y no escrito aqui.
     *
     * Es la misma fuente que rige cuando no hay ninguna fila guardada
     * (`SettingKey`), asi que «sin licencia» y «sin color configurado» se ven
     * exactamente igual. Con el valor copiado en esta clase, cambiar el color de
     * serie del producto dejaria dos acentos de fabricante distintos segun el
     * estado de la licencia.
     */
    private static function productAccent(): string
    {
        return SettingValue::productDefault(SettingKey::BRANDING_ACCENT_COLOR)->asText();
    }
}
