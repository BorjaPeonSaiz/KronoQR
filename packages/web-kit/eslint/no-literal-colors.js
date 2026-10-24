// «Ninguna SPA declara un color propio» (docs/06-guia-visual.md).
//
// POR QUE EXISTE. La regla estaba escrita y no la vigilaba ninguna herramienta:
// una convencion que no verifica una herramienta es una sugerencia. Un color
// suelto en una plantilla o en un `.ts` es un color que la marca configurable
// no alcanza: el cliente cambia el acento y ese elemento se queda
// con el color del fabricante, sin que nadie lo vea hasta que lo mira el
// cliente. Todo color sale de un token `--kq-*` de `theme.css`.
//
// QUE DETECTA. Cadenas y plantillas de texto en `.ts` y en la parte `<script>`
// de los `.vue`, y los atributos y expresiones de la `<template>`:
//   - hexadecimales de 3, 4, 6 u 8 cifras (`#fff`, `#ffff`, `#1a2b3c`, `#rrggbbaa`);
//   - funciones de color: `rgb()`, `rgba()`, `hsl()`, `hsla()`, `hwb()`, `lab()`,
//     `lch()`, `oklab()`, `oklch()`, `color()`, `color-mix()` y `light-dark()`;
//   - NOMBRES de color CSS (`red`, `rebeccapurple`...), pero solo donde son un
//     color y no una palabra: el valor de una propiedad de color (`color: red`
//     en un texto de estilo), el atributo `fill`/`stroke`/`color`... de una
//     plantilla, y una propiedad de objeto cuya clave es de color
//     (`{ color: 'red', backgroundColor: 'blue' }`). `transparent`, `currentColor`
//     e `inherit` no son un color propio y se admiten;
//   - un bloque `<style>` de un `.vue` que declare cualquiera de los anteriores;
//   - un HEXADECIMAL escrito en un comentario: el valor duplicado en un
//     comentario se desfasa en silencio cuando el token cambia. Se dice «el
//     token `--kq-color-x`», no su valor.
//
// QUE NO ALCANZA. Los `.css`: ESLint no los analiza y Stylelint no esta
// instalado (no se anade una dependencia por esto). Los `main.css` de cada SPA
// los cubre `base.spec.ts`; el quiosco declara ahi los cinco colores de
// confirmacion, medidos en `kioskConfirmation.spec.ts`.
//
// EXCEPCION. El fichero que DEFINE valores de color por contrato del producto
// (acento de serie, pares de contraste) se excluye por ruta, en la
// configuracion de web-kit, con el motivo escrito alli. Para un caso suelto:
// `// eslint-disable-next-line kronoqr-colors/no-literal-colors -- <motivo>`.

