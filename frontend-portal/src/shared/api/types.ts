// Alias de los tipos del contrato. NO se declara aqui ninguna forma de la API:
// todo sale de `schema.d.ts`, que genera `npm run api:generate` desde
// `docs/api/openapi.yaml` (CLAUDE.md, orden de autoridad 2; ADR-013). Este
// fichero solo pone nombres cortos para no escribir
// `components['schemas']['PortalSession']` en cada componente.
import type { components } from './schema'

type Schemas = components['schemas']

export type PortalLoginRequest = Schemas['PortalLoginRequest']
export type PortalEmployee = Schemas['PortalEmployee']
export type PortalSession = Schemas['PortalSession']

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
