<script setup lang="ts">
// Carga de ausencias por fichero (RF-GP-04), calcada del paso «employees» del
// asistente de puesta en marcha (`onboarding/steps/EmployeesImportStep.vue`,
// RF-GP-05): DOS FASES, LA SEGUNDA NUNCA SIN LA PRIMERA. `validate` no escribe
// nada y devuelve el informe fila a fila; solo tras revisarlo se manda
// `apply` con `confirm_checksum` -el `sha256` que devolvio la validacion-,
// asi que lo que se aplica es EXACTAMENTE lo que se reviso (regla dura 5).
//
// Una fila identica a una ausencia activa ya registrada sale `unchanged`, no
// como rechazo (decision 5 de la ficha): reimportar el mismo fichero es
// seguro.
//
// Los textos de cada fila y los avisos del fichero entero llegan del servidor
// ya en el idioma de la interfaz y no entran en ningun registro tecnico
// (regla dura 21): este dialogo es el unico que los enseña.
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import BaseDialog from '@/shared/ui/BaseDialog.vue'
import { importAbsences } from './absences.api'
import type { AbsenceImportReport } from '@/shared/api/types'

const emit = defineEmits<{ close: []; applied: [AbsenceImportReport] }>()

const { t } = useI18n()

const selectedFile = ref<File | null>(null)
const report = ref<AbsenceImportReport | null>(null)
const validating = ref(false)
const applying = ref(false)
const error = ref<unknown>(null)

function onFileChange(event: Event): void {
  const files = (event.target as HTMLInputElement).files

  selectedFile.value = files !== null && files.length > 0 ? (files[0] ?? null) : null
  // Un fichero nuevo invalida el informe anterior: su huella ya no es la de
  // este fichero, y aplicar con ella daria un 409.
  report.value = null
}

const canValidate = computed(() => selectedFile.value !== null && !validating.value)
const canApply = computed(
  () =>
    report.value !== null &&
    report.value.mode === 'validate' &&
    !report.value.truncated &&
    !applying.value,
)
const applied = computed(() => report.value?.mode === 'apply')

async function validate(): Promise<void> {
  if (selectedFile.value === null) {
    return
  }

  validating.value = true
  error.value = null

  try {
    report.value = await importAbsences({ file: selectedFile.value, mode: 'validate' })
  } catch (caught) {
    error.value = caught
  } finally {
    validating.value = false
  }
}

async function apply(): Promise<void> {
  if (selectedFile.value === null || report.value === null) {
    return
  }

  applying.value = true
  error.value = null

  try {
    report.value = await importAbsences({
      file: selectedFile.value,
      mode: 'apply',
      confirmChecksum: report.value.file.sha256,
    })
    emit('applied', report.value)
  } catch (caught) {
    error.value = caught
  } finally {
    applying.value = false
  }
}
</script>

<template>
  <BaseDialog :title="t('absences.import.heading')" size="wide" @close="emit('close')">
    <p class="text-sm text-kq-text-muted">{{ t('absences.import.intro') }}</p>

    <ErrorNotice v-if="error !== null" :error="error" class="mt-4" />

    <div class="mt-4 flex flex-col gap-2">
      <label for="absence-import-file" class="font-medium text-kq-text">
        {{ t('absences.import.fileLabel') }}
      </label>
      <p class="text-sm text-kq-text-muted">{{ t('absences.import.fileHint') }}</p>
      <input
        id="absence-import-file"
        type="file"
        accept=".csv,.xlsx"
        data-test="import-file"
        class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text"
        @change="onFileChange"
      />
    </div>

    <div class="mt-4 flex gap-3">
      <button
        type="button"
        :disabled="!canValidate"
        :aria-busy="validating"
        data-test="import-validate"
        class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-4 py-2 text-kq-text hover:bg-kq-surface-alt disabled:opacity-60"
        @click="validate"
      >
        {{ validating ? t('absences.import.validating') : t('absences.import.validate') }}
      </button>
      <button
        v-if="report !== null && !applied"
        type="button"
        :disabled="!canApply"
        :aria-busy="applying"
        data-test="import-apply"
        class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 font-semibold text-kq-on-primary disabled:opacity-60"
        @click="apply"
      >
        {{ applying ? t('absences.import.applying') : t('absences.import.apply') }}
      </button>
    </div>

    <template v-if="report !== null">
      <p
        v-if="report.truncated"
        class="mt-4 rounded-kq border border-kq-warning bg-kq-warning-soft p-4 text-kq-warning"
        role="alert"
        data-test="import-truncated"
      >
        {{ t('absences.import.truncated') }}
      </p>

      <div
        v-if="report.file.warnings.length > 0"
        class="mt-4 rounded-kq border border-kq-warning bg-kq-warning-soft p-4 text-kq-warning"
        role="alert"
        data-test="import-file-warnings"
      >
        <p class="font-semibold">{{ t('absences.import.fileWarnings') }}</p>
        <ul class="mt-1 list-disc pl-5">
          <li v-for="(warning, index) of report.file.warnings" :key="index">
            {{ warning.detail }}
          </li>
        </ul>
      </div>

      <p role="status" class="mt-4" data-test="import-report-status">
        {{
          applied
            ? t('absences.import.appliedSummary', report.summary)
            : t('absences.import.validatedSummary', report.summary)
        }}
      </p>

      <div class="mt-2 overflow-x-auto">
        <table class="w-full border-collapse text-left text-sm">
          <caption class="sr-only">
            {{
              t('absences.import.tableCaption')
            }}
          </caption>
          <thead>
            <tr class="border-b border-kq-border">
              <th scope="col" class="py-2 pr-4">{{ t('absences.import.columns.line') }}</th>
              <th scope="col" class="py-2 pr-4">{{ t('absences.import.columns.label') }}</th>
              <th scope="col" class="py-2 pr-4">{{ t('absences.import.columns.outcome') }}</th>
              <th scope="col" class="py-2">{{ t('absences.import.columns.messages') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="row of report.rows"
              :key="row.line"
              class="border-b border-kq-border"
              :data-test="`import-row-${row.line}`"
            >
              <th scope="row" class="py-2 pr-4 font-normal">{{ row.line }}</th>
              <td class="py-2 pr-4">{{ row.label }}</td>
              <td class="py-2 pr-4">
                <span
                  class="rounded-kq-sm px-2 py-0.5"
                  :class="{
                    'bg-kq-success-soft text-kq-success': row.outcome === 'create',
                    'bg-kq-surface-alt text-kq-text-muted': row.outcome === 'unchanged',
                    'bg-kq-danger-soft text-kq-danger': row.outcome === 'reject',
                  }"
                >
                  {{ t(`absences.import.outcomes.${row.outcome}`) }}
                </span>
              </td>
              <td class="py-2">
                <ul v-if="row.messages.length > 0" class="flex flex-col gap-1">
                  <li
                    v-for="(message, index) of row.messages"
                    :key="index"
                    :class="message.severity === 'error' ? 'text-kq-danger' : 'text-kq-warning'"
                  >
                    {{ message.detail }}
                  </li>
                </ul>
                <span v-else class="text-kq-text-muted">—</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <template #actions>
      <button
        type="button"
        class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-4 py-2 text-kq-text hover:bg-kq-surface-alt"
        @click="emit('close')"
      >
        {{ t('common.close') }}
      </button>
    </template>
  </BaseDialog>
</template>
