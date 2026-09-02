<script setup lang="ts">
// Paso 7 (ultimo, ademas del cierre): el primer quiosco (RF-PD-03). Omitible:
// puede que la tablet todavia no haya llegado al hotel.
//
// PUNTO DE INTEGRACION LIMPIO PARA LA 5.6, todavia sin implementar. Los
// endpoints de emparejamiento (`/kiosk/pair`, `/kiosk/pair/confirm`) NO
// existen: este paso solo ofrece omitir y explica el procedimiento manual
// (`kiosk:pairing-code` en la consola del servidor). Cuando la 5.6 los
// implemente, este componente es el unico sitio que hay que tocar para
// añadir el formulario del codigo de emparejamiento y, al confirmarlo, llamar
// a `setup.recordStep('kiosk', 'completed')` — exactamente igual que hacen ya
// el resto de pasos omitibles.
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import { useSetupStore } from '../setup.store'

const { t } = useI18n()
const setup = useSetupStore()

const skipping = ref(false)
const error = ref<unknown>(null)

async function skip(): Promise<void> {
  skipping.value = true
  error.value = null

  try {
    await setup.recordStep('kiosk', 'skipped')
  } catch (caught) {
    error.value = caught
  } finally {
    skipping.value = false
  }
}
</script>

<template>
  <div class="flex flex-col gap-4">
    <h2 tabindex="-1" class="text-lg font-semibold text-kq-text focus:outline-none">
      {{ t('onboarding.steps.kiosk.heading') }}
    </h2>
    <p class="text-sm text-kq-text-muted">{{ t('onboarding.steps.kiosk.intro') }}</p>

    <p class="rounded-kq border border-kq-border bg-kq-surface-alt p-4 text-kq-text" role="note">
      {{ t('onboarding.steps.kiosk.notYetAvailable') }}
    </p>

    <ErrorNotice v-if="error !== null" :error="error" />

    <div>
      <button
        type="button"
        :disabled="skipping"
        :aria-busy="skipping"
        data-test="skip"
        class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 font-semibold text-kq-on-primary disabled:opacity-60"
        @click="skip"
      >
        {{ skipping ? t('onboarding.actions.saving') : t('onboarding.steps.kiosk.skip') }}
      </button>
    </div>
  </div>
</template>
