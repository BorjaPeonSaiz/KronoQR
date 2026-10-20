<script setup lang="ts">
// Selector de idioma (D22, PO7-05, RF-PD-08).
//
// SOLO con dos o mas idiomas ofrecidos por la instalacion, como en el quiosco y
// el panel: `offeredLocales` (web-kit) cruza los idiomas de la instalacion con
// los que el portal trae traducidos. Con uno solo, un grupo con un boton siempre
// pulsado no es un control, es un adorno, y no se pinta.
//
// Botones y no un desplegable: se aciertan con el dedo (48 px) y se leen todos
// de un vistazo. El idioma elegido manda sobre el de la persona y el del
// navegador (`resolveUiLocale`, `stored`).
import { offeredLocales } from '@kronoqr/web-kit/branding'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useBrandingStore } from '@/shared/branding/branding.store'
import { SUPPORTED_LOCALES } from '@/shared/i18n'
import type { AppLocale } from '@/shared/i18n'
import { useLocaleChoiceStore } from '@/shared/i18n/locale.store'

const { locale, t } = useI18n()
const branding = useBrandingStore()
const choice = useLocaleChoiceStore()

const options = computed<readonly AppLocale[]>(() =>
  offeredLocales(branding.current.locales, SUPPORTED_LOCALES),
)
</script>

<template>
  <div
    v-if="options.length > 1"
    data-test="language-selector"
    class="flex items-center gap-2"
    role="group"
    :aria-label="t('language.label')"
  >
    <button
      v-for="option of options"
      :key="option"
      type="button"
      :lang="option"
      :aria-pressed="locale === option"
      class="min-h-12 min-w-12 rounded-kq-sm px-3 py-2 text-lg font-semibold"
      :class="
        locale === option
          ? 'bg-kq-primary-strong text-kq-on-primary'
          : 'border border-kq-border-strong bg-kq-surface-raised text-kq-text hover:bg-kq-surface-alt'
      "
      @click="choice.choose(option)"
    >
      {{ t(`language.${option}`) }}
    </button>
  </div>
</template>
