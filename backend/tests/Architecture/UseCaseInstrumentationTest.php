<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\UseCase\AddShiftEntryHandler;
use App\Modules\Attendance\Application\UseCase\CorrectShiftHandler;
use App\Modules\Attendance\Application\UseCase\DetectAttendanceAnomalies;
use App\Modules\Attendance\Application\UseCase\DetectCredentialPatterns;
use App\Modules\Attendance\Application\UseCase\ReconcileDailyTotals;
use App\Modules\Attendance\Application\UseCase\RegisterPinScanHandler;
use App\Modules\Attendance\Application\UseCase\RegisterScanBatchHandler;
use App\Modules\Attendance\Application\UseCase\RegisterScanHandler;
use App\Modules\Attendance\Application\UseCase\ReportDiscardedScans;
use App\Modules\Attendance\Application\UseCase\VoidShiftHandler;
use App\Modules\Compliance\Application\UseCase\ApplyRetention;
use App\Modules\Compliance\Application\UseCase\EnsureAuditLogPartitions;
use App\Modules\Compliance\Application\UseCase\GenerateLegalExport;
use App\Modules\Compliance\Application\UseCase\NotifyPendingIncidents;
use App\Modules\Compliance\Application\UseCase\OpenIncident;
use App\Modules\Compliance\Application\UseCase\PlanRetention;
use App\Modules\Compliance\Application\UseCase\PublishIncidentMetrics;
use App\Modules\Compliance\Application\UseCase\ReadIncidentBoard;
use App\Modules\Compliance\Application\UseCase\ReconcileWorkRecordWithAudit;
use App\Modules\Compliance\Application\UseCase\RecordAuditEntry;
use App\Modules\Compliance\Application\UseCase\RecordSystemEvent;
use App\Modules\Compliance\Application\UseCase\ResolveIncident;
use App\Modules\Compliance\Application\UseCase\ReviewOutOfBoundsPurge;
use App\Modules\Compliance\Application\UseCase\SweepLegalExportFiles;
use App\Modules\Compliance\Application\UseCase\VerifyAuditChain;
use App\Modules\Identity\Application\UseCase\AuthenticatePortalEmployeeHandler;
use App\Modules\Identity\Application\UseCase\ChangeOwnPasswordHandler;
use App\Modules\Identity\Application\UseCase\CreateManagementAccountHandler;
use App\Modules\Identity\Application\UseCase\CredentialStatusBoard;
use App\Modules\Identity\Application\UseCase\DeactivateManagementAccountHandler;
use App\Modules\Identity\Application\UseCase\DeliverCredential;
use App\Modules\Identity\Application\UseCase\PrintCredential;
use App\Modules\Identity\Application\UseCase\PrintCredentialBatch;
use App\Modules\Identity\Application\UseCase\RenderInstructionsSheet;
use App\Modules\Identity\Application\UseCase\ResetManagementPasswordHandler;
use App\Modules\Identity\Application\UseCase\ResetTwoFactorHandler;
use App\Modules\Identity\Application\UseCase\RetireSigningKey;
use App\Modules\Identity\Application\UseCase\RevokeCredentialsOfOffboardedEmployee;
use App\Modules\Identity\Application\UseCase\RotateSigningKey;
use App\Modules\Kiosk\Application\UseCase\CheckKioskHealth;
use App\Modules\Kiosk\Application\UseCase\ClaimPairing;
use App\Modules\Kiosk\Application\UseCase\ConfirmPairing;
use App\Modules\Kiosk\Application\UseCase\ListDevices;
use App\Modules\Kiosk\Application\UseCase\RecordHeartbeat;
use App\Modules\Kiosk\Application\UseCase\RequestPairing;
use App\Modules\Kiosk\Application\UseCase\UnpairDevice;
use App\Modules\Product\Application\UseCase\ActivateLicenseHandler;
use App\Modules\Product\Application\UseCase\BuildTelemetryReportHandler;
use App\Modules\Product\Application\UseCase\CompleteSetupHandler;
use App\Modules\Product\Application\UseCase\DeliverDiagnosticsBundle;
use App\Modules\Product\Application\UseCase\DescribeLicenseHandler;
use App\Modules\Product\Application\UseCase\DownloadDataExportHandler;
use App\Modules\Product\Application\UseCase\GenerateDataExportHandler;
use App\Modules\Product\Application\UseCase\GenerateDiagnosticsBundleHandler;
use App\Modules\Product\Application\UseCase\GetBrandingHandler;
use App\Modules\Product\Application\UseCase\GetComplianceProfileHandler;
use App\Modules\Product\Application\UseCase\GetKioskManifestHandler;
use App\Modules\Product\Application\UseCase\GetLicenseStatusHandler;
use App\Modules\Product\Application\UseCase\GetSettingsHandler;
use App\Modules\Product\Application\UseCase\GetSetupStateHandler;
use App\Modules\Product\Application\UseCase\GrantSupportAccessHandler;
use App\Modules\Product\Application\UseCase\ListDataExportsHandler;
use App\Modules\Product\Application\UseCase\ListErrorEvents;
use App\Modules\Product\Application\UseCase\ListSupportGrantsHandler;
use App\Modules\Product\Application\UseCase\PruneErrorEvents;
use App\Modules\Product\Application\UseCase\PurgeExpiredDataExportsHandler;
use App\Modules\Product\Application\UseCase\RecordPendingDiagnosticsAudits;
use App\Modules\Product\Application\UseCase\RecordPlanUsageHandler;
use App\Modules\Product\Application\UseCase\RecordSetupStepHandler;
use App\Modules\Product\Application\UseCase\RecordSupportAccessUseHandler;
use App\Modules\Product\Application\UseCase\RequestDataExportHandler;
use App\Modules\Product\Application\UseCase\ResanitizeErrorHistory;
use App\Modules\Product\Application\UseCase\ResolveErrorEvent;
use App\Modules\Product\Application\UseCase\RevokeSupportAccessHandler;
use App\Modules\Product\Application\UseCase\RevokeSupportGrantsOfDeactivatedAccount;
use App\Modules\Product\Application\UseCase\RunDoctorHandler;
use App\Modules\Product\Application\UseCase\SendTelemetryHandler;
use App\Modules\Product\Application\UseCase\SweepExpiredDiagnosticsBundles;
use App\Modules\Product\Application\UseCase\UpdateComplianceProfileHandler;
use App\Modules\Product\Application\UseCase\UpdateSettingsHandler;
use App\Modules\Reporting\Application\UseCase\DownloadReportExport;
use App\Modules\Reporting\Application\UseCase\GenerateReportExportHandler;
use App\Modules\Reporting\Application\UseCase\ListReportExports;
use App\Modules\Reporting\Application\UseCase\PublishAbsenceMetrics;
use App\Modules\Reporting\Application\UseCase\PublishAdoptionMetrics;
use App\Modules\Reporting\Application\UseCase\PublishComplianceMetrics;
use App\Modules\Reporting\Application\UseCase\PublishPresenceMetrics;
use App\Modules\Reporting\Application\UseCase\PurgeExpiredReportExports;
use App\Modules\Reporting\Application\UseCase\RecordAdoptionReportExport;
use App\Modules\Reporting\Application\UseCase\RequestReportExport;
use App\Modules\Reporting\Application\UseCase\SendWeeklySummaries;
use App\Modules\Reporting\Application\UseCase\ShowReportExport;
use App\Modules\Shared\Infrastructure\Metrics\Exposition\MetricCatalogue;
use App\Modules\Shared\Infrastructure\Metrics\Exposition\MetricDefinition;
use App\Modules\Shared\Infrastructure\Metrics\Exposition\MetricType;
use App\Modules\Shared\Infrastructure\Metrics\RedisUseCaseMetrics;
use App\Modules\Workforce\Application\UseCase\EmployeeWriteRetry;
use App\Modules\Workforce\Application\UseCase\ResetEmployeePinHandler;
use Tests\Architecture\Support\ModuleTree;

