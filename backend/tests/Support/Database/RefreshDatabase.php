<?php

declare(strict_types=1);

namespace Tests\Support\Database;

use Illuminate\Foundation\Testing\RefreshDatabase as FrameworkRefreshDatabase;

/**
 * `RefreshDatabase` de Laravel, migrando con el rol de MIGRACION.
 *
 * **Por que hace falta.** Desde la tarea 1.14 hay dos roles: `fichaje_app`, que
 * es el de runtime y **no tiene DDL** (regla dura 6), y `fichaje_migrator`, que
 * es el propietario y el unico que puede crear tablas. `migrate:fresh` sobre la
 * conexion por defecto fallaria — y debe fallar: es la garantia funcionando.
 *
 * Lo importante es lo que **no** cambia: la conexion por defecto de las pruebas
 * sigue siendo la de la aplicacion. Las pruebas escriben, leen y chocan con las
 * restricciones **con el rol con el que corre el producto**, que es la unica
 * forma de que una prueba de integracion diga algo sobre permisos. Solo el
 * `migrate:fresh` inicial usa el otro rol.
 *
 * `migrateFreshUsing()` se redefine entera en lugar de delegar en la del
 * framework: es corta, y una alias de trait para añadir una clave se lee peor
 * que la lista completa.
 */
/** @phpstan-ignore trait.unused (Pest lo compone con uses() en el fichero de prueba, no con un `use` dentro de una clase: PHPStan no puede ver esa composicion y lo da por muerto.) */
trait RefreshDatabase
{
    use FrameworkRefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing(): array
    {
        $seeder = $this->seeder();

        return array_merge(
            [
                '--database' => config()->string('database.migrations.connection'),
                '--drop-views' => $this->shouldDropViews(),
                '--drop-types' => $this->shouldDropTypes(),
            ],
            $seeder ? ['--seeder' => $seeder] : ['--seed' => $this->shouldSeed()],
        );
    }
}
