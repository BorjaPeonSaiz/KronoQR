<script setup lang="ts">
// Exportacion para la Inspeccion de Trabajo (RF-IN-05, RL-03, RL-06).
//
// Para que existe, literalmente: para que quien recibe un requerimiento con
// plazo pueda entregar el registro horario sin llamar a nadie. La via de consola
// —`php artisan compliance:legal-export`, runbook `requerimiento-inspeccion.md`—
// sigue existiendo y es la de respaldo; esta es la del dia a dia.
//
// Lo que esta pantalla NO hace, y se dice en ella misma en vez de dejar que
// alguien lo suponga:
//
//  - **No filtra por centro ni por departamento.** El endpoint tiene dos
//    alcances: la plantilla completa o una persona. Ofrecer aqui un filtro que
//    el servidor ignora seria dejar a quien exporta convencido de haber acotado
//    lo que entrego.
//  - **No previsualiza el fichero.** Es una lista nominal de la plantilla con
//    sus horas: se descarga y se suelta, como el PDF de una tarjeta. Pintarla en
//    pantalla la dejaria viva en memoria y en el historial de una sesion
//    compartida.
//
// La descarga se confirma con las cifras que devuelve el servidor en cabeceras
// —las mismas que quedan en `audit_log`— para que quien contesta el
// requerimiento pueda decir cuanto entrego.
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { downloadDocument } from '@/shared/download/downloadDocument'
import { announce } from '@/shared/ui/announcer'
import ErrorNotice from '@/shared/ui/ErrorNotice.vue'
import FormField from '@/shared/ui/FormField.vue'
import { downloadLegalExport, type LegalExportTally } from './legalExport.api'

const { t } = useI18n()

const from = ref('')
const to = ref('')
const employeeUuid = ref('')

const running = ref(false)
const error = ref<unknown>(null)
const lastTally = ref<LegalExportTally | null>(null)

/**
 * Un periodo invertido no se «arregla» dando la vuelta a las fechas: el fichero
 * que acabaria en un expediente llevaria escrito un periodo que nadie pidio. Se
 * avisa antes de llamar, y el servidor lo vuelve a comprobar.
 */
const invertedPeriod = computed(() => from.value !== '' && to.value !== '' && to.value < from.value)

const canSubmit = computed(
  () => from.value !== '' && to.value !== '' && !invertedPeriod.value && !running.value,
)

async function submit(): Promise<void> {
  if (!canSubmit.value) {
    return
  }

  running.value = true
  error.value = null
  lastTally.value = null

  try {
    const exported = await downloadLegalExport({
      from: from.value,
      to: to.value,
      employeeUuid: employeeUuid.value.trim(),
    })

    downloadDocument(exported.document)
    lastTally.value = exported.tally
    announce(
      t('reports.legalExport.done', {
        shiftEntries: exported.tally.shiftEntries ?? 0,
        corrections: exported.tally.corrections ?? 0,
      }),
    )
  } catch (failure) {
    error.value = failure
    announce(t('reports.legalExport.failed'))
  } finally {
    running.value = false
  }
}
</script>

<template>
  <section class="flex flex-col gap-6">
    <header class="flex flex-col gap-2">
      <h1 class="text-2xl font-semibold">{{ t('reports.legalExport.heading') }}</h1>
      <p class="max-w-3xl text-slate-700">{{ t('reports.legalExport.intro') }}</p>
    </header>

    <form class="flex max-w-3xl flex-col gap-4" novalidate @submit.prevent="submit">
      <div class="grid gap-4 sm:grid-cols-2">
        <FormField
          :label="t('reports.legalExport.from')"
          :hint="t('reports.legalExport.workDateHint')"
          required
        >
          <template #default="{ id, describedBy }">
            <input
              :id="id"
              v-model="from"
              type="date"
              required
              :aria-describedby="describedBy"
              class="rounded border border-slate-400 px-3 py-2"
            />
          </template>
        </FormField>

        <FormField
          :label="t('reports.legalExport.to')"
          :hint="t('reports.legalExport.inclusiveHint')"
          :errors="invertedPeriod ? [t('reports.legalExport.inverted')] : []"
          required
        >
          <template #default="{ id, describedBy, invalid }">
            <input
              :id="id"
              v-model="to"
              type="date"
              required
              :aria-describedby="describedBy"
              :aria-invalid="invalid"
              class="rounded border border-slate-400 px-3 py-2"
            />
          </template>
        </FormField>
      </div>

      <FormField
        :label="t('reports.legalExport.employee')"
        :hint="t('reports.legalExport.employeeHint')"
      >
        <template #default="{ id, describedBy }">
          <input
            :id="id"
            v-model="employeeUuid"
            type="text"
            inputmode="text"
            autocomplete="off"
            :aria-describedby="describedBy"
            class="rounded border border-slate-400 px-3 py-2 font-mono"
          />
        </template>
      </FormField>

      <div class="flex items-center gap-3">
        <button
          type="submit"
          :disabled="!canSubmit"
          class="rounded bg-slate-900 px-4 py-2 text-white hover:bg-slate-800 disabled:opacity-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900"
        >
          {{ t('reports.legalExport.submit') }}
        </button>
        <p v-if="running" class="text-slate-700">{{ t('reports.legalExport.running') }}</p>
      </div>
    </form>

    <ErrorNotice v-if="error !== null" :error="error" />

    <p
      v-if="lastTally !== null"
      class="max-w-3xl rounded-md border border-emerald-300 bg-emerald-50 p-4 text-emerald-900"
    >
      {{
        t('reports.legalExport.done', {
          shiftEntries: lastTally.shiftEntries ?? 0,
          corrections: lastTally.corrections ?? 0,
        })
      }}
    </p>

    <section class="max-w-3xl rounded-md border border-slate-300 bg-white p-4">
      <h2 class="font-semibold">{{ t('reports.legalExport.contents.heading') }}</h2>
      <ul class="mt-2 list-disc pl-5 text-slate-700">
        <li>{{ t('reports.legalExport.contents.entries') }}</li>
        <li>{{ t('reports.legalExport.contents.corrections') }}</li>
        <li>{{ t('reports.legalExport.contents.voided') }}</li>
        <li>{{ t('reports.legalExport.contents.times') }}</li>
        <li>{{ t('reports.legalExport.contents.format') }}</li>
      </ul>
      <p class="mt-3 text-sm text-slate-600">{{ t('reports.legalExport.audited') }}</p>
      <p class="mt-1 text-sm text-slate-600">{{ t('reports.legalExport.noSiteFilter') }}</p>
    </section>
  </section>
</template>
