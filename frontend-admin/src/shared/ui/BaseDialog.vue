<script setup lang="ts">
// Dialogo modal accesible (WCAG 2.2 AA).
//
// Lo que hace y por que:
//  - `role="dialog"` + `aria-modal` + `aria-labelledby`: un lector de pantalla
//    anuncia de que va la ventana al abrirla.
//  - Atrapa el foco y lo devuelve al elemento que la abrio al cerrarse. Sin esto
//    quien navega con teclado se queda tabulando por detras del velo. La trampa
//    vale tambien cuando el foco esta en el propio panel (tras un clic en su
//    texto) y si algo lo lleva fuera (`focusin` en el documento): V7-PA-1.
//  - Si al cerrarse el elemento de origen ya no esta (la fila se ha retirado), el
//    foco no se pierde: lo recoge `focusRecovery` del marco (V7-PA-4).
//  - `dismissible: false` para los dialogos que NO se pueden cerrar sin una
//    accion explicita — el del PIN, que solo se enseña una vez.
//
// No usa `<Teleport>` a proposito: el panel no tiene ningun ancestro con
// `transform`, asi que `position: fixed` basta, y mantenerlo en el arbol del
// componente hace que la prueba unitaria vea lo mismo que ve el navegador.
import { onBeforeUnmount, onMounted, ref, useId } from 'vue'

const props = withDefaults(
  defineProps<{
    title: string
    /** Si se puede cerrar con Escape, con el velo o con el aspa. */
    dismissible?: boolean
    /** Anchura del panel. `wide` para las tablas de confirmacion largas. */
    size?: 'normal' | 'wide'
  }>(),
  { dismissible: true, size: 'normal' },
)

const emit = defineEmits<{ close: [] }>()

const titleId = useId()
const root = ref<HTMLElement | null>(null)
const panel = ref<HTMLElement | null>(null)
let previouslyFocused: HTMLElement | null = null
/** Hacia donde iba el ultimo Tab: si el foco se escapa, vuelve por el extremo contrario. */
let lastDirection: 'forward' | 'back' = 'forward'

/**
 * Controles de fecha y hora: son un grupo de segmentos internos (dia, mes, hora...) y
 * `document.activeElement` es el input entero. Tab y Shift+Tab mueven el foco ENTRE sus
 * segmentos; solo salen del input desde el primero o el ultimo, y eso no se ve desde fuera.
 */
const SEGMENTED_INPUT_TYPES = new Set(['date', 'time', 'datetime-local', 'month', 'week'])

const FOCUSABLE =
  'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'

function focusableElements(): HTMLElement[] {
  return Array.from(panel.value?.querySelectorAll<HTMLElement>(FOCUSABLE) ?? [])
}

function requestClose(): void {
  if (props.dismissible) {
    emit('close')
  }
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    event.stopPropagation()
    requestClose()

    return
  }

  if (event.key !== 'Tab') {
    return
  }

  const elements = focusableElements()
  const first = elements[0]
  const last = elements[elements.length - 1]

  if (first === undefined || last === undefined) {
    // Sin controles el foco se queda en el panel: no hay hacia donde tabular.
    event.preventDefault()
    panel.value?.focus()

    return
  }

  const active = document.activeElement

  lastDirection = event.shiftKey ? 'back' : 'forward'

  // En un control segmentado se deja al navegador mover el foco entre sus segmentos. Si
  // sale del dialogo, `keepFocusInside` lo devuelve por el extremo contrario.
  if (active instanceof HTMLInputElement && SEGMENTED_INPUT_TYPES.has(active.type)) {
    return
  }

  // El foco puede estar en el propio panel (`tabindex="-1"`, tras un clic en el texto)
  // o fuera: ninguno de los dos es un extremo y el navegador sacaria al usuario
  // del dialogo.
  const inControl = active instanceof HTMLElement && elements.includes(active)

  if (event.shiftKey && (active === first || !inControl)) {
    event.preventDefault()
    last.focus()

    return
  }

  if (!event.shiftKey && (active === last || !inControl)) {
    event.preventDefault()
    first.focus()
  }
}

// Si algo manda el foco fuera del dialogo (un script, un lector de pantalla), vuelve
// dentro. El fondo no es `inert`: el marco de la aplicacion no es ancestro del dialogo.
function keepFocusInside(event: FocusEvent): void {
  const target = event.target

  if (!(target instanceof Node) || root.value === null || root.value.contains(target)) {
    return
  }

  // Otro dialogo modal apilado encima tiene el mando.
  if (target instanceof Element && target.closest('[aria-modal="true"]') !== null) {
    return
  }

  const elements = focusableElements()

  ;(
    (lastDirection === 'back' ? elements[elements.length - 1] : elements[0]) ?? panel.value
  )?.focus()
}

onMounted(() => {
  document.addEventListener('focusin', keepFocusInside)
  previouslyFocused = document.activeElement instanceof HTMLElement ? document.activeElement : null
  const elements = focusableElements()
  ;(elements[0] ?? panel.value)?.focus()
})

onBeforeUnmount(() => {
  document.removeEventListener('focusin', keepFocusInside)

  // Solo si el origen sigue en el documento: si se ha retirado, `focus()` no hace
  // nada y el foco caeria en `<body>`; en ese caso actua `focusRecovery`.
  if (previouslyFocused?.isConnected === true) {
    previouslyFocused.focus()
  }
})
</script>

<template>
  <div
    ref="root"
    class="fixed inset-0 z-50 flex items-center justify-center p-4"
    @keydown="onKeydown"
  >
    <div class="absolute inset-0 bg-kq-text/60" aria-hidden="true" @click="requestClose" />
    <div
      ref="panel"
      role="dialog"
      aria-modal="true"
      :aria-labelledby="titleId"
      tabindex="-1"
      data-test="dialog-panel"
      class="relative max-h-full w-full overflow-y-auto rounded-kq bg-kq-surface-raised p-6 text-kq-text shadow-kq-raised"
      :class="size === 'wide' ? 'max-w-3xl' : 'max-w-xl'"
    >
      <h2 :id="titleId" class="text-xl font-semibold">{{ title }}</h2>
      <div class="mt-4">
        <slot />
      </div>
      <div class="mt-6 flex flex-wrap justify-end gap-3">
        <slot name="actions" />
      </div>
    </div>
  </div>
</template>
