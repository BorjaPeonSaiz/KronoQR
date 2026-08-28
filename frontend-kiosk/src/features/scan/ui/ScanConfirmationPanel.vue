<script setup lang="ts">
// Pantalla de confirmacion (RF-AT-05, RF-KI-06).
//
// Del doc 01 §11, literal:
//   «Buenos dias, Lucia — Entrada 07:02»
//   «Hasta luego, Lucia — Salida 11:02 · Hoy: 6 h 0 min»
//
// Reglas que gobiernan esta plantilla:
//
//   - Feedback DOBLE. El color no dice nada por si solo: cada desenlace lleva
//     ademas texto explicito y un simbolo. El sonido lo pone `useScanSound`.
//   - Tipografia >= 24 px en TODO lo que se lee de un vistazo. El nombre va a
//     56 px porque se lee desde la cola, a dos metros.
//   - Horas y minutos, NUNCA decimales.
//   - `aria-live="assertive"`: es un mensaje que interrumpe, no una novedad
//     que puede esperar.
//   - Un desenlace `pending` NO dice «Entrada» ni «Salida». Todavia no se sabe:
//     lo decide el agregado `WorkDay` en el servidor. Inventarlo aqui seria
//     escribir un registro legal a ojo.
//   - `verifying` (solo PIN, RF-AT-11) es un tono NEUTRO, no una de las cinco
//     confirmaciones sagradas de `main.css`: usa los tokens del sistema
//     (`bg-kq-kiosk-surface-raised`, `text-kq-kiosk-text`), no un color de
//     desenlace. Nunca suena a exito: seria mentir mientras el servidor
//     todavia no ha contestado.
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatClockTime } from '../domain/clockTime'
import { greetingSlotFor } from '../domain/greeting'
import type { ConfirmationVariant, ScanConfirmation } from '../domain/scanOutcome'
import { isArrival, variantFor } from '../domain/scanOutcome'
import { splitWorkedMinutes } from '../domain/workedTime'

const props = defineProps<{ confirmation: ScanConfirmation }>()

const { t, locale } = useI18n()

const BACKGROUNDS: Readonly<Record<ConfirmationVariant, string>> = {
  entry: 'bg-kiosk-entry',
  exit: 'bg-kiosk-exit',
  pending: 'bg-kiosk-pending',
  // No es uno de los cinco colores de confirmacion (doc 06 §1.2): es el
  // panel destacado neutro del propio sistema visual del quiosco.
  verifying: 'bg-kq-kiosk-surface-raised',
  notice: 'bg-kiosk-notice',
  error: 'bg-kiosk-error',
}

/** El resto de variantes son fondos oscuros saturados con blanco encima
 *  (medido en `main.css`, > 9:1 los cinco). `verifying` usa el panel
 *  destacado del sistema visual, y ahi el texto de la guia (doc 06) es el
 *  token crema, no blanco puro. */
const TEXT_CLASSES: Readonly<Record<ConfirmationVariant, string>> = {
  entry: 'text-white',
  exit: 'text-white',
  pending: 'text-white',
  verifying: 'text-kq-kiosk-text',
  notice: 'text-white',
  error: 'text-white',
}

/**
 * Simbolo redundante con el color, para quien no lo distingue. `notice`
 * (anti-rebote, «ya has fichado») no lleva ninguno: no es un aviso que
 * necesite un glifo de 5 rem gritando encima del titular, y menos uno que se
 * parezca a una exclamacion de alarma para algo que no es un error.
 */
const SYMBOLS: Readonly<Record<ConfirmationVariant, string>> = {
  entry: '→',
  exit: '←',
  pending: '⋯',
  verifying: '↻',
  notice: '',
  error: '✕',
}

const variant = computed<ConfirmationVariant>(() => variantFor(props.confirmation))

const time = computed(() => formatClockTime(props.confirmation.occurredAt, locale.value))

function headlineFor(confirmation: ScanConfirmation): string {
  switch (confirmation.kind) {
    case 'accepted':
      return isArrival(confirmation.action)
        ? t(`scan.greeting.${greetingSlotFor(confirmation.occurredAt)}`, {
            name: confirmation.displayName,
          })
        : t('scan.greeting.farewell', { name: confirmation.displayName })
    case 'pending':
      return confirmation.displayName === null
        ? t('scan.pending.titleAnonymous')
        : t('scan.pending.titleNamed', { name: confirmation.displayName })
    case 'verifying':
      return t('scan.verifying.title')
    case 'debounced':
      return t('scan.debounced.title')
    case 'rejected':
    case 'unreadable':
      // Mensaje UNICO para toda causa de rechazo (regla dura 17, RS-03). Aqui no
      // se distingue «formato invalido» de «credencial revocada», ni siquiera
      // entre un rechazo local y uno del servidor.
      return t('scan.rejected.title')
  }
}

function detailFor(confirmation: ScanConfirmation, at: string): string {
  switch (confirmation.kind) {
    case 'accepted':
      return t(`scan.action.${confirmation.action}`, { time: at })
    case 'pending':
      return t('scan.pending.body', { time: at })
    case 'verifying':
      return t('scan.verifying.body')
    case 'debounced':
      return t('scan.debounced.body')
    case 'rejected':
    case 'unreadable':
      return t('scan.rejected.body')
  }
}

const headline = computed<string>(() => headlineFor(props.confirmation))

const detail = computed<string>(() => detailFor(props.confirmation, time.value))

const total = computed(() => {
  const confirmation = props.confirmation
  if (confirmation.kind !== 'accepted' && confirmation.kind !== 'debounced') return null

  const worked = splitWorkedMinutes(confirmation.workedMinutes)
  return t('scan.todayTotal', {
    duration: t('scan.duration', { hours: worked.hours, minutes: worked.minutes }),
  })
})
</script>

<template>
  <div
    class="flex h-full w-full flex-col items-center justify-center gap-6 px-10 text-center"
    :class="[BACKGROUNDS[variant], TEXT_CLASSES[variant]]"
    :data-variant="variant"
    :data-kind="props.confirmation.kind"
    data-testid="scan-confirmation"
    role="alert"
    aria-live="assertive"
    aria-atomic="true"
  >
    <span
      v-if="SYMBOLS[variant] !== ''"
      aria-hidden="true"
      class="text-[5rem] leading-none font-black"
    >
      {{ SYMBOLS[variant] }}
    </span>

    <p class="text-confirm-lg leading-tight font-bold" data-testid="confirmation-headline">
      {{ headline }}
    </p>

    <p class="text-confirm-md font-semibold" data-testid="confirmation-detail">
      {{ detail }}
    </p>

    <p v-if="total !== null" class="text-confirm-md font-medium" data-testid="confirmation-total">
      {{ total }}
    </p>

    <p
      v-if="props.confirmation.kind === 'pending'"
      class="text-confirm-sm rounded-full bg-white/15 px-6 py-2 font-medium"
      data-testid="confirmation-pending-badge"
    >
      {{ t('scan.pending.badge') }}
    </p>
  </div>
</template>
