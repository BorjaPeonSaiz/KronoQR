<script setup lang="ts">
// Revision final antes de cerrar el asistente (RF-PD-03).
//
// El asistente NO se cierra solo al resolver el ultimo paso: `available` sigue
// en `true` hasta este `POST /setup/complete` explicito (decision de la 5.5).
// Es la unica oportunidad de mirar los ocho pasos juntos antes de que el
// asistente deje de estar accesible para siempre.
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { SetupStepStatus } from '@/shared/api/types'
import { useSetupStore } from './setup.store'
import { stepHeadingKey } from './steps'

defineProps<{ steps: readonly SetupStepStatus[] }>()

const { t } = useI18n()
const setup = useSetupStore()

const completing = ref(false)
const error = ref<unknown>(null)

/**
 * No emite nada: `setup.complete()` deja el resumen en `setup.completion`, en
 * el MISMO tick en que cierra el asistente (`setup.available` pasa a
 * `false`). `OnboardingView` lee las dos cosas del store, asi que nunca pinta
 * un instante intermedio en el que el asistente ya esta cerrado pero el
 * resumen todavia no existe.
 */
async function finish(): Promise<void> {
  completing.value = true
  error.value = null

  try {
    await setup.complete()
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
      {{ t('onboarding.review.heading') }}
    </h2>
    <p class="text-sm text-kq-text-muted">{{ t('onboarding.review.intro') }}</p>

    <ul class="flex flex-col gap-1" data-test="review-list">
      <li
        v-for="step of steps"
        :key="step.step"
        class="flex items-center justify-between gap-3 rounded-kq-sm border border-kq-border bg-kq-surface-raised px-3 py-2"
        :data-test="`review-${step.step}`"
      >
        <span class="text-kq-text">{{ t(stepHeadingKey(step.step)) }}</span>
        <span
          class="rounded-kq-sm px-2 py-0.5 text-sm"
          :class="
            step.state === 'completed'
              ? 'bg-kq-success-soft text-kq-success'
              : 'bg-kq-surface-alt text-kq-text-muted'
          "
        >
          {{ t(`onboarding.review.states.${step.state}`) }}
        </span>
      </li>
    </ul>

    <ErrorNotice v-if="error !== null" :error="error" />

    <div>
      <button
        type="button"
        :disabled="completing"
        :aria-busy="completing"
        data-test="complete-setup"
        class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 font-semibold text-kq-on-primary disabled:opacity-60"
        @click="finish"
      >
        {{ completing ? t('onboarding.review.completing') : t('onboarding.review.complete') }}
      </button>
    </div>
  </div>
</template>
