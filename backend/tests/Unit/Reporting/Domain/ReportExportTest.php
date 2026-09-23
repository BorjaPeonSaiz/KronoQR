<?php

declare(strict_types=1);

use App\Modules\Reporting\Domain\Exception\InvalidReportExportTransition;
use App\Modules\Reporting\Domain\Model\ReportExport;
use App\Modules\Reporting\Domain\ValueObject\ReportExportFailure;
use App\Modules\Reporting\Domain\ValueObject\ReportExportKind;
use App\Modules\Reporting\Domain\ValueObject\ReportExportNotificationChannel;
use App\Modules\Reporting\Domain\ValueObject\ReportExportParameters;
use App\Modules\Reporting\Domain\ValueObject\ReportExportStatus;
use App\Modules\Reporting\Domain\ValueObject\ReportGranularity;
use App\Modules\Reporting\Domain\ValueObject\ReportGrouping;
use App\Modules\Shared\Domain\ValueObject\AccessScope;
use Tests\Support\Reporting\ReportExportFixtures;

/*
 * El ciclo de vida de un informe en diferido, sin base de datos y sin reloj
 * (**RF-IN-06**, ADR-041, ficha 3.9).
 *
 * ## Que se prueba aqui y que no
 *
 * Aqui vive **la maquina de estados y el enlace de un solo uso**: que una fila
 * no pueda resucitar, que purgar borre la ruta y mate el enlace, y que un token
 * consumido no sirva dos veces. Lo que ocurre alrededor —que el trabajo escriba
 * el fichero, que la descarga responda `410`, que la purga corra de madrugada—
 * lo prueban las de integracion y las de feature.
 *
 * ## Unitaria de verdad: ni PostgreSQL ni `now()`
 *
 * Los instantes entran como argumento (regla dura 2), asi que «el enlace caduca
 * a los quince minutos» se comprueba en microsegundos en lugar de esperando
 * quince minutos o moviendo el reloj de la maquina. Es exactamente la razon por
 * la que el modelo no lee la hora.
 */

it('arranca solo desde pending y sella el momento', function (): void {
    $arrancada = ReportExportFixtures::pending()->start(ReportExportFixtures::at('2026-03-08T09:00:05+00:00'));

    expect($arrancada->status)->toBe(ReportExportStatus::Running)
        ->and($arrancada->isInProgress())->toBeTrue()
        ->and($arrancada->startedAt?->format(DATE_ATOM))->toBe('2026-03-08T09:00:05+00:00');
})->group('RF-IN-06');

it('no deja arrancar dos veces la misma exportacion', function (): void {
    // La red que impide que un reintento del trabajador reabra una generacion en
    // curso. Sin ella, dos trabajadores escribirian el mismo fichero a la vez.
    $arrancada = ReportExportFixtures::pending()->start(ReportExportFixtures::at('2026-03-08T09:00:05+00:00'));

    expect(static fn () => $arrancada->start(ReportExportFixtures::at('2026-03-08T09:00:06+00:00')))
        ->toThrow(InvalidReportExportTransition::class);
})->group('RF-IN-06');

it('al terminar deja fichero, huella, recuento y caducidad a la vez', function (): void {
    // Es lo que el CHECK `report_exports_chk_completed_is_complete` exige en la
    // base de datos: una fila `completed` sin alguno de los cinco seria un
    // informe que la pantalla ofrece descargar y que no existe.
    $terminada = ReportExportFixtures::completed();

    expect($terminada->status)->toBe(ReportExportStatus::Completed)
        ->and($terminada->fileName)->toBe('kronoqr-horas-2026-03-01_2026-03-31.csv')
        ->and($terminada->sha256)->toHaveLength(64)
        ->and($terminada->sizeBytes)->toBe(4096)
        ->and($terminada->rowCount)->toBe(31)
        ->and($terminada->expiresAt)->not->toBeNull()
        ->and($terminada->isDownloadable())->toBeTrue();
})->group('RF-IN-06');

it('no deja dar por terminada una exportacion que ya fallo', function (): void {
    // El caso real: la obsolescencia la da por muerta y el trabajador, que seguia
    // vivo, intenta cerrarla minutos despues. Sin esta guarda la fila quedaria
    // como descargable apuntando a un fichero que la purga ya borro.
    $fallida = ReportExportFixtures::pending()
        ->start(ReportExportFixtures::at('2026-03-08T09:00:05+00:00'))
        ->fail(ReportExportFixtures::at('2026-03-08T10:00:00+00:00'), ReportExportFailure::Stale);

    expect(static fn () => $fallida->complete(
        completedAt: ReportExportFixtures::at('2026-03-08T10:05:00+00:00'),
        filePath: '/tmp/x.csv',
        fileName: 'x.csv',
        sizeBytes: 1,
        sha256: str_repeat('b', 64),
        rowCount: 1,
        criteria: [],
        expiresAt: ReportExportFixtures::at('2026-03-15T10:05:00+00:00'),
    ))->toThrow(InvalidReportExportTransition::class);
})->group('RF-IN-06');