/*
 * TODO CASO DE USO SE MIDE (doc 02 §8, bloque 24 de la 2.2.1).
 *
 * ## Que se exige
 *
 * Toda clase de `Modules/*\/Application/UseCase/` con un metodo publico
 * `handle`, `__invoke`, `execute` o `run` es un caso de uso —Compliance y Kiosk
 * no usan el sufijo `Handler`, asi que el sufijo no sirve para reconocerlos—, y
 * tiene que llamar a `UseCaseInstrumentation::run('<modulo>.<accion_snake>', …)`
 * con un literal. Las clases sin esos metodos —comandos, resultados, vistas— y
 * los enumerados e interfaces quedan fuera por construccion.
 *
 * ## La lista de excepciones solo puede encoger
 *
 * Cada excepcion lleva un motivo de un catalogo cerrado:
 *
 * - `telemetria_propia`: el caso de uso ya mide con su puerto de metricas o su
 *   `*Telemetry` de `Application`, con atributos que son dominio de cada acto.
 * - `telemetria_http`: lo mide el controlador con un `*Telemetry` de
 *   `Http/Support`.
 * - `sin_logica`: no es un caso de uso aunque viva aqui (un ayudante de
 *   reintento).
 * - `pendiente_pr_b`: lo instrumenta la segunda mitad del bloque 24.
 *
 * La prueba falla en los dos sentidos: por un caso de uso sin medir que no esta
 * en la lista, y por una entrada de la lista que ya usa el ayudante o que ya no
 * existe. Y `USE_CASE_INSTRUMENTATION_MAX_EXCEPTIONS` solo baja: una excepcion
 * nueva exige subirla, y eso se ve en la revision.
 */

