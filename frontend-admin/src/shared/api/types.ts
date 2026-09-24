// Alias de los tipos del contrato. NO se declara aqui ninguna forma de la API:
// todo sale de `schema.d.ts`, que genera `npm run api:generate` desde
// `docs/api/openapi.yaml` (CLAUDE.md, orden de autoridad 2; ADR-013). Este
// fichero solo pone nombres cortos para no escribir
// `components['schemas']['Employee']` en cada componente.
import type { components } from './schema'

type Schemas = components['schemas']
/** Parametros con forma cerrada declarados aparte de los esquemas (p. ej. los enums de `GET /reports/payroll-export`). */
type Parameters_ = components['parameters']

export type ManagementUser = Schemas['ManagementUser']
export type UserRole = Schemas['UserRole']
export type Session = Schemas['Session']
export type LoginRequest = Schemas['LoginRequest']

// Segundo factor obligatorio de las cuentas de gestion (RS-06, tarea 2.1).
export type TwoFactorChallenge = Schemas['TwoFactorChallenge']
export type TwoFactorCode = Schemas['TwoFactorCode']
export type TwoFactorEnrolment = Schemas['TwoFactorEnrolment']

export type Employee = Schemas['Employee']
export type EmployeeCollection = Schemas['EmployeeCollection']
export type EmployeeProvisioned = Schemas['EmployeeProvisioned']
export type EmploymentStatus = Schemas['EmploymentStatus']
export type CreateEmployeeRequest = Schemas['CreateEmployeeRequest']
export type UpdateEmployeeRequest = Schemas['UpdateEmployeeRequest']
export type OffboardEmployeeRequest = Schemas['OffboardEmployeeRequest']
export type PageMeta = Schemas['PageMeta']

export type PinStatus = Schemas['PinStatus']
export type IssuedPin = Schemas['IssuedPin']
export type PinDeliveryReceipt = Schemas['PinDeliveryReceipt']

export type Site = Schemas['Site']

// Perfil de cumplimiento: los umbrales LEGALES del centro (RF-PD-07, tarea 5.2).
export type ComplianceProfile = Schemas['ComplianceProfile']
export type ComplianceProfileBody = Schemas['ComplianceProfileBody']
export type UpdateComplianceProfileRequest = Schemas['UpdateComplianceProfileRequest']

// Licencia (RF-PD-04, RF-PD-05, tarea 5.3). `LicenseFeature` son las
// funcionalidades ACCESORIAS de ADR-023 y ninguna otra: el registro legal no es
// licenciable y no tiene valor en ese enum.
export type License = Schemas['License']
export type LicenseState = Schemas['LicenseState']
export type LicenseFeature = Schemas['LicenseFeature']
export type ActivateLicenseRequest = Schemas['ActivateLicenseRequest']
export type Department = Schemas['Department']
export type DepartmentCollection = Schemas['DepartmentCollection']
export type CreateDepartmentRequest = Schemas['CreateDepartmentRequest']

// Configuracion de la instalacion (RF-PD-01, tarea 5.1). El paso de
// organizacion del asistente (tarea 5.5) escribe `BRANDING_APP_NAME` y las
// claves `LOCALE_*`; la tarea 5.8 leera y escribira el resto del catalogo por
// el mismo sitio.
export type SettingKey = Schemas['SettingKey']
export type SettingValue = Schemas['SettingValue']
export type SettingType = Schemas['SettingType']
export type SettingImpact = Schemas['SettingImpact']
export type SettingSource = Schemas['SettingSource']
export type SettingConstraints = Schemas['SettingConstraints']
export type InstallationSetting = Schemas['InstallationSetting']
export type InstallationSettings = Schemas['InstallationSettings']
export type UpdateSettingsRequest = Schemas['UpdateSettingsRequest']

// Marca de la instalacion (RF-PD-08, tarea 5.8): la proyeccion PUBLICA de cinco
// claves del catalogo de arriba, tal como la sirve `GET /api/v1/branding` (sin
// sesion). Esta es la forma en bruto del contrato, en snake_case; el estado
// reactivo que consume la aplicacion es el `Branding` en camelCase de
// `@kronoqr/web-kit/branding` (`parseBranding` convierte de uno a otro y no
// lanza ante una respuesta con otra forma).
export type Branding = Schemas['Branding']

