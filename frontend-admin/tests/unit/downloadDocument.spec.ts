import { afterEach, describe, expect, it, vi } from 'vitest'
import { downloadDocument } from '@/shared/download/downloadDocument'

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

describe('descarga de documentos', () => {
  it('suelta el blob en el acto: un PDF de tarjetas no se queda vivo en el navegador', () => {
    const createObjectURL = vi.fn(() => 'blob:kronoqr')
    const revokeObjectURL = vi.fn()

    vi.stubGlobal('URL', { ...URL, createObjectURL, revokeObjectURL })
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})

    downloadDocument({
      blob: new Blob(['%PDF-1.7'], { type: 'application/pdf' }),
      filename: 'credenciales.pdf',
      printedCount: 40,
    })

    expect(click).toHaveBeenCalledTimes(1)
    expect(revokeObjectURL).toHaveBeenCalledWith('blob:kronoqr')
    expect(document.querySelectorAll('a')).toHaveLength(0)
  })
})
