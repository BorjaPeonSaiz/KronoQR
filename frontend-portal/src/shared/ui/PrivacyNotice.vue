<script setup lang="ts">
// Aviso de proteccion de datos del portal (D8, V3-PO-4, art. 13 RGPD).
//
// Va en la pantalla de acceso, donde la persona teclea su codigo y su PIN. El
// contenido viene de la marca de la instalacion (`privacy_notice` de
// `GET /api/v1/branding`, RF-PD-08): responsable y politica completa los pone el
// cliente en el panel, no el codigo (regla dura 13). Con los dos a `null`, la
// redaccion generica de los textos. Aqui la politica puede ser un enlace; la
// regla de «nunca enlace» es del quiosco, que acaba en una pantalla compartida.
import type { PrivacyNotice } from '@kronoqr/web-kit/branding'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps<{ notice: PrivacyNotice }>()

const { t } = useI18n()

// Solo `https`: la URL la escribe el cliente y acaba en un `href`.
const policyUrl = computed(() =>
  props.notice.policyUrl !== null && props.notice.policyUrl.startsWith('https://')
    ? props.notice.policyUrl
    : null,
)
</script>

<template>
  <section
    data-test="privacy-notice"
    aria-labelledby="privacy-heading"
    class="mt-6 border-t border-kq-border pt-4 text-base text-kq-text-muted"
  >
    <h2 id="privacy-heading" class="font-heading text-lg font-semibold text-kq-text">
      {{ t('privacy.heading') }}
    </h2>
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
      <li>{{ t('privacy.retention') }}</li>
      <li>{{ t('privacy.rights') }}</li>
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
  </section>
</template>
