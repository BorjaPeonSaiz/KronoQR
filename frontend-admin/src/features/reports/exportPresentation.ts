// Lo que comparten las cuatro piezas que pintan una exportacion en segundo plano
// (aviso de cabecera, lista de Informes, estado de una pantalla y su composable):
// una sola definicion de «en curso», de la fase y del motivo de un fallo.
import type { ReportExport } from '@/shared/api/types'

/** El `t` de vue-i18n, reducido a lo que se usa aqui. */
export type Translate = (key: string) => string

/** `pending` o `running`: lo unico que se sondea y se puede cancelar. */
export function isActiveExport(row: Pick<ReportExport, 'status'>): boolean {
  return row.status === 'pending' || row.status === 'running'
}

/** La fase de una exportacion `running`; «Generando» si el servidor aun no ha dicho cual. */
export function stageText(t: Translate, row: Pick<ReportExport, 'progress'>): string {
  return row.progress === null || row.progress === undefined
    ? t('reportExports.job.running')
    : t(`reportExports.job.stage.${row.progress.stage}`)
}

/**
 * Los codigos ESTABLES de `failure_reason` que el catalogo declara: nunca el mensaje
 * de una excepcion (regla dura 21). Un codigo que esta version todavia no conoce se
 * enseña tal cual llega, en vez de esconderlo.
 */
const FAILURE_REASON_KEYS: Readonly<Record<string, string>> = {
  write_failed: 'reportExports.list.failureReasons.write_failed',
  database_error: 'reportExports.list.failureReasons.database_error',
  query_timeout: 'reportExports.list.failureReasons.query_timeout',
  stale: 'reportExports.list.failureReasons.stale',
  unexpected: 'reportExports.list.failureReasons.unexpected',
}

export function failureReasonLabel(t: Translate, code: string | null): string {
  if (code === null) {
    return t('common.empty')
  }

  const key = FAILURE_REASON_KEYS[code]

  return key === undefined ? code : t(key)
}
