<script setup lang="ts">
// Paso 6 (RF-GP-05). Omitible: se puede terminar la puesta en marcha y
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
//
// READMISIONES (RN-27, ADR-062 §6). Una fila cuyo documento es de una persona de
// baja sale como `rehire` con el aviso `rehire_proposed`: se propone darla de alta
// de nuevo en su ficha, no se rechaza. El resumen lo cuenta aparte
// (`summary.rehire`) y el servidor devuelve `rehire_digest`, que `apply` manda de
// vuelta (`confirm_rehire_digest`): solo se readmite a quien se vio en el resumen.
// Si entre la validacion y la aplicacion cambia a quien se readmitiria (o el
// fichero), `apply` responde `409` SIN escribir nada; aqui se dice por que, se
// descarta el informe viejo y se pide validar otra vez. Cada codigo nuevo lleva su
// «que hacer» (`messageAdvice`) debajo del texto del servidor.
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import { isApiError } from '@kronoqr/web-kit/http'
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
/** El `409` de `apply`: lo revisado ya no es lo que se aplicaria. No se ha escrito nada. */
const stale = ref(false)

/** Codigos de la readmision con «que hacer» propio (`onboarding.steps.employees.messageAdvice`). */
const REHIRE_ADVICE_CODES: readonly string[] = [
  'rehire_proposed',
  'rehire_requires_hired_at',
  'rehire_not_after_termination',
  'rehire_identity_mismatch',
  'rehire_ignores_identity_changes',
]

function onFileChange(event: Event): void {
  const files = (event.target as HTMLInputElement).files

  selectedFile.value = files !== null && files.length > 0 ? (files[0] ?? null) : null
  stale.value = false
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
  stale.value = false

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
  stale.value = false

  try {
    report.value = await importEmployees({
      file: selectedFile.value,
      mode: 'apply',
      confirmChecksum: report.value.file.sha256,
      confirmRehireDigest: report.value.rehire_digest,
    })
    setup.importedWithoutPin += report.value.summary.create
  } catch (caught) {
    if (isApiError(caught) && caught.status === 409) {
      // Lo revisado ya no es lo que se aplicaria (otro fichero, o alguien dio de baja
      // o de alta a una persona del fichero): se descarta el informe para que «Aplicar»
      // no pueda volver a enviar la huella vieja, y se pide validar de nuevo.
      stale.value = true
      report.value = null
    } else {
      error.value = caught
    }
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

    <div
      v-if="stale"
      role="alert"
      data-test="import-stale"
      class="rounded-kq border border-kq-warning bg-kq-warning-soft p-4 text-kq-warning"
    >
      <p class="font-semibold">{{ t('onboarding.steps.employees.stale.title') }}</p>
      <p class="mt-1">{{ t('onboarding.steps.employees.stale.advice') }}</p>
    </div>

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
        v-aria-disabled.reason="!canValidate"
        type="button"
        :aria-busy="validating"
        data-test="validate"
        class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-4 py-2 text-kq-text hover:bg-kq-surface-alt aria-disabled:opacity-60"
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
        v-aria-disabled.reason="!canApply"
        type="button"
        :aria-busy="applying"
        data-test="apply"
        class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 font-semibold text-kq-on-primary aria-disabled:opacity-60"
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

      <!-- Readmisiones: se cuentan aparte y se piden revisar ANTES de aplicar (RN-27). -->
      <div
        v-if="!applied && report.summary.rehire > 0"
        role="status"
        data-test="rehire-notice"
        class="rounded-kq-sm border border-kq-warning bg-kq-warning-soft p-3 text-kq-warning"
      >
        <p>
          {{
            t(
              'onboarding.steps.employees.rehireNotice',
              { count: report.summary.rehire },
              report.summary.rehire,
            )
          }}
        </p>
        <p class="mt-1">{{ t('onboarding.steps.employees.rehireFutureNotice') }}</p>
      </div>
      <p
        v-if="applied && report.summary.rehire > 0"
        data-test="rehired-notice"
        class="rounded-kq-sm border border-kq-warning bg-kq-warning-soft p-3 text-kq-warning"
      >
        {{
          t(
            'onboarding.steps.employees.rehiredNotice',
            { count: report.summary.rehire },
            report.summary.rehire,
          )
        }}
      </p>

      <p
        v-if="applied && report.summary.create > 0"
        class="rounded-kq-sm border border-kq-warning bg-kq-warning-soft p-3 text-kq-warning"
        data-test="no-pin-notice"
      >
        {{
          t(
            'onboarding.steps.employees.noPinNotice',
            { count: report.summary.create },
            report.summary.create,
          )
        }}
      </p>

      <div
        tabindex="0"
        role="region"
        :aria-label="t('onboarding.steps.employees.tableCaption')"
        class="relative overflow-x-auto"
      >
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
                    'bg-kq-warning-soft text-kq-warning': row.outcome === 'rehire',
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
                    <span
                      v-if="REHIRE_ADVICE_CODES.includes(message.code)"
                      class="block text-kq-text-muted"
                      data-test="message-advice"
                    >
                      {{ t(`onboarding.steps.employees.messageAdvice.${message.code}`) }}
                    </span>
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
        v-aria-disabled.busy="finishing"
        type="button"
        :aria-busy="finishing"
        data-test="continue"
        class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 font-semibold text-kq-on-primary aria-disabled:opacity-60"
        @click="finish('completed')"
      >
        {{ t('onboarding.actions.continue') }}
      </button>
      <button
        v-aria-disabled.busy="finishing"
        type="button"
        data-test="skip"
        class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-4 py-2 text-kq-text hover:bg-kq-surface-alt aria-disabled:opacity-60"
        @click="finish('skipped')"
      >
        {{ t('onboarding.actions.skip') }}
      </button>
    </div>
  </div>
</template>
