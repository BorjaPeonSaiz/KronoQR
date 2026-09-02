<script setup lang="ts">
// El aviso PERSISTENTE de licencia, visible en todas las pantallas del panel
// (RF-PD-05, ADR-019, ADR-028; decision del responsable de producto del
// 01-09-2026).
//
// ## «Persistente» significa que no se descarta
//
// No hay boton de cerrar, y es deliberado. ADR-028 lo dice con estas palabras:
// *«persistente significa que no se descarta: desaparece cuando el exceso se
// corrige o la licencia se amplia»*. Un aviso descartable sobre una licencia que
// caduca en treinta dias se cierra el primer dia y ya nadie se entera de nada,
// que es justo el fallo que este aviso existe para evitar.
//
// ## Aparece 30 dias antes, no el mismo dia
//
// La antelacion la fija el servidor (`config/license.php`, 30 de serie) y viaja
// en `meta.expiry_warning_days`: el panel no lleva el numero compilado dentro
// (regla dura 13, ADR-017). Durante esos treinta dias **no se ha degradado
// nada**: la licencia esta vigente y el texto lo dice.
//
// ## Cambia de tono, no de sitio
//
// Al caducar pasa de aviso a critico y el texto cambia de «caduca el X; esto se
// degradara» a «caduco el X; esto esta degradado desde entonces». Mismo lugar y
// misma forma: quien ya lo ha visto no tiene que buscarlo otra vez.
//
// ## Nunca dice que el sistema este parado
//
// Porque no lo esta. Cada variante del texto termina recordando que se sigue
// fichando y que el registro sigue siendo accesible (regla dura 15). Es la
// diferencia entre un aviso que produce una renovacion y uno que produce una
// llamada de soporte a las siete de la mañana.
//
// ## Solo para quien puede hacer algo
//
// Se pinta con el ambito `settings`/`license` del token, que es el del
// administrador de instalacion. A un responsable de departamento un aviso de
// licencia solo le da ruido: no puede renovar nada.
import { computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { LICENSE_MANAGE } from '@/features/auth/abilities'
import { useSessionStore } from '@/features/auth/session.store'
import { formatLicenseDay } from './license.dates'
import { useLicenseStore } from './license.store'

const { t, locale } = useI18n()
const session = useSessionStore()
const store = useLicenseStore()

const canManage = computed<boolean>(() => session.can(LICENSE_MANAGE))

const visible = computed<boolean>(() => canManage.value && store.needsNotice)

const data = computed(() => store.license?.data ?? null)

const exceeded = computed(() =>
  store.exceededLimits.map((limit) => t(`license.limits.${limit.limit}`)).join(', '),
)

function formatDay(instant: string | null | undefined): string {
  return formatLicenseDay(instant, locale.value)
}

onMounted(() => {
  if (canManage.value) {
    // Forzado, y no memoizado: este componente vive dentro del marco
    // autenticado, asi que se monta **una vez por sesion** —al entrar y al
    // recargar la pagina— y no en cada navegacion. Sin forzar, un segundo acceso
    // en la misma pestaña heredaria el estado del anterior, que es como un
    // banner acaba anunciando una caducidad que ya se renovo.
    void store.load(true)
  }
})
</script>

<template>
  <div
    v-if="visible && data !== null"
    class="border-b p-3"
    :class="{
      'border-kq-warning bg-kq-warning-soft text-kq-warning': data.severity === 'warning',
      'border-kq-danger bg-kq-danger-soft text-kq-danger': data.severity === 'critical',
    }"
    role="status"
    data-test="license-notice"
    :data-state="data.state"
    :data-severity="data.severity"
  >
    <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3">
      <p>
        <span data-test="license-notice-text">
          {{
            t(`license.notice.${data.state}`, {
              until: formatDay(data.valid_until),
              from: formatDay(data.valid_from),
              days: data.days_until_expiry ?? 0,
              elapsed: data.days_since_expiry ?? 0,
            })
          }}
        </span>
        <span v-if="store.exceededLimits.length > 0" data-test="license-notice-excess">
          {{ t('license.notice.exceeded', { limits: exceeded }) }}
        </span>
      </p>

      <RouterLink
        :to="{ name: 'license' }"
        class="rounded-kq-sm border border-current px-3 py-1"
        data-test="license-notice-link"
      >
        {{ t('license.notice.action') }}
      </RouterLink>
    </div>
  </div>
</template>
