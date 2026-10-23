// La pasada de axe del quiosco: la definicion compartida vive en `@kronoqr/web-kit/axePass`.
//
// REGLA DESACTIVADA, CON MOTIVO. `video-caption` exige una pista de subtitulos en todo
// `<video>`. Aqui el `<video>` no es contenido audiovisual: es el visor EN VIVO de la camara,
// se abre con `audio: false` y va marcado `aria-hidden`. No hay nada que subtitular, y anadir
// un `<track>` vacio para contentar a la herramienta seria falsear el resultado.
import type { Page } from '@playwright/test'
import { AXE_TAGS, expectNoBlockingViolations as sharedPass } from '@kronoqr/web-kit/axePass'
import type { AxePassOptions } from '@kronoqr/web-kit/axePass'

export { AXE_TAGS }

/** Ver el comentario de cabecera. */
export const AXE_DISABLED_RULES = ['video-caption']

export async function expectNoBlockingViolations(
  page: Page,
  options: AxePassOptions = {},
): Promise<void> {
  await sharedPass(page, {
    ...options,
    disabledRules: [...AXE_DISABLED_RULES, ...(options.disabledRules ?? [])],
  })
}
