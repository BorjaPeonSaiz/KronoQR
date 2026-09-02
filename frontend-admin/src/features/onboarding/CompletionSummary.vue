<script setup lang="ts">
// El resumen final accionable (RF-PD-03): que falta antes del primer dia, en
// cifras y nunca en nombres (regla dura 21, `SetupSummary` del contrato).
//
// LA CIFRA QUE DE VERDAD IMPORTA ES `credentials_pending`: sin tarjeta
// impresa y entregada nadie ficha el primer dia (ADR-014), y emitirlas e
// imprimirlas lleva dias de logistica —por eso se dice AQUI, en el momento en
// que la puesta en marcha se da por terminada, y no se deja para que alguien
// lo descubra delante de la tablet a las 06:00. El detalle persona a persona
// esta en el panel de estado de credenciales (RF-QR-08) y en
// `credentials:status --pending` desde la consola del servidor.
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import type { SetupCompletion } from '@/shared/api/types'

const props = defineProps<{ completion: SetupCompletion }>()

const { t } = useI18n()

const pending = computed(() => props.completion.summary.credentials_pending)
</script>

<template>
  <div class="flex flex-col gap-6">
    <header>
      <h2 tabindex="-1" class="text-lg font-semibold text-kq-text focus:outline-none">
        {{ t('onboarding.completion.heading') }}
      </h2>
      <p class="mt-1 text-sm text-kq-text-muted">{{ t('onboarding.completion.intro') }}</p>
    </header>

    <!-- Lo accionable, arriba y sin buscarlo. -->
    <div
      class="flex flex-col gap-2 rounded-kq border p-4"
      :class="
        pending > 0
          ? 'border-kq-warning bg-kq-warning-soft text-kq-warning'
          : 'border-kq-success bg-kq-success-soft text-kq-success'
      "
      role="alert"
      data-test="credentials-alert"
    >
      <p class="font-semibold">
        {{
          pending > 0
            ? t('onboarding.completion.credentialsPending', { count: pending })
            : t('onboarding.completion.credentialsNone')
        }}
      </p>
      <p v-if="pending > 0">{{ t('onboarding.completion.credentialsAdvice') }}</p>
      <p class="text-sm">
        <RouterLink :to="{ name: 'credentials' }" class="font-semibold underline">
          {{ t('onboarding.completion.credentialsLink') }}
        </RouterLink>
      </p>
      <p class="font-mono text-sm">{{ t('onboarding.completion.credentialsCommand') }}</p>
    </div>

    <dl class="grid gap-3 sm:grid-cols-2" data-test="summary">
      <div>
        <dt class="text-kq-text-muted">{{ t('onboarding.completion.fields.employees') }}</dt>
        <dd class="text-lg font-semibold text-kq-text">{{ completion.summary.employees }}</dd>
      </div>
      <div>
        <dt class="text-kq-text-muted">{{ t('onboarding.completion.fields.departments') }}</dt>
        <dd class="text-lg font-semibold text-kq-text">{{ completion.summary.departments }}</dd>
      </div>
      <div>
        <dt class="text-kq-text-muted">{{ t('onboarding.completion.fields.license') }}</dt>
        <dd class="text-lg font-semibold text-kq-text">
          {{ t(`license.states.${completion.summary.license}`) }}
        </dd>
      </div>
      <div>
        <dt class="text-kq-text-muted">{{ t('onboarding.completion.fields.kiosks') }}</dt>
        <dd class="text-lg font-semibold text-kq-text">{{ completion.summary.kiosks }}</dd>
      </div>
    </dl>

    <div>
      <RouterLink
        :to="{ name: 'employees' }"
        class="inline-block rounded-kq-sm bg-kq-primary-strong px-4 py-2 font-semibold text-kq-on-primary"
        data-test="go-to-panel"
      >
        {{ t('onboarding.completion.goToPanel') }}
      </RouterLink>
    </div>
  </div>
</template>
