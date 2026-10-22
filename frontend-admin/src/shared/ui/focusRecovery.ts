// El foco no se pierde cuando lo que lo tenia se retira (V7-PA-4, V21-7-PA-1).
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
// ejemplo— o, a falta de el, al contenido principal.

const FOCUSABLE_SELECTOR =
  'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'

export interface FocusRecoveryOptions {
  /** Donde cae el foco si no sobrevive ningun ancestro enfocable. */
  readonly fallback: () => HTMLElement | null
  readonly document?: Document
}

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

  function remember(event: FocusEvent): void {
    const target = event.target

    // El foco que vive dentro de un dialogo modal no cuenta: el sitio al que hay que
    // volver es el de ANTES de abrirlo. Si ese origen se retira mientras el dialogo
    // esta abierto, al cerrarlo el foco aterriza en su contenedor y no en `<body>`.
    if (
      target instanceof Element &&
      target !== doc.body &&
      target.closest('[aria-modal="true"]') === null
    ) {
      lastFocused = target
      lastAncestors = ancestorsOf(target)
    }
  }

  function recover(): void {
    const lost = lastFocused

    // «Perdido» = ya no esta en el documento, o sigue pero lo han deshabilitado (el boton
    // «Generar» mientras genera): el navegador lo suelta y el foco cae en `<body>`.
    if (lost === null || (lost.isConnected && !lost.matches(':disabled'))) {
      return
    }

    // Solo si el foco se ha perdido de verdad: si otra cosa lo ha recogido, no se toca.
    if (doc.activeElement !== null && doc.activeElement !== doc.body) {
      return
    }

    // Un control deshabilitado no puede recibir el foco: se sube a sus ancestros.
    const survivor = lastAncestors.find(
      (ancestor) =>
        ancestor.isConnected &&
        ancestor instanceof HTMLElement &&
        ancestor.matches(FOCUSABLE_SELECTOR),
    )
    const target = survivor instanceof HTMLElement ? survivor : options.fallback()

    lastFocused = null
    lastAncestors = []

    if (target === null) {
      return
    }

    if (!target.matches(FOCUSABLE_SELECTOR)) {
      target.setAttribute('tabindex', '-1')
    }

    target.focus({ preventScroll: true })
  }

  const observer = new MutationObserver(recover)

  observer.observe(doc.body, {
    childList: true,
    subtree: true,
    attributes: true,
    attributeFilter: ['disabled'],
  })
  doc.addEventListener('focusin', remember)

  return () => {
    observer.disconnect()
    doc.removeEventListener('focusin', remember)
  }
}
