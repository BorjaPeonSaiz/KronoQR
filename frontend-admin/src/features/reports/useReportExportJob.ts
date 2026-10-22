// Una exportacion larga en diferido, de punta a punta, para las pantallas que la
// piden (Inspeccion, nomina, cuadro de impacto: RC2, V5-PA-4, V21-5-PA-2, V3-UX-04).
//
// Reutiliza la cola de informes (`report_exports`) y su cache de consultas: pedir,
// sondear cada 3 s mientras haya algo `pending`/`running` (no hay tiempo real para
// esto), cancelar y descargar. Lo que NO hace es anunciar cada sondeo: la region
// viva solo oye el inicio, el cambio de fase, el fallo, la cancelacion y el final.
//
// **El enlace de descarga nunca se guarda** (ADR-041): cada descarga pide el
// estado de nuevo, igual que `ReportExportsPanel`.
import { announce } from '@kronoqr/web-kit/announcer'
import { downloadDocument } from '@kronoqr/web-kit/downloadDocument'
import { useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ReportExport, ReportExportCollection } from '@/shared/api/types'
import {
  cancelReportExport,
  downloadReportExportFile,
  listReportExports,
  REPORT_EXPORTS_QUERY_KEY,
  requestReportExport,
  showReportExport,
  type RequestReportExportInput,
} from './reportExports.api'
import { REPORT_EXPORTS_POLL_INTERVAL_MS, trackedExports } from './useReportReadyNotices'

export function isActiveExport(row: ReportExport): boolean {
  return row.status === 'pending' || row.status === 'running'
}

export function useReportExportJob() {
  const { t } = useI18n()
  const queryClient = useQueryClient()

  const uuid = ref<string | null>(null)
  const alreadyInProgress = ref(false)
  const requesting = ref(false)
  const cancelling = ref(false)
  const downloading = ref(false)
  const error = ref<unknown>(null)

  const { data } = useQuery({
    queryKey: REPORT_EXPORTS_QUERY_KEY,
    queryFn: listReportExports,
    enabled: computed(() => uuid.value !== null),
    retry: false,
    refetchInterval: (query) => {
      const rows = query.state.data?.data ?? []

      return rows.some(isActiveExport) ? REPORT_EXPORTS_POLL_INTERVAL_MS : false
    },
  })

  const job = computed<ReportExport | null>(
    () => data.value?.data.find((row) => row.uuid === uuid.value) ?? null,
  )

  function putInCache(row: ReportExport): void {
    queryClient.setQueryData<ReportExportCollection>(REPORT_EXPORTS_QUERY_KEY, (previous) => ({
      data: [row, ...(previous?.data ?? []).filter((other) => other.uuid !== row.uuid)].slice(
        0,
        20,
      ),
    }))
  }

  function stageLabel(row: ReportExport): string {
    return row.progress === null || row.progress === undefined
      ? t('reportExports.status.running')
      : t(`reportExports.job.stage.${row.progress.stage}`)
  }

  async function start(input: RequestReportExportInput): Promise<void> {
    if (requesting.value) {
      return
    }

    requesting.value = true
    error.value = null

    try {
      const requested = await requestReportExport(input)

      uuid.value = requested.data.uuid
      alreadyInProgress.value = requested.alreadyInProgress
      trackedExports.add(requested.data.uuid)
      putInCache(requested.data)
      announce(
        requested.alreadyInProgress
          ? t('reportExports.job.announce.alreadyRunning')
          : t('reportExports.job.announce.started'),
      )
    } catch (failure) {
      error.value = failure
      announce(t('reportExports.job.announce.requestFailed'))
    } finally {
      requesting.value = false
    }
  }

  async function cancel(): Promise<void> {
    if (uuid.value === null || cancelling.value) {
      return
    }

    cancelling.value = true

    try {
      putInCache(await cancelReportExport(uuid.value))
    } catch (failure) {
      error.value = failure
    } finally {
      cancelling.value = false
    }
  }

  async function download(): Promise<void> {
    if (uuid.value === null || downloading.value) {
      return
    }

    downloading.value = true
    error.value = null

    try {
      const fresh = await showReportExport(uuid.value)
      const link = fresh.data.download

      if (link === null || link === undefined) {
        putInCache(fresh.data)

        return
      }

      const document_ = await downloadReportExportFile(link.url)

      downloadDocument(document_)
      announce(t('reportExports.list.downloaded', { filename: document_.filename }))
    } catch (failure) {
      error.value = failure
    } finally {
      downloading.value = false
      void queryClient.invalidateQueries({ queryKey: REPORT_EXPORTS_QUERY_KEY })
    }
  }

  /** Olvida la exportacion en pantalla (no la borra: la fila sigue en la lista de Informes). */
  function dismiss(): void {
    if (uuid.value !== null) {
      trackedExports.delete(uuid.value)
    }

    uuid.value = null
    alreadyInProgress.value = false
    error.value = null
  }

  // Region viva: solo los cambios que importan, nunca cada sondeo.
  watch(
    () => [job.value?.status, job.value?.progress?.stage] as const,
    ([status, stage], [previousStatus, previousStage]) => {
      const row = job.value

      if (row === null || status === undefined) {
        return
      }

      if (status === previousStatus && stage === previousStage) {
        return
      }

      if (status === 'running' && stage !== previousStage) {
        announce(t('reportExports.job.announce.stage', { stage: stageLabel(row) }))
      } else if (status === 'completed' && previousStatus !== 'completed') {
        announce(t('reportExports.job.announce.completed'))
      } else if (status === 'failed' && previousStatus !== 'failed') {
        announce(t('reportExports.job.announce.failed'))
      } else if (status === 'cancelled' && previousStatus !== 'cancelled') {
        announce(t('reportExports.job.announce.cancelled'))
      }
    },
  )

  onUnmounted(() => {
    if (uuid.value !== null) {
      trackedExports.delete(uuid.value)
    }
  })

  return {
    job,
    alreadyInProgress,
    requesting,
    cancelling,
    downloading,
    error,
    start,
    cancel,
    download,
    dismiss,
  }
}
