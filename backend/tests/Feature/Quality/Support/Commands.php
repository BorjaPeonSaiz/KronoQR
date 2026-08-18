<?php

declare(strict_types=1);

namespace Tests\Feature\Quality\Support;

use Illuminate\Support\Facades\Artisan;

/**
 * Ejecuta un comando de calidad y devuelve lo unico que importa de el: su codigo
 * de salida y lo que ha escrito.
 *
 * Se usa `Artisan::call()` y no el `$this->artisan()` fluido de Laravel porque
 * este ultimo no tiene tipo dentro de las clausuras de Pest y PHPStan 9 no lo
 * resuelve. Suprimir el error habria sido esconder que la prueba no esta
 * tipada; esta forma esta tipada de verdad y ademas se lee mejor, porque el
 * codigo de salida es EL sujeto de estas pruebas: lo que decide si la CI
 * bloquea.
 */
final class Commands
{
    /**
     * @return array{0: int, 1: string} Codigo de salida y salida del comando.
     */
    public static function run(string $command): array
    {
        $exitCode = Artisan::call($command);

        return [$exitCode, Artisan::output()];
    }
}
