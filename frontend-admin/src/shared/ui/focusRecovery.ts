// El foco no se pierde cuando lo que lo tenia se retira.
//
// Convencion: lo que se retira devuelve el foco a su origen. Un boton que
// desaparece al confirmar -resolver una incidencia retira su fila; el aviso de
// «PDF ocupado» se cambia por otro- deja el foco en `<body>`, y el siguiente
// Tab empieza otra vez por el enlace de salto, ~30 paradas atras. `BaseDialog`
// ya devuelve el foco a quien lo abrio MIENTRAS ese elemento siga ahi; esto
// cubre el resto, para cualquier control y no solo para los dialogos.
//
// Como funciona: se recuerda el ultimo elemento enfocado y sus ancestros (hay que
// guardarlos mientras estan unidos al arbol: al retirar un nodo pierde el
// `parentElement`). Cuando el DOM cambia y el foco ha caido en `<body>` porque
// ese elemento ya no esta, el foco pasa al ancestro enfocable mas cercano que
// siga en el documento —un contenedor con desplazamiento (`tabindex="0"`), por
// ejemplo— o, a falta de el, al encabezado `<h1>` del contenido.

const FOCUSABLE_SELECTOR =
  'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'

export interface FocusRecoveryOptions {
  /** Donde cae el foco si no sobrevive ningun ancestro enfocable: el `<h1>` del contenido. */
  readonly fallback: () => HTMLElement | null
  /** Hay un cambio de pantalla en curso: lo recoge `screenChange`, no se foca dos veces. */
  readonly suspended?: () => boolean
  readonly document?: Document
}

const MODAL_SELECTOR = '[aria-modal="true"]'

function ancestorsOf(element: Element): Element[] {
  const chain: Element[] = []

  for (let node = element.parentElement; node !== null; node = node.parentElement) {
    chain.push(node)
  }

  return chain
}

/** Instala la recuperacion del foco. Devuelve la funcion que la retira. */
export function installFocusRecovery(options: FocusRecoveryOptions): () => void {
  const doc = options.document ?? globalThis.document
  let lastFocused: Element | null = null
  let lastAncestors: Element[] = []

  function forget(): void {
    lastFocused = null
    lastAncestors = []
  }

  // Mientras hay un dialogo modal abierto el foco vive dentro de el y no cuenta: el sitio al
  // que hay que volver es el de ANTES de abrirlo, asi que ni se recuerda lo de dentro ni se
  // olvida el origen cuando el foco sale hacia el dialogo.
  function remember(event: FocusEvent): void {
    const target = event.target

    if (doc.querySelector(MODAL_SELECTOR) !== null) {
      return
    }

    if (target instanceof Element && target !== doc.body) {
      lastFocused = target
      lastAncestors = ancestorsOf(target)
    }
  }

  // Si la persona mueve el foco por su cuenta y el elemento sigue ahi, no hay nada que
  // recuperar: se olvida, para no tirar de una referencia vieja mas tarde.
  function release(event: FocusEvent): void {
    if (doc.querySelector(MODAL_SELECTOR) !== null) {
      return
    }

    // Chromium dispara `focusout` al retirar el elemento enfocado y lo hace ANTES de
    // desunirlo: se mira un instante despues, cuando ya esta fuera o sigue dentro de verdad.
    const target = event.target

    queueMicrotask(() => {
      if (target === lastFocused && target instanceof Element && target.isConnected) {
        forget()
      }
    })
  }

  function recover(): void {
    const lost = lastFocused

    if (lost === null || options.suspended?.() === true) {
      return
    }

    // Con un dialogo abierto el foco es suyo; se recupera al cerrarse.
    if (doc.querySelector(MODAL_SELECTOR) !== null) {
      return
    }

    if (lost.isConnected) {
      // Sigue en el documento: no se ha perdido.
      return
    }

    // Solo si el foco se ha perdido de verdad: si otra cosa lo ha recogido, no se toca.
    if (doc.activeElement !== null && doc.activeElement !== doc.body) {
      forget()

      return
    }

    const survivor = lastAncestors.find(
      (ancestor) =>
        ancestor.isConnected &&
        ancestor instanceof HTMLElement &&
        ancestor.matches(FOCUSABLE_SELECTOR),
    )
    const target = survivor instanceof HTMLElement ? survivor : options.fallback()

    forget()

    if (target === null) {
      return
    }

    if (!target.matches(FOCUSABLE_SELECTOR)) {
      target.setAttribute('tabindex', '-1')
    }

    target.focus({ preventScroll: true })
  }

  const observer = new MutationObserver(recover)

  observer.observe(doc.body, { childList: true, subtree: true })
  doc.addEventListener('focusin', remember)
  doc.addEventListener('focusout', release)

  return () => {
    observer.disconnect()
    doc.removeEventListener('focusin', remember)
    doc.removeEventListener('focusout', release)
  }
}
