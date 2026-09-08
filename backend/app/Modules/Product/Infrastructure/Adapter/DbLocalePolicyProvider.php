<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Adapter;

use App\Modules\Product\Application\UseCase\GetSettingsHandler;
use App\Modules\Product\Domain\ValueObject\SettingKey;
use App\Modules\Shared\Application\Port\LocalePolicyProvider;
use App\Modules\Shared\Domain\ValueObject\LocalePolicy;
use App\Support\Locale\NegotiableLocales;
use Illuminate\Contracts\Config\Repository as Config;
use Throwable;

/**
 * Los idiomas de la instalacion, resueltos desde `installation_settings`
 * (RF-PD-01, RF-PD-08, ADR-017).
 *
 * ## Implementa DOS contratos, y no es un descuido
 *
 * - {@see LocalePolicyProvider}, de `Shared/Application/Port`, es el que usan
 *   los modulos: el endpoint publico de la marca recibe un {@see LocalePolicy}
 *   con su invariante ya garantizada.
 * - {@see NegotiableLocales}, de `App\Support`, es el que usa el middleware que
 *   negocia el idioma de cada respuesta. Vive fuera de los modulos y **no puede
 *   nombrar un tipo de `Shared`** (Deptrac: `AppFramework` no alcanza
 *   `App\Modules\*`), asi que recibe los dos campos sueltos.
 *
 * Son dos formas del mismo hecho pedidas desde dos lados de una frontera
 * arquitectonica. Un adaptador por cada uno duplicaria la consulta, la memoria y
 * el respaldo — y el dia que divergieran, la API diria que ofrece un idioma y
 * las respuestas saldrian en otro.
 *
 * ## El respaldo de configuracion, y por que no es «lo mismo pero peor»
 *
 * Si la lectura falla —base de datos caida, Redis caido, una fila corrupta que
 * rompe la invariante— rige `app.locale` / `app.supported_locales`, es decir,
 * `APP_LOCALE` y `APP_SUPPORTED_LOCALES` del `.env`. **Esto esta en el camino de
 * todas las peticiones**, incluida la que devuelve el error que explica que la
 * base de datos no responde: sin respaldo, una instalacion con PostgreSQL caido
 * daria 500 en `/health` en lugar de decir que le pasa.
 *
 * El respaldo no es la fuente: en cuanto la base de datos responde, manda la
 * fila (decision de la tarea 5.1, y la misma que rige para la marca).
 *
 * ## Memoria por peticion
 *
 * `scoped()`, como el resto de proveedores del modulo: se pregunta una vez por
 * el middleware y otra por el endpoint de marca. Un cambio guardado en el panel
 * se aplica en la peticion siguiente, sin reiniciar nada.
 */
final class DbLocalePolicyProvider implements LocalePolicyProvider, NegotiableLocales
{
    private ?LocalePolicy $policy = null;

    public function __construct(
        private readonly GetSettingsHandler $settings,
        private readonly Config $config,
    ) {}

    public function current(): LocalePolicy
    {
        if ($this->policy instanceof LocalePolicy) {
            return $this->policy;
        }

        try {
            $resolved = $this->settings->handle();

            return $this->policy = new LocalePolicy(
                default: $resolved->text(SettingKey::LOCALE_DEFAULT),
                available: $resolved->textList(SettingKey::LOCALE_AVAILABLE),
            );
        } catch (Throwable) {
            // Sin log a proposito: esto se ejecuta en cada peticion, asi que un
            // aviso aqui serian miles de lineas identicas mientras dure la
            // caida. Lo que si deja rastro es la causa —la propia base de datos
            // caida—, y quien lo mira es `GET /api/v1/ready` y `doctor`.
            return $this->policy = $this->fallback();
        }
    }

    public function defaultLocale(): string
    {
        return $this->current()->default;
    }

    /**
     * @return list<string>
     */
    public function availableLocales(): array
    {
        return $this->current()->available;
    }

    /**
     * Los idiomas del `.env`, saneados hasta cumplir la invariante.
     *
     * El idioma por defecto se **añade** a la lista si no estaba, en vez de
     * fallar: un `.env` con `APP_LOCALE=es` y `APP_SUPPORTED_LOCALES=en` es un
     * descuido de despliegue, y la reaccion util es servir en castellano —lo que
     * pidieron— y no dejar la instalacion sin poder responder.
     */
    private function fallback(): LocalePolicy
    {
        $default = $this->config->get('app.locale');
        $default = \is_string($default) && $default !== '' ? $default : 'es';

        $available = [];

        $configured = $this->config->get('app.supported_locales');

        if (\is_array($configured)) {
            foreach ($configured as $locale) {
                if (\is_string($locale) && $locale !== '' && ! \in_array($locale, $available, true)) {
                    $available[] = $locale;
                }
            }
        }

        if (! \in_array($default, $available, true)) {
            array_unshift($available, $default);
        }

        return new LocalePolicy($default, $available);
    }
}
