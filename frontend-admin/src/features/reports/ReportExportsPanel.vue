<script setup lang="ts">
// Bloque «Exportaciones en segundo plano» (RF-IN-06, RF-IN-07, tarea 3.9):
// las exportaciones de informes DEL SOLICITANTE -tanto el informe por periodo
// como la salida a nomina, que comparten el mismo ciclo de vida
// (`report_exports`, decision 1 de la ficha)-, con su estado, su descarga
// caducable y sus criterios.
//
// **NO PIDE NINGUNA EXPORTACION.** Eso lo hacen `PeriodReportView` (cuando un
// informe no cabe en una respuesta sincrona) y `PayrollExportView` (con su
// propio boton «Generar en segundo plano»): las dos llaman a
// `requestReportExport` con los parametros que ya tienen en pantalla, y este
// panel solo enseña el resultado. Partirlo asi evita que este componente
// tuviera que repetir el formulario de periodo de las dos pantallas.
//
// **SONDEO SOLO MIENTRAS HAYA `pending`/`running`** (mismo criterio que
// `DataExportPanel`, tarea 5.10): una instalacion sin ninguna exportacion en
// curso no golpea el servidor cada 10 s para nada, y `useQuery` para el
// sondeo en cuanto se desmonta la pantalla -no hace falta un `onUnmounted`
// propio, es lo que ya hace TanStack Query-.
//
// **EL ENLACE DE DESCARGA NUNCA SE GUARDA.** Cada clic en «Descargar» pide el
// estado de nuevo (`showReportExport`), que devuelve un enlace de un solo uso
// recien emitido, y con ese se descarga en el acto (decision 3 de la ficha,
// ADR-041). Volver a pulsar pide otro.
import { announce } from '@kronoqr/web-kit/announcer'
import EmptyState from '@kronoqr/web-kit/components/EmptyState.vue'
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import LoadingPanel from '@kronoqr/web-kit/components/LoadingPanel.vue'
import { formatInstantWithZone, FALLBACK_TIMEZONE } from '@kronoqr/web-kit/datetime'
import { downloadDocument } from '@kronoqr/web-kit/downloadDocument'
import { useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { getSite } from '@/shared/api/organisation.api'
import type { ReportExport } from '@/shared/api/types'
import {
  downloadReportExportFile,
  listReportExports,
  REPORT_EXPORTS_QUERY_KEY,
  showReportExport,
} from './reportExports.api'

/** Mismo intervalo que fija la decision 8 de la ficha: cada 10 s mientras haya algo pendiente. */
const POLL_INTERVAL_MS = 10_000

const QUERY_KEY = REPORT_EXPORTS_QUERY_KEY

const { t, locale } = useI18n()

const { data: site } = useQuery({ queryKey: ['site'] as const, queryFn: getSite })
const timezone = computed(() => site.value?.timezone ?? FALLBACK_TIMEZONE)

const queryClient = useQueryClient()

const {
  data: collection,
  error: listError,
  isPending: listPending,
  dataUpdatedAt,
} = useQuery({
  queryKey: QUERY_KEY,
  queryFn: listReportExports,
  // Sondeo SOLO mientras algo sigue en curso: la funcion se vuelve a evaluar
  // en cada respuesta, asi que en cuanto la ultima fila pendiente termina el
  // sondeo se apaga solo, sin que nadie tenga que desactivarlo a mano.
  refetchInterval: (query) => {
    const rows = query.state.data?.data ?? []
    const active = rows.some((row) => row.status === 'pending' || row.status === 'running')

    return active ? POLL_INTERVAL_MS : false
  },
})

const exportsList = computed<ReportExport[]>(() => collection.value?.data ?? [])

const lastUpdatedLabel = computed(() =>
  dataUpdatedAt.value === 0
    ? ''
    : t('reportExports.list.lastUpdatedAt', {
        moment: formatInstantWithZone(
          new Date(dataUpdatedAt.value).toISOString(),
          timezone.value,
          locale.value,
        ),
      }),
)

function instantLabel(value: string | null): string {
  return value === null
    ? t('common.empty')
    : formatInstantWithZone(value, timezone.value, locale.value)
}

/** «Lista para descargar», anunciada por el lector de pantalla (regin viva global de `AppShellView`) en cuanto una fila TERMINA, no en cada sondeo. */
const announcedReady = new Set<string>()

watch(
  exportsList,
  (rows) => {
    for (const row of rows) {
      if (row.status === 'completed' && !announcedReady.has(row.uuid)) {
        announcedReady.add(row.uuid)
        announce(t('reportExports.list.readyAnnounce'))
      }
    }
  },
  { immediate: true },
)

/**
 * Los codigos ESTABLES de `failure_reason` que el catalogo declara (mismo
 * criterio que `dataExport.list.failureReasons`, tarea 5.10): nunca el
 * mensaje de una excepcion (regla dura 21). Un codigo que esta pantalla
 * todavia no conoce se enseña tal cual llega, en vez de esconderlo.
 */
const FAILURE_REASON_KEYS: Readonly<Record<string, string>> = {
  write_failed: 'reportExports.list.failureReasons.write_failed',
  database_error: 'reportExports.list.failureReasons.database_error',
  query_timeout: 'reportExports.list.failureReasons.query_timeout',
  stale: 'reportExports.list.failureReasons.stale',
  unexpected: 'reportExports.list.failureReasons.unexpected',
}

function failureReasonLabel(code: string | null): string {
  if (code === null) {
    return t('common.empty')
  }

  const key = FAILURE_REASON_KEYS[code]

  return key === undefined ? code : t(key)
}

/** Tamaño legible, en unidades BINARIAS (mismo criterio que `dataExport.api.ts`/`sizeLabel`): no es una cifra del registro legal. */
function sizeLabel(bytes: number | null): string {
  if (bytes === null) {
    return t('common.empty')
  }

  const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'] as const
  let value = bytes
  let unitIndex = 0

  while (value >= 1024 && unitIndex < units.length - 1) {
    value /= 1024
    unitIndex += 1
  }

  const decimals = unitIndex === 0 ? 0 : 1
  const formatted = new Intl.NumberFormat(locale.value, {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  }).format(value)

  return `${formatted} ${units[unitIndex]}`
}

function rowCountLabel(row: ReportExport): string {
  return row.row_count === null
    ? t('common.empty')
    : t('reportExports.list.rows', { count: row.row_count })
}

function periodLabel(row: ReportExport): string {
  return t('reportExports.list.period', { from: row.parameters.from, to: row.parameters.to })
}

// --- Descargar una exportacion completada ------------------------------------

const downloadingUuid = ref<string | null>(null)
const downloadError = ref<unknown>(null)

async function download(row: ReportExport): Promise<void> {
  downloadingUuid.value = row.uuid
  downloadError.value = null

  try {
    // SIEMPRE se pide el estado justo antes de descargar: el enlace de la
    // lista puede llevar ya rato en pantalla, y cada peticion emite uno nuevo
    // que invalida el anterior (decision 3 de la ficha). Nunca se reutiliza
    // `row.download`.
    const fresh = await showReportExport(row.uuid)
    const link = fresh.data.download

    if (link === null || link === undefined) {
      // La fila dejo de estar lista entre el sondeo y el clic (purgada, o el
      // fichero ya no existe): se relee la lista para que la pantalla cuente
      // lo que hay de verdad, en vez de un error generico.
      await queryClient.invalidateQueries({ queryKey: QUERY_KEY })

      return
    }

    const document_ = await downloadReportExportFile(link.url)

    downloadDocument(document_)
    announce(t('reportExports.list.downloaded', { filename: document_.filename }))
  } catch (failure) {
    downloadError.value = failure
  } finally {
    downloadingUuid.value = null
    // El propio `download_count` y `downloaded_at` de la fila cambian en el
    // servidor: se relee para que la siguiente vez que se enseñe la lista sea
    // la de verdad, no la que habia antes de descargar.
    void queryClient.invalidateQueries({ queryKey: QUERY_KEY })
  }
}
</script>

<template>
  <section id="report-exports" class="flex flex-col gap-4" data-test="report-exports">
    <div>
      <h2 class="font-heading text-lg font-bold">{{ t('reportExports.heading') }}</h2>
      <p class="mt-1 text-sm text-kq-text-muted">{{ t('reportExports.intro') }}</p>
    </div>

    <ErrorNotice v-if="downloadError !== null" :error="downloadError" data-test="download-error" />

    <LoadingPanel v-if="listPending" :label="t('reportExports.list.loading')" />
    <ErrorNotice v-else-if="listError !== null" :error="listError" data-test="list-error" />
    <EmptyState
      v-else-if="exportsList.length === 0"
      :title="t('reportExports.list.empty.title')"
      :description="t('reportExports.list.empty.description')"
    />

    <template v-else>
      <p v-if="lastUpdatedLabel !== ''" class="text-xs text-kq-text-muted" data-test="last-updated">
        {{ lastUpdatedLabel }}
      </p>

      <div
        class="overflow-x-auto rounded-kq border border-kq-border bg-kq-surface-raised shadow-kq-soft"
      >
        <table class="w-full border-collapse text-left">
          <caption class="px-3 py-2 text-left text-sm text-kq-text-muted">
            {{
              t('reportExports.list.caption')
            }}
          </caption>
          <thead class="border-b border-kq-border bg-kq-surface-alt">
            <tr>
              <th scope="col" class="px-3 py-2">{{ t('reportExports.list.columns.status') }}</th>
              <th scope="col" class="px-3 py-2">{{ t('reportExports.list.columns.kind') }}</th>
              <th scope="col" class="px-3 py-2">{{ t('reportExports.list.columns.format') }}</th>
              <th scope="col" class="px-3 py-2">{{ t('reportExports.list.columns.period') }}</th>
              <th scope="col" class="px-3 py-2">{{ t('reportExports.list.columns.size') }}</th>
              <th scope="col" class="px-3 py-2">{{ t('reportExports.list.columns.rows') }}</th>
              <th scope="col" class="px-3 py-2">{{ t('reportExports.list.columns.expiresAt') }}</th>
              <th scope="col" class="px-3 py-2">{{ t('reportExports.list.columns.actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="row of exportsList"
              :key="row.uuid"
              class="border-b border-kq-border align-top"
            >
              <td class="px-3 py-2">
                <span
                  class="rounded-full px-2 py-0.5 text-sm"
                  :class="{
                    'bg-kq-success-soft text-kq-success': row.status === 'completed',
                    'bg-kq-danger-soft text-kq-danger': row.status === 'failed',
                    'bg-kq-surface-alt text-kq-text-muted':
                      row.status !== 'completed' && row.status !== 'failed',
                  }"
                  :data-test="`status-${row.uuid}`"
                >
                  {{ t(`reportExports.status.${row.status}`) }}
                </span>
                <p v-if="row.status === 'failed'" class="mt-1 text-sm text-kq-text-muted">
                  {{
                    t('reportExports.list.failedReason', {
                      reason: failureReasonLabel(row.failure_reason),
                    })
                  }}
                </p>
                <p v-if="row.status === 'purged'" class="mt-1 text-sm text-kq-text-muted">
                  {{ t('reportExports.list.purgedNotice') }}
                </p>
              </td>
              <td class="px-3 py-2">{{ t(`reportExports.kind.${row.kind}`) }}</td>
              <td class="px-3 py-2">{{ t(`reports.period.export.format.${row.format}`) }}</td>
              <td class="px-3 py-2">{{ periodLabel(row) }}</td>
              <td class="px-3 py-2">{{ sizeLabel(row.size_bytes) }}</td>
              <td class="px-3 py-2">{{ rowCountLabel(row) }}</td>
              <td class="px-3 py-2">{{ instantLabel(row.expires_at) }}</td>
              <td class="px-3 py-2">
                <button
                  v-if="row.status === 'completed'"
                  type="button"
                  class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-2 py-1 text-sm text-kq-text hover:bg-kq-surface-alt disabled:opacity-60"
                  :disabled="downloadingUuid === row.uuid"
                  :aria-busy="downloadingUuid === row.uuid"
                  :data-test="`download-${row.uuid}`"
                  @click="download(row)"
                >
                  {{
                    downloadingUuid === row.uuid
                      ? t('reportExports.list.downloading')
                      : t('reportExports.list.download')
                  }}
                </button>

                <details class="mt-2" :data-test="`criteria-${row.uuid}`">
                  <summary class="cursor-pointer text-sm text-kq-text-muted">
                    {{ t('reportExports.list.criteriaToggle') }}
                  </summary>
                  <ul class="mt-1 list-disc space-y-1 pl-4 text-sm text-kq-text-muted">
                    <li v-for="(criterion, index) of row.criteria" :key="index">{{ criterion }}</li>
                  </ul>
                </details>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </section>
</template>
