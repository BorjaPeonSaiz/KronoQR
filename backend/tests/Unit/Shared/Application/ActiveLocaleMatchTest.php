<?php

declare(strict_types=1);

use App\Http\Rules\ActiveLocale;
use App\Modules\Shared\Application\Support\ActiveLocaleMatch;
use App\Support\Locale\NegotiableLocales;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\PotentiallyTranslatedString;
use Illuminate\Translation\Translator;

/*
 * Un solo criterio de «idioma activo» (RF-PD-01, RF-KI-05): sin distinguir
 * mayusculas ni espacios, y con la forma de la lista. Lo comparten la regla de
 * la API y de la consola (`ActiveLocale`) y la importacion de plantilla.
 */

/** @param list<string> $available */
function idiomaActivoNegociable(array $available): NegotiableLocales
{
    return new readonly class($available) implements NegotiableLocales
    {
        /** @param list<string> $available */
        public function __construct(private array $available) {}

        public function defaultLocale(): string
        {
            return $this->available[0];
        }

        public function availableLocales(): array
        {
            return $this->available;
        }

        public function activeLocaleFor(string $requested): ?string
        {
            return ActiveLocaleMatch::canonical($this->available, $requested);
        }
    };
}

function idiomaActivoValida(NegotiableLocales $locales, mixed $value): bool
{
    $failed = false;

    (new ActiveLocale($locales))->validate('locale', $value, function (string $message) use (&$failed): PotentiallyTranslatedString {
        $failed = true;

        return new PotentiallyTranslatedString($message, new Translator(new ArrayLoader, 'es'));
    });

    return ! $failed;
}

it('da la forma de la lista al idioma pedido, sin distinguir mayusculas ni espacios', function (string $pedido, ?string $esperado): void {
    expect(ActiveLocaleMatch::canonical(['es', 'en'], $pedido))->toBe($esperado);
})->with([
    'tal cual' => ['es', 'es'],
    'en mayusculas' => ['ES', 'es'],
    'mezclado' => ['eN', 'en'],
    'con espacios' => [' en ', 'en'],
    'inactivo' => ['fr', null],
    'vacio' => ['', null],
    'solo espacios' => ['  ', null],
])->group('RF-PD-01', 'RF-KI-05');

it('la regla de la API acepta lo mismo que la importacion y rechaza lo mismo', function (): void {
    $locales = idiomaActivoNegociable(['es', 'en']);

    expect(idiomaActivoValida($locales, 'ES'))->toBeTrue();
    expect(idiomaActivoValida($locales, 'en'))->toBeTrue();
    expect(idiomaActivoValida($locales, 'fr'))->toBeFalse();
    expect(idiomaActivoValida($locales, 7))->toBeFalse();
    expect(idiomaActivoValida(idiomaActivoNegociable(['es']), 'EN'))->toBeFalse();
})->group('RF-PD-01', 'RF-KI-05');
