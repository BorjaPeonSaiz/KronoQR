<script setup lang="ts">
// Marco de la aplicacion autenticada: navegacion, quien ha entrado, salida y la
// unica region viva del panel.
//
// La navegacion se construye desde los AMBITOS del token, no desde el rol: lo
// que no se puede usar no se enseña. Es cortesia, no seguridad — la de verdad
// esta en la policy de cada endpoint (regla dura 18).
import { announcement } from '@kronoqr/web-kit/announcer'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, RouterView, useRouter } from 'vue-router'
import { CREDENTIALS_MANAGE, EMPLOYEES_MANAGE, REPORTS_LEGAL } from '@/features/auth/abilities'
import { useSessionStore } from '@/features/auth/session.store'

const { t } = useI18n()
const router = useRouter()
const session = useSessionStore()

interface NavItem {
  name: string
  label: string
  ability: string
}

const navigation = computed<NavItem[]>(() =>
  [
    { name: 'employees', label: t('app.nav.employees'), ability: EMPLOYEES_MANAGE },
    { name: 'credentials', label: t('app.nav.credentials'), ability: CREDENTIALS_MANAGE },
    { name: 'legal-export', label: t('app.nav.legalExport'), ability: REPORTS_LEGAL },
  ].filter((item) => session.can(item.ability)),
)

const roleLabels = computed(() => session.roles.map((role) => t(`app.roles.${role}`)).join(', '))

async function signOut(): Promise<void> {
  await session.logOut()
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
      <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 p-4">
        <p class="text-lg font-semibold">{{ t('app.title') }}</p>
        <nav :aria-label="t('app.nav.label')">
          <ul class="flex gap-2">
            <li v-for="item of navigation" :key="item.name">
              <RouterLink
                :to="{ name: item.name }"
                class="rounded px-3 py-2 hover:bg-slate-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900"
                active-class="bg-slate-900 text-white hover:bg-slate-800"
              >
                {{ item.label }}
              </RouterLink>
            </li>
          </ul>
        </nav>
        <div class="flex items-center gap-3">
          <p class="text-sm text-slate-700">
            {{ t('app.signedInAs', { name: session.displayName, roles: roleLabels }) }}
          </p>
          <button
            type="button"
            class="rounded border border-slate-400 px-3 py-2 hover:bg-slate-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900"
            @click="signOut"
          >
            {{ t('app.signOut') }}
          </button>
        </div>
      </div>
    </header>

    <!-- Region viva unica del panel: aqui se anuncia todo lo que cambia sin
         mover el foco (WCAG 2.2 AA, 4.1.3). -->
    <p role="status" aria-live="polite" class="sr-only">{{ announcement }}</p>

    <main id="main" class="mx-auto max-w-6xl p-4">
      <RouterView />
    </main>
  </div>
</template>
