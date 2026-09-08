<?php

declare(strict_types=1);

namespace Tests\Support\Product;

use App\Modules\Product\Application\Port\DoctorTranslator;
use App\Modules\Product\Application\Port\LicenseRepository;
use App\Modules\Product\Application\Port\LicenseStatePublisher;
use App\Modules\Product\Application\Port\LicenseVerifier;
use App\Modules\Product\Application\UseCase\GetLicenseStatusHandler;
use App\Modules\Product\Application\UseCase\RunDoctorHandler;
use App\Modules\Product\Domain\ValueObject\License;
use App\Modules\Product\Domain\ValueObject\LicenseVerification;
use App\Modules\Product\Domain\ValueObject\StoredLicense;
use App\Modules\Shared\Application\Port\Clock;
use DateTimeImmutable;
use RuntimeException;

/**
 * Los dos colaboradores concretos que `BuildTelemetryReportHandler` necesita,
 * armados **sin contenedor y sin base de datos** (RF-PD-12).
 *
 * ## Por que existe
 *
 * La suite `Unit` corre sobre PHPUnit puro (ver `tests/Pest.php`): no hay
 * `app()` ni PostgreSQL. `GetLicenseStatusHandler` y `RunDoctorHandler` son
 * clases finales -no se pueden doblar- y ese es el diseño correcto: el punto
 * unico de resolucion de la licencia (ADR-023) no debe poder sustituirse por
 * cualquier cosa. Lo que si se puede sustituir son sus **puertos**, que es lo
 * que se hace aqui.
 *
 * Devuelve una instalacion **sin licencia**, que es el estado en el que la
 * telemetria no se envia por la tercera condicion, y un `doctor` **sin sondas**,
 * que produce un informe vacio y valido. Ninguno de los dos toca nada.
 */
final class UnlicensedInstallation
{
    public static function licenses(Clock $clock): GetLicenseStatusHandler
    {
        return new GetLicenseStatusHandler(
            licenses: new class implements LicenseRepository
            {
                public function current(): ?StoredLicense
                {
                    return null;
                }

                public function activate(string $signedKey, License $license, DateTimeImmutable $activatedAt, ?int $actorUserId): void
                {
                    throw new RuntimeException('Esta prueba no activa licencias.');
                }

                public function markVerified(DateTimeImmutable $verifiedAt): void {}
            },
            verifier: new class implements LicenseVerifier
            {
                public function verify(string $signedKey): LicenseVerification
                {
                    throw new RuntimeException('Sin licencia guardada no hay nada que verificar.');
                }
            },
            probe: new class implements LicenseStatePublisher
            {
                public function publish(string $state): void {}
            },
            clock: $clock,
            expiryWarningDays: 30,
        );
    }

    /**
     * `doctor` sin sondas: un informe vacio y valido.
     *
     * Lo que la telemetria saca de `doctor` es el veredicto de cada
     * comprobacion, y con cero sondas son cero veredictos. Lo que se prueba con
     * esto no es `doctor` -tiene sus propias pruebas- sino que la telemetria no
     * lo ejecuta cuando esta apagada.
     */
    public static function doctor(Clock $clock): RunDoctorHandler
    {
        return new RunDoctorHandler(
            probes: [],
            translator: new class implements DoctorTranslator
            {
                public function translate(string $key, array $params, string $locale): ?string
                {
                    return null;
                }
            },
            clock: $clock,
            productVersion: '2.1.0',
        );
    }
}