// Asistente de puesta en marcha (RF-PD-03, RF-GP-05, tarea 5.5).
export type SetupStep = Schemas['SetupStep']
export type SetupStepState = Schemas['SetupStepState']
export type SetupStepStatus = Schemas['SetupStepStatus']
export type SetupStatus = Schemas['SetupStatus']
export type SetupSummary = Schemas['SetupSummary']
export type SetupCompletion = Schemas['SetupCompletion']
export type CreateFirstAdministratorRequest = Schemas['CreateFirstAdministratorRequest']
export type CreateInstallationSiteRequest = Schemas['CreateInstallationSiteRequest']
export type RecordSetupStepRequest = Schemas['RecordSetupStepRequest']

// Importacion masiva de plantilla (RF-GP-05, paso «employees» del asistente).
export type EmployeeImportMode = Schemas['EmployeeImportMode']
export type EmployeeImportOutcome = Schemas['EmployeeImportOutcome']
export type EmployeeImportMessage = Schemas['EmployeeImportMessage']
export type EmployeeImportRow = Schemas['EmployeeImportRow']
export type EmployeeImportReport = Schemas['EmployeeImportReport']

export type Credential = Schemas['Credential']
export type IssuedCredential = Schemas['IssuedCredential']
export type IssueCredentialRequest = Schemas['IssueCredentialRequest']
export type RevokeCredentialRequest = Schemas['RevokeCredentialRequest']
export type PrintCredentialBatchRequest = Schemas['PrintCredentialBatchRequest']
export type CredentialLifecycleStatus = Schemas['CredentialLifecycleStatus']
export type CredentialStatusRow = Schemas['CredentialStatusRow']
export type CredentialStatusBoard = Schemas['CredentialStatusBoard']
export type CredentialCoverage = Schemas['CredentialCoverage']

export type EmployeeWorkDays = Schemas['EmployeeWorkDays']
export type WorkDayDetail = Schemas['WorkDayDetail']
export type WorkDayShiftEntry = Schemas['WorkDayShiftEntry']
export type WorkDayCorrection = Schemas['WorkDayCorrection']
export type CorrectionAuthor = Schemas['CorrectionAuthor']
export type CorrectionAction = Schemas['CorrectionAction']
export type CorrectionReasonCode = Schemas['CorrectionReasonCode']
export type ShiftMarks = Schemas['ShiftMarks']
export type ShiftEntryStatus = Schemas['ShiftEntryStatus']
export type ClockingSource = Schemas['ClockingSource']

// Correccion del registro horario (RF-PA-04, RN-13, ADR-026, ADR-035, tarea
// 5.11b): alta manual, rectificar y anular un tramo. Las tres piden un motivo
// del catalogo cerrado (`CorrectionReasonCode`, ya alias arriba) y devuelven
// `CorrectedShiftEntry` entero.
export type AddShiftEntryRequest = Schemas['AddShiftEntryRequest']
export type CorrectShiftEntryRequest = Schemas['CorrectShiftEntryRequest']
export type VoidShiftEntryRequest = Schemas['VoidShiftEntryRequest']
export type CorrectedShiftEntry = Schemas['CorrectedShiftEntry']

// `Problem`/`ValidationProblem` (RFC 9457) ya no se alias aqui: el cliente HTTP
// base que los consumia vive en `@kronoqr/web-kit/http`, que declara su propia
// forma estructural para no depender del `schema.d.ts` de ninguna SPA
// (ADR-036).

// Presencia en vivo (RF-PA-01, RF-PA-02, tarea 2.4). `PresenceUpdatedMessage`
// es el cuerpo del mensaje del WebSocket, descrito como webhook en el contrato.
export type LivePresenceBoard = Schemas['LivePresenceBoard']
export type LivePresenceEntry = Schemas['LivePresenceEntry']
export type LivePresenceMeta = Schemas['LivePresenceMeta']
export type LivePresenceStatus = Schemas['LivePresenceStatus']
export type RealtimeSubscription = Schemas['RealtimeSubscription']
export type PresenceUpdatedMessage = Schemas['PresenceUpdatedMessage']

// Bandeja de incidencias (RF-PA-05, RF-PR-01, tarea 2.5).
export type Incident = Schemas['Incident']
export type IncidentCollection = Schemas['IncidentCollection']
export type IncidentPageMeta = Schemas['IncidentPageMeta']
export type IncidentEmployee = Schemas['IncidentEmployee']
export type IncidentUser = Schemas['IncidentUser']
export type IncidentContext = Schemas['IncidentContext']
export type IncidentType = Schemas['IncidentType']
export type IncidentSeverity = Schemas['IncidentSeverity']
export type IncidentStatus = Schemas['IncidentStatus']
export type IncidentOutcome = Schemas['IncidentOutcome']
export type ResolveIncidentRequest = Schemas['ResolveIncidentRequest']
export type WorkDayIncident = Schemas['WorkDayIncident']

