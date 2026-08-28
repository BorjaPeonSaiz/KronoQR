<script setup lang="ts">
// Marco de la aplicacion autenticada: navegacion entre las dos pantallas
// propias, quien ha entrado, salida y la unica region viva del portal.
//
// Sin ambitos que repartir en la navegacion: las dos pantallas son de
// cualquier empleado con sesion (regla dura 18 — la autorizacion real la
// aplica el servidor en cada endpoint).
import { announcement } from '@kronoqr/web-kit/announcer'
import { useI18n } from 'vue-i18n'
import { RouterLink, RouterView, useRouter } from 'vue-router'
import { useSessionStore } from '@/features/login/session.store'

const { t } = useI18n()
const router = useRouter()
const session = useSessionStore()

/** Ver la nota de `session.store.ts`: solo olvida la sesion en este dispositivo. */
async function signOut(): Promise<void> {
  session.signOutLocally()
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

        <p class="font-heading text-lg font-bold text-kq-primary-strong">{{ t('app.title') }}</p>
        <nav :aria-label="t('app.nav.label')">
          <ul class="flex gap-2">
            <li>
              <RouterLink
                :to="{ name: 'my-records' }"
                class="inline-block min-h-12 rounded-kq-sm px-3 py-2 text-lg hover:bg-kq-surface-alt"
                active-class="bg-kq-primary-strong text-kq-on-primary hover:bg-kq-primary-strong"
              >
                {{ t('app.nav.myRecords') }}
              </RouterLink>
            </li>
            <li>
              <RouterLink
                :to="{ name: 'my-export' }"
                class="inline-block min-h-12 rounded-kq-sm px-3 py-2 text-lg hover:bg-kq-surface-alt"
                active-class="bg-kq-primary-strong text-kq-on-primary hover:bg-kq-primary-strong"
              >
                {{ t('app.nav.myExport') }}
              </RouterLink>
            </li>
          </ul>
        </nav>
        <div class="flex items-center gap-3">
          <p v-if="session.employee !== null" class="text-base text-kq-text-muted">
            {{ t('app.signedInAs', { name: session.employee.display_name }) }}
          </p>
          <button
            type="button"
            class="min-h-12 rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-lg text-kq-text hover:bg-kq-surface-alt"
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
