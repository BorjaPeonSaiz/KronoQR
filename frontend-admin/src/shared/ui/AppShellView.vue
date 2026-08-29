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
import {
  ATTENDANCE_READ,
  CREDENTIALS_MANAGE,
  EMPLOYEES_MANAGE,
  REPORTS_LEGAL,
} from '@/features/auth/abilities'
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
    { name: 'live', label: t('app.nav.live'), ability: ATTENDANCE_READ },
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
  <div class="min-h-dvh bg-kq-surface text-kq-text">
    <a
      href="#main"
      class="sr-only rounded-kq-sm bg-kq-primary-strong px-3 py-2 text-kq-on-primary focus:not-sr-only focus:absolute focus:top-2 focus:left-2"
    >
      {{ t('app.skipToContent') }}
    </a>

    <header class="border-b border-kq-border bg-kq-surface-raised">
      <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 p-4">
        <p class="font-heading text-lg font-bold text-kq-primary-strong">{{ t('app.title') }}</p>
        <nav :aria-label="t('app.nav.label')">
          <ul class="flex gap-2">
            <li v-for="item of navigation" :key="item.name">
              <RouterLink
                :to="{ name: item.name }"
                class="rounded-kq-sm px-3 py-2 hover:bg-kq-surface-alt"
                active-class="bg-kq-primary-strong text-kq-on-primary hover:brightness-95"
              >
                {{ item.label }}
              </RouterLink>
            </li>
          </ul>
        </nav>
        <div class="flex items-center gap-3">
          <p class="text-sm text-kq-text-muted">
            {{ t('app.signedInAs', { name: session.displayName, roles: roleLabels }) }}
          </p>
          <button
            type="button"
            class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text hover:bg-kq-surface-alt"
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
