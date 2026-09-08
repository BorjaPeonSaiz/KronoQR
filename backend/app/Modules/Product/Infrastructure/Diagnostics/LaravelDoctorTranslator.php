<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics;

use App\Modules\Product\Application\Port\DoctorTranslator;
use App\Modules\Product\Application\UseCase\RunDoctorHandler;
use Illuminate\Contracts\Translation\Translator;

/**
 * Adaptador del traductor de Laravel para el informe de `doctor` (RF-PD-13).
 *
 * ## Devuelve `null` cuando la clave no existe
 *
 * Y no la clave, que es lo que hace `trans()`. La diferencia importa: quien
 * pregunta —{@see RunDoctorHandler}—
 * necesita distinguir «este texto falta» de «este texto es literalmente
 * `doctor.fixes.x.ok`», porque el contrato exige `fix: null` en las
 * comprobaciones correctas y una cadena ahi romperia el cliente generado.
 */
final readonly class LaravelDoctorTranslator implements DoctorTranslator
{
    public function __construct(private Translator $translator) {}

    public function translate(string $key, array $params, string $locale): ?string
    {
        $replacements = [];

        foreach ($params as $name => $value) {
            $replacements[$name] = match (true) {
                $value === null => '',
                is_bool($value) => $value ? 'si' : 'no',
                default => (string) $value,
            };
        }

        /** @var mixed $translated */
        $translated = $this->translator->get($key, $replacements, $locale);

        // `get()` devuelve la clave tal cual cuando no la encuentra. Con la
        // clave por respuesta no se puede saber si falta o si el texto es esa
        // cadena, asi que se compara: ninguna frase del informe es su clave.
        return is_string($translated) && $translated !== $key ? $translated : null;
    }
}