it('admite fallar desde pending, que es la fila que nadie llego a recoger', function (): void {
    // La cola estaba parada: el trabajo no llego a arrancar y la obsolescencia
    // tiene que poder cerrarlo igual, o esa persona se queda con `409` para
    // siempre.
    $fallida = ReportExportFixtures::pending()
        ->fail(ReportExportFixtures::at('2026-03-08T10:00:00+00:00'), ReportExportFailure::Stale);

    expect($fallida->status)->toBe(ReportExportStatus::Failed)
        ->and($fallida->failureReason)->toBe(ReportExportFailure::Stale)
        ->and($fallida->isInProgress())->toBeFalse();
})->group('RF-IN-06');

it('purgar borra la ruta y el enlace, y conserva la huella', function (): void {
    // Regla dura 5: la fila se queda para siempre. Lo que desaparece es la ruta
    // —para que nadie intente servir un fichero que ya no esta— y el enlace
    // vigente, que moriria de todos modos al borrarse el fichero.
    $purgada = ReportExportFixtures::completed()
        ->issueDownloadToken(hash('sha256', 'secreto'), ReportExportFixtures::at('2026-03-15T09:10:00+00:00'))
        ->purge(ReportExportFixtures::at('2026-03-15T04:25:00+00:00'));

    expect($purgada->status)->toBe(ReportExportStatus::Purged)
        ->and($purgada->filePath)->toBeNull()
        ->and($purgada->downloadTokenHash)->toBeNull()
        ->and($purgada->downloadTokenExpiresAt)->toBeNull()
        ->and($purgada->isDownloadable())->toBeFalse();

    // Y lo que se conserva, que es lo que permite reconocer el fichero en una
    // conversacion meses despues.
    expect($purgada->fileName)->toBe('kronoqr-horas-2026-03-01_2026-03-31.csv')
        ->and($purgada->sha256)->toHaveLength(64)
        ->and($purgada->rowCount)->toBe(31);
})->group('RF-IN-06');

it('no emite enlace de una exportacion que no esta terminada', function (): void {
    expect(static fn () => ReportExportFixtures::pending()->issueDownloadToken(
        hash('sha256', 'secreto'),
        ReportExportFixtures::at('2026-03-08T09:15:00+00:00'),
    ))->toThrow(InvalidReportExportTransition::class);
})->group('RF-IN-06');

it('emitir un enlace nuevo invalida el anterior', function (): void {
    /*
     * La rotacion de ADR-041, que es lo que convierte un enlace olvidado en el
     * historial de un navegador compartido en un enlace muerto: basta con que
     * alguien vuelva a abrir la pantalla de informes.
     */
    $primera = ReportExportFixtures::completed()
        ->issueDownloadToken(hash('sha256', 'primero'), ReportExportFixtures::at('2026-03-08T09:20:00+00:00'));

    $segunda = $primera
        ->issueDownloadToken(hash('sha256', 'segundo'), ReportExportFixtures::at('2026-03-08T09:40:00+00:00'));

    expect($segunda->downloadTokenMatches(hash('sha256', 'segundo')))->toBeTrue();
    expect($segunda->downloadTokenMatches(hash('sha256', 'primero')))->toBeFalse();
})->group('RF-IN-06');

it('el enlace es de un solo uso y deja contada la descarga', function (): void {
    $emitida = ReportExportFixtures::completed()
        ->issueDownloadToken(hash('sha256', 'unico'), ReportExportFixtures::at('2026-03-08T09:20:00+00:00'));

    $consumida = $emitida->consumeDownloadToken(ReportExportFixtures::at('2026-03-08T09:12:00+00:00'));

    expect($consumida->downloadLinkWasConsumed())->toBeTrue()
        ->and($consumida->downloadTokenExpiresAt)->toBeNull()
        ->and($consumida->downloadCount)->toBe(1)
        ->and($consumida->downloadedAt?->format(DATE_ATOM))->toBe('2026-03-08T09:12:00+00:00');

    // Y el token que se acaba de gastar ya no vale: es lo que el `410
    // …link-used` del contrato afirma.
    expect($consumida->downloadTokenMatches(hash('sha256', 'unico')))->toBeFalse();
})->group('RF-IN-06');

it('el enlace caduca en el instante exacto de su vencimiento', function (): void {
    // `<=` y no `<`: en el segundo del vencimiento el enlace ya no vale. Es el
    // lado seguro, y la unica forma de probarlo sin esperar quince minutos es que
    // el instante entre por argumento (regla dura 2).
    $emitida = ReportExportFixtures::completed()
        ->issueDownloadToken(hash('sha256', 'unico'), ReportExportFixtures::at('2026-03-08T09:20:00+00:00'));

    expect($emitida->downloadLinkExpired(ReportExportFixtures::at('2026-03-08T09:19:59+00:00')))->toBeFalse();
    expect($emitida->downloadLinkExpired(ReportExportFixtures::at('2026-03-08T09:20:00+00:00')))->toBeTrue();
})->group('RF-IN-06');

