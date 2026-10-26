<?php

declare(strict_types=1);

use App\Modules\Product\Application\UseCase\GetBrandingHandler;
use App\Modules\Shared\Application\Port\BrandingProvider;
use App\Modules\Shared\Application\Port\LocalePolicyProvider;
use App\Modules\Shared\Domain\ValueObject\Branding;
use App\Modules\Shared\Domain\ValueObject\LocalePolicy;
use Psr\Log\NullLogger;
use Tests\Support\Product\FixedLogo;

/*
 * `GET /branding` con la marca ilegible (RF-PD-08): el nombre y el color caen
 * a los del producto, pero los idiomas son los del puerto de idiomas, que ya
 * trae el respaldo de `APP_SUPPORTED_LOCALES` que usa `NegotiateLocale`. Con
 * los de serie del catalogo, el quiosco ofreceria idiomas que la API no sirve.
 */

function brandingIlegible(): BrandingProvider
{
    return new class implements BrandingProvider
    {
        public function current(): Branding
        {
            throw new RuntimeException('installation_settings ilegible');
        }
    };
}

function brandingIdiomas(LocalePolicy $policy): LocalePolicyProvider
{
    return new class($policy) implements LocalePolicyProvider
    {
        public function __construct(private readonly LocalePolicy $policy) {}

        public function current(): LocalePolicy
        {
            return $this->policy;
        }
    };
}

it('sin marca legible publica los idiomas del puerto de idiomas, no los de serie', function (): void {
    $handler = new GetBrandingHandler(
        brandingIlegible(),
        brandingIdiomas(new LocalePolicy('en', ['en', 'es'])),
        FixedLogo::none(),
        new NullLogger,
    );

    $branding = $handler->handle();

    expect($branding->applicationName)->toBe('KronoQR');
    expect($branding->accentColor)->toBeNull();
    expect($branding->locales->default)->toBe('en');
    expect($branding->locales->available)->toBe(['en', 'es']);
})->group('RF-PD-08', 'RF-PD-01');

it('si hasta el puerto de idiomas falla, cae a los de serie y nunca a una lista vacia', function (): void {
    $idiomasRotos = new class implements LocalePolicyProvider
    {
        public function current(): LocalePolicy
        {
            throw new RuntimeException('sin contenedor');
        }
    };

    $branding = (new GetBrandingHandler(brandingIlegible(), $idiomasRotos, FixedLogo::none(), new NullLogger))->handle();

    expect($branding->locales->available)->toBe(['es']);
    expect($branding->locales->default)->toBe('es');
})->group('RF-PD-08');
