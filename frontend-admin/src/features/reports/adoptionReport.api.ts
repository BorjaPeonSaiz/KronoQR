// Cuadro de impacto y adopción (RF-IN-08, RNF-D-01, tarea 3.13): los doce
// indicadores del doc 01 §1.3, por periodo y con comparación contra el
// periodo anterior. Las formas salen del contrato (`schema.d.ts`, regenerado
// desde `docs/api/openapi.yaml` por `backend-laravel`); aquí no se inventa
// ninguna (ADR-013).
//
// **NADA SE CALCULA AQUÍ** (regla dura 7): cada valor, delta y objetivo viene
// resuelto del servidor, que es el único que lee `daily_totals`,
// `scan_events`, `shift_corrections`, `incidents`, `credentials` y el
// ajuste `BASELINE_MANUAL_HOURS_PER_MONTH`. Esta pantalla solo construye
// tarjetas de presentación a partir de lo que llega, y convierte minutos a
// `HH:MM` para mostrarlos (nunca decimales ambiguos).
import { isApiError, requestBlob, requestJson } from '@kronoqr/web-kit/http'
import type { BinaryDocument } from '@kronoqr/web-kit/http'
import type { AdoptionExportFormat, AdoptionReport } from '@/shared/api/types'

export interface AdoptionReportQuery {
  /**
   * Primera jornada del cuadro, `YYYY-MM-DD` en la zona del centro. Opcional:
   * sin ella (ni `to`) el servidor toma el mes natural anterior completo, ya
   * resuelto en la zona del centro (regla dura 3): no se adivina en el
   * navegador.
   */
  readonly from?: string
  readonly to?: string
}

export function getAdoptionReport(query: AdoptionReportQuery = {}): Promise<AdoptionReport> {
  return requestJson<AdoptionReport>('/api/v1/reports/adoption', {
    query: { from: query.from, to: query.to },
  })
}

const ACCEPT: Record<AdoptionExportFormat, string> = {
  csv: 'text/csv, application/problem+json',
  xlsx: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet, application/problem+json',
  pdf: 'application/pdf, application/problem+json',
}

export interface AdoptionReportDownload {
  document: BinaryDocument
}

export async function downloadAdoptionReport(
  query: AdoptionReportQuery,
  format: AdoptionExportFormat,
): Promise<AdoptionReportDownload> {
  const document_ = await requestBlob(
    '/api/v1/reports/adoption/export',
    `kronoqr-impacto-adopcion-${query.from ?? ''}_${query.to ?? ''}.${format}`,
    { accept: ACCEPT[format], query: { format, from: query.from, to: query.to } },
  )

  if (document_ === null) {
    throw new Error('La descarga del cuadro de impacto y adopción ha llegado vacía.')
  }

  return { document: document_ }
}

/** `true` si el fallo es un `402`: `impact_dashboard` no está en el plan contratado (regla dura 15, ADR-019/ADR-023). */
export function isAdoptionDashboardLicenseRequired(error: unknown): boolean {
  return isApiError(error) && error.status === 402
}

/**
 * `true` si el `422` es `urn:kronoqr:problem:report-too-large` (rango sobre
 * los 366 días de `DateRange::MAXIMUM_DAYS`), distinguido por `type` y no por
 * el texto del mensaje (que va traducido al idioma de quien pregunta). Este
 * cuadro **no tiene generación en diferido** (son doce filas): la única
 * salida es acortar el rango, al contrario que el informe por periodo.
 */
export function isAdoptionReportTooLarge(error: unknown): boolean {
  return isApiError(error) && error.problem?.type === 'urn:kronoqr:problem:report-too-large'
}
