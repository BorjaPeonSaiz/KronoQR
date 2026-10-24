// Aviso de «informe listo» en cualquier pantalla (RF-IN-06 «con notificacion»).
//
// Antes solo `ReportExportsPanel` sondeaba, y solo existia en `/reports`: quien
// pedia «Generar en segundo plano» desde Nomina, o salia de Informes, no se
// enteraba. Ahora lo observa el marco de la aplicacion, que esta siempre
// montado, sobre la MISMA consulta (`REPORT_EXPORTS_QUERY_KEY`): la cache se
// comparte con el panel y las pantallas que piden exportaciones, y el sondeo
// de 3 s sigue encendido solo mientras haya una `pending`/`running`.
//
// SE AVISA DE LO QUE TERMINA, NO DE LO QUE YA ESTABA TERMINADO. La primera
// lista que llega es la linea base (hasta 20 exportaciones completadas de
// otros dias): se recuerdan sin anunciar. Despues, cada fila `completed` que no
// estaba entre las conocidas como completadas es un aviso nuevo. Esa regla
// sustituye al `watch` con `immediate: true` del panel, que anunciaba las 20 al
// montar.
import { announce } from '@kronoqr/web-kit/announcer'
import { useQuery } from '@tanstack/vue-query'
import { computed, ref, watch } from 'vue'
import type { Ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { isActiveExport } from './exportPresentation'
import { listReportExports, REPORT_EXPORTS_QUERY_KEY } from './reportExports.api'

/** Cada 3 s mientras haya algo pendiente o en curso (no hay tiempo real para esto; zona management, 120 r/m). */
export const REPORT_EXPORTS_POLL_INTERVAL_MS = 3_000

/**
 * Exportaciones que una pantalla ya sigue y anuncia por su cuenta (barra de
 * avance, descarga): el aviso de cabecera no las repite.
 */
export const trackedExports = new Set<string>()

export function useReportReadyNotices(enabled: Ref<boolean>) {
  const { t } = useI18n()

  const { data } = useQuery({
    queryKey: REPORT_EXPORTS_QUERY_KEY,
    queryFn: listReportExports,
    enabled,
    // Un fallo de red aqui no es un aviso: el panel de Informes cuenta el suyo.
    retry: false,
    refetchInterval: (query) => {
      const rows = query.state.data?.data ?? []

      return rows.some(isActiveExport) ? REPORT_EXPORTS_POLL_INTERVAL_MS : false
    },
  })

  /** Identificadores ya vistos como completados; `null` hasta que llega la primera lista. */
  let knownCompleted: Set<string> | null = null
  /** Exportaciones que han terminado desde que la persona miro por ultima vez. */
  const readyUuids = ref<string[]>([])

  watch(
    () => data.value?.data,
    (rows) => {
      if (rows === undefined) {
        return
      }

      const completed = rows.filter((row) => row.status === 'completed').map((row) => row.uuid)

      if (knownCompleted === null) {
        knownCompleted = new Set(completed)

        return
      }

      const unseen = completed.filter((uuid) => !knownCompleted?.has(uuid))

      knownCompleted = new Set([...knownCompleted, ...unseen])

      const fresh = unseen.filter((uuid) => !trackedExports.has(uuid))

      if (fresh.length === 0) {
        return
      }

      readyUuids.value = [...readyUuids.value, ...fresh]
      announce(
        t(
          'reportExports.ready.announce',
          { count: readyUuids.value.length },
          readyUuids.value.length,
        ),
      )
    },
    { immediate: true },
  )

  const readyCount = computed(() => readyUuids.value.length)

  /** La persona ya lo ha visto (ha abierto Informes) o lo descarta. */
  function dismiss(): void {
    readyUuids.value = []
  }

  return { readyCount, dismiss }
}
