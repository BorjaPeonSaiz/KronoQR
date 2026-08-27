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
// cliente. Si faltan, el aviso sigue apareciendo con una redaccion generica.
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { PrivacyNoticeConfig } from '@/shared/config/privacy'
import type { QrPath } from '@/shared/qr/renderQrPath'

const props = defineProps<{ config: PrivacyNoticeConfig }>()

// El componente pinta dos raices —el aviso y, cuando toca, el dialogo del QR—,
// asi que las clases del padre se aplican a mano sobre el aviso.
defineOptions({ inheritAttrs: false })

const { t } = useI18n()

const dialogOpen = ref(false)
const qr = ref<QrPath | null>(null)
const qrFailed = ref(false)

async function openDialog(): Promise<void> {
  dialogOpen.value = true
  if (qr.value !== null || props.config.policyUrl === null) return

  const { renderQrPath } = await import('@/shared/qr/renderQrPath')
  const rendered = await renderQrPath(props.config.policyUrl)
  qr.value = rendered
  qrFailed.value = rendered === null
}

function closeDialog(): void {
  dialogOpen.value = false
}

// Si cambia la configuracion (recarga de marca en 5.8), el QR se regenera.
watch(
  () => props.config.policyUrl,
  () => {
    qr.value = null
    qrFailed.value = false
  },
)
</script>

<template>
  <section
    v-bind="$attrs"
    class="rounded-lg bg-slate-800 px-5 py-4 text-slate-200"
    :aria-label="t('privacy.heading')"
    data-testid="privacy-notice"
  >
    <h2 class="text-base font-semibold text-slate-100">{{ t('privacy.heading') }}</h2>
    <p class="mt-1 text-base leading-relaxed">
      {{
        props.config.controller === null
          ? t('privacy.controllerUnknown')
          : t('privacy.controllerKnown', { controller: props.config.controller })
      }}
      {{ t('privacy.purpose') }} {{ t('privacy.basis') }} {{ t('privacy.retention') }}
    </p>
    <p class="mt-1 text-base leading-relaxed">
      {{ t('privacy.rights') }} {{ t('privacy.noBiometrics') }}
    </p>

    <div class="mt-3 flex flex-wrap items-center gap-3">
      <p v-if="props.config.policyUrl === null" class="text-base">
        {{ t('privacy.policyPending') }}
      </p>
      <template v-else>
        <a
          class="kiosk-touch inline-flex items-center rounded-lg bg-slate-700 px-4 text-base font-medium text-slate-50 underline"
          :href="props.config.policyUrl"
          rel="noopener noreferrer"
        >
          {{ t('privacy.policyLink', { url: props.config.policyUrl }) }}
        </a>
        <button
          type="button"
          class="kiosk-touch rounded-lg bg-slate-700 px-4 text-base font-medium text-slate-50"
          @click="openDialog"
        >
          {{ t('privacy.showQr') }}
        </button>
      </template>
    </div>
  </section>

  <div
    v-if="dialogOpen"
    class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/90 p-6"
    role="dialog"
    aria-modal="true"
    :aria-label="t('privacy.qrDialogTitle')"
  >
    <div class="max-w-xl rounded-2xl bg-slate-100 p-8 text-slate-900">
      <h2 class="text-2xl font-bold">{{ t('privacy.qrDialogTitle') }}</h2>
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

      <p class="mt-4 break-all text-base">{{ props.config.policyUrl }}</p>

      <button
        type="button"
        class="kiosk-touch mt-6 w-full rounded-lg bg-slate-900 px-6 text-lg font-semibold text-slate-50"
        @click="closeDialog"
      >
        {{ t('privacy.close') }}
      </button>
    </div>
  </div>
</template>
