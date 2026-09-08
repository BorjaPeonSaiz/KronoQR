<script setup lang="ts">
// Marco de la aplicacion autenticada: navegacion, quien ha entrado, salida y la
// unica region viva del panel.
//
// La navegacion se construye desde los AMBITOS del token, no desde el rol: lo
// que no se puede usar no se enseña. Es cortesia, no seguridad — la de verdad
// esta en la policy de cada endpoint (regla dura 18).
import { announcement } from '@kronoqr/web-kit/announcer'
import BrandMark from '@kronoqr/web-kit/components/BrandMark.vue'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, RouterView, useRouter } from 'vue-router'
import {
  ATTENDANCE_READ,
  CREDENTIALS_MANAGE,
  DIAGNOSTICS_MANAGE,
  EMPLOYEES_MANAGE,
  INCIDENTS_MANAGE,
  LICENSE_MANAGE,
  REPORTS_LEGAL,
  REPORTS_MANAGE,
  SETTINGS_MANAGE,
  SUPPORT_MANAGE,
} from '@/features/auth/abilities'
import { useSessionStore } from '@/features/auth/session.store'
import LicenseNotice from '@/features/settings/LicenseNotice.vue'
import { useBrandingStore } from '@/shared/branding/branding.store'

const { t } = useI18n()
const router = useRouter()
const session = useSessionStore()
const branding = useBrandingStore()

interface NavItem {
  name: string
  label: string
  /** En O: basta con que la sesion lleve uno de los ambitos listados. */
  abilities: readonly string[]
}

const navigation = computed<NavItem[]>(() =>
  [
    { name: 'employees', label: t('app.nav.employees'), abilities: [EMPLOYEES_MANAGE] },
    { name: 'live', label: t('app.nav.live'), abilities: [ATTENDANCE_READ] },
    { name: 'incidents', label: t('app.nav.incidents'), abilities: [INCIDENTS_MANAGE] },
    { name: 'credentials', label: t('app.nav.credentials'), abilities: [CREDENTIALS_MANAGE] },
    { name: 'reports', label: t('app.nav.reports'), abilities: [REPORTS_MANAGE] },
    { name: 'legal-export', label: t('app.nav.legalExport'), abilities: [REPORTS_LEGAL] },
    {
      name: 'compliance-profile',
      label: t('app.nav.compliance'),
      abilities: [SETTINGS_MANAGE],
    },
    { name: 'devices', label: t('app.nav.devices'), abilities: [SETTINGS_MANAGE] },
    { name: 'branding', label: t('app.nav.branding'), abilities: [SETTINGS_MANAGE] },
    { name: 'license', label: t('app.nav.license'), abilities: [LICENSE_MANAGE] },
    {
      // Soporte (RF-PD-09, RF-PD-11, tarea 5.9): la alcanza quien lleva
      // `support:*` -para conceder y revocar accesos- **o** `diagnostics:*`
      // -un token de soporte con ese alcance tambien puede generar el
      // paquete anonimizado de su propia intervencion-. Ninguno de los dos lo
      // lleva un rol distinto de `admin` (doc 02 §7.3).
      name: 'support',
      label: t('app.nav.support'),
      abilities: [SUPPORT_MANAGE, DIAGNOSTICS_MANAGE],
    },
  ].filter((item) => item.abilities.some((ability) => session.can(ability))),
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
        <!-- Marca de la instalacion (RF-PD-08, tarea 5.8; ADR-036): logotipo
             o nombre, resueltos por `BrandMark` (compartido con el portal,
             `@kronoqr/web-kit`). «Panel de gestion» se queda como subtitulo,
             siempre visible: dice que aplicacion es esta dentro del
             producto, y la marca dice de que cliente. -->
        <div class="flex flex-col gap-0.5">
          <BrandMark :branding="branding.current" size="sm" />
          <p class="text-xs text-kq-text-muted">{{ t('app.title') }}</p>
        </div>
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

    <!-- El aviso PERSISTENTE de licencia (RF-PD-05, ADR-019, ADR-028).
         Debajo de la cabecera y encima de todo lo demas: se ve en cualquier
         pantalla y no se puede descartar mientras la condicion siga siendo
         cierta. Solo lo ve quien puede hacer algo con el. -->
    <LicenseNotice />

    <!-- Region viva unica del panel: aqui se anuncia todo lo que cambia sin
         mover el foco (WCAG 2.2 AA, 4.1.3). -->
    <p role="status" aria-live="polite" class="sr-only">{{ announcement }}</p>

    <main id="main" class="mx-auto max-w-6xl p-4">
      <RouterView />
    </main>
  </div>
</template>
