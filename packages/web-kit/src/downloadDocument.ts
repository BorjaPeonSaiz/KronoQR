// Descarga de un documento generado por el servidor, compartida por las SPA del
// panel y del portal (ADR-036).
//
// Un PDF de credencial es un instrumento al portador; un CSV del historico
// propio es el registro horario de una persona. Los dos casos exigen lo mismo:
// la SPA solo dispara la descarga y suelta el blob en el mismo momento. No lo
// guarda en ningun estado, no lo abre en una pestaña que quede en el historial
// y no lo mantiene vivo en `URL.createObjectURL` mas alla del clic.
//
// La forma que exige es deliberadamente minima —`blob` y `filename`— y no
// depende de `BinaryDocument` de `./http`: cualquier objeto con esas dos
// propiedades sirve, que es justo lo que ya devuelve `requestBlob`.
export interface DownloadableDocument {
  blob: Blob
  filename: string
}

export function downloadDocument(document_: DownloadableDocument, root: Document = document): void {
  const url = URL.createObjectURL(document_.blob)
  const anchor = root.createElement('a')

  anchor.href = url
  anchor.download = document_.filename
  anchor.rel = 'noopener'
  root.body.appendChild(anchor)
  anchor.click()
  anchor.remove()
  URL.revokeObjectURL(url)
}
