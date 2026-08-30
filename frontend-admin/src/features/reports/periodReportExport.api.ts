// Descarga del informe de horas por periodo en CSV, XLSX o PDF (RF-IN-04).
//
// **ES EL MISMO INFORME QUE `periodReport.api.ts`, CON OTRA PRESENTACION.** Se
// manda exactamente la misma `PeriodReportQuery` mas el formato: si esta funcion
// construyera su propia consulta, el fichero que alguien adjunta a un correo y
// la tabla que estaba mirando podrian discrepar, y el que se creeria seria el
// equivocado. Por eso el tipo de la consulta se importa y no se copia.
//
// **NO SE PARSEA NADA.** El cuerpo es un fichero con las horas de personas
// identificadas: se descarga y se suelta, como el CSV de la exportacion legal.
// Pintarlo en pantalla lo dejaria vivo en memoria y en el historial de una
// sesion compartida.
//
// **SE DESCARGA CON `fetch` Y EL TOKEN, NO CON UN `<a href>`.** Un enlace directo
// no lleva `Authorization`, asi que la unica forma de que funcionara seria poner
// el token en la URL — donde acabaria en el historial del navegador, en los logs
// del proxy inverso y en la cabecera `Referer`.
import type { BinaryDocument } from '@kronoqr/web-kit/http'
import { requestBlob } from '@kronoqr/web-kit/http'
import type { PeriodReportQuery } from './periodReport.api'

/** Los tres formatos de fichero del contrato. `json` no es uno de ellos. */
export type PeriodReportFormat = 'csv' | 'xlsx' | 'pdf'

/** `Accept` de cada formato, para que un error llegue como `problem+json`. */
const ACCEPT: Record<PeriodReportFormat, string> = {
  csv: 'text/csv, application/problem+json',
  xlsx: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet, application/problem+json',
  pdf: 'application/pdf, application/problem+json',
}

export interface PeriodReportDownload {
  document: BinaryDocument
  /**
   * Huella SHA-256 del contenido, tal cual la publica el servidor. Es la misma
   * para los tres formatos del mismo informe y la misma que imprime el pie del
   * PDF: sirve para decir «este papel y este fichero son el mismo informe».
   *
   * `null` si el servidor no la mandara, que hoy no ocurre.
   */
  digest: string | null
}

export async function downloadPeriodReport(
  query: PeriodReportQuery,
  format: PeriodReportFormat,
): Promise<PeriodReportDownload> {
  const document_ = await requestBlob(
    '/api/v1/reports/period/export',
    // Solo un respaldo: el nombre real lo pone el `Content-Disposition` del
    // servidor, que es quien decide que el fichero no lleve el nombre de nadie.
    `kronoqr-horas-${query.from}_${query.to}.${format}`,
    {
      accept: ACCEPT[format],
      query: {
        format,
        from: query.from,
        to: query.to,
        granularity: query.granularity,
        group_by: query.groupBy,
        department_id: query.departmentId,
        employee_uuid: query.employeeUuid,
        include_open_shifts: query.includeOpenShifts === true ? true : undefined,
      },
    },
  )

  if (document_ === null) {
    // El endpoint nunca responde 204: un periodo sin filas devuelve un fichero
    // con sus criterios, porque «no hay nadie con horas ahi» tambien es una
    // afirmacion que hay que poder descargar.
    throw new Error('La descarga del informe ha llegado vacia.')
  }

  return { document: document_, digest: document_.headers.get('X-Kronoqr-Report-Digest') }
}
