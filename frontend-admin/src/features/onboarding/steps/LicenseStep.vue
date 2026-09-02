<script setup lang="ts">
// Paso 6: licencia (RF-PD-03, ADR-019, regla dura 15). Omitible: un asistente
// que exigiera licencia para terminar convertiria la licencia en requisito de
// arranque, y eso es justo lo que la regla dura 15 prohibe.
//
// Reutiliza la MISMA pantalla de activacion que `/license` (tarea 5.3): mismo
// estado, mismo aviso de que el registro horario nunca depende de la
// licencia, mismo formulario para pegar la clave. El asistente solo añade el
// «continuar» / «omitir por ahora».
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import LicenseView from '@/features/settings/LicenseView.vue'
import { useSetupStore } from '../setup.store'

const { t } = useI18n()
const setup = useSetupStore()

const finishing = ref(false)
const error = ref<unknown>(null)

async function finish(state: 'completed' | 'skipped'): Promise<void> {
  finishing.value = true
  error.value = null

  try {
    await setup.recordStep('license', state)
  } catch (caught) {
    error.value = caught
  } finally {
    finishing.value = false
  }
}
</script>

<template>
  <div class="flex flex-col gap-6">
    <div>
      <h2 tabindex="-1" class="text-lg font-semibold text-kq-text focus:outline-none">
        {{ t('onboarding.steps.license.heading') }}
      </h2>
      <p class="mt-1 text-sm text-kq-text-muted">{{ t('onboarding.steps.license.intro') }}</p>
    </div>

    <LicenseView heading-level="h3" />

    <ErrorNotice v-if="error !== null" :error="error" />

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
        {{ t('onboarding.steps.license.skip') }}
      </button>
    </div>
  </div>
</template>
