<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\Event\DomainEvent;
use App\Modules\Workforce\Application\Command\CreateSiteCommand;
use App\Modules\Workforce\Application\Command\UpdateSiteCommand;
use App\Modules\Workforce\Application\Port\SiteRepository;
use App\Modules\Workforce\Application\Port\WorkforceEventPublisher;
use App\Modules\Workforce\Application\UseCase\CreateSiteHandler;
use App\Modules\Workforce\Application\UseCase\UpdateSiteHandler;
use App\Modules\Workforce\Domain\Event\SiteConfigured;
use App\Modules\Workforce\Domain\Exception\SiteAlreadyConfigured;
use App\Modules\Workforce\Domain\Model\Site;
use App\Modules\Workforce\Domain\ValueObject\SiteTimezone;
use Tests\Support\Database\ImmediateTransactions;
use Tests\Support\Time\FixedClock;

/*
 * El centro de la instalacion se crea una vez y se modifica en su sitio
 * (ADR-040). Sin base de datos: un repositorio en memoria basta para afirmar
 * la regla, que es del caso de uso y no del esquema (el esquema tiene la suya
 * en `SingleSiteSchemaTest`).
 *
 * DESDE LA TAREA 5.5 SE COMPRUEBA TAMBIEN EL EVENTO. Crear el centro y cambiarle
 * la zona horaria fijan el parametro con el que RN-05 atribuye cada tramo a una
 * jornada, asi que los dos dejan asiento (regla dura 6). Hasta la 5.5 el
 * docblock de `UpdateSiteHandler` decia que se auditaba y no se publicaba
 * ningun evento: estas dos afirmaciones son las que impiden que vuelva a
 * ocurrir.
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

/**
 * @return WorkforceEventPublisher&object{published: list<DomainEvent>}
 */
function siteEventRecorder(): WorkforceEventPublisher
{
    return new class implements WorkforceEventPublisher
    {
        /** @var list<DomainEvent> */
        public array $published = [];

        public function publish(DomainEvent ...$events): void
        {
            foreach ($events as $event) {
                $this->published[] = $event;
            }
        }
    };
}

function createSiteHandler(SiteRepository $sites, WorkforceEventPublisher $events): CreateSiteHandler
{
    return new CreateSiteHandler(
        $sites,
        $events,
        FixedClock::at('2026-09-02T08:00:00'),
        ImmediateTransactions::connection(),
    );
}

function updateSiteHandler(SiteRepository $sites, WorkforceEventPublisher $events): UpdateSiteHandler
{
    return new UpdateSiteHandler(
        $sites,
        $events,
        FixedClock::at('2026-09-02T08:00:00'),
        ImmediateTransactions::connection(),
    );
}

it('crea el centro cuando la instalacion no tiene ninguno', function (): void {
    $repository = siteRepositoryHolding(null);

    $site = createSiteHandler($repository, siteEventRecorder())
        ->handle(new CreateSiteCommand('Hotel Marina', 'Europe/Madrid'));

    expect($site->id)->toBe(1)
        ->and($site->timezone->identifier)->toBe('Europe/Madrid')
        ->and($repository->installationSite())->toBe($site);
})->group('RF-GP-01', 'RN-05', 'RF-PD-03');

it('anuncia el alta del centro con su zona horaria, para que quede auditada', function (): void {
    // Regla dura 6. `sites.timezone` es el parametro con el que se decide a que
    // jornada va cada tramo (RN-05): sin este evento no habria asiento del valor
    // con el que nacio la instalacion, y eso no se puede reconstruir despues.
    $events = siteEventRecorder();

    createSiteHandler(siteRepositoryHolding(null), $events)
        ->handle(new CreateSiteCommand('Hotel Marina', 'Atlantic/Canary'));

    expect($events->published)->toHaveCount(1);

    $event = $events->published[0];

    expect($event)->toBeInstanceOf(SiteConfigured::class);

    // Estrechado a mano y no con `expect()->toBeInstanceOf()` encadenado: el
    // publicador declara `list<DomainEvent>` y sin este `assert` el analizador
    // no puede saber que las propiedades de abajo existen.
    assert($event instanceof SiteConfigured);

    expect($event->eventName())->toBe('workforce.site_created')
        ->and($event->created)->toBeTrue()
        ->and($event->siteId)->toBe(1)
        ->and($event->timezone)->toBe('Atlantic/Canary')
        // En el alta no hay valor anterior, y `null` significa «no habia
        // centro», no «no se sabe».
        ->and($event->previousName)->toBeNull()
        ->and($event->previousTimezone)->toBeNull();
})->group('RF-PD-03', 'RL-04');

