<script setup lang="ts">
// Aviso de proteccion de datos del portal (art. 13 RGPD).
//
// Va en la pantalla de acceso, donde la persona teclea su codigo y su PIN. El
// contenido viene de la marca de la instalacion (`privacy_notice` de
// `GET /api/v1/branding`, RF-PD-08): responsable y politica completa los pone el
// cliente en el panel, no el codigo (regla dura 13). Con los dos a `null`, la
// redaccion generica de los textos. Aqui la politica puede ser un enlace; la
// regla de «nunca enlace» es del quiosco, que acaba en una pantalla compartida.
import { safePolicyUrl, type PrivacyNotice } from '@kronoqr/web-kit/branding'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps<{ notice: PrivacyNotice }>()

const { t } = useI18n()

// Solo https y ASCII imprimible: la URL la escribe el cliente y acaba en un href.
const policyUrl = computed(() => safePolicyUrl(props.notice.policyUrl))

// Sin cifra (o con una que no es un entero >= 1, p. ej. una copia antigua): redaccion generica.
const years = computed(() => {
  const value = props.notice.retentionYears

  return typeof value === 'number' && Number.isInteger(value) && value >= 1 ? value : null
})
</script>

<template>
  <!-- Plegado: seis puntos seguidos empujan el formulario fuera de la pantalla en
       un movil. El resumen es un control de 48 px y el contenido sigue en el DOM. -->
  <section
    data-test="privacy-notice"
    aria-labelledby="privacy-heading"
    class="mt-6 border-t border-kq-border text-base text-kq-text-muted"
  >
    <details>
      <summary
        class="flex min-h-12 cursor-pointer items-center py-3 font-heading text-lg font-semibold text-kq-text"
      >
        <h2 id="privacy-heading" class="font-heading text-lg font-semibold">
          {{ t('privacy.heading') }}
        </h2>
      </summary>
      <ul class="mt-2 list-disc space-y-1 pl-5">
        <li>
          {{
            notice.controllerName === null
              ? t('privacy.controllerUnknown')
              : t('privacy.controllerKnown', { controller: notice.controllerName })
          }}
        </li>
        <li>{{ t('privacy.purpose') }}</li>
        <li>{{ t('privacy.basis') }}</li>
        <li data-test="retention">
          {{
            years === null
              ? t('privacy.retention')
              : t('privacy.retentionYears', { count: years }, years)
          }}
        </li>
        <li>{{ t('privacy.rights') }}</li>
        <li>{{ t('privacy.complaint') }}</li>
        <li>{{ t('privacy.noBiometrics') }}</li>
      </ul>
      <p class="mt-2">
        <a
          v-if="policyUrl !== null"
          :href="policyUrl"
          target="_blank"
          rel="noopener noreferrer"
          class="inline-block min-h-12 py-3 font-medium text-kq-primary-strong underline"
        >
          {{ t('privacy.policyLink') }}
          <span class="sr-only"> {{ t('privacy.opensInNewTab') }}</span>
        </a>
        <template v-else>{{ t('privacy.policyPending') }}</template>
      </p>
    </details>
  </section>
</template>
