<script setup lang="ts">
// El estado de UNA exportacion en diferido que se acaba de pedir: fase, barra de
// avance, cancelar y descargar (RC2, V5-PA-4, V21-5-PA-2, V3-UX-04).
//
// **No anuncia nada**: la region viva la maneja `useReportExportJob` (inicio, fase,
// fallo, cancelacion y final), y esta pieza solo pinta. Un `aria-live` aqui repetiria
// en cada sondeo lo que el lector ya oyo.
//
// BARRA HONESTA. Solo la exportacion legal da porcentaje; el resto trae
// `percent: null` y la barra es INDETERMINADA, sin `aria-valuenow` (un `role=progressbar`
// con valor inventado diria a un lector de pantalla algo que nadie sabe). Y nunca
// llega a 100 antes de `completed`: eso lo garantiza el servidor.
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import type { ReportExport } from '@/shared/api/types'

const props = defineProps<{
  job: ReportExport
  /** Ya habia una en curso (`409`): se enseña esa en vez de crear otra. */
  alreadyInProgress: boolean
  cancelling: boolean
  downloading: boolean
  /** Enlazar a la lista de Informes solo tiene sentido para quien puede abrirla (`reports:*`). */
  canOpenReports: boolean
}>()

const emit = defineEmits<{ cancel: []; download: []; dismiss: [] }>()

const { t } = useI18n()

const active = computed(() => props.job.status === 'pending' || props.job.status === 'running')
const percent = computed(() => props.job.progress?.percent ?? null)
const stageText = computed(() =>
  props.job.progress === null || props.job.progress === undefined
    ? t('reportExports.job.running')
    : t(`reportExports.job.stage.${props.job.progress.stage}`),
)

const failureText = computed(() => {
  const code = props.job.failure_reason

  return code === null ? t('common.empty') : t(`reportExports.list.failureReasons.${code}`, code)
})
</script>

<template>
  <section
    class="max-w-3xl rounded-kq border border-kq-border bg-kq-surface-raised p-4 shadow-kq-soft"
    data-test="export-job"
    :data-status="job.status"
  >
    <p
      v-if="alreadyInProgress"
      class="mb-3 rounded-kq-sm border border-kq-warning bg-kq-warning-soft px-3 py-2 text-kq-warning"
      data-test="export-job-existing"
    >
      {{ t('reportExports.job.alreadyRunning') }}
      <RouterLink
        v-if="canOpenReports"
        :to="{ name: 'reports', hash: '#report-exports' }"
        class="font-medium underline"
      >
        {{ t('reportExports.job.seeExisting') }}
      </RouterLink>
    </p>

    <h2 class="font-heading text-lg font-bold">
      {{ t(`reportExports.kind.${job.kind}`) }}
      <span class="font-normal text-kq-text-muted">
        {{ t('reportExports.list.period', { from: job.parameters.from, to: job.parameters.to }) }}
      </span>
    </h2>

    <template v-if="active">
      <p class="mt-2" data-test="export-job-phase">
        {{ job.status === 'pending' ? t('reportExports.job.queued') : stageText }}
      </p>

      <div
        v-if="job.status === 'running'"
        role="progressbar"
        :aria-label="t('reportExports.job.progressLabel')"
        aria-valuemin="0"
        aria-valuemax="100"
        :aria-valuenow="percent ?? undefined"
        :aria-valuetext="percent === null ? stageText : `${percent} %`"
        class="mt-2 h-3 w-full overflow-hidden rounded-full bg-kq-surface-alt"
        data-test="export-job-progress"
      >
        <div
          class="h-full rounded-full bg-kq-primary-strong"
          :class="percent === null ? 'w-1/3 animate-pulse' : ''"
          :style="percent === null ? undefined : { width: `${percent}%` }"
        ></div>
      </div>

      <p class="mt-2 text-sm text-kq-text-muted">{{ t('reportExports.job.keepWorking') }}</p>

      <button
        type="button"
        class="mt-3 rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-1.5 text-kq-text hover:bg-kq-surface-alt disabled:opacity-60"
        :disabled="cancelling"
        :aria-busy="cancelling"
        data-test="export-job-cancel"
        @click="emit('cancel')"
      >
        {{ cancelling ? t('reportExports.job.cancelling') : t('reportExports.job.cancel') }}
      </button>
    </template>

    <template v-else-if="job.status === 'completed'">
      <p class="mt-2 text-kq-success" data-test="export-job-done">
        {{ t('reportExports.job.completed') }}
      </p>
      <div class="mt-3 flex flex-wrap gap-2">
        <button
          type="button"
          class="rounded-kq-sm bg-kq-primary-strong px-3 py-1.5 text-kq-on-primary hover:brightness-95 disabled:opacity-60"
          :disabled="downloading"
          :aria-busy="downloading"
          data-test="export-job-download"
          @click="emit('download')"
        >
          {{ downloading ? t('reportExports.list.downloading') : t('reportExports.list.download') }}
        </button>
        <button
          type="button"
          class="rounded-kq-sm border border-kq-border-strong px-3 py-1.5 text-kq-text hover:bg-kq-surface-alt"
          data-test="export-job-dismiss"
          @click="emit('dismiss')"
        >
          {{ t('reportExports.job.dismiss') }}
        </button>
      </div>
    </template>

    <template v-else>
      <p
        class="mt-2"
        :class="job.status === 'failed' ? 'text-kq-danger' : 'text-kq-text-muted'"
        data-test="export-job-ended"
      >
        <template v-if="job.status === 'failed'">
          {{ t('reportExports.list.failedReason', { reason: failureText }) }}
        </template>
        <template v-else-if="job.status === 'cancelled'">
          {{ t('reportExports.job.cancelled') }}
        </template>
        <template v-else>{{ t('reportExports.list.purgedNotice') }}</template>
      </p>
      <button
        type="button"
        class="mt-3 rounded-kq-sm border border-kq-border-strong px-3 py-1.5 text-kq-text hover:bg-kq-surface-alt"
        data-test="export-job-dismiss"
        @click="emit('dismiss')"
      >
        {{ t('reportExports.job.dismiss') }}
      </button>
    </template>
  </section>
</template>