it('se niega a crear un segundo centro', function (): void {
    $repository = siteRepositoryHolding(Site::create('Hotel Marina', SiteTimezone::fromString('Europe/Madrid'))->withId(1));
    $events = siteEventRecorder();

    expect(fn () => createSiteHandler($repository, $events)->handle(new CreateSiteCommand('Hotel Atlantico', 'Atlantic/Canary')))
        ->toThrow(SiteAlreadyConfigured::class);

    // Y no anuncia nada: un asiento de un alta que no ocurrio es peor que no
    // tener asiento.
    expect($events->published)->toBe([]);
})->group('RF-GP-01', 'RF-PD-03');

it('modifica el centro de la instalacion sin identificarlo', function (): void {
    $repository = siteRepositoryHolding(Site::create('Hotel Marina', SiteTimezone::fromString('Europe/Madrid'))->withId(1));

    $site = updateSiteHandler($repository, siteEventRecorder())
        ->handle(new UpdateSiteCommand(timezone: 'Atlantic/Canary'));

    expect($site?->name)->toBe('Hotel Marina')
        ->and($site?->timezone->identifier)->toBe('Atlantic/Canary')
        ->and($repository->installationSite()?->timezone->identifier)->toBe('Atlantic/Canary');
})->group('RN-05');

it('anuncia el cambio de zona horaria, que era la deuda de la tarea 1.6', function (): void {
    // `UpdateSiteHandler` afirmaba en su docblock que el cambio «queda auditado
    // por el oyente de Compliance» y no publicaba ningun evento: no habia oyente
    // ni asiento. Esta prueba es la que impide que la promesa vuelva a quedarse
    // sin cumplir.
    $repository = siteRepositoryHolding(Site::create('Hotel Marina', SiteTimezone::fromString('Europe/Madrid'))->withId(1));
    $events = siteEventRecorder();

    updateSiteHandler($repository, $events)->handle(new UpdateSiteCommand(timezone: 'Atlantic/Canary'));

    $event = $events->published[0];

    expect($event)->toBeInstanceOf(SiteConfigured::class);

    // Estrechado a mano y no con `expect()->toBeInstanceOf()` encadenado: el
    // publicador declara `list<DomainEvent>` y sin este `assert` el analizador
    // no puede saber que las propiedades de abajo existen.
    assert($event instanceof SiteConfigured);

    expect($event->eventName())->toBe('workforce.site_updated')
        ->and($event->created)->toBeFalse()
        ->and($event->timezone)->toBe('Atlantic/Canary')
        // Y CON EL VALOR ANTERIOR. Sin el, el asiento no permite responder a
        // «¿en que zona se calculo la jornada del turno de noche del 3 de
        // marzo?» sin ir a buscar el asiento previo, que puede no existir si el
        // centro se creo antes de la 5.5 (RL-04).
        ->and($event->previousTimezone)->toBe('Europe/Madrid')
        ->and($event->previousName)->toBe('Hotel Marina');
})->group('RN-05', 'RL-04');

it('no anuncia nada cuando el PATCH no cambia nada', function (): void {
    // El panel manda el formulario entero, asi que «guardar sin tocar nada» es
    // lo mas facil que puede pasar. Un `site.updated` con el mismo antes y
    // despues llenaria el trail de ruido y esconderia el cambio de zona horaria
    // que si importa (RL-04).
    $repository = siteRepositoryHolding(Site::create('Hotel Marina', SiteTimezone::fromString('Europe/Madrid'))->withId(1));
    $events = siteEventRecorder();

    $site = updateSiteHandler($repository, $events)
        ->handle(new UpdateSiteCommand(name: 'Hotel Marina', timezone: 'Europe/Madrid'));

    // Y aun asi devuelve el centro: para quien llama, quedo como pedia.
    expect($site?->name)->toBe('Hotel Marina')
        ->and($events->published)->toBe([]);
})->group('RN-05', 'RL-04');

it('no tiene nada que modificar antes de la puesta en marcha', function (): void {
    $repository = siteRepositoryHolding(null);
    $events = siteEventRecorder();

    expect(updateSiteHandler($repository, $events)->handle(new UpdateSiteCommand(name: 'Hotel Marina')))->toBeNull();

    expect($events->published)->toBe([]);
})->group('RF-GP-01');
