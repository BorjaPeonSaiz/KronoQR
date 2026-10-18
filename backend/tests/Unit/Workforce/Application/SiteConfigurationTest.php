<?php

declare(strict_types=1);

use App\Modules\Shared\Application\Port\ResponsibleAccounts;
use App\Modules\Shared\Application\Port\SerializedLedgerWrite;
use App\Modules\Shared\Domain\Event\DomainEvent;
use App\Modules\Shared\Domain\ValueObject\ResponsibleAccount;
use App\Modules\Shared\Domain\ValueObject\ResponsibleReference;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Workforce\Application\Command\CorrectSiteTimezoneCommand;
use App\Modules\Workforce\Application\Command\CreateSiteCommand;
use App\Modules\Workforce\Application\Command\UpdateSiteCommand;
use App\Modules\Workforce\Application\Port\SiteRepository;
use App\Modules\Workforce\Application\Port\WorkforceEventPublisher;
use App\Modules\Workforce\Application\Port\WorkRecordExtent;
use App\Modules\Workforce\Application\Port\WorkRecordPresence;
use App\Modules\Workforce\Application\Support\SiteMaintainers;
use App\Modules\Workforce\Application\UseCase\CorrectSiteTimezoneHandler;
use App\Modules\Workforce\Application\UseCase\CreateSiteHandler;
use App\Modules\Workforce\Application\UseCase\SiteTimezoneCorrectionOutcome;
use App\Modules\Workforce\Application\UseCase\UpdateSiteHandler;
use App\Modules\Workforce\Domain\Event\SiteConfigured;
use App\Modules\Workforce\Domain\Exception\InvalidSiteTimezoneCorrection;
use App\Modules\Workforce\Domain\Exception\SiteAlreadyConfigured;
use App\Modules\Workforce\Domain\Exception\SiteTimezoneLocked;
use App\Modules\Workforce\Domain\Exception\UnknownTimezone;
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
 * @return SiteRepository&object{stored: ?Site, saves: int, corrections: list<string>}
 */
