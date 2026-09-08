// Ayuda SOLO para las pruebas que ejercitan `accentOverrides`/`contrastWarnings`
// de `@kronoqr/web-kit/branding` sobre el documento real (hoy, `BrandingView`,
// que las usa para la previsualizacion y el aviso de contraste).
//
// jsdom no ejecuta el pipeline de Tailwind/PostCSS que compila `theme.css` en
// el navegador de verdad, asi que `getComputedStyle(document.documentElement)`
// no resuelve ningun token `--kq-*` a menos que se declare como estilo EN
// LINEA del propio elemento, que jsdom si sabe leer sin depender de ninguna
// hoja de estilos.
//
// Los valores se PARSEAN del `theme.css` real, no se copian a mano: si el
// sistema visual del doc 06 cambia un tono, esta ayuda lo sigue sin que haya
// que tocar ninguna prueba.
//
// La ruta se resuelve desde `process.cwd()` (la raiz de `frontend-admin`,
// igual que hace `tests/unit/i18n.spec.ts`) y NO desde `import.meta.url`:
// bajo Vite en entorno jsdom, `import.meta.url` resuelve a una URL del
// servidor de desarrollo (`http://localhost:.../@fs/...`), no a una ruta de
// fichero, y `fileURLToPath` la rechaza.
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

const THEME_CSS_PATH = resolve(process.cwd(), '../packages/web-kit/src/theme.css')

/** Declara los tokens de verdad en `document.documentElement`. Devuelve como deshacerlo. */
export function installRealThemeTokens(): () => void {
  const css = readFileSync(THEME_CSS_PATH, 'utf8')
  const declarations = [...css.matchAll(/(--kq-[\w-]+):\s*([^;]+);/g)]
    .map(([, name, value]) => (name === undefined || value === undefined ? null : { name, value }))
    .filter((declaration): declaration is { name: string; value: string } => declaration !== null)

  if (declarations.length === 0) {
    throw new Error('No se ha encontrado ningun token --kq-* en theme.css: revisa la ruta.')
  }

  for (const { name, value } of declarations) {
    document.documentElement.style.setProperty(name, value.trim())
  }

  return () => {
    for (const { name } of declarations) {
      document.documentElement.style.removeProperty(name)
    }
  }
}
