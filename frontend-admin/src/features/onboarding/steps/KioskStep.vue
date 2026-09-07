<script setup lang="ts">
// Paso 7 (ultimo, ademas del cierre): el primer quiosco (RF-PD-03, RF-PD-06).
// Omitible: puede que la tablet todavia no haya llegado al hotel.
//
// Reutiliza el MISMO formulario que la pantalla «Quioscos» (tarea 5.6,
// `PairKioskForm`): el codigo de seis digitos que muestra la tablet y el
// nombre del quiosco. `PairKioskForm` ya enseña su propio resumen tras
// confirmar (version de la app, hora en que la tablet pidio el codigo) para
// que se contraste con la tablet delante; ESTE paso no avanza solo al
// vincular —seria pasar por encima de ese resumen sin darle tiempo a
// leerse—, sino que exige un «Continuar» explicito, igual que ya hace el paso
// de licencia tras activar una clave.
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import PairKioskForm from '@/features/devices/PairKioskForm.vue'
import { useSetupStore } from '../setup.store'

const { t } = useI18n()
const setup = useSetupStore()

const skipping = ref(false)
const completing = ref(false)
const error = ref<unknown>(null)
/** Si ya se vinculo un quiosco en esta visita al paso: cambia «Omitir» por «Continuar». */
const paired = ref(false)

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

function onPaired(): void {
  paired.value = true
}

async function continueStep(): Promise<void> {
  completing.value = true
  error.value = null

  try {
    await setup.recordStep('kiosk', 'completed')
  } catch (caught) {
    error.value = caught
  } finally {
    completing.value = false
  }
}
</script>

<template>
  <div class="flex flex-col gap-4">
    <h2 tabindex="-1" class="text-lg font-semibold text-kq-text focus:outline-none">
      {{ t('onboarding.steps.kiosk.heading') }}
    </h2>
    <p class="text-sm text-kq-text-muted">{{ t('onboarding.steps.kiosk.intro') }}</p>

    <ErrorNotice v-if="error !== null" :error="error" />

    <PairKioskForm @paired="onPaired" />

    <div>
      <button
        v-if="paired"
        type="button"
        :disabled="completing"
        :aria-busy="completing"
        data-test="continue"
        class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 font-semibold text-kq-on-primary disabled:opacity-60"
        @click="continueStep"
      >
        {{ completing ? t('onboarding.actions.saving') : t('onboarding.actions.continue') }}
      </button>
      <button
        v-else
        type="button"
        :disabled="skipping"
        :aria-busy="skipping"
        data-test="skip"
        class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-4 py-2 text-kq-text hover:bg-kq-surface-alt disabled:opacity-60"
        @click="skip"
      >
        {{ skipping ? t('onboarding.actions.saving') : t('onboarding.steps.kiosk.skip') }}
      </button>
    </div>
  </div>
</template>
