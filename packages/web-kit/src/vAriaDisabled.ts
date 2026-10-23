// Boton «deshabilitado» que NO pierde el foco (V21-7-PA-1; precedente: AccountsView).
//
// `disabled` hace que el navegador suelte el foco en `<body>` en cuanto el boton se
// deshabilita —justo al pulsar «Guardar»: durante el envio—, y quien navega con
// teclado vuelve a empezar la pagina por el enlace de salto. `aria-disabled="true"`
// conserva el foco, el lector de pantalla lo anuncia como no disponible y el clic se
// bloquea aqui por codigo (tambien el Enter o el Espacio, que generan un clic, y el
// envio implicito de un formulario, que pulsa el boton por defecto).
//
// Modificadores:
//  - `.reason`: el motivo es que falta algo por completar; se enlaza con
//    `aria-describedby` al texto `#kq-blocked-reason` (lo pinta el marco, el acceso y
//    el asistente) solo mientras este bloqueado.
//  - `.busy`: el motivo es un envio en curso; se marca `aria-busy`.
import type { Directive } from 'vue'

export const BLOCKED_REASON_ID = 'kq-blocked-reason'

declare module 'vue' {
  interface GlobalDirectives {
    vAriaDisabled: typeof vAriaDisabled
  }
}

function block(event: Event): void {
  const target = event.currentTarget

  if (target instanceof HTMLElement && target.getAttribute('aria-disabled') === 'true') {
    event.preventDefault()
    event.stopImmediatePropagation()
  }
}

function apply(
  element: HTMLElement,
  disabled: unknown,
  modifiers: Readonly<Partial<Record<string, boolean>>>,
): void {
  const blocked = disabled === true

  if (blocked) {
    element.setAttribute('aria-disabled', 'true')
  } else {
    element.removeAttribute('aria-disabled')
  }

  if (modifiers['reason'] === true) {
    if (blocked) {
      element.setAttribute('aria-describedby', BLOCKED_REASON_ID)
    } else if (element.getAttribute('aria-describedby') === BLOCKED_REASON_ID) {
      element.removeAttribute('aria-describedby')
    }
  }

  if (modifiers['busy'] === true) {
    if (blocked) {
      element.setAttribute('aria-busy', 'true')
    } else {
      element.removeAttribute('aria-busy')
    }
  }
}

export const vAriaDisabled: Directive<HTMLElement, unknown> = {
  mounted(element, binding) {
    // En captura y sobre el propio elemento: corre antes que el `@click` de Vue.
    element.addEventListener('click', block, true)
    apply(element, binding.value, binding.modifiers)
  },
  updated(element, binding) {
    apply(element, binding.value, binding.modifiers)
  },
  beforeUnmount(element) {
    element.removeEventListener('click', block, true)
  },
}