// Informe de horas por periodo y contratos historizados (RF-IN-01..03,
// RF-GP-02, tarea 2.8).
export type PeriodReport = Schemas['PeriodReport']
export type PeriodReportRow = Schemas['PeriodReportRow']
export type PeriodReportSubject = Schemas['PeriodReportSubject']
export type PeriodReportMeta = Schemas['PeriodReportMeta']
export type ContractCoverage = Schemas['ContractCoverage']
export type ReportGranularity = Schemas['ReportGranularity']
export type ReportGrouping = Schemas['ReportGrouping']
export type EmploymentContract = Schemas['EmploymentContract']
export type EmploymentContractCollection = Schemas['EmploymentContractCollection']
export type CreateEmploymentContractRequest = Schemas['CreateEmploymentContractRequest']
export type ScheduleType = Schemas['ScheduleType']

// Ausencias (RF-GP-04, tarea 3.10): vacaciones, baja medica y permiso, sin
// flujo de aprobacion (doc 05 §8). `AbsenceDetail` envuelve la vigente
// (`absence`) y sus versiones anteriores (`history`, de la mas antigua a la
// mas reciente, sin incluir la propia).
export type AbsenceType = Schemas['AbsenceType']
export type AbsenceStatus = Schemas['AbsenceStatus']
export type Absence = Schemas['Absence']
export type AbsenceDetail = Schemas['AbsenceDetail']
export type AbsenceCollection = Schemas['AbsenceCollection']
export type CreateAbsenceRequest = Schemas['CreateAbsenceRequest']
export type CorrectAbsenceRequest = Schemas['CorrectAbsenceRequest']
export type VoidAbsenceRequest = Schemas['VoidAbsenceRequest']
export type AbsenceImportOutcome = Schemas['AbsenceImportOutcome']
export type AbsenceImportMessage = Schemas['AbsenceImportMessage']
export type AbsenceImportRow = Schemas['AbsenceImportRow']
export type AbsenceImportReport = Schemas['AbsenceImportReport']

// Emparejamiento de quiosco por codigo (RF-PD-06, tarea 5.6). El panel solo
// confirma: `PairingRequested`/`PairingClaim*` son cosa de la tablet
// (frontend-kiosk) y no se alias aqui.
export type PairingConfirmRequest = Schemas['PairingConfirmRequest']
export type PairingConfirmed = Schemas['PairingConfirmed']
export type PairingCodeRejected = Schemas['PairingCodeRejected']
export type Device = Schemas['Device']
export type DeviceList = Schemas['DeviceList']
// Salud del quiosco y reloj/umbrales del servidor (RF-PA-07, tarea 3.3): el
// veredicto lo calcula el servidor con la misma regla que `kiosk:health`, y
// `meta` es lo que el panel necesita para no adivinar ni la hora ni el umbral.
export type DeviceHealth = Schemas['DeviceHealth']
export type DeviceListMeta = Schemas['DeviceListMeta']
export type KioskHealthThresholds = Schemas['KioskHealthThresholds']

// Diagnostico y accesos de soporte (RF-PD-09, RF-PD-11, RF-PD-13, tarea 5.9,
// ADR-020). El paquete se descarga tal cual llega: el panel no lo interpreta,
// solo lo guarda, y por eso el unico tipo que lee de el es el `manifest`.
export type DiagnosticsBundleRequest = Schemas['DiagnosticsBundleRequest']
export type DiagnosticsBundle = Schemas['DiagnosticsBundle']
export type DiagnosticsManifest = Schemas['DiagnosticsManifest']
export type DoctorReport = Schemas['DoctorReport']
export type DoctorCheck = Schemas['DoctorCheck']
export type SupportScope = Schemas['SupportScope']
export type SupportGrant = Schemas['SupportGrant']
export type SupportGrantCollection = Schemas['SupportGrantCollection']
export type GrantSupportAccessRequest = Schemas['GrantSupportAccessRequest']
export type IssuedSupportGrant = Schemas['IssuedSupportGrant']

// Exportacion integra de los datos de la instalacion (RF-PD-14, RL-20, tarea
// 5.10). Nunca se interpreta su contenido en el panel: el ZIP se descarga tal
// cual llega, igual que el paquete de diagnostico (`DiagnosticsBundle`).
export type DataExport = Schemas['DataExport']
export type DataExportResource = Schemas['DataExportResource']
export type DataExportCollection = Schemas['DataExportCollection']

