// Exportacion integra de los datos de la instalacion (RF-PD-14, RL-20, ADR-019,
// regla dura 15). Nunca se interpreta el contenido del ZIP en el panel: se
// pide, se sondea su estado y se descarga tal cual llega, igual que el
// paquete de diagnostico de `features/support` (ver `README.md` de esta
// carpeta). Las formas salen del contrato; aqui no se inventa ninguna.
import type { BinaryDocument } from '@kronoqr/web-kit/http'
import { isApiError, requestBlob, requestJson } from '@kronoqr/web-kit/http'
import type { DataExport, DataExportCollection, DataExportResource } from '@/shared/api/types'

/**
 * Nombre de reserva si, por lo que sea, la respuesta llegara sin
 * `Content-Disposition`. El servidor SIEMPRE la manda (contrato,
 * `GET /data-export/{uuid}/download`): esto es solo para no descargar un
 * fichero sin nombre si algun dia deja de hacerlo.
 */
const FALLBACK_EXPORT_FILENAME = 'kronoqr-export.zip'

/**
 * `GET /api/v1/data-export` (RF-PD-14, RL-20): las 20 exportaciones mas
 * recientes de la instalacion, de la mas nueva a la mas antigua. Es lo que el
 * panel sondea mientras una esta en curso, y donde aparece tambien la que se
 * genero desde la consola con `product:export-all`. Las filas no se borran
 * nunca (regla dura 5): una purgada sigue apareciendo con `status: purged`.
 */
export function listDataExports(): Promise<DataExportCollection> {
  return requestJson<DataExportCollection>('/api/v1/data-export')
}

/**
 * El `409` de `POST /api/v1/data-export` cuando ya hay una exportacion
 * `pending` o `running` (contrato, `urn:kronoqr:problem:data-export-in-progress`):
 * lleva la fila en curso en `export`, para enseñarla en vez de fallar.
 */
interface DataExportInProgressProblem {
  type: string
  export: DataExport
}

function isDataExportInProgress(problem: unknown): problem is DataExportInProgressProblem {
  return (
    typeof problem === 'object' &&
    problem !== null &&
    (problem as { type?: unknown }).type === 'urn:kronoqr:problem:data-export-in-progress' &&
    typeof (problem as { export?: unknown }).export === 'object'
  )
}

/**
 * `POST /api/v1/data-export` (RF-PD-14, RL-20): pide la exportacion integra de
 * todos los datos de la instalacion. **Nunca depende de la licencia**
 * (ADR-019, regla dura 15): es la garantia de continuidad del cliente.
 *
 * Responde siempre con la fila que hay que enseñar, tanto si la acaba de
 * crear (`202`, en `pending`) como si ya habia una `pending`/`running` (`409`
 * con la fila en `export`, segun el contrato): la vista no distingue los dos
 * casos y solo enseña lo que vuelve, en vez de tratar el segundo como un
 * fallo.
 */
export async function requestDataExport(): Promise<DataExportResource> {
  try {
    return await requestJson<DataExportResource>('/api/v1/data-export', { method: 'POST' })
  } catch (failure) {
    if (isApiError(failure) && failure.status === 409 && isDataExportInProgress(failure.problem)) {
      return { data: failure.problem.export }
    }

    throw failure
  }
}

/**
 * `GET /api/v1/data-export/{uuid}/download` (RF-PD-14, RL-20): el ZIP de una
 * exportacion `completed`. Se pide con `requestBlob` y el contenido **nunca**
 * se interpreta en el panel: se guarda tal cual llega, con el nombre que trae
 * `Content-Disposition`. Un `409` (todavia no ha terminado) o un `404` (no
 * existe, fallo o se purgo) llegan como `ApiError` normal, que la vista lee
 * con `ErrorNotice`.
 */
export async function downloadDataExport(uuid: string): Promise<BinaryDocument> {
  const document_ = await requestBlob(
    `/api/v1/data-export/${uuid}/download`,
    FALLBACK_EXPORT_FILENAME,
    { accept: 'application/zip, application/problem+json' },
  )

  if (document_ === null) {
    // El endpoint nunca responde 204: una exportacion completada siempre trae
    // el ZIP.
    throw new Error('La exportación ha llegado vacía.')
  }

  return document_
}
