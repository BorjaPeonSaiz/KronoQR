<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Base de toda la semilla: es de **desarrollo** y se niega a correr en
 * produccion (SC7-03).
 *
 * POR QUE. `UserSeeder` crea cuentas `admin`, `rrhh` y `auditor` con una
 * contraseña publicada en el README, y el resto siembra plantilla y fichajes
 * inventados. En la instalacion de un cliente, cualquiera de las dos cosas
 * es un incidente: una puerta trasera en el panel o un registro horario con
 * valor legal mezclado con datos falsos. Nada de lo que el producto necesita
 * para arrancar vive aqui —el catalogo de roles, el perfil de cumplimiento y
 * los umbrales los siembran sus migraciones—, asi que no hay ninguna salida
 * legitima en produccion y la guarda no tiene escape.
 *
 * DOS CERRADURAS, Y ESTA ES LA SEGUNDA. La primera es que la imagen de
 * produccion no lleva `database/seeders/` (`.dockerignore` y la comprobacion de
 * la etapa `vendor` del Dockerfile de la aplicacion; `SeedersOutOfProductionImageTest`).
 * Esta cubre el caso en que la primera falle o alguien siembre desde un arbol
 * de codigo con `APP_ENV=production`. `db:seed` ya pide `--force` en
 * produccion, pero `--force` es justo lo que se escribe sin pensar.
 *
 * La comprobacion va en `__invoke` y no solo en `DatabaseSeeder::run()` porque
 * `__invoke` es lo que ejecutan tanto `db:seed` como `db:seed --class=...` y
 * `$this->call(...)`: asi ninguna semilla suelta escribe una fila en
 * produccion, tampoco `UserSeeder` llamado a mano.
 *
 * **Falla cerrada**: si no se puede saber el entorno, se trata como produccion.
 */
abstract class DevelopmentSeeder extends Seeder
{
    /**
     * @param  array<array-key, mixed>  $parameters
     */
    #[\Override]
    public function __invoke(array $parameters = []): mixed
    {
        $this->refuseInProduction();

        return parent::__invoke($parameters);
    }

    /**
     * Lanza antes de tocar la base de datos si el entorno es produccion o no
     * se puede determinar.
     */
    protected function refuseInProduction(): void
    {
        $environment = $this->environment();

        // Lista de permitidos y no de prohibidos (revision de seguridad del
        // bloque 14): un APP_ENV inesperado —`staging`, `prod`, uno mal
        // escrito— tambien se niega, igual que un entorno que no se sabe.
        if (! in_array($environment, ['local', 'testing'], true)) {
            throw new RuntimeException(
                'The development seeders refuse to run in production: they create demo accounts with a published password and invented attendance records.',
            );
        }
    }

    private function environment(): ?string
    {
        // La propiedad de `Seeder` no tiene tipo y queda en null hasta que
        // alguien llama a `setContainer()`: una semilla construida a mano no
        // tiene contenedor, y entonces no hay entorno que leer.
        /** @var Container|null $container */
        $container = $this->container;

        if ($container === null || ! $container->bound('env')) {
            return null;
        }

        $environment = $container->make('env');

        return is_string($environment) ? $environment : null;
    }
}
