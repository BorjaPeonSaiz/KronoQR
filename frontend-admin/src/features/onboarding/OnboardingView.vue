<script setup lang="ts">
// Asistente de puesta en marcha (RF-PD-03, RF-GP-05, tarea 5.5).
//
// REANUDABLE Y SIN CALLEJONES SIN SALIDA. El estado viene de `setup.store`
// (`GET /setup/status`, publica, o `GET /setup/steps`, autenticada — ver el
// comentario de `load()` alli): cada paso que se resuelve queda escrito en el
// servidor (marcado con `PUT /setup/steps/{step}` o deducido del dato para
// `administrator` y `site`), asi que abandonar a mitad y volver —incluso en
// otra pestaña, incluso al dia siguiente— retoma exactamente donde se dejo.
// Ningun paso deja a quien lo usa sin una salida sin abrir una consola.
//
// UN PASO PENDIENTE A LA VEZ. El paso activo es el primero en `pending`, en el
// orden del contrato (`STEP_ORDER`). Cuando ninguno queda pendiente —todos
// `completed` o `skipped`— se enseña la revision final (`ReviewStep`); el
// asistente NO se cierra solo, hace falta el «Finalizar» explicito (decision
// de la 5.5): asi hay una unica oportunidad de ver los ocho pasos juntos antes
// de que `POST /setup/complete` los deje fuera de alcance para siempre.
import { announce, announcement } from '@kronoqr/web-kit/announcer'
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import LoadingPanel from '@kronoqr/web-kit/components/LoadingPanel.vue'
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import type { SetupStep, SetupStepStatus } from '@/shared/api/types'
import CompletionSummary from './CompletionSummary.vue'
import ReviewStep from './ReviewStep.vue'
import { useSetupStore } from './setup.store'
import { STEP_ORDER, stepHeadingKey } from './steps'
import AdministratorStep from './steps/AdministratorStep.vue'
import ComplianceProfileStep from './steps/ComplianceProfileStep.vue'
import DepartmentsStep from './steps/DepartmentsStep.vue'
import EmployeesImportStep from './steps/EmployeesImportStep.vue'
import KioskStep from './steps/KioskStep.vue'
import LicenseStep from './steps/LicenseStep.vue'
import OrganisationStep from './steps/OrganisationStep.vue'
import SiteStep from './steps/SiteStep.vue'

const STEP_COMPONENTS = {
  administrator: AdministratorStep,
  organisation: OrganisationStep,
  site: SiteStep,
  departments: DepartmentsStep,
  compliance_profile: ComplianceProfileStep,
  employees: EmployeesImportStep,
  license: LicenseStep,
  kiosk: KioskStep,
} as const satisfies Record<SetupStep, unknown>

const { t } = useI18n()
const setup = useSetupStore()

const regionRef = ref<HTMLElement | null>(null)

/**
 * Los pasos, en el orden fijo del contrato: `GET /setup/steps` no promete un
 * orden. Vacio mientras `setup.stepsKnown` es `false` —sin sesion, la
 * respuesta publica de `GET /setup/status` no trae `steps`— y eso es
 * intencional: `currentStepComponent`, de abajo, no depende de esta lista para
 * el primer paso.
 */
const orderedSteps = computed<SetupStepStatus[]>(() =>
  STEP_ORDER.map((step) => setup.steps.find((entry) => entry.step === step)).filter(
    (entry): entry is SetupStepStatus => entry !== undefined,
  ),
)

const pendingStep = computed(() => orderedSteps.value.find((entry) => entry.state === 'pending'))
const allResolved = computed(() => orderedSteps.value.length > 0 && pendingStep.value === undefined)

/**
 * El componente del paso activo. **El primer administrador es un caso
 * especial**: es el UNICO paso que se puede resolver sin sesion —es derivado,
 * no depende de `steps`—, y por eso se pinta en cuanto el asistente esta
 * disponible aunque `setup.stepsKnown` siga en `false` (instalacion recien
 * montada, o un token guardado que ya no vale y `main.ts` todavia no ha vuelto
 * a `/login`). En cuanto hay sesion, `setup.steps` llega de verdad y este
 * `computed` pasa a seguir el paso pendiente como el resto.
 */
