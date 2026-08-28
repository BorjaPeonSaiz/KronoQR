<script setup lang="ts">
// Selector de idioma persistente (RF-KI-05).
//
// Dos botones y no un desplegable: un `select` nativo en Android abre una hoja
// modal que tapa la camara y exige dos toques precisos. Con guantes, eso son dos
// toques fallidos. Los botones cumplen los 48 px y se aciertan de un manotazo.
import { useI18n } from 'vue-i18n'
import type { AppLocale } from '@/shared/i18n'
import { SUPPORTED_LOCALES, storeLocale } from '@/shared/i18n'

const { locale, t } = useI18n()

function select(next: AppLocale): void {
  locale.value = next
  storeLocale(next)
  if (typeof document !== 'undefined') document.documentElement.lang = next
}
</script>

<template>
  <div class="flex items-center gap-2" role="group" :aria-label="t('language.label')">
    <button
      v-for="option in SUPPORTED_LOCALES"
      :key="option"
      type="button"
      class="kiosk-touch rounded-kq-sm px-4 text-lg font-semibold"
      :class="
        locale === option
          ? 'bg-kq-kiosk-primary-strong text-kq-kiosk-on-primary'
          : 'border border-kq-kiosk-border bg-kq-kiosk-surface-raised text-kq-kiosk-text'
      "
      :aria-pressed="locale === option"
      :lang="option"
      @click="select(option)"
    >
      {{ t(`language.${option}`) }}
    </button>
  </div>
</template>
