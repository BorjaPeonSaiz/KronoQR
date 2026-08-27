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
  <div class="min-h-dvh bg-slate-100 text-slate-900">
    <a
      href="#main"
      class="sr-only rounded bg-slate-900 px-3 py-2 text-white focus:not-sr-only focus:absolute focus:top-2 focus:left-2"
    >
      {{ t('app.skipToContent') }}
    </a>

    <header class="border-b border-slate-300 bg-white">
      <div class="mx-auto flex max-w-3xl flex-wrap items-center justify-between gap-4 p-4">
        <p class="text-lg font-semibold">{{ t('app.title') }}</p>
        <nav :aria-label="t('app.nav.label')">
          <ul class="flex gap-2">
            <li>
              <RouterLink
                :to="{ name: 'my-records' }"
                class="inline-block min-h-12 rounded px-3 py-2 text-lg hover:bg-slate-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900"
                active-class="bg-slate-900 text-white hover:bg-slate-800"
              >
                {{ t('app.nav.myRecords') }}
              </RouterLink>
            </li>
            <li>
              <RouterLink
                :to="{ name: 'my-export' }"
                class="inline-block min-h-12 rounded px-3 py-2 text-lg hover:bg-slate-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900"
                active-class="bg-slate-900 text-white hover:bg-slate-800"
              >
                {{ t('app.nav.myExport') }}
              </RouterLink>
            </li>
          </ul>
        </nav>
        <div class="flex items-center gap-3">
          <p v-if="session.employee !== null" class="text-base text-slate-700">
            {{ t('app.signedInAs', { name: session.employee.display_name }) }}
          </p>
          <button
            type="button"
            class="min-h-12 rounded border border-slate-400 px-3 py-2 text-lg hover:bg-slate-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900"
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

    <main id="main" class="mx-auto max-w-3xl p-4">
      <RouterView />
    </main>
  </div>
</template>
