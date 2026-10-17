// Exportacion normalizada para la Inspeccion de Trabajo (RF-IN-05, RL-06).
//
// **No devuelve JSON y no puede devolverlo.** RL-06 exige formato tabular
// legible y tratable, no propietario: el servidor entrega `text/csv` en UTF-8
// con BOM. Aqui no se parsea nada — el fichero se descarga tal cual y se suelta,
// como el PDF de una tarjeta: es una lista nominal de la plantilla con sus
// horas, y no tiene por que quedarse viva en el navegador.
//
// Las dos cabeceras de recuento son las mismas cifras que quedan en `audit_log`.
// Enseñarlas no es cosmetica: quien atiende un requerimiento tiene que poder
// decir cuantos tramos y cuantas correcciones entrego sin abrir el fichero.
import type { BinaryDocument } from '@kronoqr/web-kit/http'
import { requestBlob, requestJson } from '@kronoqr/web-kit/http'
import type { LegalExportEmployeeMatches, LegalExportEmployeeSearch } from '@/shared/api/types'

export interface LegalExportQuery {
  /** Primer dia del periodo, `YYYY-MM-DD`, por fecha de jornada (RN-05). */
  from: string
  /** Ultimo dia, inclusive. */
  to: string
  /** Sin este valor se exporta la plantilla completa. */
  employeeUuid?: string
}

/** Lo que el fichero afirma llevar dentro. */
export interface LegalExportTally {
  shiftEntries: number | null
  corrections: number | null
}

export interface LegalExport {
  document: BinaryDocument
  tally: LegalExportTally
}

function counted(document_: BinaryDocument, header: string): number | null {
  const raw = document_.headers.get(header)
  const value = raw === null ? Number.NaN : Number.parseInt(raw, 10)

  return Number.isFinite(value) ? value : null
}

export async function downloadLegalExport(query: LegalExportQuery): Promise<LegalExport> {
  const document_ = await requestBlob(
    '/api/v1/reports/legal-export',
    `registro-horario-${query.from}_${query.to}.csv`,
    {
      accept: 'text/csv, application/problem+json',
      query: {
        from: query.from,
        to: query.to,
        // `undefined` no se serializa: sin persona, la peticion no lleva el
        // parametro y el alcance es la plantilla completa.
        employee_uuid: query.employeeUuid === '' ? undefined : query.employeeUuid,
      },
    },
  )

  if (document_ === null) {
    // El endpoint nunca responde 204: un periodo sin jornadas devuelve un
    // fichero con su cabecera de criterios, porque «no hay nada» tambien es una
    // afirmacion que hay que poder entregar.
    throw new Error('La exportacion legal ha llegado vacia.')
  }

  return {
    document: document_,
    tally: {
      shiftEntries: counted(document_, 'X-Kronoqr-Export-Shift-Rows'),
      corrections: counted(document_, 'X-Kronoqr-Export-Correction-Rows'),
    },
  }
}

/**
 * Busca a la persona de la exportacion por nombre/codigo o por DNI
 * (`searchLegalExportEmployees`, RF-IN-05).
 *
 * Es un `POST` para que el DNI **nunca viaje en la URL**. El resultado es una
 * lista nominal: no se guarda en cache de consultas ni en ningun almacen; lo
 * recoge quien llama y vive lo que viva el componente. Cada llamada deja un
 * asiento en la auditoria, por eso tampoco se invoca mientras se teclea.
 */
export async function searchLegalExportEmployees(
  body: LegalExportEmployeeSearch,
): Promise<LegalExportEmployeeMatches> {
  return requestJson<LegalExportEmployeeMatches>('/api/v1/reports/legal-export/employees/search', {
    method: 'POST',
    body,
  })
}
