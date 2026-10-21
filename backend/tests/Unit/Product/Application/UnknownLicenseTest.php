<?php

declare(strict_types=1);

use App\Modules\Product\Application\Exception\LicenseUnavailable;
use App\Modules\Product\Application\Port\LicenseRepository;
use App\Modules\Product\Application\Port\LicenseStatePublisher;
use App\Modules\Product\Application\Port\LicenseVerifier;
use App\Modules\Product\Application\Port\PlanUsageCounter;
use App\Modules\Product\Application\UseCase\BuildTelemetryReportHandler;
use App\Modules\Product\Application\UseCase\GetLicenseStatusHandler;
use App\Modules\Product\Domain\ValueObject\License;
use App\Modules\Product\Domain\ValueObject\LicenseVerification;
use App\Modules\Product\Domain\ValueObject\PlanLimit;
use App\Modules\Product\Domain\ValueObject\StoredLicense;
use App\Modules\Product\Domain\ValueObject\TelemetryState;
use App\Modules\Product\Infrastructure\Adapter\LicensedFeatureGate;
use App\Modules\Shared\Domain\ValueObject\Feature;
use App\Modules\Shared\Domain\ValueObject\FeatureRestriction;
use Psr\Log\NullLogger;
use Tests\Support\Product\SpyTelemetryCounters;
use Tests\Support\Product\SpyTelemetryFacts;
use Tests\Support\Product\UnlicensedInstallation;
use Tests\Support\Time\FixedClock;

/*
 * LA LICENCIA QUE NO SE PUEDE LEER ES `unknown`, NO `absent` (V21-4-PL-1,
 * RF-PD-04, ADR-019). Sin base de datos ni contenedor.
 *
 * Con PostgreSQL caido, la lectura devolvia `null` —«no hay licencia»— y de ahi
 * salian el aviso de «sin licencia», la degradacion de lo accesorio, el estado
 * `absent` en la cache de `/health` y en la telemetria, y el consejo de
 * reactivarla en plena caida. Lo que se afirma: nada de eso ocurre.
 */

/**
 * Una tabla `license` que no se puede leer las veces indicadas y luego no
 * tiene ninguna fila. Cuenta las lecturas y si alguien la marca verificada.
 */
final class UnknownLicenseRepository implements LicenseRepository
{
    public int $reads = 0;

    public bool $verified = false;

    public function __construct(private int $failures = PHP_INT_MAX) {}

    public function current(): ?StoredLicense
    {
        $this->reads++;

        if ($this->failures-- > 0) {
            throw LicenseUnavailable::because(new RuntimeException('SQLSTATE[08006] connection refused'));
        }

        return null;
    }

    public function activate(string $signedKey, License $license, DateTimeImmutable $activatedAt, ?int $actorUserId): void
    {
        throw new LogicException('Esta prueba no activa licencias.');
    }

    public function markVerified(DateTimeImmutable $verifiedAt): void
    {
        $this->verified = true;
    }
}

/** Lo que se publica en la cache que lee `/health`. */
final class UnknownLicensePublished implements LicenseStatePublisher
{
    /** @var list<string> */
    public array $states = [];

    public function publish(string $state): void
    {
        $this->states[] = $state;
    }
}

function unknownLicenseStatus(UnknownLicenseRepository $repository, UnknownLicensePublished $published): GetLicenseStatusHandler
{
    return new GetLicenseStatusHandler(
        licenses: $repository,
        verifier: new class implements LicenseVerifier
        {
            public function verify(string $signedKey): LicenseVerification
            {
                throw new LogicException('Sin clave leida no hay nada que verificar.');
            }
        },
        probe: $published,
        clock: FixedClock::at('2026-10-21 03:00:00'),
        expiryWarningDays: 30,
    );
}

it('no resuelve absent ni lo deja en la cache de /health ni la marca verificada', function (): void {
    $repository = new UnknownLicenseRepository;
    $published = new UnknownLicensePublished;

    expect(static fn () => unknownLicenseStatus($repository, $published)->handle(touch: true))
        ->toThrow(LicenseUnavailable::class);

    // Sin publicar: la sonda conserva lo ultimo que supo o responde `unknown`
    // por si sola, nunca un `absent` inventado.
    expect($published->states)->toBe([])
        ->and($repository->verified)->toBeFalse();
})->group('RF-PD-04', 'RNF-D-03');

it('el FeatureGate no degrada ninguna funcionalidad ni da motivo de licencia', function (): void {
    $gate = new LicensedFeatureGate(unknownLicenseStatus(new UnknownLicenseRepository, new UnknownLicensePublished), new NullLogger);

    foreach (Feature::cases() as $feature) {
        $availability = $gate->statusOf($feature);

        expect($availability->enabled)->toBeTrue($feature->value)
            ->and($availability->restriction)->toBeNull();
    }
})->group('RF-PD-04', 'RNF-D-03');

it('el FeatureGate no memoriza unknown: en cuanto se lee, manda el estado real', function (): void {
    $repository = new UnknownLicenseRepository(failures: 1);
    $gate = new LicensedFeatureGate(unknownLicenseStatus($repository, new UnknownLicensePublished), new NullLogger);

    expect($gate->isEnabled(Feature::AdvancedReports))->toBeTrue();

    // La base vuelve y no hay fila: ahora si es `absent`, y degrada.
    $availability = $gate->statusOf(Feature::AdvancedReports);

    expect($repository->reads)->toBe(2)
        ->and($availability->enabled)->toBeFalse()
        ->and($availability->restriction)->toBe(FeatureRestriction::LicenseAbsent);
})->group('RF-PD-04', 'RNF-D-03');

it('la telemetria la envia como unknown y no como absent', function (): void {
    $clock = FixedClock::at('2026-10-21 05:40:00');

    $build = new BuildTelemetryReportHandler(
        licenses: unknownLicenseStatus(new UnknownLicenseRepository, new UnknownLicensePublished),
        doctor: UnlicensedInstallation::doctor($clock),
        usage: new class implements PlanUsageCounter
        {
            public function count(PlanLimit $limit): int
            {
                return 0;
            }
        },
        counters: new SpyTelemetryCounters,
        facts: new SpyTelemetryFacts,
        clock: $clock,
        productVersion: '2.3.0',
        phpVersion: '8.4.24',
        doctorLocale: 'es',
    );

    $report = $build->handle(new TelemetryState('9f2c7b41-0f6a-4a1e-9d54-6b0f3a2c81de'))->report;

    expect($report->licenseState)->toBe('unknown')
        ->and($report->licensePlan)->toBeNull()
        ->and($report->licenseFeatures)->toBe([]);
})->group('RF-PD-12', 'RF-PD-04');
