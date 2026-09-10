<?php

declare(strict_types=1);

use App\Modules\Reporting\Application\UseCase\PublishAdoptionMetrics;
use App\Modules\Shared\Application\Port\InstallationSiteProvider;
use App\Modules\Shared\Domain\ValueObject\InstallationSite;
use Tests\Support\Reporting\RecordingAdoptionMetrics;
use Tests\Support\Reporting\RecordingWorkDayCompletionReader;
use Tests\Support\Time\FixedClock;

/*
 * `workdays_complete_ratio{site}` — la metrica de adopcion (RF-IN-08, doc 02
 * §8.2, tarea 3.1).
 *
 * **Que decide este caso de uso y por eso que se prueba aqui:** QUE DIA se mide.
 * El recuento lo hace una consulta y su forma se comprueba contra PostgreSQL; lo
 * que no puede fallar sin que nadie lo note es la fecha, porque un ratio
 * calculado sobre el dia equivocado es un numero perfectamente creible.
 */

function siteProvider(?InstallationSite $site): InstallationSiteProvider
{
    return new class($site) implements InstallationSiteProvider
    {
        public function __construct(private ?InstallationSite $site) {}

        public function installationSite(): ?InstallationSite
        {
            return $this->site;
        }
    };
}

it('mide la jornada de ayer en la zona del centro', function (): void {
    // 06:30 UTC del 4 de marzo son las 07:30 en Madrid: ayer es el 3 por los dos
    // relojes, y esta es la comprobacion facil.
    $reader = new RecordingWorkDayCompletionReader([1 => ['complete' => 8, 'total' => 10]]);
    $metrics = new RecordingAdoptionMetrics;

    (new PublishAdoptionMetrics(
        $reader,
        $metrics,
        siteProvider(new InstallationSite(1, 'Hotel Marina', 'Europe/Madrid')),
        FixedClock::at('2026-03-04T06:30:00'),
    ))->handle();

    expect($reader->askedFor)->toBe('2026-03-03')
        ->and($metrics->published[0]['workDate'])->toBe('2026-03-03')
        ->and($metrics->published[0]['bySite'])->toBe([1 => ['complete' => 8, 'total' => 10]]);
})->group('RF-IN-08');

it('respeta la zona del centro cuando UTC y el centro no estan en el mismo dia', function (): void {
    // 23:10 UTC del 3 de marzo son las 00:10 del 4 en Madrid. Para el centro,
    // ayer es el DIA 3; para UTC seria el 2. Es la unica hora del dia en la que
    // se nota, y es de madrugada — que es cuando corre la tarea programada.
    $reader = new RecordingWorkDayCompletionReader;

    (new PublishAdoptionMetrics(
        $reader,
        new RecordingAdoptionMetrics,
        siteProvider(new InstallationSite(1, 'Hotel Marina', 'Europe/Madrid')),
        FixedClock::at('2026-03-03T23:10:00'),
    ))->handle();

    expect($reader->askedFor)->toBe('2026-03-03');
})->group('RF-IN-08', 'RN-05');

it('publica sin centros cuando no hubo ninguna jornada, para que el adaptador omita la serie', function (): void {
    // Ni cero ni NaN: la ausencia de la serie es la unica forma honesta de decir
    // «ese dia el centro estaba cerrado». Cero se leeria como «nadie cerro su
    // jornada», que es una alarma.
    $metrics = new RecordingAdoptionMetrics;

    $published = (new PublishAdoptionMetrics(
        new RecordingWorkDayCompletionReader,
        $metrics,
        siteProvider(new InstallationSite(1, 'Hotel Marina', 'Europe/Madrid')),
        FixedClock::at('2026-03-04T04:50:00'),
    ))->handle();

    expect($published)->toBeTrue()
        ->and($metrics->published[0]['bySite'])->toBe([]);
})->group('RF-IN-08');

it('no hace nada y no falla en una instalacion sin centro', function (): void {
    // RF-PD-03: antes del asistente de puesta en marcha esto es lo esperado, y
    // una tarea programada que fallara cada dia entrena a no mirar el
    // planificador.
    $reader = new RecordingWorkDayCompletionReader([1 => ['complete' => 1, 'total' => 1]]);
    $metrics = new RecordingAdoptionMetrics;

    $published = (new PublishAdoptionMetrics(
        $reader,
        $metrics,
        siteProvider(null),
        FixedClock::at('2026-03-04T04:50:00'),
    ))->handle();

    expect($published)->toBeFalse()
        ->and($metrics->published)->toBe([])
        ->and($reader->askedFor)->toBeNull();
})->group('RF-IN-08', 'RF-PD-03');
