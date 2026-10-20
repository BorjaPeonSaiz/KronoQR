<script setup lang="ts">
// Marco de la aplicacion autenticada: navegacion entre las dos pantallas
// propias, quien ha entrado, salida y la unica region viva del portal.
//
// Sin ambitos que repartir en la navegacion: las dos pantallas son de
// cualquier empleado con sesion (regla dura 18 — la autorizacion real la
// aplica el servidor en cada endpoint).
import { announcement } from '@kronoqr/web-kit/announcer'
import BrandMark from '@kronoqr/web-kit/components/BrandMark.vue'
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import { useSessionStore } from '@/features/login/session.store'
import { useBrandingStore } from '@/shared/branding/branding.store'
import LanguageSelector from '@/shared/ui/LanguageSelector.vue'

const { t } = useI18n()
const router = useRouter()
const route = useRoute()
const session = useSessionStore()
const branding = useBrandingStore()

/**
 * Estados del enlace de navegacion (V7-UX-01). Cada estado fija fondo Y texto a
 * la vez, para que ninguna utilidad de `hover:`/`active:` cambie el fondo y deje
 * el texto del estado anterior (blanco sobre crema, 1,14:1). Las parejas estan
 * medidas en `themePairs.ts` (`nav link …`). El activo se calcula aqui, no con
 * `active-class`, igual que en el panel, y no cambia al pasar o pulsar.
 */
const LINK_ACTIVE = 'bg-kq-primary-strong text-kq-on-primary'
const LINK_IDLE =
  'text-kq-text hover:bg-kq-surface-alt active:bg-kq-primary-soft active:text-kq-on-primary-soft'

function linkClass(name: string): string {
  return route.name === name ? LINK_ACTIVE : LINK_IDLE
}

/** Avisa al servidor y borra la sesion local siempre (ver `session.store.ts`, PO1). */
const signingOut = ref(false)

async function signOut(): Promise<void> {
  if (signingOut.value) {
    return
  }

  signingOut.value = true

  try {
    await session.signOut()
  } finally {
    signingOut.value = false
  }

  await router.push({ name: 'login' })
}
</script>

<template>
  <div class="min-h-dvh bg-kq-surface text-kq-text">
    <a
      href="#main"
      class="sr-only rounded-kq-sm bg-kq-primary-strong px-3 py-2 text-kq-on-primary focus:not-sr-only focus:absolute focus:top-2 focus:left-2"
    >
      {{ t('app.skipToContent') }}
    </a>

    <header class="border-b border-kq-border bg-kq-surface-raised">
      <div
        class="mx-auto flex max-w-3xl flex-wrap items-center justify-between gap-4 p-4 lg:w-[88%] lg:max-w-none 2xl:max-w-[1600px]"
      >
        <!--
          Por debajo de 1024 px se mantiene el `max-w-3xl` de siempre. A partir de
          `lg` la cabecera y el contenido comparten el mismo ancho -al menos el
          80% de la ventana- para que un monitor de escritorio no deje el
          registro horario apretado en la mitad izquierda de la pantalla. El
          tope en `2xl` evita lineas de texto interminables en monitores
          ultrapanoramicos sin bajar del 80% hasta los 2000 px de ancho.
        -->

        <div class="flex min-w-0 flex-col gap-0.5">
          <BrandMark :branding="branding.current" />
          <!-- El nombre del producto ("Mi portal del empleado") se queda como
               subtitulo: la marca (logotipo o nombre de la instalacion) es lo
               que identifica la aplicacion, esto dice para que sirve. -->
          <p class="hidden text-sm text-kq-text-muted sm:block">{{ t('app.title') }}</p>
        </div>
        <nav :aria-label="t('app.nav.label')">
          <ul class="flex flex-wrap gap-2">
            <li>
              <RouterLink
                :to="{ name: 'my-records' }"
                class="inline-block min-h-12 rounded-kq-sm px-3 py-2 text-lg"
                :class="linkClass('my-records')"
                :aria-current="route.name === 'my-records' ? 'page' : undefined"
              >
                {{ t('app.nav.myRecords') }}
              </RouterLink>
            </li>
            <li>
              <RouterLink
                :to="{ name: 'my-export' }"
                class="inline-block min-h-12 rounded-kq-sm px-3 py-2 text-lg"
                :class="linkClass('my-export')"
                :aria-current="route.name === 'my-export' ? 'page' : undefined"
              >
                {{ t('app.nav.myExport') }}
              </RouterLink>
            </li>
          </ul>
        </nav>
        <div class="flex flex-wrap items-center gap-3">
          <LanguageSelector />
          <p v-if="session.employee !== null" class="text-base text-kq-text-muted">
            {{ t('app.signedInAs', { name: session.employee.display_name }) }}
          </p>
          <button
            type="button"
            class="min-h-12 rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-lg text-kq-text hover:bg-kq-surface-alt"
            :aria-busy="signingOut"
            @click="signOut"
          >
            {{ t('app.signOut') }}
          </button>
        </div>
      </div>
    </header>

    <!-- Region viva unica del portal: aqui se anuncia todo lo que cambia sin
         mover el foco (WCAG 2.2 AA, 4.1.3). -->
    <p role="status" aria-live="polite" class="sr-only">{{ announcement }}</p>

    <main id="main" class="mx-auto max-w-3xl p-4 lg:w-[88%] lg:max-w-none 2xl:max-w-[1600px]">
      <RouterView />
    </main>
  </div>
</template>
