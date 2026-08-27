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
export type SiteCollection = Schemas['SiteCollection']
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
export type SiteCredentialCoverage = Schemas['SiteCredentialCoverage']

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

export type Problem = Schemas['Problem']
export type ValidationProblem = Schemas['ValidationProblem']
