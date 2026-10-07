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
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import FormField from '@kronoqr/web-kit/components/FormField.vue'
import { isApiError } from '@kronoqr/web-kit/http'
import { exceedsMaxRange, isInvertedRange, MAX_RANGE_DAYS } from '@kronoqr/web-kit/dateRange'
import { downloadDocument } from '@kronoqr/web-kit/downloadDocument'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { exportMyWorkDays } from './export.api'
import type { ExportFormat } from './export.api'
import type { WorkDateRange } from '../my-records/workdays.api'
import { UNBOUNDED_RANGE } from '../my-records/workdays.api'

const { t } = useI18n()

const range = ref<WorkDateRange>({ ...UNBOUNDED_RANGE })
const submitting = ref(false)
const error = ref<unknown>(null)
const done = ref(false)
const FORMATS: readonly ExportFormat[] = ['csv', 'pdf']
const format = ref<ExportFormat>('csv')
/** El servidor no pudo generar el PDF (`503`): se ofrece el CSV en su lugar. */
const pdfUnavailable = ref(false)

const inverted = computed(() => isInvertedRange(range.value))
const tooWide = computed(() => exceedsMaxRange(range.value))
const canSubmit = computed(() => !inverted.value && !tooWide.value && !submitting.value)

const rangeErrors = computed<string[]>(() => {
  if (inverted.value) {
    return [t('myExport.filters.inverted')]
  }

  return tooWide.value ? [t('myExport.filters.tooWide', { days: MAX_RANGE_DAYS })] : []
})

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
  pdfUnavailable.value = false

  try {
    const document_ = await exportMyWorkDays(range.value, format.value)

    downloadDocument(document_)
    done.value = true
    announce(t('myExport.announce.done'))
  } catch (caught) {
    if (format.value === 'pdf' && isApiError(caught) && caught.status === 503) {
      pdfUnavailable.value = true
      announce(t('myExport.pdfUnavailable.title'))
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
            class="size-6 accent-kq-primary-strong"
          />
          <span class="min-w-0 break-words">{{ t(`myExport.format.${option}`) }}</span>
        </label>
      </fieldset>

      <button
        type="submit"
        :disabled="!canSubmit"
        :aria-busy="submitting"
        data-test="export-submit"
        class="min-h-12 max-w-full break-words rounded-kq-sm bg-kq-primary-strong px-4 py-2 text-lg font-semibold text-kq-on-primary disabled:opacity-50"
      >
        {{ submitting ? t('myExport.downloading') : t(`myExport.download.${format}`) }}
      </button>
    </form>

    <div
      v-if="pdfUnavailable"
      role="alert"
      data-test="pdf-unavailable"
      class="mt-4 max-w-prose rounded-kq border border-kq-warning bg-kq-warning-soft p-4 text-kq-warning"
    >
      <p class="font-semibold">{{ t('myExport.pdfUnavailable.title') }}</p>
      <p class="mt-1">{{ t('myExport.pdfUnavailable.advice') }}</p>
      <button
        type="button"
        data-test="use-csv"
        :disabled="submitting"
        class="mt-3 min-h-12 max-w-full break-words rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-4 py-2 text-lg font-semibold text-kq-text"
        @click="retryWithCsv"
      >
        {{ t('myExport.pdfUnavailable.useCsv') }}
      </button>
    </div>

    <ErrorNotice v-if="error !== null" :error="error" class="mt-4" />

    <p v-if="done && error === null" role="status" class="mt-4 text-kq-text-muted">
      {{ t('myExport.announce.done') }}
    </p>
  </section>
</template>