function siteRepositoryHolding(?Site $site): SiteRepository
{
    return new class($site) implements SiteRepository
    {
        public int $saves = 0;

        /** @var list<string> */
        public array $corrections = [];

        public function __construct(public ?Site $stored) {}

        public function add(Site $site): Site
        {
            $this->stored = $site->withId(1);

            return $this->stored;
        }

        public function save(Site $site): void
        {
            $this->saves++;
            $this->stored = $site;
        }

        public function saveTimezoneCorrection(Site $corrected, SiteTimezone $previous): int
        {
            $this->saves++;
            $this->corrections[] = $previous->identifier.'->'.$corrected->timezone->identifier;
            $this->stored = $corrected;

            return count($this->corrections);
        }

        public function installationSite(): ?Site
        {
            return $this->stored;
        }

        public function installationSiteForUpdate(): ?Site
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

/**
 * El registro horario, reducido a lo que el centro necesita saber de el: si
 * existe (RN-23).
 */
function siteWorkRecord(bool $hayRegistro): WorkRecordPresence
{
    return new class($hayRegistro) implements WorkRecordPresence
    {
        public function __construct(private readonly bool $hayRegistro) {}

        public function exists(): bool
        {
            return $this->hayRegistro;
        }

        public function count(): int
        {
            return $this->hayRegistro ? 1 : 0;
        }

        public function extent(): ?WorkRecordExtent
        {
            return $this->hayRegistro ? new WorkRecordExtent(1, '2026-09-01', '2026-09-01') : null;
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

function updateSiteHandler(SiteRepository $sites, WorkforceEventPublisher $events, bool $hayRegistro = false): UpdateSiteHandler
{
    return new UpdateSiteHandler(
        $sites,
        siteWorkRecord($hayRegistro),
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

    expect($site?->site->name)->toBe('Hotel Marina')
        ->and($site?->site->timezone->identifier)->toBe('Atlantic/Canary')
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
    expect($site?->site->name)->toBe('Hotel Marina')
        ->and($events->published)->toBe([]);
})->group('RN-05', 'RL-04');

it('no tiene nada que modificar antes de la puesta en marcha', function (): void {
    $repository = siteRepositoryHolding(null);
    $events = siteEventRecorder();

    expect(updateSiteHandler($repository, $events)->handle(new UpdateSiteCommand(name: 'Hotel Marina')))->toBeNull();

    expect($events->published)->toBe([]);
})->group('RF-GP-01');

/*
 * RN-23 (ADR-056): la zona queda fija con el primer tramo. El caso de uso
 * pregunta si hay registro horario y el centro decide; estas pruebas fijan lo
 * que el caso de uso hace con la respuesta.
 */

it('no guarda ni anuncia nada si pide otra zona con registro horario, aunque traiga tambien un nombre', function (): void {
    // Una peticion se acepta entera o no se acepta: el nombre tampoco cambia, y
    // no queda asiento de un cambio que no ocurrio.
    $original = Site::create('Hotel Marina', SiteTimezone::fromString('Europe/Madrid'))->withId(1);
    $repository = siteRepositoryHolding($original);
    $events = siteEventRecorder();

    expect(fn () => updateSiteHandler($repository, $events, hayRegistro: true)
        ->handle(new UpdateSiteCommand(name: 'Hotel Atlantico', timezone: 'Atlantic/Canary')))
        ->toThrow(SiteTimezoneLocked::class);

    expect($repository->saves)->toBe(0)
        ->and($repository->stored)->toBe($original)
        ->and($events->published)->toBe([]);
})->group('RN-23', 'RL-04');

it('renombra el centro con registro horario y anuncia solo el nombre', function (): void {
    $repository = siteRepositoryHolding(Site::create('Hotel Marina', SiteTimezone::fromString('Europe/Madrid'))->withId(1));
    $events = siteEventRecorder();

    $view = updateSiteHandler($repository, $events, hayRegistro: true)
        ->handle(new UpdateSiteCommand(name: 'Hotel Atlantico', timezone: 'Europe/Madrid'));

    expect($view?->site->name)->toBe('Hotel Atlantico')
        ->and($view?->timezoneLocked())->toBeTrue()
        ->and($repository->saves)->toBe(1)
        ->and($events->published)->toHaveCount(1);
})->group('RN-23', 'RF-GP-01');

it('con la misma zona y registro horario responde el centro sin guardar ni anunciar', function (): void {
    $repository = siteRepositoryHolding(Site::create('Hotel Marina', SiteTimezone::fromString('Europe/Madrid'))->withId(1));
    $events = siteEventRecorder();

    $view = updateSiteHandler($repository, $events, hayRegistro: true)
        ->handle(new UpdateSiteCommand(timezone: 'Europe/Madrid'));

    expect($view?->site->timezone->identifier)->toBe('Europe/Madrid')
        ->and($view?->timezoneLocked())->toBeTrue()
        ->and($repository->saves)->toBe(0)
        ->and($events->published)->toBe([]);
})->group('RN-23', 'RL-04');

it('sin registro horario cambia la zona y dice que sigue abierta', function (): void {
    $repository = siteRepositoryHolding(Site::create('Hotel Marina', SiteTimezone::fromString('Europe/Madrid'))->withId(1));

    $view = updateSiteHandler($repository, siteEventRecorder())
        ->handle(new UpdateSiteCommand(timezone: 'Atlantic/Canary'));

    expect($view?->site->timezone->identifier)->toBe('Atlantic/Canary')
        ->and($view?->timezoneLocked())->toBeFalse();
})->group('RN-23', 'RN-05');

it('rechaza una zona que no existe antes de mirar el registro horario', function (): void {
    $repository = siteRepositoryHolding(Site::create('Hotel Marina', SiteTimezone::fromString('Europe/Madrid'))->withId(1));

    expect(fn () => updateSiteHandler($repository, siteEventRecorder(), hayRegistro: true)
        ->handle(new UpdateSiteCommand(timezone: 'Europe/Atlantida')))
        ->toThrow(UnknownTimezone::class);

    expect($repository->saves)->toBe(0);
})->group('RN-23', 'RN-05');

/*
 * RN-23 (ADR-056 §3): la correccion por consola. Quien responde se comprueba
 * con los roles de `SitePolicy::update` y, en la correccion, con la cadena de
 * `audit_log` tomada: la baja de una cuenta toma la cadena antes de tocar su
 * fila, asi que el asiento no puede quedar firmado por una cuenta inactiva.
 */

/**
 * Pasos y candados de la correccion, en el orden en que ocurren.
 */
final class SiteCorrectionJournal
{
    /** @var list<string> */
    public array $steps = [];

    public bool $chainHeld = false;
}

/**
 * @param  list<int>  $responsables
 */
function siteCorrectionHandler(SiteRepository $sites, SiteCorrectionJournal $journal, array $responsables): CorrectSiteTimezoneHandler
{
    $accounts = new class($journal, $responsables) implements ResponsibleAccounts
    {
        /** @param list<int> $active */
        public function __construct(private SiteCorrectionJournal $journal, private array $active) {}

        public function find(ResponsibleReference $reference): ?ResponsibleAccount
        {
            return null;
        }

        public function isActiveManagementAccount(int $userId, array $roles = []): bool
        {
            $this->journal->steps[] = 'responsible:'.($this->journal->chainHeld ? 'chain' : 'free').':'
                .implode(',', array_map(static fn (UserRole $role): string => $role->value, $roles));

            return in_array($userId, $this->active, true);
        }
    };

    $ledger = new class($journal) implements SerializedLedgerWrite
    {
        public function __construct(private SiteCorrectionJournal $journal) {}

        public function withChainLock(callable $work): mixed
        {
            $this->journal->chainHeld = true;

            try {
                return $work();
            } finally {
                $this->journal->chainHeld = false;
            }
        }
    };

    return new CorrectSiteTimezoneHandler(
        $sites,
        siteWorkRecord(true),
        $accounts,
        siteEventRecorder(),
        FixedClock::at('2026-09-02T08:00:00'),
        ImmediateTransactions::connection(),
        $ledger,
    );
}

it('comprueba al responsable con los roles del centro y con la cadena tomada', function (): void {
    $repository = siteRepositoryHolding(Site::create('Hotel Marina', SiteTimezone::fromString('Europe/Madrid'))->withId(1));
    $journal = new SiteCorrectionJournal;

    $report = siteCorrectionHandler($repository, $journal, [7])
        ->handle(new CorrectSiteTimezoneCommand('Atlantic/Canary', 'Se puso Madrid y era Canarias', 7));

    expect($report->outcome)->toBe(SiteTimezoneCorrectionOutcome::Corrected)
        ->and($report->shiftEntryCount)->toBe(1)
        ->and($journal->steps)->toBe(['responsible:chain:admin,rrhh'])
        ->and($repository->stored?->timezone->identifier)->toBe('Atlantic/Canary')
        // Por la puerta de la correccion, con la zona de partida: la unica
        // que la base de datos deja abierta con registro horario.
        ->and($repository->corrections)->toBe(['Europe/Madrid->Atlantic/Canary'])
        ->and(SiteMaintainers::ROLES)->toBe([UserRole::ADMIN, UserRole::RRHH]);
})->group('RN-23', 'RL-04');

it('no corrige ni guarda si el responsable no vale al confirmar', function (): void {
    $repository = siteRepositoryHolding(Site::create('Hotel Marina', SiteTimezone::fromString('Europe/Madrid'))->withId(1));

    expect(fn (): mixed => siteCorrectionHandler($repository, new SiteCorrectionJournal, [])
        ->handle(new CorrectSiteTimezoneCommand('Atlantic/Canary', 'Se puso Madrid y era Canarias', 7)))
        ->toThrow(InvalidSiteTimezoneCorrection::class);

    expect($repository->saves)->toBe(0);
})->group('RN-23');

it('comprueba al responsable ya en la vista previa, sin candado', function (): void {
    $repository = siteRepositoryHolding(Site::create('Hotel Marina', SiteTimezone::fromString('Europe/Madrid'))->withId(1));
    $journal = new SiteCorrectionJournal;

    $preview = siteCorrectionHandler($repository, $journal, [7])->preview('Atlantic/Canary', 7);

    expect($preview->outcome)->toBe(SiteTimezoneCorrectionOutcome::Corrected)
        ->and($preview->extent?->firstWorkDate)->toBe('2026-09-01')
        ->and($journal->steps)->toBe(['responsible:free:admin,rrhh'])
        ->and(fn (): mixed => siteCorrectionHandler($repository, new SiteCorrectionJournal, [])->preview('Atlantic/Canary', 7))
        ->toThrow(InvalidSiteTimezoneCorrection::class);
})->group('RN-23');
