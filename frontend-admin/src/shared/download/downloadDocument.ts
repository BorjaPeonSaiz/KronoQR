// Descarga de un documento generado por el servidor.
//
// El PDF de una tarjeta es un **instrumento al portador**: quien lo tenga puede
// fabricar la credencial de otra persona y fichar por ella. Por eso el panel
// solo dispara la descarga y suelta el blob en el mismo momento: no lo guarda en
// ningun estado, no lo abre en una pestaña que quede en el historial y no lo
// mantiene vivo en `URL.createObjectURL` mas alla del clic.
import type { BinaryDocument } from '@/shared/api/http'

export function downloadDocument(document_: BinaryDocument, root: Document = document): void {
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