const USE_CASE_INSTRUMENTATION_MAX_EXCEPTIONS = 94;

const USE_CASE_INSTRUMENTATION_PATTERN = '/^[a-z]+\.[a-z0-9_]+$/';

const USE_CASE_INSTRUMENTATION_REASONS = ['telemetria_propia', 'telemetria_http', 'sin_logica', 'pendiente_pr_b'];

/** @var array<class-string, string> $excepciones */
$excepciones = [
    // --- Telemetria propia en el caso de uso.
    DetectAttendanceAnomalies::class => 'telemetria_propia',
    DetectCredentialPatterns::class => 'telemetria_propia',
    ReconcileDailyTotals::class => 'telemetria_propia',
    RegisterPinScanHandler::class => 'telemetria_propia',
    RegisterScanBatchHandler::class => 'telemetria_propia',
    RegisterScanHandler::class => 'telemetria_propia',
    ReportDiscardedScans::class => 'telemetria_propia',
    ApplyRetention::class => 'telemetria_propia',
    EnsureAuditLogPartitions::class => 'telemetria_propia',
    GenerateLegalExport::class => 'telemetria_propia',
    PublishIncidentMetrics::class => 'telemetria_propia',
    ReconcileWorkRecordWithAudit::class => 'telemetria_propia',
    ResolveIncident::class => 'telemetria_propia',
    ReviewOutOfBoundsPurge::class => 'telemetria_propia',
    VerifyAuditChain::class => 'telemetria_propia',
    AuthenticatePortalEmployeeHandler::class => 'telemetria_propia',
    ChangeOwnPasswordHandler::class => 'telemetria_propia',
    CreateManagementAccountHandler::class => 'telemetria_propia',
    CredentialStatusBoard::class => 'telemetria_propia',
    DeactivateManagementAccountHandler::class => 'telemetria_propia',
    DeliverCredential::class => 'telemetria_propia',
    PrintCredential::class => 'telemetria_propia',
    PrintCredentialBatch::class => 'telemetria_propia',
    RenderInstructionsSheet::class => 'telemetria_propia',
    ResetManagementPasswordHandler::class => 'telemetria_propia',
    ResetTwoFactorHandler::class => 'telemetria_propia',
    RetireSigningKey::class => 'telemetria_propia',
    RevokeCredentialsOfOffboardedEmployee::class => 'telemetria_propia',
    RotateSigningKey::class => 'telemetria_propia',
    ClaimPairing::class => 'telemetria_propia',
    ConfirmPairing::class => 'telemetria_propia',
    RecordHeartbeat::class => 'telemetria_propia',
    RequestPairing::class => 'telemetria_propia',
    RecordPlanUsageHandler::class => 'telemetria_propia',
    UpdateComplianceProfileHandler::class => 'telemetria_propia',
    UpdateSettingsHandler::class => 'telemetria_propia',
    PublishAbsenceMetrics::class => 'telemetria_propia',
    PublishAdoptionMetrics::class => 'telemetria_propia',
    PublishComplianceMetrics::class => 'telemetria_propia',
    PublishPresenceMetrics::class => 'telemetria_propia',
    SendWeeklySummaries::class => 'telemetria_propia',
    ResetEmployeePinHandler::class => 'telemetria_propia',

    // --- Medidos desde el controlador con un `*Telemetry` de `Http/Support`.
    AddShiftEntryHandler::class => 'telemetria_http',
    CorrectShiftHandler::class => 'telemetria_http',
    VoidShiftHandler::class => 'telemetria_http',
    ReadIncidentBoard::class => 'telemetria_http',
    ListDevices::class => 'telemetria_http',
    ActivateLicenseHandler::class => 'telemetria_http',
    DescribeLicenseHandler::class => 'telemetria_http',
    GetBrandingHandler::class => 'telemetria_http',
    GetKioskManifestHandler::class => 'telemetria_http',
    GetComplianceProfileHandler::class => 'telemetria_http',
    GetSettingsHandler::class => 'telemetria_http',

    // --- No es un caso de uso: el reintento de la transaccion de plantilla.
    EmployeeWriteRetry::class => 'sin_logica',

    // --- Segunda mitad del bloque 24.
    NotifyPendingIncidents::class => 'pendiente_pr_b',
    OpenIncident::class => 'pendiente_pr_b',
    PlanRetention::class => 'pendiente_pr_b',
    RecordAuditEntry::class => 'pendiente_pr_b',
    RecordSystemEvent::class => 'pendiente_pr_b',
    SweepLegalExportFiles::class => 'pendiente_pr_b',
    CheckKioskHealth::class => 'pendiente_pr_b',
    UnpairDevice::class => 'pendiente_pr_b',
    BuildTelemetryReportHandler::class => 'pendiente_pr_b',
    CompleteSetupHandler::class => 'pendiente_pr_b',
    DeliverDiagnosticsBundle::class => 'pendiente_pr_b',
    DownloadDataExportHandler::class => 'pendiente_pr_b',
    GenerateDataExportHandler::class => 'pendiente_pr_b',
    GenerateDiagnosticsBundleHandler::class => 'pendiente_pr_b',
    GetLicenseStatusHandler::class => 'pendiente_pr_b',
    GetSetupStateHandler::class => 'pendiente_pr_b',
    GrantSupportAccessHandler::class => 'pendiente_pr_b',
    ListDataExportsHandler::class => 'pendiente_pr_b',
    ListErrorEvents::class => 'pendiente_pr_b',
    ListSupportGrantsHandler::class => 'pendiente_pr_b',
    PruneErrorEvents::class => 'pendiente_pr_b',
    PurgeExpiredDataExportsHandler::class => 'pendiente_pr_b',
    RecordPendingDiagnosticsAudits::class => 'pendiente_pr_b',
    RecordSetupStepHandler::class => 'pendiente_pr_b',
    RecordSupportAccessUseHandler::class => 'pendiente_pr_b',
    RequestDataExportHandler::class => 'pendiente_pr_b',
    ResanitizeErrorHistory::class => 'pendiente_pr_b',
    ResolveErrorEvent::class => 'pendiente_pr_b',
    RevokeSupportAccessHandler::class => 'pendiente_pr_b',
    RevokeSupportGrantsOfDeactivatedAccount::class => 'pendiente_pr_b',
    RunDoctorHandler::class => 'pendiente_pr_b',
    SendTelemetryHandler::class => 'pendiente_pr_b',
    SweepExpiredDiagnosticsBundles::class => 'pendiente_pr_b',
    DownloadReportExport::class => 'pendiente_pr_b',
    GenerateReportExportHandler::class => 'pendiente_pr_b',
    ListReportExports::class => 'pendiente_pr_b',
    PurgeExpiredReportExports::class => 'pendiente_pr_b',
    RecordAdoptionReportExport::class => 'pendiente_pr_b',
    RequestReportExport::class => 'pendiente_pr_b',
    ShowReportExport::class => 'pendiente_pr_b',
];

