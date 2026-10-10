<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Adapter;

use App\Modules\Shared\Application\Port\DeployedVersionProvider;
use Illuminate\Contracts\Config\Repository;

/**
 * Lee `config('app.version')`, que `App\Support\Version\DeployedVersion` ya
 * dejo resuelta al cargar la configuracion: entorno (`APP_VERSION`,
 * `IMAGE_TAG`), fichero `VERSION` o `0.0.0`.
 *
 * **Se lee en cada llamada, no en el constructor**: el adaptador es un
 * singleton y una prueba que fija la version con `config()->set()` tiene que
 * verla. Leer un valor de configuracion ya cargado no cuesta nada.
 */
final readonly class ConfigDeployedVersionProvider implements DeployedVersionProvider
{
    /**
     * El mismo «no se» que `DeployedVersion::UNKNOWN`. Se repite el literal
     * porque esta capa no puede importar `App\Support` (Deptrac).
     */
    private const string UNKNOWN = '0.0.0';

    public function __construct(private Repository $config) {}

    #[\Override]
    public function deployedVersion(): string
    {
        $version = $this->config->get('app.version');

        // Nunca vacia (contrato del puerto). Un valor que no sea texto solo
        // puede venir de una configuracion manipulada a mano; se trata como el
        // «no se» de siempre en vez de reventar la salud de los quioscos.
        return \is_string($version) && trim($version) !== '' ? trim($version) : self::UNKNOWN;
    }
}