it('no ofrece enlace de una exportacion cuyo fichero ya caduco', function (): void {
    /*
     * La ventana entre el vencimiento y la purga diaria: la fila sigue
     * `completed` y el fichero sigue en el disco, y durante esas horas el enlace
     * **no** se emite. La caducidad es una promesa al cliente, no un efecto
     * secundario de cuando pase el planificador.
     */
    $terminada = ReportExportFixtures::completed();

    expect($terminada->canIssueDownloadLink(ReportExportFixtures::at('2026-03-15T09:01:59+00:00')))->toBeTrue();
    expect($terminada->canIssueDownloadLink(ReportExportFixtures::at('2026-03-15T09:02:00+00:00')))->toBeFalse();
})->group('RF-IN-06');

it('sella por donde se aviso sin tocar nada mas', function (): void {
    $avisada = ReportExportFixtures::completed()->markNotified(
        ReportExportFixtures::at('2026-03-08T09:02:01+00:00'),
        ReportExportNotificationChannel::Panel,
    );

    expect($avisada->notificationChannel)->toBe(ReportExportNotificationChannel::Panel)
        ->and($avisada->status)->toBe(ReportExportStatus::Completed)
        ->and($avisada->isDownloadable())->toBeTrue();
})->group('RF-IN-06');

it('la nomina no admite PDF y el informe por periodo si', function (): void {
    // El catalogo que alimenta a la vez las reglas del `FormRequest` y el `CHECK`
    // de la migracion: un programa de nomina no importa un PDF (decision 5).
    expect(ReportExportKind::Payroll->allows())->toBe(['csv', 'xlsx'])
        ->and(ReportExportKind::Period->allows())->toBe(['csv', 'xlsx', 'pdf']);
})->group('RF-IN-06', 'RF-IN-07');

it('purgar minimiza la fila: sin alcance y sin los filtros que señalan a alguien', function (): void {
    /*
     * RL-11. Al perder el fichero, la fila pierde los tres campos que señalan a
     * personas. Lo que queda describe **que** se genero y ya no **de quien**, y
     * por eso `report_exports` puede conservarse sin plazo —y no necesita entrar
     * en `RetentionScope`—.
     *
     * El hecho completo no se pierde: `report_export.requested` guardo los
     * parametros y el alcance enteros en `audit_log`, que se conserva cuatro años
     * (RL-02).
     */
    $conFiltro = new ReportExport(
        id: 1,
        uuid: '019a12b4-5c6d-7e8f-9012-3456789abcde',
        kind: ReportExportKind::Period,
        format: 'csv',
        status: ReportExportStatus::Completed,
        parameters: new ReportExportParameters(
            from: '2026-03-01',
            to: '2026-03-31',
            granularity: ReportGranularity::Day,
            grouping: ReportGrouping::Employee,
            includeOpenShifts: false,
            departmentId: 7,
            employeeUuid: '019a0000-0000-7000-8000-000000000002',
        ),
        scope: AccessScope::forDepartments(7),
        requestedByUserId: 7,
        requestedByUuid: '019a12b4-0000-7000-8000-000000000001',
        requestedByName: 'Direccion',
        requestedAt: ReportExportFixtures::at('2026-03-08T09:00:00+00:00'),
        startedAt: ReportExportFixtures::at('2026-03-08T09:00:05+00:00'),
        completedAt: ReportExportFixtures::at('2026-03-08T09:02:00+00:00'),
        failedAt: null,
        failureReason: null,
        filePath: '/tmp/kronoqr-horas.csv',
        fileName: 'kronoqr-horas-2026-03-01_2026-03-31.csv',
        sizeBytes: 4096,
        sha256: str_repeat('a', 64),
        rowCount: 31,
        criteria: ['Los totales salen del registro horario ya consolidado.'],
        expiresAt: ReportExportFixtures::at('2026-03-15T09:02:00+00:00'),
        purgedAt: null,
        downloadTokenHash: null,
        downloadTokenExpiresAt: null,
        downloadedAt: null,
        downloadCount: 0,
        notifiedAt: null,
        notificationChannel: null,
    );

    $purgada = $conFiltro->purge(ReportExportFixtures::at('2026-03-15T04:25:00+00:00'));

    // Lo que se va: los tres campos que dicen DE QUIEN era el informe.
    expect($purgada->scope)->toBeNull()
        ->and($purgada->parameters->employeeUuid)->toBeNull()
        ->and($purgada->parameters->departmentId)->toBeNull();

    // Lo que se queda: lo que dice QUE se genero, y sin lo cual la lista de
    // exportaciones purgadas no podria explicarse a si misma.
    expect($purgada->parameters->from)->toBe('2026-03-01')
        ->and($purgada->parameters->to)->toBe('2026-03-31')
        ->and($purgada->parameters->granularity)->toBe(ReportGranularity::Day)
        ->and($purgada->parameters->grouping)->toBe(ReportGrouping::Employee)
        ->and($purgada->format)->toBe('csv')
        ->and($purgada->sha256)->toHaveLength(64)
        ->and($purgada->rowCount)->toBe(31)
        ->and($purgada->fileName)->toBeString();
})->group('RL-11', 'RF-IN-06');
