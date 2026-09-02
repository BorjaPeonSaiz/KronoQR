// Importacion masiva de plantilla (RF-GP-05), paso «employees» del asistente
// (contradiccion C-1 resuelta: el requisito se movio aqui desde la tarea 3.10).
//
// Multipart y no JSON con el fichero en base64: se lee en streaming en el
// servidor y nunca se carga entero en memoria (§3.1). Dos fases: `validate` no
// escribe nada y devuelve el informe linea a linea; `apply` exige
// `confirm_checksum` con el `file.sha256` que devolvio la validacion, para que
// lo que se aplica sea exactamente lo que se reviso.
import { requestJson } from '@kronoqr/web-kit/http'
import type { EmployeeImportMode, EmployeeImportReport } from '@/shared/api/types'

export interface ImportEmployeesInput {
  file: File
  mode: EmployeeImportMode
  /** Obligatorio con `mode: 'apply'`: el `file.sha256` que devolvio la validacion. */
  confirmChecksum?: string
}

export function importEmployees(input: ImportEmployeesInput): Promise<EmployeeImportReport> {
  const form = new FormData()

  form.set('file', input.file)
  form.set('mode', input.mode)

  if (input.confirmChecksum !== undefined) {
    form.set('confirm_checksum', input.confirmChecksum)
  }

  return requestJson<EmployeeImportReport>('/api/v1/employees/import', {
    method: 'POST',
    body: form,
  })
}
