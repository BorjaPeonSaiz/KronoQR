<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Console;

use Illuminate\Console\Command;

/**
 * La salida de una orden de consola en el idioma de la instalacion.
 *
 * Quien lee estas ordenes es el operador del hotel —la purga, las credenciales,
 * la licencia, el soporte—, y el producto le habla en el idioma que eligio en el
 * panel (ADR-017), no en el que dejo el instalador en `APP_LOCALE`. Los textos
 * viven en `lang/{es,en}/console.php`, bajo la seccion que declara la orden en
 * su constante `TEXTS`; `ConsoleTextsTest` impide que vuelva a aparecer un
 * literal con prosa dentro de una orden.
 *
 * Usa `$this->laravel` —el contenedor que Symfony Console le da a toda orden de
 * Artisan— y no el ayudante `app()`, para que la orden no dependa de un estado
 * global que en las pruebas no existe.
 *
 * @phpstan-require-extends Command
 */
trait SpeaksInstallationLanguage
{
    /**
     * Un texto de la seccion de la orden (`console.<orden>.<clave>`).
     *
     * @param  array<string, int|string>  $replace
     */
    protected function text(string $key, array $replace = []): string
    {
        return $this->installationText(self::TEXTS.'.'.$key, $replace);
    }

    /**
     * Un texto de cualquier fichero de `lang/`, por su clave completa: los que
     * la orden comparte con la API o con otra orden (`support.reason_hint`).
     *
     * @param  array<string, int|string>  $replace
     */
    protected function installationText(string $key, array $replace = []): string
    {
        return $this->laravel->make(InstallationText::class)->line($key, $replace);
    }

    /**
     * Un parrafo de varias lineas.
     *
     * @param  array<string, int|string>  $replace
     * @return list<string>
     */
    protected function texts(string $key, array $replace = []): array
    {
        return $this->laravel->make(InstallationText::class)->lines(self::TEXTS.'.'.$key, $replace);
    }

    /**
     * Escribe un parrafo de varias lineas.
     *
     * @param  array<string, int|string>  $replace
     */
    protected function paragraph(string $key, array $replace = []): void
    {
        foreach ($this->texts($key, $replace) as $line) {
            $this->line($line);
        }
    }
}
