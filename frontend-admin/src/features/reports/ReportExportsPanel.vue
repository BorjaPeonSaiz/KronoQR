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
// curso no golpea el servidor cada 3 s para nada, y `useQuery` para el
// sondeo en cuanto se desmonta la pantalla -no hace falta un `onUnmounted`
// propio, es lo que ya hace TanStack Query-.
//
// **EL AVISO DE «LISTA PARA DESCARGAR» NO ES DE ESTE PANEL.** Lo da el marco de
// la aplicacion (`useReportReadyNotices`, en `AppShellView`) en cualquier
// pantalla y solo de lo que termina; aqui anunciarlo al montar repetia hasta 20
// avisos de exportaciones de otros dias (R3-PA-08).
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
import { formatBytes } from '@kronoqr/web-kit/formatBytes'
import { useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { getSite } from '@/shared/api/organisation.api'
import type { ReportExport, ReportExportCollection } from '@/shared/api/types'
import {
  cancelReportExport,
  downloadReportExportFile,
  listReportExports,
  REPORT_EXPORTS_QUERY_KEY,
  showReportExport,
} from './reportExports.api'
import ExportProgressBar from './ExportProgressBar.vue'
import { failureReasonLabel, stageText } from './exportPresentation'
import { REPORT_EXPORTS_POLL_INTERVAL_MS } from './useReportReadyNotices'

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
  refetch: refetchList,
} = useQuery({
  queryKey: QUERY_KEY,
  queryFn: listReportExports,
  // Sondeo SOLO mientras algo sigue en curso: la funcion se vuelve a evaluar
  // en cada respuesta, asi que en cuanto la ultima fila pendiente termina el
  // sondeo se apaga solo, sin que nadie tenga que desactivarlo a mano.
  refetchInterval: (query) => {
    const rows = query.state.data?.data ?? []
    const active = rows.some((row) => row.status === 'pending' || row.status === 'running')

    return active ? REPORT_EXPORTS_POLL_INTERVAL_MS : false
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

/** Tamaño legible, en unidades BINARIAS (`formatBytes` de `@kronoqr/web-kit`, ADR-036, misma funcion que `DataExportPanel`): no es una cifra del registro legal. */
function sizeLabel(bytes: number | null): string {
  return bytes === null ? t('common.empty') : formatBytes(bytes, locale.value)
}

function rowCountLabel(row: ReportExport): string {
  return row.row_count === null
    ? t('common.empty')
    : t('reportExports.list.rows', { count: row.row_count })
}

function periodLabel(row: ReportExport): string {
  return t('reportExports.list.period', { from: row.parameters.from, to: row.parameters.to })
}

// --- Cancelar una exportacion pending/running ----------------------------------

const cancellingUuid = ref<string | null>(null)

const CANCEL_ANNOUNCEMENTS: Readonly<Partial<Record<ReportExport['status'], string>>> = {
  cancelled: 'reportExports.job.announce.cancelled',
  completed: 'reportExports.job.announce.completed',
  failed: 'reportExports.job.announce.failed',
}

async function cancel(row: ReportExport): Promise<void> {
  cancellingUuid.value = row.uuid
  downloadError.value = null

  try {
    const updated = await cancelReportExport(row.uuid)

    queryClient.setQueryData<ReportExportCollection>(QUERY_KEY, (previous) => ({
      data: (previous?.data ?? []).map((other) => (other.uuid === updated.uuid ? updated : other)),
    }))
    // Se anuncia lo que ha pasado de verdad: si ya habia terminado (409 not-cancellable),
    // la fila vuelve `completed` o `failed`, no `cancelled`.
    const outcome = CANCEL_ANNOUNCEMENTS[updated.status]

    if (outcome !== undefined) {
      announce(t(outcome))
    }
  } catch (failure) {
    downloadError.value = failure
  } finally {
    cancellingUuid.value = null
    void queryClient.invalidateQueries({ queryKey: QUERY_KEY })
  }
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
    <ErrorNotice
      v-else-if="listError !== null"
      :error="listError"
      data-test="list-error"
      @retry="refetchList()"
    />
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
        tabindex="0"
        role="region"
        :aria-label="t('reportExports.list.caption')"
        class="relative overflow-x-auto rounded-kq border border-kq-border bg-kq-surface-raised shadow-kq-soft"
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
                <div v-if="row.status === 'running'" class="mt-1 text-sm text-kq-text-muted">
                  <p :data-test="`stage-${row.uuid}`">{{ stageText(t, row) }}</p>
                  <ExportProgressBar
                    :percent="row.progress?.percent ?? null"
                    :stage="stageText(t, row)"
                    class="mt-1 h-2 w-32"
                    :data-test="`progress-${row.uuid}`"
                  />
                </div>
                <p v-if="row.status === 'cancelled'" class="mt-1 text-sm text-kq-text-muted">
                  {{ t('reportExports.job.cancelled') }}
                </p>
                <p v-if="row.status === 'failed'" class="mt-1 text-sm text-kq-text-muted">
                  {{
                    t('reportExports.list.failedReason', {
                      reason: failureReasonLabel(t, row.failure_reason),
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
                  v-aria-disabled.busy="downloadingUuid === row.uuid"
                  type="button"
                  class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-2 py-1 text-sm text-kq-text hover:bg-kq-surface-alt aria-disabled:opacity-60"
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

                <button
                  v-if="row.status === 'pending' || row.status === 'running'"
                  v-aria-disabled.busy="cancellingUuid === row.uuid"
                  type="button"
                  class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-2 py-1 text-sm text-kq-text hover:bg-kq-surface-alt aria-disabled:opacity-60"
                  :aria-busy="cancellingUuid === row.uuid"
                  :data-test="`cancel-${row.uuid}`"
                  @click="cancel(row)"
                >
                  {{
                    cancellingUuid === row.uuid
                      ? t('reportExports.job.cancelling')
                      : t('reportExports.job.cancel')
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
