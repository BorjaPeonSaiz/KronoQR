<script setup lang="ts">
// Aviso de privacidad en capa 1 (RF-KI-09, RL-09, art. 13 RGPD).
//
// NO ES DECORATIVO: es un requisito legal, y por eso esta SIEMPRE en pantalla y
// no detras de un boton. Lo que va detras de un boton es la capa 2 —la politica
// completa— a la que se llega por enlace y por QR.
//
// Lo que dice la capa 1: quien es el responsable, para que trata los datos, con
// que base juridica, cuanto los conserva y como ejercer los derechos. Y una
// linea que no exige el articulo 13 pero si tranquiliza a quien pone la mano
// delante de una camara: aqui no hay biometria (ADR-009, regla dura 20).
//
// El responsable y la URL son CONFIGURACION (regla dura 13): cambian con cada
// cliente y vienen de `GET /api/v1/branding` (`privacy_notice`), que el quiosco
// ya guarda en `localStorage` y por tanto sigue enseñando sin red. Si nunca los
// recibio, el aviso sigue apareciendo con una redaccion generica. `parseBranding`
// ya ha descartado cualquier URL que no sea http(s); aqui se vuelve a comprobar
// antes de pintar un `href`: es lo ultimo que se interpone entre una copia
// manipulada y un enlace de `javascript:`.
import type { PrivacyNotice } from '@kronoqr/web-kit/branding'
import type { QrPath } from '@kronoqr/web-kit/qr/renderQrPath'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps<{ notice: PrivacyNotice }>()

/**
 * Solo https y ASCII imprimible (F15 del dictamen de seguridad): defensa en
 * profundidad sobre `parseBranding`, que ya lo exige.
 */
const policyUrl = computed<string | null>(() => {
  const url = props.notice.policyUrl
  return url !== null && /^https:\/\/[!-~]+$/.test(url) ? url : null
})

/** El host que se enseña bajo el QR: lo unico que una persona puede comprobar de un vistazo. */
const policyHost = computed<string>(() => {
  if (policyUrl.value === null) return ''
  try {
    return new URL(policyUrl.value).host
  } catch {
    return ''
  }
})

// El componente pinta dos raices —el aviso y, cuando toca, el dialogo del QR—,
// asi que las clases del padre se aplican a mano sobre el aviso.
defineOptions({ inheritAttrs: false })

const { t } = useI18n()

const dialogOpen = ref(false)
const qr = ref<QrPath | null>(null)
const qrFailed = ref(false)

async function openDialog(): Promise<void> {
  dialogOpen.value = true
  if (qr.value !== null || policyUrl.value === null) return

  const { renderQrPath } = await import('@kronoqr/web-kit/qr/renderQrPath')
  const rendered = await renderQrPath(policyUrl.value)
  qr.value = rendered
  qrFailed.value = rendered === null
}

function closeDialog(): void {
  dialogOpen.value = false
}

// Si cambia la configuracion (recarga de marca en 5.8), el QR se regenera.
watch(policyUrl, () => {
  qr.value = null
  qrFailed.value = false
})
</script>

<template>
  <section
    v-bind="$attrs"
    class="rounded-kq-sm bg-kq-kiosk-surface-raised px-4 py-2 text-kq-kiosk-text-muted"
    :aria-label="t('privacy.heading')"
    data-testid="privacy-notice"
  >
    <!-- Capa 1 del art. 13 RGPD en un unico parrafo: menos altura, ningun
         contenido escondido. El encabezado abre la frase, no ocupa su propia
         linea. -->
    <p class="text-sm leading-snug">
      <span class="font-semibold text-kq-kiosk-text">{{ t('privacy.heading') }}:</span>
      {{
        props.notice.controllerName === null
          ? t('privacy.controllerUnknown')
          : t('privacy.controllerKnown', { controller: props.notice.controllerName })
      }}
      {{ t('privacy.purpose') }} {{ t('privacy.basis') }} {{ t('privacy.retention') }}
      {{ t('privacy.rights') }} {{ t('privacy.noBiometrics') }}
    </p>

    <!-- `kiosk-touch` fija el minimo de 48 px en el CONTROL, no en el texto:
         la fila se compacta pero el objetivo tactil no baja de tamano. -->
    <div class="mt-2 flex flex-wrap items-center gap-2">
      <p v-if="policyUrl === null" class="text-sm">
        {{ t('privacy.policyPending') }}
      </p>
      <template v-else>
        <!-- SIN ENLACE NAVEGABLE (F14 del dictamen de seguridad): la tablet es
             compartida y tocar un aviso legal no puede sacar al empleado del
             quiosco. La direccion va como TEXTO y como QR (RL-09 admite «enlace
             o QR»): quien la quiera la lee en su movil. -->
        <p class="min-w-0 break-all text-sm" data-testid="privacy-policy-url">
          {{ t('privacy.policyLink', { url: policyUrl }) }}
        </p>
        <button
          type="button"
          class="kiosk-touch rounded-kq-sm border border-kq-kiosk-border px-3 text-sm font-medium text-kq-kiosk-text"
          @click="openDialog"
        >
          {{ t('privacy.showQr') }}
        </button>
      </template>
    </div>
  </section>

  <div
    v-if="dialogOpen"
    class="fixed inset-0 z-50 flex items-center justify-center bg-kq-kiosk-surface/90 p-6"
    role="dialog"
    aria-modal="true"
    :aria-label="t('privacy.qrDialogTitle')"
  >
    <div class="max-w-xl rounded-kq bg-kq-surface-raised p-8 text-kq-text">
      <h2 class="font-heading text-2xl font-bold">{{ t('privacy.qrDialogTitle') }}</h2>
      <p class="mt-2 text-lg">{{ t('privacy.qrDialogBody') }}</p>

      <svg
        v-if="qr !== null"
        class="mx-auto mt-6 h-64 w-64 bg-white"
        :viewBox="`0 0 ${qr.size} ${qr.size}`"
        role="img"
        :aria-label="t('privacy.qrDialogTitle')"
        shape-rendering="crispEdges"
      >
        <path :d="qr.path" fill="#000000" />
      </svg>
      <p v-else-if="qrFailed" class="mt-6 text-lg">{{ t('privacy.qrUnavailable') }}</p>

      <p class="mt-4 text-base font-semibold" data-testid="privacy-qr-host">
        {{ t('privacy.qrHost', { host: policyHost }) }}
      </p>
      <p class="mt-1 break-all text-base">{{ policyUrl }}</p>

      <button
        type="button"
        class="kiosk-touch mt-6 w-full rounded-kq-sm bg-kq-primary-strong px-6 text-lg font-semibold text-kq-on-primary"
        @click="closeDialog"
      >
        {{ t('privacy.close') }}
      </button>
    </div>
  </div>
</template>
