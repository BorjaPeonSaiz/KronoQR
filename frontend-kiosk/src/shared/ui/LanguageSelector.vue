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
// cruza las dos listas y nunca deja el selector vacio. Sin `policy` se usa la
// de la copia guardada de la marca (`readCachedLocalePolicy`), que sin copia es
// la del producto: solo espanol, y entonces el selector ni se pinta.
import { offeredLocales, resolveUiLocale, type LocalePolicy } from '@kronoqr/web-kit/branding'
import { computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { readCachedLocalePolicy } from '@/shared/branding/useBranding'
import type { AppLocale } from '@/shared/i18n'
import { isSupportedLocale, readStoredLocale, SUPPORTED_LOCALES, storeLocale } from '@/shared/i18n'

const props = defineProps<{ policy?: LocalePolicy }>()

const { locale, t } = useI18n()

const effectivePolicy = computed<LocalePolicy>(() => props.policy ?? readCachedLocalePolicy())

const options = computed<readonly AppLocale[]>(() =>
  offeredLocales(effectivePolicy.value, SUPPORTED_LOCALES),
)

function apply(next: AppLocale): void {
  locale.value = next
  if (typeof document !== 'undefined') document.documentElement.lang = next
}

function select(next: AppLocale): void {
  apply(next)
  storeLocale(next)
}

// La marca llega DESPUES del arranque y su politica de idiomas puede cambiar.
// Dos casos, ninguno guarda nada (no es una eleccion de nadie):
//   - el idioma activo ya no se ofrece: se recalcula (con uno solo, ese);
//   - la politica cambia y no hay eleccion guardada: se recalcula con el
//     navegador, como en el arranque.
// Una eleccion guardada que sigue ofrecida no se toca.
watch(
  options,
  (next, previous) => {
    const current = isSupportedLocale(locale.value) && next.includes(locale.value)
    const changed = previous !== undefined
    if (current && !(changed && readStoredLocale() === null)) return
    apply(
      resolveUiLocale<AppLocale>({
        policy: effectivePolicy.value,
        shipped: SUPPORTED_LOCALES,
        stored: readStoredLocale(),
        browser: typeof navigator === 'undefined' ? [] : navigator.languages,
      }),
    )
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
