<script setup lang="ts">
// Paso 6 (RF-GP-05, contradiccion C-1 resuelta: el requisito se movio aqui
// desde la tarea 3.10). Omitible: se puede terminar la puesta en marcha y
// dar de alta la plantilla despues, uno a uno o con una importacion posterior.
//
// DOS FASES, LA SEGUNDA NUNCA SIN LA PRIMERA. `validate` no escribe nada y
// devuelve el informe linea a linea (create/update/unchanged/reject, con el
// motivo de cada rechazo). Solo tras revisarlo y pulsar «aplicar» se manda
// `apply` con `confirm_checksum` — el `sha256` que devolvio la validacion—,
// asi que lo que se aplica es EXACTAMENTE lo que se reviso (regla dura 5).
//
// Los textos de cada linea (`label`, `messages[].detail`) y los avisos DEL
// FICHERO ENTERO (`file.warnings`, p. ej. `unknown_column`: una columna mal
// escrita —«e-mail» donde el mapa espera «email»— deja creer que se cargaron
// los correos cuando no se cargo ninguno) llegan del servidor YA en el idioma
// de la interfaz (`Accept-Language`): no se vuelven a traducir aqui. No entran
// en ningun registro tecnico ni en `error_events` (regla dura 21): esta
// pantalla es la unica que los enseña.
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { EmployeeImportReport } from '@/shared/api/types'
import { importEmployees } from '../employeeImport.api'
import { useSetupStore } from '../setup.store'

const { t } = useI18n()
const setup = useSetupStore()

const selectedFile = ref<File | null>(null)
const report = ref<EmployeeImportReport | null>(null)
const validating = ref(false)
const applying = ref(false)
const finishing = ref(false)
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
    report.value = await importEmployees({ file: selectedFile.value, mode: 'validate' })
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
    report.value = await importEmployees({
      file: selectedFile.value,
      mode: 'apply',
      confirmChecksum: report.value.file.sha256,
    })
  } catch (caught) {
    error.value = caught
  } finally {
    applying.value = false
  }
}

async function finish(state: 'completed' | 'skipped'): Promise<void> {
  finishing.value = true
  error.value = null

  try {
    await setup.recordStep('employees', state)
  } catch (caught) {
    error.value = caught
  } finally {
    finishing.value = false
  }
}
</script>

<template>
  <div class="flex flex-col gap-4">
    <h2 tabindex="-1" class="text-lg font-semibold text-kq-text focus:outline-none">
      {{ t('onboarding.steps.employees.heading') }}
    </h2>
    <p class="text-sm text-kq-text-muted">{{ t('onboarding.steps.employees.intro') }}</p>

    <ErrorNotice v-if="error !== null" :error="error" />

    <div class="flex flex-col gap-2">
      <label for="onboarding-import-file" class="font-medium text-kq-text">
        {{ t('onboarding.steps.employees.fields.file') }}
      </label>
      <p class="text-sm text-kq-text-muted">{{ t('onboarding.steps.employees.hints.file') }}</p>
      <input
        id="onboarding-import-file"
        type="file"
        accept=".csv,.xlsx"
        data-test="import-file"
        class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text"
        @change="onFileChange"
      />
    </div>

    <div class="flex gap-3">
      <button
        type="button"
        :disabled="!canValidate"
        :aria-busy="validating"
        data-test="validate"
        class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-4 py-2 text-kq-text hover:bg-kq-surface-alt disabled:opacity-60"
        @click="validate"
      >
        {{
          validating
            ? t('onboarding.steps.employees.validating')
            : t('onboarding.steps.employees.validate')
        }}
      </button>
      <button
        v-if="report !== null && !applied"
        type="button"
        :disabled="!canApply"
        :aria-busy="applying"
        data-test="apply"
        class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 font-semibold text-kq-on-primary disabled:opacity-60"
        @click="apply"
      >
        {{
          applying
            ? t('onboarding.steps.employees.applying')
            : t('onboarding.steps.employees.apply')
        }}
      </button>
    </div>

    <template v-if="report !== null">
      <p
        v-if="report.truncated"
        class="rounded-kq border border-kq-warning bg-kq-warning-soft p-4 text-kq-warning"
        role="alert"
        data-test="truncated"
      >
        {{ t('onboarding.steps.employees.truncated') }}
      </p>

      <!-- Avisos DEL FICHERO ENTERO (`unknown_column`, hoy), no de una fila:
           una columna mal escrita ("e-mail" donde el mapa espera "email") deja
           creer que se cargaron los correos cuando no se cargo ninguno. Viven
           solo aqui, en la respuesta autenticada y en este `ref` local — nunca
           en el reportador de errores ni en almacenamiento (regla dura 21). -->
      <div
        v-if="report.file.warnings.length > 0"
        class="rounded-kq border border-kq-warning bg-kq-warning-soft p-4 text-kq-warning"
        role="alert"
        data-test="file-warnings"
      >
        <p class="font-semibold">{{ t('onboarding.steps.employees.fileWarnings') }}</p>
        <ul class="mt-1 list-disc pl-5">
          <li v-for="(warning, index) of report.file.warnings" :key="index">
            {{ warning.detail }}
          </li>
        </ul>
      </div>

      <p role="status" data-test="report-status">
        {{
          applied
            ? t('onboarding.steps.employees.appliedSummary', report.summary)
            : t('onboarding.steps.employees.validatedSummary', report.summary)
        }}
      </p>

      <div class="overflow-x-auto">
        <table class="w-full border-collapse text-left text-sm">
          <caption class="sr-only">
            {{
              t('onboarding.steps.employees.tableCaption')
            }}
          </caption>
          <thead>
            <tr class="border-b border-kq-border">
              <th scope="col" class="py-2 pr-4">
                {{ t('onboarding.steps.employees.columns.line') }}
              </th>
              <th scope="col" class="py-2 pr-4">
                {{ t('onboarding.steps.employees.columns.label') }}
              </th>
              <th scope="col" class="py-2 pr-4">
                {{ t('onboarding.steps.employees.columns.outcome') }}
              </th>
              <th scope="col" class="py-2">
                {{ t('onboarding.steps.employees.columns.messages') }}
              </th>
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
                    'bg-kq-primary-soft text-kq-on-primary-soft': row.outcome === 'update',
                    'bg-kq-surface-alt text-kq-text-muted': row.outcome === 'unchanged',
                    'bg-kq-danger-soft text-kq-danger': row.outcome === 'reject',
                  }"
                >
                  {{ t(`onboarding.steps.employees.outcomes.${row.outcome}`) }}
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

    <div class="flex gap-3">
      <button
        type="button"
        :disabled="finishing"
        :aria-busy="finishing"
        data-test="continue"
        class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 font-semibold text-kq-on-primary disabled:opacity-60"
        @click="finish('completed')"
      >
        {{ t('onboarding.actions.continue') }}
      </button>
      <button
        type="button"
        :disabled="finishing"
        data-test="skip"
        class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-4 py-2 text-kq-text hover:bg-kq-surface-alt disabled:opacity-60"
        @click="finish('skipped')"
      >
        {{ t('onboarding.actions.skip') }}
      </button>
    </div>
  </div>
</template>
