// Los identificadores de `src/**` se escriben en ingles (doc 02 §3.5).
//
// POR QUE EXISTE. La convencion estaba escrita desde el primer dia y no la
// verificaba nadie, que segun la regla que gobierna esa seccion la convertia en
// una sugerencia. El dano no es estetico: en cuanto convive `tramo` con
// `shiftEntry`, el glosario del doc 01 §13 deja de ser el puente entre el
// lenguaje del hotel y el del codigo, hay dos nombres para el mismo concepto y
// nadie sabe cual manda. La contrapartida en el backend es
// `IdentifierLanguageTest` sobre `backend/app/`.
//
// POR QUE VIVE EN `web-kit` Y NO COPIADA EN CUATRO FICHEROS. Porque una lista de
// veinte palabras copiada cuatro veces diverge en la primera prisa, y entonces
// la regla dice cuatro cosas distintas segun la SPA. Las tres SPA ya dependen de
// `@kronoqr/web-kit` por los tokens visuales (doc 06); la regla entra por la
// misma puerta.
//
// DONDE SE APLICA Y DONDE NO (decision del 24-09-2026). En `src/**`, si. En
// `tests/**`, no: alli los ayudantes, las constantes y los conjuntos de datos van
// en el idioma del escenario, igual que las descripciones de los `it()` y de los
// `test()`, porque una prueba fallida tiene que leerse sola.
//
// QUE NO ES. No es un detector de espanol: `importe` o `fecha` pasan, y esta bien
// que pasen. Son las veinte palabras del glosario, que son las unicas con
// traduccion acordada y las unicas que producen el dano de los dos nombres.

/**
 * El glosario del doc 01 §13, en la forma en que aparece dentro de un
 * identificador. Identico al de `IdentifierLanguage::GLOSSARY` del backend.
 */
export const GLOSSARY = [
  'Fichaje',
  'Jornada',
  'Tramo',
  'Empleado',
  'Ausencia',
  'Quiosco',
  'Credencial',
  'Incidencia',
  'Turno',
  'Centro',
  'Departamento',
  'Contrato',
  'Usuario',
  'Pausa',
  'Descanso',
  'Nomina',
  'Informe',
  'Ajuste',
  'Licencia',
  'Tarjeta',
]

// El limite de palabra de un `camelCase` no es `\b`: es "hasta la siguiente
// mayuscula, digito, guion bajo, `$` o fin del nombre". Sin el, `Turnover` seria
// un `Turno`, `Informer` un `Informe` y `centroid` un `Centro`, y una regla que
// grita ante el patron correcto acaba desactivada entera.
//
// El plural opcional (`(?:es|s)?`) esta porque es lo que de verdad se escribe:
// no `tramo`, sino `tramos` o `credenciales`. Ninguna palabra inglesa del arbol
// cae por anadirlo: `Turnos`, `Centros` e `Informes` no son palabras.
const END_OF_SEGMENT = '(?:es|s)?(?=[A-Z0-9_$]|$)'

const lower = GLOSSARY.map((word) => word.toLowerCase()).join('|')
const upper = GLOSSARY.map((word) => word.toUpperCase()).join('|')
const capitalised = GLOSSARY.join('|')

// `id-match` exige que el identificador CASE con el patron, asi que la
// prohibicion se escribe como anticipacion negativa:
//
// 1. ninguna palabra del glosario capitalizada en ningun sitio (`getJornada`),
// 2. ninguna en minuscula al principio, que es el unico sitio donde un
//    `camelCase` la pone (`tramoVigente`),
// 3. ninguna en mayusculas dentro de una constante (`MAX_JORNADA`),
// 4. y solo ASCII: `[A-Za-z0-9_$]+` deja fuera `añoFiscal`.
//
// Los `.*` de la primera y la tercera son imprescindibles: `(?!X)` solo prueba
// `X` en la posicion donde esta, y sin ellos `getJornada` pasaria.
export const IDENTIFIER_PATTERN = [
  '^',
  `(?!.*(?:${capitalised})${END_OF_SEGMENT})`,
  `(?!(?:${lower})${END_OF_SEGMENT})`,
  `(?!.*(?:^|_)(?:${upper})(?:ES|S)?(?:_|$))`,
  '[A-Za-z0-9_$]+$',
].join('')

/**
 * Bloque de configuracion plana de ESLint, para las tres SPA y para `web-kit`.
 *
 * `onlyDeclarations: true` y `properties: false` a proposito: lo que se gobierna
 * es como se LLAMA lo que este repositorio declara, no como se llama lo que
 * llega de fuera. Una clave de objeto puede tener que ser `jornada` porque asi
 * viene en una respuesta o asi la espera un `i18n`, y renombrarla no seria una
 * mejora: seria un fallo.
 *
 * @param {string[]} files rutas a vigilar; por defecto `src/**`, nunca `tests/**`
 */
export function identifierLanguage(files = ['src/**/*.{ts,mts,tsx,vue,js,mjs}']) {
  return {
    name: 'kronoqr/identificadores-en-ingles',
    files,
    rules: {
      'id-match': [
        'error',
        IDENTIFIER_PATTERN,
        {
          properties: false,
          onlyDeclarations: true,
        },
      ],
    },
  }
}
