// Exportaciones de informes en segundo plano (RF-IN-06, tarea 3.9): informes
// grandes que no caben en una respuesta sincrona, con notificacion en el panel
// y enlace de descarga caducable y de un solo uso (ADR-041). Las formas salen
// del contrato (`docs/api/openapi.yaml`, `operationId: requestReportExport`,
// `listReportExports`, `showReportExport`, `downloadReportExport`); aqui no se
// inventa ninguna.
//
// **EL ENLACE DE DESCARGA NUNCA SE GUARDA NI SE REUTILIZA** (decision 3 de la
// ficha, ADR-041): cada `GET /reports/exports/{uuid}` de una fila `completed`
// emite un token nuevo que invalida el anterior, y la LISTA nunca lo trae. Por
// eso `downloadReportExportFile` exige que quien la llama acabe de pedir el
// estado: no hay ninguna funcion aqui que memorice un enlace entre una llamada
// y la siguiente.
//
// **SE DESCARGA CON `fetch`, NUNCA CON UNA NAVEGACION DE PAGINA COMPLETA**,
// aunque el enlace no lleve `Authorization` (va sin sesion a proposito, para
// que funcione desde un correo): solo asi un `410` -enlace usado o caducado-
// se puede explicar en la propia pantalla en vez de dejar que el navegador
// enseñe el JSON crudo del problema y abandone la SPA.
import type { BinaryDocument } from '@kronoqr/web-kit/http'
import { apiBaseUrl, isApiError, requestBlob, requestJson } from '@kronoqr/web-kit/http'
import type {
  ReportExport,
  ReportExportCollection,
  ReportExportRequest,
  ReportExportResource,
} from '@/shared/api/types'
import type { PeriodReportQuery } from './periodReport.api'

/** `period` (RF-IN-01..03) o `payroll` (RF-IN-07): los dos tipos que admite `kind`. */
export type ReportExportKind = ReportExport['kind']
/** `payroll` solo admite `csv`/`xlsx` (contrato): un PDF no lo importa ningun programa de nomina. */
export type ReportExportFormat = ReportExport['format']
export type ReportExportStatus = ReportExport['status']

export interface RequestReportExportInput extends PeriodReportQuery {
  kind: ReportExportKind
  format: ReportExportFormat
}

/**
 * El cuerpo exacto de `ReportExportRequest`: a diferencia del informe
 * sincrono, el contrato declara `granularity`/`group_by`/`include_open_shifts`
 * como propiedades siempre presentes (con valor de serie documentado, pero
 * sin `?` en el esquema), asi que aqui se rellenan explicitamente en vez de
 * omitirlas.
 */
function toRequestBody(input: RequestReportExportInput): ReportExportRequest {
  return {
    kind: input.kind,
    format: input.format,
    from: input.from,
    to: input.to,
    granularity: input.granularity ?? 'day',
    group_by: input.groupBy ?? 'employee',
    include_open_shifts: input.includeOpenShifts === true,
    ...(input.departmentId === undefined ? {} : { department_id: input.departmentId }),
    ...(input.employeeUuid === undefined ? {} : { employee_uuid: input.employeeUuid }),
  }
}

/**
 * El `409` de `POST /api/v1/reports/exports` cuando el solicitante YA tiene
 * una exportacion `pending`/`running` (contrato,
 * `urn:kronoqr:problem:report-export-in-progress`): una por persona, no por
 * instalacion. Lleva la fila en curso en `export`, igual que el mismo patron
 * de `data-export-in-progress` (`dataExport.api.ts`).
 */
interface ReportExportInProgressProblem {
  type: string
  export: ReportExport
}

function isReportExportInProgress(problem: unknown): problem is ReportExportInProgressProblem {
  return (
    typeof problem === 'object' &&
    problem !== null &&
    (problem as { type?: unknown }).type === 'urn:kronoqr:problem:report-export-in-progress' &&
    typeof (problem as { export?: unknown }).export === 'object'
  )
}

/**
 * Pide una exportacion en segundo plano (RF-IN-06, `kind: period`; RF-IN-07,
 * `kind: payroll`). Devuelve siempre la fila que hay que enseñar, tanto si la
 * acaba de crear (`202`, `pending`) como si ya habia una en curso (`409`, con
 * la fila en `export`): la vista no distingue los dos casos, igual que
 * `requestDataExport`.
 */