const currentStepComponent = computed(() => {
  if (!setup.stepsKnown) {
    return STEP_COMPONENTS.administrator
  }

  const step = pendingStep.value

  return step === undefined ? null : STEP_COMPONENTS[step.step]
})

/** Clave estable de lo que se esta enseñando ahora mismo, para el foco y el anuncio. */
const activeKey = computed(() => {
  if (setup.completion !== null) return 'completion'
  if (!setup.loaded) return 'loading'
  if (!setup.available) return 'already-done'
  if (!setup.stepsKnown) return 'administrator'
  if (pendingStep.value !== undefined) return pendingStep.value.step
  if (allResolved.value) return 'review'

  return 'loading'
})

async function load(): Promise<void> {
  await setup.load(true)
}

onMounted(load)

async function focusRegion(): Promise<void> {
  await nextTick()
  regionRef.value?.focus()
}

/** Anuncia y mueve el foco al cambiar de paso: cada paso nuevo es un cambio de pantalla. */
watch(activeKey, (key) => {
  if (key === 'loading') return

  const label =
    key === 'completion'
      ? t('onboarding.completion.heading')
      : key === 'already-done'
        ? t('onboarding.alreadyDone.heading')
        : key === 'review'
          ? t('onboarding.review.heading')
          : t(stepHeadingKey(key as SetupStep))

  announce(t('onboarding.stepAnnouncement', { label }))
  void focusRegion()
})
</script>

<template>
  <main class="flex min-h-dvh justify-center bg-kq-surface p-4">
    <div class="w-full max-w-3xl py-8">
      <h1 class="text-2xl font-bold text-kq-text">{{ t('onboarding.heading') }}</h1>
      <p class="mt-1 text-kq-text-muted">{{ t('onboarding.intro') }}</p>

      <!-- Region viva propia: el asistente vive fuera de `AppShellView` (WCAG 2.2 AA, 4.1.3). -->
      <p role="status" aria-live="polite" class="sr-only">{{ announcement }}</p>

      <!-- Progreso: orientacion, no navegacion — cada paso se resuelve en orden. -->
      <ol
        v-if="setup.loaded && setup.available && setup.stepsKnown"
        class="mt-6 flex flex-wrap gap-2"
        :aria-label="t('onboarding.progress.label')"
        data-test="progress"
      >
        <li
          v-for="entry of orderedSteps"
          :key="entry.step"
          class="rounded-kq-sm px-3 py-1 text-sm"
          :aria-current="entry.step === pendingStep?.step ? 'step' : undefined"
          :class="{
            'bg-kq-success-soft text-kq-success': entry.state === 'completed',
            'bg-kq-surface-alt text-kq-text-muted': entry.state === 'skipped',
            'bg-kq-primary-soft font-semibold text-kq-on-primary-soft':
              entry.state === 'pending' && entry.step === pendingStep?.step,
          }"
        >
          {{ t(stepHeadingKey(entry.step)) }}
        </li>
      </ol>

      <div
        ref="regionRef"
        tabindex="-1"
        class="mt-6 rounded-kq border border-kq-border bg-kq-surface-raised p-6 shadow-kq-soft focus:outline-none"
      >
        <LoadingPanel v-if="!setup.loaded && setup.loading" :label="t('onboarding.loading')" />

        <ErrorNotice v-else-if="!setup.loaded && setup.error !== null" :error="setup.error" />

        <template v-else-if="setup.completion !== null">
          <CompletionSummary :completion="setup.completion" />
        </template>

        <template v-else-if="!setup.available">
          <div class="flex flex-col gap-4">
            <h2 tabindex="-1" class="text-lg font-semibold text-kq-text focus:outline-none">
              {{ t('onboarding.alreadyDone.heading') }}
            </h2>
            <p class="text-kq-text-muted">{{ t('onboarding.alreadyDone.intro') }}</p>
            <RouterLink
              :to="{ name: 'login' }"
              class="font-semibold text-kq-primary-strong underline"
            >
              {{ t('onboarding.alreadyDone.goToLogin') }}
            </RouterLink>
          </div>
        </template>

        <ReviewStep v-else-if="allResolved" :steps="orderedSteps" />

        <component :is="currentStepComponent" v-else-if="currentStepComponent !== null" />
      </div>
    </div>
  </main>
</template>
