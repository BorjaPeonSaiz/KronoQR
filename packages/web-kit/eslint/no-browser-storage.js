// Regla de lint contra el almacenamiento del navegador (ADR-052).
//
// Una sola definicion para el panel, el portal y web-kit. Cada aplicacion
// declara solo su lista de excepciones, fichero a fichero y con su motivo.

const STORAGE_NAMES = ['localStorage', 'sessionStorage']

export const STORAGE_MESSAGE =
  'Los datos del registro no se guardan en el navegador: son datos personales y el equipo puede ser compartido. `localStorage` sobrevive al cierre de la pestana y `sessionStorage` es una copia en el equipo; guarda el estado en memoria (Pinia, TanStack Query) y vuelve a pedirlo al servidor. La unica excepcion admitida es un fichero declarado en `eslint.config.js` con su motivo (cache de marca, sesion de la propia persona, marca tecnica de recarga).'

/** @param {readonly string[]} names */
const restrictedGlobals = (names) => names.map((name) => ({ name, message: STORAGE_MESSAGE }))

/** `window.x`, `globalThis.x` y `self.x`, con punto o con corchetes. */
const restrictedProperties = (names) =>
  ['window', 'globalThis', 'self'].flatMap((object) =>
    names.map((property) => ({ object, property, message: STORAGE_MESSAGE })),
  )

const rulesFor = (names) => ({
  'no-restricted-globals': ['error', ...restrictedGlobals(names)],
  'no-restricted-properties': ['error', ...restrictedProperties(names)],
})

/**
 * Bloques de configuracion plana: la prohibicion y, despues, un bloque por
 * excepcion que deja prohibido lo que NO se permite en ese fichero.
 *
 * @param {object} [options]
 * @param {string[]} [options.files] ficheros vigilados; por defecto `src/**`
 * @param {{ file: string, allow: Array<'localStorage' | 'sessionStorage'>, reason: string }[]} [options.exceptions]
 */
export function noBrowserStorage({
  files = ['src/**/*.{ts,mts,tsx,vue,js,mjs}'],
  exceptions = [],
} = {}) {
  return [
    {
      name: 'kronoqr/sin-almacenamiento-del-navegador',
      files,
      rules: rulesFor(STORAGE_NAMES),
    },
    ...exceptions.map(({ file, allow, reason }) => {
      if (!reason) throw new Error(`noBrowserStorage: la excepcion de ${file} necesita un motivo`)
      return {
        name: `kronoqr/excepcion-almacenamiento/${file}`,
        files: [file],
        rules: rulesFor(STORAGE_NAMES.filter((name) => !allow.includes(name))),
      }
    }),
  ]
}
