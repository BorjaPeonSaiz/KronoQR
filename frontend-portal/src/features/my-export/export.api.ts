// Descarga de mi propio historico (RF-ID-05, RL-05, art. 20 RGPD).
//
// **Solo CSV en esta version.** `format=csv` es el unico valor admitido hoy;
// el PDF llega con la maquinaria de exportacion de la tarea 2.9 como un valor
// mas del mismo parametro (ADR-012, cambio aditivo). Se envia explicito y no
// se omite -aunque hoy sea tambien el valor por omision del servidor- porque
// es la forma prevista por el contrato de seleccionar formato, y omitirlo
// dejaria de pedirlo el dia que haya mas de uno.
import type { BinaryDocument } from '@kronoqr/web-kit/http'
import { requestBlob } from '@kronoqr/web-kit/http'
import type { WorkDateRange } from '../my-records/workdays.api'

export async function exportMyWorkDaysCsv(range: WorkDateRange): Promise<BinaryDocument> {
  const document_ = await requestBlob('/api/v1/me/export', 'mi-registro-horario.csv', {
    accept: 'text/csv, application/problem+json',
    query: {
      format: 'csv',
      from: range.from === '' ? undefined : range.from,
      to: range.to === '' ? undefined : range.to,
    },
  })

  if (document_ === null) {
    // El endpoint del historico propio nunca responde 204: un periodo sin
    // jornadas devuelve igualmente un CSV con su cabecera de criterios.
    throw new Error('La exportacion del historico propio ha llegado vacia.')
  }

  return document_
}
