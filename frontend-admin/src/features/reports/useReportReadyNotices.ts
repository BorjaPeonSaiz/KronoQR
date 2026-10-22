// Aviso de «informe listo» en cualquier pantalla (RF-IN-06 «con notificacion»,
// V21-5-PA-1, V5-PA-3, R3-PA-08).
//
// Antes solo `ReportExportsPanel` sondeaba, y solo existia en `/reports`: quien
// pedia «Generar en segundo plano» desde Nomina, o salia de Informes, no se
// enteraba. Ahora lo observa el marco de la aplicacion, que esta siempre
// montado, sobre la MISMA consulta (`REPORT_EXPORTS_QUERY_KEY`): la cache se
// comparte con el panel y las pantallas que piden exportaciones, y el sondeo
// de 10 s sigue encendido solo mientras haya una `pending`/`running`.
//
// SE AVISA DE LO QUE TERMINA, NO DE LO QUE YA ESTABA TERMINADO. La primera
// lista que llega es la linea base (hasta 20 exportaciones completadas de
// otros dias): se recuerdan sin anunciar. Despues, cada fila `completed` que no
// estaba entre las conocidas como completadas es un aviso nuevo. Esa regla
// sustituye al `watch` con `immediate: true` del panel, que anunciaba las 20 al
// montar (R3-PA-08).
import { announce } from '@kronoqr/web-kit/announcer'
import { useQuery } from '@tanstack/vue-query'
import { computed, ref, watch } from 'vue'
import type { Ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { listReportExports, REPORT_EXPORTS_QUERY_KEY } from './reportExports.api'

/** Mismo intervalo que fija la decision 8 de la ficha: cada 10 s mientras haya algo pendiente. */
export const REPORT_EXPORTS_POLL_INTERVAL_MS = 10_000

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
      const active = rows.some((row) => row.status === 'pending' || row.status === 'running')

      return active ? REPORT_EXPORTS_POLL_INTERVAL_MS : false
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

      const fresh = completed.filter((uuid) => !knownCompleted?.has(uuid))

      if (fresh.length === 0) {
        return
      }

      knownCompleted = new Set([...knownCompleted, ...fresh])
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
