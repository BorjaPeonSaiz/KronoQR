// Trae a la vista un aviso que ha aparecido lejos de donde esta quien lo provoco.
//
// Un `role="alert"` ya lo lee el lector de pantalla, pero quien ve la pantalla y
// pulso «Confirmar» al final de un dialogo largo (o «Dar de baja» al final de una
// ficha) no ve un aviso que se pinto arriba: parece que no ha pasado nada. `nearest`
// no mueve nada si ya se ve, y sin animacion (`prefers-reduced-motion`).
//
// `scrollIntoView` no existe en jsdom: sin el metodo no hace nada, y no es un fallo.
export function revealElement(element: HTMLElement | null | undefined): void {
  if (element === null || element === undefined) {
    return
  }

  const reveal = (element as Partial<HTMLElement>).scrollIntoView

  if (typeof reveal === 'function') {
    reveal.call(element, { block: 'nearest', behavior: 'auto' })
  }
}
