// «Sin literales fuera de i18n» (KI7-02, CLAUDE.md: los textos de usuario van en
// `i18n`).
//
// POR QUE EXISTE. Era una convencion que no verificaba ninguna herramienta, y
// segun la regla que gobierna doc 02 §3.5 eso es una sugerencia. Un literal
// suelto en una plantilla es un texto que no se traduce: el quiosco saldria con
// una palabra en espanol en una instalacion inglesa, y nadie lo veria hasta que
// lo leyera un cliente.
//
// LA REGLA ES `vue/no-bare-strings-in-template`, que ya trae `eslint-plugin-vue`
// pero NO esta en `flat/recommended`. Se activa aqui, una sola vez, para las
// tres SPA (mismo motivo que `identifier-language.js`: una lista copiada tres
// veces diverge en la primera prisa).
//
// LA LISTA BLANCA son simbolos y puntuacion que no se traducen: separadores,
// operadores, digitos del teclado numerico y los glifos decorativos que ya
// acompanan a un texto traducido (van `aria-hidden`). Una PALABRA, nunca: si una
// plantilla necesita una, va a `i18n`.

/** Lo que una plantilla puede escribir directamente sin pasar por `i18n`. */
export const NON_TRANSLATABLE = [
  // puntuacion y operadores
  ...'()[]{}<>,.:;!?&+-=*/#%|\'"'.split(''),
  // separadores y flechas tipograficas: · • — – … → ←
  '·',
  '•',
  '—',
  '–',
  '…',
  '→',
  '←',
  // aspas y marcas: × ✕ ✓
  '×',
  '✕',
  '✓',
  // digitos (teclado numerico del PIN)
  ...'0123456789'.split(''),
  // glifos decorativos (aria-hidden), siempre junto a un texto traducido: ⚠ ⏸ ⌫ ⏳
  '⚠',
  '⏸',
  '⌫',
  '⏳',
]

/**
 * Bloque de configuracion plana de ESLint para las tres SPA.
 *
 * @param {string[]} files rutas a vigilar; por defecto las plantillas de `src/**`
 */
export function noBareStrings(files = ['src/**/*.vue']) {
  return {
    name: 'kronoqr/sin-literales-fuera-de-i18n',
    files,
    rules: {
      'vue/no-bare-strings-in-template': ['error', { allowlist: NON_TRANSLATABLE }],
    },
  }
}