export async function requestReportExport(
  input: RequestReportExportInput,
): Promise<ReportExportResource> {
  try {
    return await requestJson<ReportExportResource>('/api/v1/reports/exports', {
      method: 'POST',
      body: toRequestBody(input),
    })
  } catch (failure) {
    if (
      isApiError(failure) &&
      failure.status === 409 &&
      isReportExportInProgress(failure.problem)
    ) {
      return { data: failure.problem.export }
    }

    throw failure
  }
}

/** Las 20 exportaciones mas recientes DEL SOLICITANTE (contrato): ni de otro, ni de toda la instalacion. */
export function listReportExports(): Promise<ReportExportCollection> {
  return requestJson<ReportExportCollection>('/api/v1/reports/exports')
}

/**
 * El estado de una exportacion y, si esta `completed` y no ha caducado, un
 * enlace de descarga **nuevo** (ADR-041). Cada llamada gasta el enlace
 * anterior: no se debe llamar «por si acaso», solo justo antes de ofrecer la
 * descarga.
 */
export function showReportExport(uuid: string): Promise<ReportExportResource> {
  return requestJson<ReportExportResource>(`/api/v1/reports/exports/${uuid}`)
}

/**
 * Detecta el `422` de `ReportTooLargeForSynchronousDelivery` (backend,
 * `urn:kronoqr:problem:report-too-large`, contrato): el informe no cabe en
 * una respuesta sincrona y la salida es pedirlo en diferido
 * (`POST /reports/exports`). Se comprueba por `type`, nunca por el texto de
 * `detail`, que es explicacion para quien depura y no un contrato (regla del
 * cliente HTTP compartido, `@kronoqr/web-kit/http`).
 */
export function reportExceedsSynchronousBudget(error: unknown): boolean {
  return (
    isApiError(error) &&
    error.status === 422 &&
    error.problem?.type === 'urn:kronoqr:problem:report-too-large'
  )
}

/** Nombre de reserva si la descarga llegara sin `Content-Disposition`, que el contrato siempre manda. */
const FALLBACK_REPORT_EXPORT_FILENAME = 'kronoqr-informe.csv'

/**
 * Descarga el fichero de un enlace de `ReportExportDownload.url` (RF-IN-06).
 *
 * **Sin `Authorization`**: el enlace es de un solo uso y va pensado para
 * abrirse sin sesion (contrato, `security: reportDownloadToken`, ADR-041),
 * asi que se pide en modo `anonymous` aunque haya sesion activa. Un enlace ya
 * usado o caducado llega como `410` (`ApiError` normal, que la pantalla
 * explica con `ErrorNotice`).
 *
 * `url` llega como ruta relativa a este mismo servidor (contrato); se admite
 * tambien una URL absoluta por si acaso, quitandole el origen antes de pedirla.
 */
export async function downloadReportExportFile(url: string): Promise<BinaryDocument> {
  const base = apiBaseUrl()
  const path = base !== '' && url.startsWith(base) ? url.slice(base.length) : url

  const document_ = await requestBlob(path, FALLBACK_REPORT_EXPORT_FILENAME, {
    anonymous: true,
    accept:
      'text/csv, application/vnd.openxmlformats-officedocument.spreadsheetml.sheet, application/pdf, application/problem+json',
  })

  if (document_ === null) {
    // El endpoint nunca responde 204: una exportacion completada siempre trae
    // el fichero.
    throw new Error('La descarga de la exportación ha llegado vacía.')
  }

  return document_
}

/**
 * Clave de TanStack Query compartida por `ReportExportsPanel` (que la sondea)
 * y por `PeriodReportView`/`PayrollExportView` (que, tras pedir una
 * exportacion nueva, escriben la fila recien creada en la misma cache para que
 * el panel la enseñe en el acto, sin esperar al primer sondeo -mismo patron
 * que `dataExport.api.ts`/`DataExportPanel.vue`, tarea 5.10-).
 */
export const REPORT_EXPORTS_QUERY_KEY = ['report-exports'] as const
