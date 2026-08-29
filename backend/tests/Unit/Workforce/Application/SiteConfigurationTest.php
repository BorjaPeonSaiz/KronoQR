<?php

declare(strict_types=1);

use App\Modules\Workforce\Application\Command\CreateSiteCommand;
use App\Modules\Workforce\Application\Command\UpdateSiteCommand;
use App\Modules\Workforce\Application\Port\SiteRepository;
use App\Modules\Workforce\Application\UseCase\CreateSiteHandler;
use App\Modules\Workforce\Application\UseCase\UpdateSiteHandler;
use App\Modules\Workforce\Domain\Exception\SiteAlreadyConfigured;
use App\Modules\Workforce\Domain\Model\Site;
use App\Modules\Workforce\Domain\ValueObject\SiteTimezone;

/*
 * El centro de la instalacion se crea una vez y se modifica en su sitio
 * (ADR-040). Sin base de datos: un repositorio en memoria basta para afirmar
 * la regla, que es del caso de uso y no del esquema (el esquema tiene la suya
 * en `SingleSiteSchemaTest`).
 */

/**
 * @return SiteRepository&object{stored: ?Site}
 */
function siteRepositoryHolding(?Site $site): SiteRepository
{
    return new class($site) implements SiteRepository
    {
        public function __construct(public ?Site $stored) {}

        public function add(Site $site): Site
        {
            $this->stored = $site->withId(1);

            return $this->stored;
        }

        public function save(Site $site): void
        {
            $this->stored = $site;
        }

        public function installationSite(): ?Site
        {
            return $this->stored;
        }
    };
}

it('crea el centro cuando la instalacion no tiene ninguno', function (): void {
    $repository = siteRepositoryHolding(null);

    $site = (new CreateSiteHandler($repository))->handle(new CreateSiteCommand('Hotel Marina', 'Europe/Madrid'));

    expect($site->id)->toBe(1)
        ->and($site->timezone->identifier)->toBe('Europe/Madrid')
        ->and($repository->installationSite())->toBe($site);
})->group('RF-GP-01', 'RN-05');

it('se niega a crear un segundo centro', function (): void {
    $repository = siteRepositoryHolding(Site::create('Hotel Marina', SiteTimezone::fromString('Europe/Madrid'))->withId(1));

    expect(fn () => (new CreateSiteHandler($repository))->handle(new CreateSiteCommand('Hotel Atlantico', 'Atlantic/Canary')))
        ->toThrow(SiteAlreadyConfigured::class);
})->group('RF-GP-01');

it('modifica el centro de la instalacion sin identificarlo', function (): void {
    $repository = siteRepositoryHolding(Site::create('Hotel Marina', SiteTimezone::fromString('Europe/Madrid'))->withId(1));

    $site = (new UpdateSiteHandler($repository))->handle(new UpdateSiteCommand(timezone: 'Atlantic/Canary'));

    expect($site?->name)->toBe('Hotel Marina')
        ->and($site?->timezone->identifier)->toBe('Atlantic/Canary')
        ->and($repository->installationSite()?->timezone->identifier)->toBe('Atlantic/Canary');
})->group('RN-05');

it('no tiene nada que modificar antes de la puesta en marcha', function (): void {
    $repository = siteRepositoryHolding(null);

    expect((new UpdateSiteHandler($repository))->handle(new UpdateSiteCommand(name: 'Hotel Marina')))->toBeNull();
})->group('RF-GP-01');
