<script setup lang="ts">
// El reloj de pared de `ScanView`/`PairingView`, y la puerta de entrada a la
// pantalla de diagnostico (RF-KI-08, tarea 3.3, decision 8).
//
// UN `<button>` REAL, no un `<div>` con `@click`: alcanzable con teclado (una
// tablet con teclado USB de recepcion existe) y anunciado como boton por el
// lector de pantalla, con su propio `aria-label` -el texto visible es solo la
// hora, que no dice para que sirve pulsarlo-.
//
// PULSACION CORTA, NADA. Solo una pulsacion sostenida 3 s dispara
// `useLongPress`; un toque accidental al pasar el dedo no hace nada, y con la
// cola de gente de un cambio de turno eso importa.
//
// EL TECLADO TAMBIEN MANTIENE PULSADO. Un `<button>` nativo no emite
// `pointerdown`/`pointerup` al activarse con Enter o Espacio (WCAG 2.1.1):
// sin esto el boton se anunciaria como alcanzable con teclado sin serlo de
// verdad. `keydown` (ignorando la repeticion del sistema operativo mientras
// la tecla sigue fisicamente pulsada, `event.repeat`) hace de `onPointerDown`
// y `keyup` de `onPointerUp`; perder el foco a medio camino (`blur`) cancela
// igual que `pointercancel`.
//
// NUNCA BLOQUEA EL FICHAJE (regla dura 19): es un boton mas en la cabecera,
// igual que el selector de idioma o el indicador de conexion; no tapa la
// camara ni el visor, y no exige tocarlo para nada del flujo normal.
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { formatClockTime } from '@/features/scan/domain/clockTime'
import { useLongPress } from '../composables/useLongPress'

const { t, locale } = useI18n()
const router = useRouter()

const now = ref(new Date())
let ticker: ReturnType<typeof setInterval> | null = null

onMounted(() => {
  ticker = setInterval(() => {
    now.value = new Date()
  }, 1_000)
})

onBeforeUnmount(() => {
  if (ticker !== null) clearInterval(ticker)
})

const longPress = useLongPress({
  onLongPress: () => {
    void router.push({ name: 'diagnostics' })
  },
})

/**
 * Solo el primer `keydown` de una pulsacion cuenta como inicio: mientras la
 * tecla sigue fisicamente abajo, el sistema operativo repite `keydown` cada
 * pocos milisegundos, y cada repeticion reiniciaria la cuenta de
 * `useLongPress` (mismo criterio que un `onPointerDown` real, que solo llega
 * una vez por pulsacion).
 */
function onKeyDown(event: KeyboardEvent): void {
  if (event.repeat) return
  longPress.onPointerDown()
}
</script>

<template>
  <button
    type="button"
    class="kiosk-touch rounded-kq-sm px-3 text-lg font-medium text-kq-kiosk-text-muted"
    :aria-label="t('diagnostics.trigger.ariaLabel')"
    data-testid="diagnostics-trigger"
    @pointerdown="longPress.onPointerDown"
    @pointerup="longPress.onPointerUp"
    @pointercancel="longPress.onPointerCancel"
    @pointerleave="longPress.onPointerLeave"
    @keydown.enter="onKeyDown"
    @keydown.space.prevent="onKeyDown"
    @keyup.enter="longPress.onPointerUp"
    @keyup.space="longPress.onPointerUp"
    @blur="longPress.onPointerCancel"
  >
    {{ formatClockTime(now, locale) }}
  </button>
</template>
