<script setup lang="ts">
// Selector de idioma persistente (RF-KI-05).
//
// Dos botones y no un desplegable: un `select` nativo en Android abre una hoja
// modal que tapa la camara y exige dos toques precisos. Con guantes, eso son dos
// toques fallidos. Los botones cumplen los 48 px y se aciertan de un manotazo.
//
// FILTRADO POR LA MARCA (RF-PD-08, tarea 5.8). `policy` es la que trae
// `GET /api/v1/branding`: los idiomas que la INSTALACION ofrece, no los que
// esta version de la aplicacion trae traducidos. `offeredLocales` (web-kit)
// cruza las dos listas y nunca deja el selector vacio. Sin `policy` (las
// pantallas de emparejamiento y de PIN, que no llevan marca todavia) se
// ofrecen los dos de siempre: es el mismo comportamiento que habia antes de
// esta tarea.
import type { LocalePolicy } from '@kronoqr/web-kit/branding'
import { offeredLocales } from '@kronoqr/web-kit/branding'
import { computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { AppLocale } from '@/shared/i18n'
import { isSupportedLocale, SUPPORTED_LOCALES, storeLocale } from '@/shared/i18n'

const props = defineProps<{ policy?: LocalePolicy }>()

const { locale, t } = useI18n()

const options = computed<readonly AppLocale[]>(() =>
  props.policy === undefined ? SUPPORTED_LOCALES : offeredLocales(props.policy, SUPPORTED_LOCALES),
)

function select(next: AppLocale): void {
  locale.value = next
  storeLocale(next)
  if (typeof document !== 'undefined') document.documentElement.lang = next
}

// Si la instalacion deja de ofrecer el idioma activo (la marca llega DESPUES
// del arranque, cuando ya se habia elegido uno), se cambia solo: al
// predeterminado de la instalacion si esta entre los ofrecidos, si no al
// primero de la lista. Nunca se deja el selector marcando un idioma que ya no
// esta entre los botones.
watch(
  options,
  (next) => {
    if (isSupportedLocale(locale.value) && next.includes(locale.value)) return
    const fallback =
      props.policy !== undefined &&
      isSupportedLocale(props.policy.default) &&
      next.includes(props.policy.default)
        ? props.policy.default
        : next[0]
    if (fallback !== undefined) select(fallback)
  },
  { immediate: true },
)
</script>

<template>
  <!-- Con un solo idioma ofrecido no hay nada que elegir: un `role="group"`
       con un unico boton siempre pulsado no es un control, es un adorno (doc
       06 regla 8). El cambio automatico al idioma ofrecido sigue ocurriendo
       igual (el `watch` de arriba no depende de que esto se pinte). -->
  <div
    v-if="options.length > 1"
    class="flex items-center gap-2"
    role="group"
    :aria-label="t('language.label')"
  >
    <button
      v-for="option in options"
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
