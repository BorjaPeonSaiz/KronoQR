<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure\Adapter;

use App\Modules\Workforce\Application\Port\PinPolicy;
use App\Modules\Workforce\Application\Port\PinPolicyProvider;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * La politica de PIN, leida de la configuracion de la instalacion
 * (`identity.pin`, RF-ID-09, regla dura 13).
 *
 * **Aqui, y no en el generador.** El caso de uso recibe la politica ya resuelta
 * y no sabe de donde sale; este adaptador es el unico sitio del modulo que
 * conoce el nombre de una clave de configuracion. Es lo que permite endurecer la
 * lista para un cliente sin tocar el repositorio y probar el generador sin
 * montar `config()`.
 *
 * **Sin lista de serie escrita aqui.** Los valores por defecto viven en
 * `config/identity.php`, que es configuracion versionada del producto. Si la
 * clave desapareciera, esta clase no inventa una lista: entrega la que hay, y
 * una lista vacia se ve en la prueba que comprueba que los patrones triviales se
 * rechazan.
 */
final readonly class ConfiguredPinPolicyProvider implements PinPolicyProvider
{
    public function __construct(private Config $config) {}

    public function policy(): PinPolicy
    {
        /** @var list<string> $forbidden */
        $forbidden = array_values(array_filter(
            (array) $this->config->get('identity.pin.forbidden', []),
            static fn (mixed $pin): bool => \is_string($pin),
        ));

        return new PinPolicy($forbidden);
    }
}
