// Salida a nomina, sincrona (RF-IN-07): `GET /api/v1/reports/payroll-export`
// (`operationId: exportPayroll`). Es el mismo informe por periodo agrupado
// por empleado (`PeriodReportReader`, decision 5 de la ficha 3.9), pasado por
// la plantilla configurable de los seis ajustes `PAYROLL_EXPORT_*` -esta
// pantalla no elige columnas ni separador: eso se configura en «Ajustes
// operativos» y aqui solo se lee para la previsualizacion (`settings.api.ts`).
//
// **NO SE PARSEA NADA.** El cuerpo es un fichero con las horas de personas
// identificadas: se descarga y se suelta, como `periodReportExport.api.ts`.
import type { BinaryDocument } from '@kronoqr/web-kit/http'
import { requestBlob } from '@kronoqr/web-kit/http'
import type { PayrollExportFormat, PayrollExportGranularity } from '@/shared/api/types'

export interface PayrollExportQuery {
  from: string
  to: string
  /** `range` por omision en el servidor (al contrario que el informe por periodo, donde `day` es el grano de la fuente). */
  granularity?: PayrollExportGranularity
  departmentId?: number
  /** Identificador **publico** del empleado (`employees.uuid`). */
  employeeUuid?: string
  /** Igual que en el informe por periodo: por omision no (contrato, `ReportIncludeOpenShifts`). */
  includeOpenShifts?: boolean
}

/** `Accept` de cada formato, para que un error llegue como `problem+json` (mismo criterio que `periodReportExport.api.ts`). */
const ACCEPT: Record<PayrollExportFormat, string> = {
  csv: 'text/csv, application/problem+json',
  xlsx: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet, application/problem+json',
}

export interface PayrollExportDownload {
  document: BinaryDocument
  /**
   * Los criterios de inclusion, ya traducidos (contrato:
   * `X-Kronoqr-Export-Criteria`, base64 de UTF-8 unido por `\n` -una cabecera
   * HTTP no admite acentos ni saltos de linea-). Vacio si el servidor no
   * mandara la cabecera, que hoy no ocurre.
   */
  criteria: readonly string[]
  /** `X-Kronoqr-Export-Rows` (contrato): filas de datos, sin contar la cabecera. `null` si no llega. */
  rowCount: number | null
}

/** Decodifica `X-Kronoqr-Export-Criteria` (base64 de UTF-8, unido por `\n`). `[]` si la cabecera no llega. */
function decodeCriteria(header: string | null): readonly string[] {
  if (header === null || header === '') {
    return []
  }

  try {
    const bytes = Uint8Array.from(atob(header), (char) => char.charCodeAt(0))

    return new TextDecoder()
      .decode(bytes)
      .split('\n')
      .filter((line) => line !== '')
  } catch {
    // Una cabecera ilegible no es motivo para tirar la descarga entera: el
    // fichero ya se ha entregado, solo faltan los criterios en pantalla.
    return []
  }
}

export async function downloadPayrollExport(
  query: PayrollExportQuery,
  format: PayrollExportFormat,
): Promise<PayrollExportDownload> {
  const document_ = await requestBlob(
    '/api/v1/reports/payroll-export',
    `kronoqr-nomina-${query.from}_${query.to}.${format}`,
    {
      accept: ACCEPT[format],
      query: {
        format,
        from: query.from,
        to: query.to,
        granularity: query.granularity,
        department_id: query.departmentId,
        employee_uuid: query.employeeUuid,
        include_open_shifts: query.includeOpenShifts === true ? true : undefined,
      },
    },
  )

  if (document_ === null) {
    // El endpoint nunca responde 204: un periodo sin nadie produce un fichero
    // con su fila de cabecera (contrato).
    throw new Error('La descarga de la salida a nómina ha llegado vacía.')
  }

  const rawRows = document_.headers.get('X-Kronoqr-Export-Rows')
  const rowCount = rawRows === null ? Number.NaN : Number.parseInt(rawRows, 10)

  return {
    document: document_,
    criteria: decodeCriteria(document_.headers.get('X-Kronoqr-Export-Criteria')),
    rowCount: Number.isFinite(rowCount) ? rowCount : null,
  }
}
