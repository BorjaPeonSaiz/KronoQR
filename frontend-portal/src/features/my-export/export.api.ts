// Descarga de mi propio historico (RF-ID-05, RL-05, art. 20 RGPD).
//
// **CSV o PDF** (`format`, ADR-012: el PDF, sellado, llego como un valor mas
// del mismo enumerado, PR19). El formato se envia siempre explicito. El nombre
// del fichero es el que manda el servidor en `Content-Disposition`
// (`mi-registro-horario-{from}_{to}.{csv|pdf}`, sin nombre ni codigo de nadie):
// aqui solo hay un nombre de reserva por si faltara la cabecera.
import type { BinaryDocument } from '@kronoqr/web-kit/http'
import { requestBlob } from '@kronoqr/web-kit/http'
import type { WorkDateRange } from '../my-records/workdays.api'

export type ExportFormat = 'csv' | 'pdf'

const ACCEPT: Readonly<Record<ExportFormat, string>> = {
  csv: 'text/csv, application/problem+json',
  pdf: 'application/pdf, application/problem+json',
}

export async function exportMyWorkDays(
  range: WorkDateRange,
  format: ExportFormat,
): Promise<BinaryDocument> {
  const document_ = await requestBlob('/api/v1/me/export', `mi-registro-horario.${format}`, {
    accept: ACCEPT[format],
    query: {
      format,
      from: range.from === '' ? undefined : range.from,
      to: range.to === '' ? undefined : range.to,
    },
  })

  if (document_ === null) {
    // El endpoint del historico propio nunca responde 204: un periodo sin
    // jornadas devuelve igualmente un fichero con su cabecera de criterios.
    throw new Error('La exportacion del historico propio ha llegado vacia.')
  }

  return document_
}
