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
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
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
const route = useRoute()
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
    {
      // Umbrales operativos e idiomas (RF-PD-01, tarea 5.13). Mismo ambito
      // que «Cumplimiento», «Quioscos» y «Marca»: las cuatro son la misma
      // potestad de administrador de instalacion.
      name: 'operational-settings',
      label: t('app.nav.operationalSettings'),
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
    {
      // Historico de errores agrupado por huella (RF-PD-15, tarea 5.12): el
      // mismo ambito que la generacion del paquete de diagnostico.
      name: 'errors',
      label: t('app.nav.errors'),
      abilities: [DIAGNOSTICS_MANAGE],
    },
  ].filter((item) => item.abilities.some((ability) => session.can(ability))),
)

/**
 * Seccion activa: la propia ruta, o la seccion que la ruta declara en
 * `meta.section` cuando no tiene entrada de menu (la ficha de un empleado).
 */
function isActive(name: string): boolean {
  return route.name === name || route.meta.section === name
}

const roleLabels = computed(() => session.roles.map((role) => t(`app.roles.${role}`)).join(', '))

async function signOut(): Promise<void> {
  await session.logOut()
  await router.push({ name: 'login' })
}
</script>

<template>
  <!-- Marco en dos columnas a partir de `md`: la cabecera es una columna
       lateral fija a la izquierda y el contenido ocupa el resto. Por debajo
       de `md` las dos columnas se apilan y las secciones vuelven a una fila
       que envuelve, sin menu plegable: cada enlace que la sesion alcanza
       esta siempre en el DOM y visible (las pruebas E2E cuentan enlaces,
       no los buscan tras un boton).

       Sigue siendo un `<header>` de primer nivel, y por tanto el landmark
       `banner`, aunque se pinte en vertical: la marca (RF-PD-08, tarea 5.8)
       «va en la cabecera» y las pruebas la localizan ahi. -->
  <div class="min-h-dvh bg-kq-surface text-kq-text md:grid md:grid-cols-[16rem_minmax(0,1fr)]">
    <a
      href="#main"
      class="sr-only rounded-kq-sm bg-kq-primary-strong px-3 py-2 text-kq-on-primary focus:not-sr-only focus:absolute focus:top-2 focus:left-2"
    >
      {{ t('app.skipToContent') }}
    </a>

    <!-- La celda lleva el fondo y el borde para que la columna se pinte
         entera aunque la pagina sea mas alta que la ventana; lo pegajoso es
         solo la cabecera de dentro. Un `<header>` dentro de un `<div>` sigue
         siendo `banner`: solo lo pierde bajo contenido seccionador. -->
    <div class="border-b border-kq-border bg-kq-surface-raised md:border-r md:border-b-0">
      <header class="flex flex-col gap-4 p-4 md:sticky md:top-0 md:h-dvh md:overflow-y-auto">
        <!-- Marca de la instalacion (RF-PD-08, tarea 5.8; ADR-036): logotipo
           o nombre, resueltos por `BrandMark` (compartido con el portal,
           `@kronoqr/web-kit`). «Panel de gestion» se queda como subtitulo,
           siempre visible: dice que aplicacion es esta dentro del
           producto, y la marca dice de que cliente. -->
        <div class="flex min-w-0 flex-col gap-0.5">
          <BrandMark :branding="branding.current" size="sm" />
          <p class="text-xs text-kq-text-muted">{{ t('app.title') }}</p>
        </div>

        <nav :aria-label="t('app.nav.label')" class="md:flex-1">
          <!-- Hueco de 6 px entre secciones: el anillo de foco (3 px, a 2 px
               de la caja) tiene que caber sin tocar a la vecina, porque
               `--kq-color-focus` es el mismo tono que el fondo de la activa
               y sobre ella desapareceria (revision de UI/UX, WCAG 2.4.11).

               El activo se calcula aqui y no con `active-class`: la ficha de
               un empleado no es hija de «Plantilla» en el router y RouterLink
               no la reconoceria; y las utilidades de hover del enlace y de la
               clase activa competian entre si (fondo crema con texto blanco
               al pasar el raton por la seccion activa). -->
          <ul class="flex flex-wrap gap-2 md:flex-col md:gap-1.5">
            <li v-for="item of navigation" :key="item.name">
              <RouterLink
                :to="{ name: item.name }"
                class="block rounded-kq-sm px-3 py-1.5"
                :class="
                  isActive(item.name)
                    ? 'bg-kq-primary-strong text-kq-on-primary hover:brightness-95'
                    : 'hover:bg-kq-surface-alt'
                "
                :aria-current="isActive(item.name) ? 'page' : undefined"
              >
                {{ item.label }}
              </RouterLink>
            </li>
          </ul>
        </nav>

        <div
          class="flex flex-wrap items-center gap-3 md:flex-col md:items-stretch md:gap-2 md:border-t md:border-kq-border md:pt-3"
        >
          <p class="min-w-0 text-xs text-kq-text-muted">
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
      </header>
    </div>

    <div class="min-w-0">
      <!-- El aviso PERSISTENTE de licencia (RF-PD-05, ADR-019, ADR-028).
           Encima del contenido de cualquier pantalla y no se puede descartar
           mientras la condicion siga siendo cierta. Solo lo ve quien puede
           hacer algo con el. -->
      <LicenseNotice />

      <!-- Region viva unica del panel: aqui se anuncia todo lo que cambia sin
           mover el foco (WCAG 2.2 AA, 4.1.3). -->
      <p role="status" aria-live="polite" class="sr-only">{{ announcement }}</p>

      <main id="main" class="mx-auto max-w-6xl p-4">
        <RouterView />
      </main>
    </div>
  </div>
</template>
