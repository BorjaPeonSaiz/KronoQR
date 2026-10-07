// «Ninguna SPA declara un color propio» (docs/06-guia-visual.md, hallazgo U1).
//
// POR QUE EXISTE. La regla estaba escrita y no la vigilaba ninguna herramienta:
// una convencion que no verifica una herramienta es una sugerencia. Un
// hexadecimal suelto en una plantilla o en un `.ts` es un color que la marca
// configurable (tarea 5.8) no alcanza: el cliente cambia el acento y ese
// elemento se queda con el color del fabricante, sin que nadie lo vea hasta que
// lo mira el cliente. Todo color sale de un token `--kq-*` de `theme.css`.
//
// QUE DETECTA. Cadenas y plantillas de texto (`'#b8542a'`, `` `rgb(0 0 0)` ``)
// en `.ts` y en la parte `<script>` de los `.vue`, y los atributos y
// expresiones de la `<template>` (`fill="#000"`, `:style="{ color: '#fff' }"`):
//   - hexadecimales de 3, 4, 6 u 8 cifras (`#fff`, `#ffff`, `#1a2b3c`, `#rrggbbaa`);
//   - funciones de color: `rgb()`, `rgba()`, `hsl()`, `hsla()`, `hwb()`, `lab()`,
//     `lch()`, `oklab()`, `oklch()`.
//
// QUE NO ALCANZA. Los bloques `<style>` de los `.vue` y los `.css`: ESLint no
// los analiza y Stylelint no esta instalado (no se anade una dependencia por
// esto). Los `.css` ya los cubre `base.spec.ts` de cada SPA; un `<style>` con
// color propio queda sin vigilar y se vigila hoy con revision (ver
// docs/06-guia-visual.md).
//
// EXCEPCION. El fichero que DEFINE valores de color por contrato del producto
// (acento de serie, pares de contraste) se excluye por ruta, en la
// configuracion de web-kit, con el motivo escrito alli. Para un caso suelto:
// `// eslint-disable-next-line kronoqr-colors/no-literal-colors -- <motivo>`.

// Hex de 3, 4, 6 u 8 cifras; tambien los grises de solo cifras (`#000`, `#333`, `#999`).
const HEX_COLOR = /(?<![\w&#-])#(?:[0-9a-f]{8}|[0-9a-f]{6}|[0-9a-f]{3,4})(?![\w-])/i
const COLOR_FUNCTION = /(?<![\w-])(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch)\(/i

const MESSAGE =
  'Color literal ({{ value }}): ninguna SPA declara un color propio (docs/06-guia-visual.md). Usa un token `--kq-*` de `packages/web-kit/src/theme.css` (en Tailwind, su utilidad semantica: `bg-surface`, `text-text-muted`, `text-danger`...; en JS, `readToken(...)`). Si hace falta un tono nuevo, es un token nuevo en web-kit, no un valor aqui.'

/** @param {unknown} text */
function containsColor(text) {
  return typeof text === 'string' && (HEX_COLOR.test(text) || COLOR_FUNCTION.test(text))
}

/** @type {import('eslint').Rule.RuleModule} */
const rule = {
  meta: {
    type: 'problem',
    schema: [],
    messages: { literalColor: MESSAGE },
  },
  create(context) {
    /** @param {import('estree').Node} node @param {string} value */
    const report = (node, value) => {
      if (containsColor(value)) {
        context.report({
          node,
          messageId: 'literalColor',
          data: { value: value.trim().slice(0, 40) },
        })
      }
    }

    const scriptVisitor = {
      Literal(node) {
        if (typeof node.value === 'string') report(node, node.value)
      },
      TemplateElement(node) {
        report(node, node.value.cooked ?? node.value.raw)
      },
    }

    const templateVisitor = {
      // Atributo estatico: <path fill="#000000" />
      VAttribute(node) {
        if (!node.directive && node.value) report(node.value, node.value.value)
      },
      // Expresiones: :fill="'#000'" , :style="{ color: `rgb(...)` }"
      'VElement Literal'(node) {
        if (typeof node.value === 'string') report(node, node.value)
      },
      'VElement TemplateElement'(node) {
        report(node, node.value.cooked ?? node.value.raw)
      },
    }

    const services = context.sourceCode.parserServices
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
