// Comentarios con el motivo, no con la historia del plan ni de las revisiones
// (doc 02 §3.5, decision del propietario del 24-10-2026).
//
// POR QUE EXISTE. Un comentario que dice «(bloque 25)», «(tarea 5.13)» o
// «(V21-3-PA-4)» cuenta en que tanda de correcciones o en que revision se
// escribio el codigo, no por que es asi. Para quien lo lea dentro de un año, el
// bloque, la tarea o el id de verificacion son numeros de un plan que ya esta
// cerrado y no explican nada; el motivo, en cambio, sigue valiendo: un
// requisito (`RF-*`, `RN-*`, `RL-*`, `RS-*`, `RQ-*`, `RNF-*`), un ADR, una
// regla dura, una seccion de un documento (`doc 01 §4`) o la decision del
// propietario con su fecha. La verificacion de la 2.2.1 encontro que estas
// referencias volvian despues de cada limpieza (de 66 a 89 en las SPA), porque
// ninguna herramienta lo impedia: una convencion que no verifica una
// herramienta es una sugerencia.
//
// QUE DETECTA, en los comentarios de `.ts`, `.js` y `.vue` (tambien en los de
// la `<template>`), sin distinguir mayusculas salvo en los ids:
//   - «bloque N» del plan: `bloque 25`, `Bloque 12c`, `bloque 14.4`,
//     `bloques 12 y 13`, `bloque A2`. «el bloque "que hacer"» o «bloqueo» pasan.
//   - Tareas del plan: `tarea 5.13`, `tareas 3.5 y 3.10`, `tarea 5.11b`,
//     `ficha 3.3`, y «la N.N» tras `de`, `desde` o `tras` (`desde la 1.8`,
//     `de la 3.3`). Las versiones publicadas llevan tres numeros (`desde la
//     2.2.0`, `hasta la 2.2.x`) y pasan, igual que «WCAG hasta la 2.2» y
//     «esta tarea», sin numero.
//   - Decisiones de una ficha del plan: `decision 7`, `decision 13 de la
//     segunda vuelta`, `decision de la 5.5`. «decision del propietario del
//     18-10-2026» pasa: es el motivo, no la historia.
//   - Hallazgos y revisiones: `hallazgo B1 del cierre`, `hallazgos U1 y
//     V1-UX-2`, `hallazgo de revision`, `revision de la 3.3`, `revision de
//     UI/UX`, `revision de codigo`, `revision de seguridad`, `cierre de la
//     Fase 5`, y las vueltas (`segunda vuelta`). «la hora de vuelta» de una
//     pausa, la «revision nocturna» o la «revision humana» pasan.
//   - Ids de verificacion largos, en cualquier sitio, porque su forma no la
//     comparte ningun requisito (los requisitos no llevan cifra en el prefijo):
//     `V21-1-RV-1`, `V1-RV-02`, `V2-PO-2`, `V6-KI-02`, `R1-RV-03`, `R6-BD-01`,
//     `V1-UX-1`; `KI7-01`, `AX7-03`, `PA7-001`, `PO7-05`; y las incidencias
//     `PIN-03`, `AUD-1`.
//   - Ids cortos (`C1`, `U1`, `K1`, `KP2`, `KT5`, `MB4`, `DC5`, `HC1`, `RC2`)
//     SOLO cuando citan: dentro de un parentesis como elemento de una lista
//     (`(C1)`, `(RF-QR-08, HC1)`, `(D22; KP2)`, `(A1 de la ...)`, `(U1 y U2)`)
//     o al principio de una linea seguidos de dos puntos (`// G1: ...`). Fuera
//     de esa posicion una letra y una cifra no son un id fiable: hexadecimales,
//     tokens `--kq-*`, `h1`, `A4`, `MP3`, el prefijo `FH1` del QR, `KQL2`,
//     `S6.5` o `T1053.003` de ATT&CK. Por eso el id corto exige mayusculas, una
//     o dos cifras y nada pegado detras (ni letra, ni cifra, ni `.`, ni `-`),
//     y se excluyen `A3`-`A5`, `MP3`/`MP4`, `FH*`, `KQ*` y `SHA*`.
//   Las frases de varias palabras se detectan tambien partidas entre dos
//   lineas de un mismo bloque `/* ... */` o `<!-- ... -->`.
//
// QUE NO ALCANZA. Un id corto citado en prosa sin parentesis (`como pidio G1`),
// una revision sin nombre ni numero («la revision pidio...»), «la ficha»
// sin numero (que tambien es la ficha del empleado) y una frase partida entre
// dos comentarios `//` seguidos: se limpian a mano. Los `schema.d.ts`
// generados: sus comentarios vienen de las descripciones de
// `docs/api/openapi.yaml` y se corrigen alli. Para un caso suelto:
// `// eslint-disable-next-line kronoqr-comments/no-plan-references -- <motivo>`.