/*
 * Los casos de uso del arbol, `clase => codigo fuente`. Se leen como texto y se
 * recorren con `scandir` (ver `ModuleTree`), no por reflexion.
 */
$casosDeUso = static function (): array {
    $found = [];

    foreach (ModuleTree::filesIn('') as $file) {
        $relative = ModuleTree::relative($file);

        if (preg_match('#^[A-Za-z]+/Application/UseCase/.+\.php$#', $relative) !== 1) {
            continue;
        }

        $source = (string) file_get_contents($file);

        if (preg_match('/^(?:(?:final|readonly|abstract)\s+)*class\s+\w+/m', $source) !== 1) {
            continue;
        }

        if (preg_match('/^\s+public\s+function\s+(?:handle|__invoke|execute|run)\s*\(/m', $source) !== 1) {
            continue;
        }

        $found['App\\Modules\\'.str_replace(['/', '.php'], ['\\', ''], $relative)] = $source;
    }

    ksort($found);

    return $found;
};

/**
 * Los literales que cada caso de uso pasa al ayudante.
 *
 * @return list<string>
 */
function useCaseInstrumentationLiterals(string $source): array
{
    preg_match_all("/UseCaseInstrumentation::run\\(\\s*'([^']*)'/", $source, $matches);

    return $matches[1];
}