// Informes generados en diferido (RF-IN-06, RF-IN-07, ADR-041, tarea 3.9): el
// informe por periodo y la salida a nomina comparten el mismo ciclo de vida.
// `download` solo lo emite `GET /reports/exports/{uuid}` (`ShowReportExport`,
// decision 3 de la ficha): nunca la lista ni el `202` de la peticion.
export type ReportExportRequest = Schemas['ReportExportRequest']
export type ReportExportDownload = Schemas['ReportExportDownload']
export type ReportExport = Schemas['ReportExport']
export type ReportExportParameters = Schemas['ReportExportParameters']
export type ReportExportResource = Schemas['ReportExportResource']
export type ReportExportCollection = Schemas['ReportExportCollection']

// Salida a nomina sincrona (RF-IN-07, `operationId: exportPayroll`). `csv`/`xlsx`
// nada mas -un PDF no lo importa ningun programa de nomina- y tres
// granularidades, `range` por omision (al contrario que el informe por
// periodo, donde `day` es el grano de la fuente).
export type PayrollExportFormat = Parameters_['PayrollExportFormat']
export type PayrollExportGranularity = Parameters_['PayrollGranularity']

// Historico de errores agrupado por huella (RF-PD-15, tarea 5.12). El envio de
// los tres clientes (`ClientErrorReport`/`ClientErrorBatch`/
// `ClientErrorsAccepted`) lo consume `@kronoqr/web-kit/clientErrorTransport`,
// que define su propio cuerpo local y no importa estos tipos (ADR-036: el
// paquete comun no depende del `schema.d.ts` de una SPA concreta).
export type ErrorEvent = Schemas['ErrorEvent']
export type ErrorEventCollection = Schemas['ErrorEventCollection']
export type ErrorEventPageMeta = Schemas['ErrorEventPageMeta']
export type ErrorSource = Schemas['ErrorSource']
export type ErrorLevel = Schemas['ErrorLevel']
export type ClientErrorReport = Schemas['ClientErrorReport']
export type ClientErrorBatch = Schemas['ClientErrorBatch']
export type ClientErrorsAccepted = Schemas['ClientErrorsAccepted']

// Vista de cumplimiento (RF-PA-06, tarea 3.4): descanso insuficiente entre
// jornadas (RN-10), jornada diaria excesiva (RN-11), tramo continuado sin
// pausa (RN-12, suspendida) y exceso semanal informativo (RN-17). El umbral de
// cada regla y el perfil que lo fija viajan siempre en `meta` (regla dura 14):
// nunca se copia un limite legal en el panel.
export type ComplianceSummary = Schemas['ComplianceSummary']
export type ComplianceFinding = Schemas['ComplianceFinding']
export type ComplianceSummaryMeta = Schemas['ComplianceSummaryMeta']
export type ComplianceRuleStatus = Schemas['ComplianceRuleStatus']
export type ComplianceRuleName = Schemas['ComplianceRuleName']
export type ComplianceWeek = Schemas['ComplianceWeek']
export type ComplianceIncidentLink = Schemas['ComplianceIncidentLink']
export type ComplianceProfileRef = Schemas['ComplianceProfileRef']
export type ComplianceTotals = Schemas['ComplianceTotals']

// Cuadro de impacto y adopcion (RF-IN-08, RNF-D-01, tarea 3.13): los doce
// indicadores del doc 01 §1.3, con objetivo y comparacion contra el periodo
// anterior. Agregado de la instalacion entera, sin identificadores de persona
// (regla dura 21): `GET /reports/adoption`.
export type AdoptionReport = Schemas['AdoptionReport']
export type AdoptionPeriod = Schemas['AdoptionPeriod']
export type AdoptionIndicator = Schemas['AdoptionIndicator']
export type AdoptionIndicatorKey = Schemas['AdoptionIndicatorKey']
export type AdoptionIndicatorUnit = Schemas['AdoptionIndicatorUnit']
export type AdoptionTarget = Schemas['AdoptionTarget']
export type AdoptionTargetComparison = AdoptionTarget['comparison']
export type AdoptionOriginShare = Schemas['AdoptionOriginShare']
// `GET /reports/adoption/export` (los tres formatos, sin valor por omision:
// quien pulsa un boton de descarga ya ha elegido uno).
export type AdoptionExportFormat = Parameters_['AdoptionExportFormat']