const SHORT_ID = String.raw`(?!(?:A[3-5]|MP[34]|FH\d|KQ[A-Z]?\d|SHA\d)(?![\w.-]))[A-Z]{1,3}\d{1,2}(?![\w.-])`

/**
 * Frase de varias palabras: cada espacio admite tambien el salto de linea con
 * el margen ` * ` de un bloque. Sin distinguir mayusculas.
 *
 * @param {string} source
 */
const phrase = (source) => new RegExp(source.replaceAll(' ', String.raw`[\s*]+`), 'i')

const PLAN_REFERENCES = [
  /\bbloques?\s+[A-Z]?\d+[a-z]?(?:\.\d+)?\b/i,
  phrase(String.raw`\b(?:tareas?|fichas?) \d+\.\d+[a-z]?\b`),
  phrase(String.raw`\b(?:de|desde|tras) la \d+\.\d+[a-z]?\b(?![.,]\w)`),
  /\bdecisi(?:o|ó)n(?:es)?\s+\d{1,2}\b(?!-\d)/i,
  phrase(String.raw`\bdecisi(?:o|ó)n(?:es)? de la (?:\d|ficha\b|tarea\b)`),
  /\bhallazgos?\s+[A-Z]{1,3}\d*-?\d+\b/i,
  phrase(String.raw`\bhallazgos? (?:de|del) (?:la )?revisi(?:o|ó)n\b`),
  phrase(
    String.raw`\brevisi(?:o|ó)n(?:es)? (?:de la \d|de UI/UX|de c(?:o|ó)digo|de seguridad|de QA)`,
  ),
  phrase(String.raw`\bcierre de la Fase \d`),
  phrase(String.raw`\b(?:primera|segunda|tercera|cuarta|quinta|ultima|última) vuelta\b`),
  /\b[A-Z]{1,3}\d+(?:-\d+)?-[A-Z]{2,3}-\d+\b/,
  /\b(?!SHA)[A-Z]{2,3}\d-\d{2,3}\b/,
  /\b(?:PIN|AUD)-\d+\b/,
  // Id corto dentro de un parentesis: justo tras `(` o tras `,` `;` `:` `y`.
  new RegExp(
    String.raw`(?<=\((?:[^()]*?(?:[,;:]|\sy))?\s*)` +
      SHORT_ID +
      String.raw`(?=\s*[,;:)]|\s+(?:y|de|del)\s)`,
  ),
  // Id corto al principio de una linea, seguido de dos puntos (`// G1: ...`).
  new RegExp(String.raw`(?<=(?:^|\n)[\s*]*)` + SHORT_ID + '(?=:)'),
]

const MESSAGE =
  'Comentario con referencia al plan o a una revision («{{value}}»): cuenta cuando se escribio el codigo, no por que es asi. Sustituyela por el motivo: el requisito (`RF-*`, `RN-*`), el ADR o la decision del propietario con su fecha.'

/** @param {string} text */
export function findPlanReference(text) {
  for (const pattern of PLAN_REFERENCES) {
    const found = pattern.exec(text)
    // En una sola linea, sin el margen ` * ` del bloque que la partia.
    if (found !== null) return found[0].replace(/\s*\n[\s*]*/g, ' ').trim()
  }
  return null
}

/** @type {import('eslint').Rule.RuleModule} */
const rule = {
  meta: {
    type: 'suggestion',
    schema: [],
    messages: { planReference: MESSAGE },
  },
  create(context) {
    const sourceCode = context.sourceCode

    /** @param {{ value: string, loc: import('estree').SourceLocation }} comment */
    const check = (comment) => {
      const found = findPlanReference(comment.value)
      if (found !== null) {
        context.report({ loc: comment.loc, messageId: 'planReference', data: { value: found } })
      }
    }

    return {
      'Program:exit'() {
        for (const comment of sourceCode.getAllComments()) check(comment)
        // Comentarios de la <template>: no estan en getAllComments().
        for (const comment of sourceCode.ast.templateBody?.comments ?? []) check(comment)
      },
    }
  },
}

export const noPlanReferencesPlugin = {
  meta: { name: 'kronoqr-comments' },
  rules: { 'no-plan-references': rule },
}

/**
 * Bloque de configuracion plana de ESLint para las tres SPA y web-kit.
 *
 * @param {string[]} files rutas a vigilar; por defecto `src/**`
 * @param {string[]} ignores rutas exentas; por defecto los `.d.ts` generados
 */
export function noPlanReferences(
  files = ['src/**/*.{ts,mts,tsx,vue,js,mjs}'],
  ignores = ['**/*.d.ts'],
) {
  return {
    name: 'kronoqr/sin-referencias-al-plan',
    files,
    ignores,
    plugins: { 'kronoqr-comments': noPlanReferencesPlugin },
    rules: { 'kronoqr-comments/no-plan-references': 'error' },
  }
}
