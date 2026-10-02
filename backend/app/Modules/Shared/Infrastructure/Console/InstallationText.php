<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Console;

use App\Modules\Shared\Application\Port\LocalePolicyProvider;
use Illuminate\Contracts\Translation\Translator;
use Throwable;

/**
 * Un texto de consola en el **idioma de la instalacion**, no en `APP_LOCALE`.
 *
 * El idioma del panel se cambia desde el panel y `APP_LOCALE` se queda como lo
 * dejo el instalador, asi que una tarea programada que tradujera con el
 * segundo escribiria en un idioma que nadie eligio. Es el mismo criterio que
 * `product:doctor`. Si el idioma de la instalacion no se puede leer —la base de
 * datos no responde—, vale `APP_LOCALE`: una purga no se para por un texto.
 */
final readonly class InstallationText
{
    public function __construct(
        private LocalePolicyProvider $locales,
        private Translator $translator,
    ) {}

    /** @param  array<string, int|string>  $replace */
    public function line(string $key, array $replace = []): string
    {
        $text = $this->translator->get($key, $replace, $this->locale());

        return \is_string($text) ? $text : $key;
    }

    private function locale(): ?string
    {
        try {
            return $this->locales->current()->default;
        } catch (Throwable) {
            return null;
        }
    }
}