it('reconoce los casos de uso del arbol (red de seguridad de la prueba)', function () use ($casosDeUso): void {
    // Si el recorrido o el reconocimiento se rompen, las comprobaciones de abajo
    // pasarian sobre un conjunto vacio.
    $todos = $casosDeUso();
    $medidos = array_filter($todos, static fn (string $source): bool => useCaseInstrumentationLiterals($source) !== []);

    expect(\count($todos))->toBeGreaterThan(100)
        ->and(\count($medidos))->toBeGreaterThanOrEqual(32);
})->group('RQ-06', 'RNF-D-01');

it('mide todo caso de uso con el ayudante, salvo la lista de excepciones', function () use ($casosDeUso, $excepciones): void {
    $sinMedir = [];

    foreach ($casosDeUso() as $class => $source) {
        if (useCaseInstrumentationLiterals($source) === [] && ! \array_key_exists($class, $excepciones)) {
            $sinMedir[] = $class;
        }
    }

    expect($sinMedir)->toBe([], 'Casos de uso sin UseCaseInstrumentation::run(...): instrumentalos (doc 02 §8).');
})->group('RQ-06', 'RNF-D-01');

it('no deja en la lista excepciones que ya miden o que ya no existen', function () use ($casosDeUso, $excepciones): void {
    $todos = $casosDeUso();
    $sobran = [];

    foreach (array_keys($excepciones) as $class) {
        if (! \array_key_exists($class, $todos)) {
            $sobran[] = $class.' (ya no existe o ya no es un caso de uso)';
        } elseif (useCaseInstrumentationLiterals($todos[$class]) !== []) {
            $sobran[] = $class.' (ya usa el ayudante)';
        }
    }

    expect($sobran)->toBe([]);
})->group('RQ-06', 'RNF-D-01');

it('solo admite motivos del catalogo cerrado y la lista no crece', function () use ($excepciones): void {
    $motivosDesconocidos = array_values(array_unique(array_diff($excepciones, USE_CASE_INSTRUMENTATION_REASONS)));

    expect($motivosDesconocidos)->toBe([])
        ->and(\count($excepciones))->toBeLessThanOrEqual(USE_CASE_INSTRUMENTATION_MAX_EXCEPTIONS);
})->group('RQ-06', 'RNF-D-01');

it('nombra cada caso de uso con un literal unico de forma modulo.accion', function () use ($casosDeUso): void {
    $vistos = [];
    $malFormados = [];
    $repetidos = [];

    foreach ($casosDeUso() as $class => $source) {
        $module = strtolower(explode('\\', $class)[2]);

        foreach (useCaseInstrumentationLiterals($source) as $useCase) {
            if (preg_match(USE_CASE_INSTRUMENTATION_PATTERN, $useCase) !== 1 || ! str_starts_with($useCase, $module.'.')) {
                $malFormados[] = $class.': '.$useCase;
            }

            if (\array_key_exists($useCase, $vistos)) {
                $repetidos[] = $useCase.' ('.$vistos[$useCase].' y '.$class.')';
            }

            $vistos[$useCase] = $class;
        }
    }

    expect($malFormados)->toBe([])
        ->and($repetidos)->toBe([]);
})->group('RQ-06', 'RNF-D-01');

it('el adaptador valida con la misma forma que exige esta prueba', function (): void {
    expect(RedisUseCaseMetrics::USE_CASE_PATTERN)->toBe(USE_CASE_INSTRUMENTATION_PATTERN);
})->group('RQ-06', 'RNF-D-01');

it('el catalogo declara la serie con exactamente use_case y outcome, sin datos de personas', function (): void {
    $definitions = array_values(array_filter(
        MetricCatalogue::all(),
        static fn (MetricDefinition $definition): bool => $definition->name === 'use_case_duration_seconds',
    ));

    expect($definitions)->toHaveCount(1)
        ->and($definitions[0]->type)->toBe(MetricType::Histogram)
        ->and($definitions[0]->labels)->toBe(['use_case', 'outcome'])
        ->and($definitions[0]->help)->toContain('Nunca lleva datos de personas');
})->group('RQ-06', 'RNF-D-01');
