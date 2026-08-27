<script setup lang="ts">
// Descarga de mi historico (RF-ID-05, RL-05). Es la mitad de «capacidad de
// entrega inmediata» de RL-03 que mira al trabajador: un fichero que llevarse,
// sin pedirselo a nadie ni esperar a que RRHH lo genere.
//
// **CSV, en esta version.** Cubre la portabilidad del articulo 20 del RGPD sin
// maquinaria adicional; el PDF -que es lo que una persona presenta ante un
// tercero- llega con la tarea 2.9.
import { announce } from '@kronoqr/web-kit/announcer'
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import FormField from '@kronoqr/web-kit/components/FormField.vue'
import { exceedsMaxRange, isInvertedRange, MAX_RANGE_DAYS } from '@kronoqr/web-kit/dateRange'
import { downloadDocument } from '@kronoqr/web-kit/downloadDocument'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { exportMyWorkDaysCsv } from './export.api'
import type { WorkDateRange } from '../my-records/workdays.api'
import { UNBOUNDED_RANGE } from '../my-records/workdays.api'

const { t } = useI18n()

const range = ref<WorkDateRange>({ ...UNBOUNDED_RANGE })
const submitting = ref(false)
const error = ref<unknown>(null)
const done = ref(false)

const inverted = computed(() => isInvertedRange(range.value))
const tooWide = computed(() => exceedsMaxRange(range.value))
const canSubmit = computed(() => !inverted.value && !tooWide.value && !submitting.value)

const rangeErrors = computed<string[]>(() => {
  if (inverted.value) {
    return [t('myExport.filters.inverted')]
  }

  return tooWide.value ? [t('myExport.filters.tooWide', { days: MAX_RANGE_DAYS })] : []
})

async function submit(): Promise<void> {
  if (!canSubmit.value) {
    return
  }

  submitting.value = true
  error.value = null
  done.value = false

  try {
    const document_ = await exportMyWorkDaysCsv(range.value)

    downloadDocument(document_)
    done.value = true
    announce(t('myExport.announce.done'))
  } catch (caught) {
    error.value = caught
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <section>
    <header>
      <h1 class="text-2xl font-bold">{{ t('myExport.title') }}</h1>
      <p class="mt-2 max-w-prose text-slate-700">{{ t('myExport.intro') }}</p>
    </header>

    <ul class="mt-4 max-w-prose list-disc space-y-1 pl-5 text-slate-700">
      <li>{{ t('myExport.contents.entries') }}</li>
      <li>{{ t('myExport.contents.corrections') }}</li>
      <li>{{ t('myExport.contents.format') }}</li>
    </ul>

    <form class="mt-6 flex max-w-3xl flex-wrap items-end gap-4" novalidate @submit.prevent="submit">
      <fieldset class="flex flex-wrap items-end gap-4 border-0 p-0">
        <legend class="sr-only">{{ t('myExport.filters.legend') }}</legend>

        <FormField
          v-slot="field"
          :label="t('myExport.filters.from')"
          :hint="t('myExport.filters.fromHint')"
          label-class="text-lg font-medium text-slate-900"
        >
          <input
            :id="field.id"
            v-model="range.from"
            type="date"
            :aria-describedby="field.describedBy"
            class="min-h-12 rounded border border-slate-400 px-3 py-2 text-lg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900"
          />
        </FormField>

        <FormField
          v-slot="field"
          :label="t('myExport.filters.to')"
          :hint="t('myExport.filters.toHint')"
          :errors="rangeErrors"
          label-class="text-lg font-medium text-slate-900"
        >
          <input
            :id="field.id"
            v-model="range.to"
            type="date"
            :aria-describedby="field.describedBy"
            :aria-invalid="field.invalid"
            class="min-h-12 rounded border border-slate-400 px-3 py-2 text-lg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900"
          />
        </FormField>
      </fieldset>

      <button
        type="submit"
        :disabled="!canSubmit"
        :aria-busy="submitting"
        class="min-h-12 rounded bg-slate-900 px-4 py-2 text-lg font-semibold text-white disabled:opacity-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900"
      >
        {{ submitting ? t('myExport.downloading') : t('myExport.download') }}
      </button>
    </form>

    <ErrorNotice v-if="error !== null" :error="error" class="mt-4" />

    <p v-if="done && error === null" role="status" class="mt-4 text-slate-700">
      {{ t('myExport.announce.done') }}
    </p>
  </section>
</template>
