<script setup lang="ts">
// Paso 5: perfil de cumplimiento (RF-PD-03, RL-21). NO omitible: los umbrales
// hay que contrastarlos con el convenio aplicable, y eso es un acto de
// alguien, no un valor por defecto que nadie miro (contrato, `SetupStepStatus`).
//
// Reutiliza la MISMA pantalla que la gestion del dia a dia (`/compliance-profile`,
// tarea 5.2): mismos umbrales visibles, mismo aviso de RL-21, mismo `ES-hosteleria`
// preseleccionado por la migracion. El asistente solo añade el boton que
// confirma que se ha revisado.
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import ComplianceProfileView from '@/features/settings/ComplianceProfileView.vue'
import { useSetupStore } from '../setup.store'

const { t } = useI18n()
const setup = useSetupStore()

const confirming = ref(false)
const error = ref<unknown>(null)

async function confirm(): Promise<void> {
  confirming.value = true
  error.value = null

  try {
    await setup.recordStep('compliance_profile', 'completed')
  } catch (caught) {
    error.value = caught
  } finally {
    confirming.value = false
  }
}
</script>

<template>
  <div class="flex flex-col gap-6">
    <div>
      <h2 tabindex="-1" class="text-lg font-semibold text-kq-text focus:outline-none">
        {{ t('onboarding.steps.complianceProfile.heading') }}
      </h2>
      <p class="mt-1 text-sm text-kq-text-muted">
        {{ t('onboarding.steps.complianceProfile.intro') }}
      </p>
    </div>

    <ComplianceProfileView heading-level="h3" />

    <ErrorNotice v-if="error !== null" :error="error" />

    <div class="flex flex-col gap-2">
      <p class="text-sm text-kq-text-muted">
        {{ t('onboarding.steps.complianceProfile.confirmHint') }}
      </p>
      <div>
        <button
          type="button"
          :disabled="confirming"
          :aria-busy="confirming"
          data-test="confirm-compliance-profile"
          class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 font-semibold text-kq-on-primary disabled:opacity-60"
          @click="confirm"
        >
          {{
            confirming
              ? t('onboarding.actions.saving')
              : t('onboarding.steps.complianceProfile.confirm')
          }}
        </button>
      </div>
    </div>
  </div>
</template>
