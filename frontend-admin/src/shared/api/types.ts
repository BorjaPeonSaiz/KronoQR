// Alias de los tipos del contrato. NO se declara aqui ninguna forma de la API:
// todo sale de `schema.d.ts`, que genera `npm run api:generate` desde
// `docs/api/openapi.yaml` (CLAUDE.md, orden de autoridad 2; ADR-013). Este
// fichero solo pone nombres cortos para no escribir
// `components['schemas']['Employee']` en cada componente.
import type { components } from './schema'

type Schemas = components['schemas']

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
export type Department = Schemas['Department']
export type DepartmentCollection = Schemas['DepartmentCollection']

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
