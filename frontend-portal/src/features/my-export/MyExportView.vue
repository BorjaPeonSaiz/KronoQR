<script setup lang="ts">
// Descarga de mi historico (RF-ID-05, RL-05). Es la mitad de «capacidad de
// entrega inmediata» de RL-03 que mira al trabajador: un fichero que llevarse,
// sin pedirselo a nadie ni esperar a que RRHH lo genere.
//
// **CSV o PDF.** El CSV cubre la portabilidad del articulo 20 del RGPD; el PDF
// -sellado- es lo que una persona presenta ante un tercero (PR19). Si el
// servidor no puede generar el PDF (`503`, sin Chromium), se avisa y se ofrece
// el CSV, que no depende de nada.
import { announce } from '@kronoqr/web-kit/announcer'
import BaseButton from '@kronoqr/web-kit/components/BaseButton.vue'
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import FormField from '@kronoqr/web-kit/components/FormField.vue'
import NoticeWithActions from '@kronoqr/web-kit/components/NoticeWithActions.vue'
import { useCountdown } from '@kronoqr/web-kit/countdown'
import { isApiError, isPdfRenderingBusy } from '@kronoqr/web-kit/http'
import { exceedsMaxRange, isInvertedRange, MAX_RANGE_DAYS } from '@kronoqr/web-kit/dateRange'
import { downloadDocument } from '@kronoqr/web-kit/downloadDocument'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useSessionStore } from '../login/session.store'
import { exportMyWorkDays } from './export.api'
import type { ExportFormat } from './export.api'
import type { WorkDateRange } from '../my-records/workdays.api'
import { UNBOUNDED_RANGE } from '../my-records/workdays.api'

const { t } = useI18n()
const session = useSessionStore()

const range = ref<WorkDateRange>({ ...UNBOUNDED_RANGE })
const submitting = ref(false)
const error = ref<unknown>(null)
const done = ref(false)
/** El servidor ha dicho que el periodo no tiene ninguna fila: el fichero solo lleva la cabecera. */
const emptyPeriod = ref(false)
const FORMATS: readonly ExportFormat[] = ['csv', 'pdf']
const format = ref<ExportFormat>('csv')
/** El servidor no pudo generar el PDF (`503`): se ofrece el CSV en su lugar. */
const pdfUnavailable = ref(false)
/** El motor de PDF esta ocupado (tope de PDF simultaneos): reintentar o usar el CSV. */
const pdfBusy = ref(false)
const retryAfterSeconds = ref<number | null>(null)

// Cuenta atras real (V21-3-UX-2): baja cada segundo y, a 0, dice que ya se puede reintentar.
const remaining = useCountdown(() => retryAfterSeconds.value)
const busyDetail = computed(() => {
  if (remaining.value === null) return t('myExport.pdfBusy.messageNoWait')

  return remaining.value === 0
    ? t('myExport.pdfBusy.messageReady')
    : t('myExport.pdfBusy.message', { seconds: remaining.value })
})

// Los avisos de PDF solo tienen sentido con el PDF elegido (V21-3-UX-2): al pasar a CSV se retiran.
watch(format, (chosen) => {
  if (chosen !== 'pdf') {
    pdfBusy.value = false
    pdfUnavailable.value = false
    retryAfterSeconds.value = null
  }
})

const inverted = computed(() => isInvertedRange(range.value))
const tooWide = computed(() => exceedsMaxRange(range.value))
const canSubmit = computed(() => !inverted.value && !tooWide.value && !submitting.value)

const rangeErrors = computed<string[]>(() => {
  if (inverted.value) {
    return [t('myExport.filters.inverted')]
  }

  return tooWide.value ? [t('myExport.filters.tooWide', { days: MAX_RANGE_DAYS })] : []
})

/** Filas que declara el servidor en el fichero, o `null` si no lo dice. */
function reportedRows(headers: Headers): number | null {
  const raw = headers.get('X-Kronoqr-Report-Rows')
  const rows = raw === null ? Number.NaN : Number.parseInt(raw, 10)

  return Number.isFinite(rows) ? rows : null
}

async function retryWithCsv(): Promise<void> {
  format.value = 'csv'
  await submit()
}

