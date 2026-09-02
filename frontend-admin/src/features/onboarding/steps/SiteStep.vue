<script setup lang="ts">
// Paso 3: el centro de trabajo y su zona horaria (RF-PD-03, ADR-040, regla dura 3).
//
// Hay exactamente un centro por instalacion: este alta es IRREPETIBLE
// (`sites_single_row_uidx`). El perfil de cumplimiento NO se elige aqui —nace
// sin asignar, y `GET /compliance-profile` resuelve el vigente— porque con un
// solo centro hay exactamente un perfil en juego (decision de la 5.5).
//
// `timezone` es el dato del que depende RN-05: la jornada de un tramo es la
// fecha civil de su hora de inicio EN ESTA ZONA, nunca en UTC ni en la del
// navegador de quien rellena este formulario.
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import FormField from '@kronoqr/web-kit/components/FormField.vue'
import { isApiError } from '@kronoqr/web-kit/http'
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { createInstallationSite } from '../setup.api'
import { useSetupStore } from '../setup.store'

const { t } = useI18n()
const setup = useSetupStore()

const name = ref('')
const timezone = ref('Europe/Madrid')
const submitting = ref(false)
const error = ref<unknown>(null)

function fieldErrors(field: string): readonly string[] {
  return isApiError(error.value) ? (error.value.fieldErrors[field] ?? []) : []
}

async function submit(): Promise<void> {
  submitting.value = true
  error.value = null

  try {
    await createInstallationSite({ name: name.value, timezone: timezone.value })
    // Paso DERIVADO: no se marca, se relee para recoger que el centro ya existe.
    await setup.refresh()
  } catch (caught) {
    error.value = caught
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="flex flex-col gap-4">
    <h2 tabindex="-1" class="text-lg font-semibold text-kq-text focus:outline-none">
      {{ t('onboarding.steps.site.heading') }}
    </h2>
    <p class="text-sm text-kq-text-muted">{{ t('onboarding.steps.site.intro') }}</p>

    <ErrorNotice
      v-if="error !== null"
      :error="error"
      :field-labels="{
        name: t('onboarding.steps.site.fields.name'),
        timezone: t('onboarding.steps.site.fields.timezone'),
      }"
    />

    <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
      <FormField
        v-slot="field"
        :label="t('onboarding.steps.site.fields.name')"
        :hint="t('onboarding.steps.site.hints.name')"
        :errors="fieldErrors('name')"
        required
      >
        <input
          :id="field.id"
          v-model="name"
          type="text"
          required
          :aria-describedby="field.describedBy"
          :aria-invalid="field.invalid"
          class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text"
        />
      </FormField>

      <FormField
        v-slot="field"
        :label="t('onboarding.steps.site.fields.timezone')"
        :hint="t('onboarding.steps.site.hints.timezone')"
        :errors="fieldErrors('timezone')"
        required
      >
        <input
          :id="field.id"
          v-model="timezone"
          type="text"
          list="onboarding-timezones"
          required
          :aria-describedby="field.describedBy"
          :aria-invalid="field.invalid"
          class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text"
        />
        <datalist id="onboarding-timezones">
          <option value="Europe/Madrid" />
          <option value="Atlantic/Canary" />
          <option value="Europe/Lisbon" />
          <option value="Europe/Paris" />
        </datalist>
      </FormField>

      <div>
        <button
          type="submit"
          :disabled="submitting"
          :aria-busy="submitting"
          class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 font-semibold text-kq-on-primary disabled:opacity-60"
        >
          {{ submitting ? t('onboarding.actions.saving') : t('onboarding.actions.continue') }}
        </button>
      </div>
    </form>
  </div>
</template>