// Hex de 3, 4, 6 u 8 cifras; tambien los grises de solo cifras (`#000`, `#333`, `#999`).
const HEX_COLOR = /(?<![\w&#-])#(?:[0-9a-f]{8}|[0-9a-f]{6}|[0-9a-f]{3,4})(?![\w-])/i
// En un comentario, `#123` es un numero de incidencia, no un color: se exige un
// hexadecimal de verdad (seis u ocho cifras, o tres o cuatro con alguna letra a-f).
const COMMENT_HEX_COLOR =
  /(?<![\w&#-])#(?:[0-9a-f]{8}|[0-9a-f]{6}|(?=\d*[a-f])[0-9a-f]{3,4})(?![\w-])/i
const COLOR_FUNCTION =
  /(?<![\w-])(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch|color-mix|color|light-dark)\(/i

// Los 148 nombres de color de CSS Color 4. `transparent`, `currentcolor` e `inherit` no estan.
const COLOR_NAMES = new Set(
  (
    'aliceblue antiquewhite aqua aquamarine azure beige bisque black blanchedalmond blue ' +
    'blueviolet brown burlywood cadetblue chartreuse chocolate coral cornflowerblue ' +
    'cornsilk crimson cyan darkblue darkcyan darkgoldenrod darkgray darkgreen darkgrey ' +
    'darkkhaki darkmagenta darkolivegreen darkorange darkorchid darkred darksalmon ' +
    'darkseagreen darkslateblue darkslategray darkslategrey darkturquoise darkviolet ' +
    'deeppink deepskyblue dimgray dimgrey dodgerblue firebrick floralwhite forestgreen ' +
    'fuchsia gainsboro ghostwhite gold goldenrod gray green greenyellow grey honeydew ' +
    'hotpink indianred indigo ivory khaki lavender lavenderblush lawngreen lemonchiffon ' +
    'lightblue lightcoral lightcyan lightgoldenrodyellow lightgray lightgreen lightgrey ' +
    'lightpink lightsalmon lightseagreen lightskyblue lightslategray lightslategrey ' +
    'lightsteelblue lightyellow lime limegreen linen magenta maroon mediumaquamarine ' +
    'mediumblue mediumorchid mediumpurple mediumseagreen mediumslateblue mediumspringgreen ' +
    'mediumturquoise mediumvioletred midnightblue mintcream mistyrose moccasin navajowhite ' +
    'navy oldlace olive olivedrab orange orangered orchid palegoldenrod palegreen ' +
    'paleturquoise palevioletred papayawhip peachpuff peru pink plum powderblue purple ' +
    'rebeccapurple red rosybrown royalblue saddlebrown salmon sandybrown seagreen seashell ' +
    'sienna silver skyblue slateblue slategray slategrey snow springgreen steelblue tan ' +
    'teal thistle tomato turquoise violet wheat white whitesmoke yellow yellowgreen '
  )
    .split(' ')
    .filter(Boolean),
)

const COLOR_PROPERTY =
  /^(?:color|fill|stroke|background|bgcolor)$|-(?:color|fill|stroke)$|[a-z](?:Color|Fill|Stroke)$/

// Un texto de estilo: `color: red`, `border: 1px solid blue`, `fill:rebeccapurple`.
const STYLE_DECLARATION =
  /(?:^|[;{\s])(?:[a-z-]*color|background|fill|stroke|border(?:-[a-z]+)?|outline|box-shadow|text-shadow)\s*:\s*([^;}]*)/gi

const MESSAGE =
  'Color literal ({{ value }}): ninguna SPA declara un color propio (docs/06-guia-visual.md). Usa un token `--kq-*` de `packages/web-kit/src/theme.css` (en una plantilla, su utilidad de Tailwind: `bg-kq-surface`, `text-kq-text-muted`, `text-kq-danger`...; en JS o en un estilo en linea, `var(--kq-color-text)` o `getComputedStyle(document.documentElement).getPropertyValue(...)`). Si hace falta un tono nuevo, es un token nuevo en web-kit, no un valor aqui.'
const COMMENT_MESSAGE =
  'Hexadecimal en un comentario ({{ value }}): el valor duplicado se desfasa en silencio cuando el token cambia (R1-UX-01). Nombra el token (`--kq-color-text`), no su valor; la fuente de verdad es `theme.css` y su tabla en docs/06-guia-visual.md.'
const STYLE_MESSAGE =
  'Color literal en un bloque <style> ({{ value }}): ninguna SPA declara un color propio (docs/06-guia-visual.md). Usa `var(--kq-color-...)` o, mejor, las utilidades de Tailwind en la plantilla.'

/** @param {string} word */
const isColorName = (word) => COLOR_NAMES.has(word.trim().toLowerCase())

/** Los nombres de color que aparecen como VALOR de una declaracion dentro de un texto de estilo. */
function namedColorInStyleText(text) {
  for (const match of text.matchAll(STYLE_DECLARATION)) {
    const words = (match[1] ?? '').split(/[\s,()/]+/)
    const found = words.find((word) => isColorName(word))
    if (found !== undefined) return found
  }
  return null
}

/** @param {unknown} text */
function containsColor(text) {
  return typeof text === 'string' && (HEX_COLOR.test(text) || COLOR_FUNCTION.test(text))
}

/** El nombre de una clave de objeto (`color`, `'background-color'`, `backgroundColor`). */
function propertyName(property) {
  if (property.computed) return null
  const key = property.key
  if (key.type === 'Identifier') return key.name
  if (key.type === 'Literal' && typeof key.value === 'string') return key.value
  return null
}

/** @type {import('eslint').Rule.RuleModule} */
const rule = {
  meta: {
    type: 'problem',
    schema: [],
    messages: {
      literalColor: MESSAGE,
      literalColorComment: COMMENT_MESSAGE,
      literalColorStyle: STYLE_MESSAGE,
    },
  },
  create(context) {
    const sourceCode = context.sourceCode

    /** @param {import('estree').Node} node @param {string} value */
    const report = (node, value) => {
      if (containsColor(value)) {
        context.report({
          node,
          messageId: 'literalColor',
          data: { value: value.trim().slice(0, 40) },
        })
        return
      }
      const named = namedColorInStyleText(value)
      if (named !== null) {
        context.report({ node, messageId: 'literalColor', data: { value: named } })
      }
    }

    /** Un nombre de color como valor de una propiedad o atributo de color. */
    const reportNamedValue = (node, name, value) => {
      if (name !== null && COLOR_PROPERTY.test(name) && typeof value === 'string') {
        if (isColorName(value)) {
          context.report({ node, messageId: 'literalColor', data: { value: value.trim() } })
        }
      }
    }

    const checkProperty = (node) => {
      const name = propertyName(node)
      const value = node.value
      if (value.type === 'Literal' && typeof value.value === 'string') {
        reportNamedValue(value, name, value.value)
      } else if (value.type === 'TemplateLiteral' && value.expressions.length === 0) {
        reportNamedValue(value, name, value.quasis[0]?.value.cooked ?? '')
      }
    }

    const scriptVisitor = {
      Literal(node) {
        if (typeof node.value === 'string') report(node, node.value)
      },
      TemplateElement(node) {
        report(node, node.value.cooked ?? node.value.raw)
      },
      Property: checkProperty,
      'Program:exit'() {
        for (const comment of sourceCode.getAllComments()) {
          const found = COMMENT_HEX_COLOR.exec(comment.value)
          if (found !== null) {
            context.report({
              loc: comment.loc,
              messageId: 'literalColorComment',
              data: { value: found[0] },
            })
          }
        }

        const ast = sourceCode.ast
        // Comentarios de la <template> (`<!-- #b8542a -->`).
        for (const comment of ast.templateBody?.comments ?? []) {
          const found = COMMENT_HEX_COLOR.exec(comment.value)
          if (found !== null) {
            context.report({
              loc: comment.loc,
              messageId: 'literalColorComment',
              data: { value: found[0] },
            })
          }
        }

        // Bloques <style> del SFC: ESLint no los analiza como codigo, se leen como texto.
        const fragment = sourceCode.parserServices?.getDocumentFragment?.()
        for (const element of fragment?.children ?? []) {
          if (element.type !== 'VElement' || element.name !== 'style') continue
          const from = element.startTag.range[1]
          const to = element.endTag?.range[0] ?? element.range[1]
          const text = sourceCode.text.slice(from, to)
          const hex = HEX_COLOR.exec(text) ?? COLOR_FUNCTION.exec(text)
          const named = hex === null ? namedColorInStyleText(text) : null
          const value = hex?.[0] ?? named
          if (value !== null && value !== undefined) {
            context.report({ loc: element.loc, messageId: 'literalColorStyle', data: { value } })
          }
        }
      },
    }

    const templateVisitor = {
      VAttribute(node) {
        if (!node.directive) {
          // Atributo estatico: <path fill="#000000" /> , <path fill="red" />
          if (node.value) {
            report(node.value, node.value.value)
            reportNamedValue(node.value, String(node.key.name), node.value.value)
          }
          return
        }
        // Vinculo con literal: :fill="'red'" (el literal tambien lo ve `VElement Literal`).
        const argument = node.key.argument
        const expression = node.value?.expression
        if (
          argument?.type === 'VIdentifier' &&
          expression?.type === 'Literal' &&
          typeof expression.value === 'string'
        ) {
          reportNamedValue(expression, argument.name, expression.value)
        }
      },
      // Expresiones: :fill="'#000'" , :style="{ color: `rgb(...)` }"
      'VElement Literal'(node) {
        if (typeof node.value === 'string') report(node, node.value)
      },
      'VElement TemplateElement'(node) {
        report(node, node.value.cooked ?? node.value.raw)
      },
      'VElement Property': checkProperty,
    }

    const services = sourceCode.parserServices
    if (services && typeof services.defineTemplateBodyVisitor === 'function') {
      return services.defineTemplateBodyVisitor(templateVisitor, scriptVisitor)
    }
    return scriptVisitor
  },
}

export const noLiteralColorsPlugin = {
  meta: { name: 'kronoqr-colors' },
  rules: { 'no-literal-colors': rule },
}

/**
 * Bloque de configuracion plana de ESLint para las tres SPA y web-kit.
 *
 * @param {string[]} files rutas a vigilar; por defecto `src/**`
 * @param {string[]} ignores rutas exentas (el fichero que define valores por contrato)
 */
export function noLiteralColors(
  files = ['src/**/*.{ts,mts,tsx,vue,js,mjs}'],
  ignores = ['**/*.d.ts'],
) {
  return {
    name: 'kronoqr/sin-colores-literales',
    files,
    ignores,
    plugins: { 'kronoqr-colors': noLiteralColorsPlugin },
    rules: { 'kronoqr-colors/no-literal-colors': 'error' },
  }
}