async function submit(): Promise<void> {
  if (!canSubmit.value) {
    return
  }

  submitting.value = true
  error.value = null
  done.value = false
  emptyPeriod.value = false
  pdfUnavailable.value = false
  pdfBusy.value = false
  retryAfterSeconds.value = null

  try {
    const document_ = await exportMyWorkDays(range.value, format.value)

    downloadDocument(document_)

    // Un periodo sin jornadas es una respuesta valida (el fichero lleva su
    // cabecera de criterios), pero NO es «descarga generada» a secas: la persona
    // buscaba sus horas y tiene que saber que no hay ninguna en esas fechas.
    // El servidor lo dice con `X-Kronoqr-Report-Rows`.
    emptyPeriod.value = reportedRows(document_.headers) === 0
    done.value = true
    announce(t(emptyPeriod.value ? 'myExport.announce.empty' : 'myExport.announce.done'))
  } catch (caught) {
    if (format.value === 'pdf' && isApiError(caught) && caught.status === 503) {
      if (isPdfRenderingBusy(caught)) {
        pdfBusy.value = true
        retryAfterSeconds.value = caught.retryAfterSeconds
      } else {
        pdfUnavailable.value = true
      }
    } else {
      error.value = caught
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <section class="min-w-0">
    <header>
      <h1 class="font-heading text-2xl font-bold text-kq-text">{{ t('myExport.title') }}</h1>
      <p class="mt-2 max-w-prose text-kq-text-muted">{{ t('myExport.intro') }}</p>
    </header>

    <ul class="mt-4 max-w-prose list-disc space-y-1 pl-5 text-kq-text-muted">
      <li>{{ t('myExport.contents.entries') }}</li>
      <li>{{ t('myExport.contents.corrections') }}</li>
      <li>{{ t('myExport.contents.format') }}</li>
      <li data-test="pdf-zone">
        {{
          session.employee === null
            ? t('myExport.contents.zoneUnknown')
            : t('myExport.contents.zone', { zone: session.employee.time_zone })
        }}
      </li>
    </ul>

    <form
      data-test="export-form"
      class="mt-6 flex max-w-3xl flex-wrap items-end gap-4"
      novalidate
      @submit.prevent="submit"
    >
      <fieldset class="flex min-w-0 max-w-full flex-wrap items-end gap-4 border-0 p-0">
        <legend class="sr-only">{{ t('myExport.filters.legend') }}</legend>

        <FormField
          v-slot="field"
          class="min-w-0 max-w-full break-words"
          :label="t('myExport.filters.from')"
          :hint="t('myExport.filters.fromHint')"
          label-class="text-lg font-medium text-kq-text"
        >
          <input
            :id="field.id"
            v-model="range.from"
            type="date"
            :aria-describedby="field.describedBy"
            class="min-h-12 w-full min-w-0 rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-lg"
          />
        </FormField>

        <FormField
          v-slot="field"
          class="min-w-0 max-w-full break-words"
          :label="t('myExport.filters.to')"
          :hint="t('myExport.filters.toHint')"
          :errors="rangeErrors"
          label-class="text-lg font-medium text-kq-text"
        >
          <input
            :id="field.id"
            v-model="range.to"
            type="date"
            :aria-describedby="field.describedBy"
            :aria-invalid="field.invalid"
            class="min-h-12 w-full min-w-0 rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-lg"
          />
        </FormField>
      </fieldset>

      <fieldset class="flex min-w-0 max-w-full flex-col gap-1 border-0 p-0">
        <legend class="text-lg font-medium text-kq-text">{{ t('myExport.format.legend') }}</legend>
        <label
          v-for="option of FORMATS"
          :key="option"
          :for="`format-${option}`"
          class="flex min-h-12 cursor-pointer items-center gap-3 text-lg"
        >
          <input
            :id="`format-${option}`"
            v-model="format"
            type="radio"
            name="format"
            :value="option"
            class="size-12 shrink-0 accent-kq-primary-strong"
          />
          <span class="min-w-0 break-words">{{ t(`myExport.format.${option}`) }}</span>
        </label>
      </fieldset>

      <BaseButton
        type="submit"
        size="lg"
        :disabled="!canSubmit"
        :busy="submitting"
        data-test="export-submit"
      >
        {{ submitting ? t('myExport.downloading') : t(`myExport.download.${format}`) }}
      </BaseButton>
    </form>

    <NoticeWithActions
      v-if="pdfBusy"
      :message="t('myExport.pdfBusy.title')"
      focus-on-mount
      class="mt-4"
      data-test="pdf-busy"
    >
      <p class="mt-1" data-test="pdf-busy-detail">{{ busyDetail }}</p>
      <template #actions>
        <BaseButton
          variant="secondary"
          size="lg"
          :busy="submitting"
          data-test="use-csv"
          @click="retryWithCsv"
        >
          {{ t('myExport.pdfUnavailable.useCsv') }}
        </BaseButton>
      </template>
    </NoticeWithActions>

    <NoticeWithActions
      v-if="pdfUnavailable"
      :message="t('myExport.pdfUnavailable.title')"
      focus-on-mount
      class="mt-4"
      data-test="pdf-unavailable"
    >
      <p class="mt-1">{{ t('myExport.pdfUnavailable.advice') }}</p>
      <template #actions>
        <BaseButton
          variant="secondary"
          size="lg"
          :busy="submitting"
          data-test="use-csv"
          @click="retryWithCsv"
        >
          {{ t('myExport.pdfUnavailable.useCsv') }}
        </BaseButton>
      </template>
    </NoticeWithActions>

    <ErrorNotice v-if="error !== null" :error="error" class="mt-4" />

    <!-- Texto visible, sin `role="status"`: lo que se lee en voz alta ya lo anuncia
         la region viva del marco (`announce`). Con dos regiones diciendo lo mismo
         sonaba dos veces. -->
    <p
      v-if="done && error === null"
      :data-test="emptyPeriod ? 'export-empty' : 'export-done'"
      class="mt-4 max-w-prose text-kq-text-muted"
    >
      {{ emptyPeriod ? t('myExport.empty') : t('myExport.announce.done') }}
    </p>
  </section>
</template>
