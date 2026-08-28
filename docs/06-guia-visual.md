# 06 — Guía visual de las tres aplicaciones

> Sistema visual compartido del quiosco, el panel y el portal. Vive en código en `packages/web-kit/src/theme.css` (tokens y utilidades de Tailwind), `base.css` (capa base: `body`, encabezados, foco visible, `prefers-reduced-motion`), `fonts.css` (tipografías autoalojadas), `themePairs.ts` (parejas texto/fondo con su exigencia) y `contrast.ts` (fórmula WCAG). Este documento explica **por qué** cada valor es el que es; los números los verifica `packages/web-kit/tests/unit/theme.spec.ts` y `base.spec.ts` en cada ejecución de la suite.

**Es la marca por defecto del fabricante, no de ningún cliente.** Un cliente cambia colores, logotipo y nombre por configuración (tarea 5.8, RF-PD-08), nunca tocando este repositorio (regla dura 13, ADR-017). Véase §7.

## 1. Paleta

Deriva de la paleta del fabricante: clara, cálida, esquinas redondeadas, sombras suaves, sin degradados fuertes. Los nombres son **semánticos y en inglés** (`surface`, `text-muted`, `primary-strong`, `danger-soft`): una plantilla nunca escribe un hexadecimal, escribe el papel que ese color cumple.

### 1.1 Panel y portal (fondo claro)

| Token (`--kq-color-*`) | Valor | Papel |
|---|---|---|
| `surface` | `#fff7ed` | Fondo de página (crema) |
| `surface-raised` | `#ffffff` | Tarjetas, diálogos, filas de tabla |
| `surface-alt` | `#fbeee0` | Filas alternas, cabeceras de tabla, secciones |
| `text` | `#3a2e28` | Texto principal |
| `text-muted` | `#6b5d54` | Texto secundario, etiquetas, ayudas |
| `border` | `#eaddcf` | Separadores y bordes **decorativos** |
| `border-strong` | `#8f7b6d` | Bordes de **controles**: campos, botones con borde |
| `primary` | `#d66c3a` | Terracota claro. **Solo acentos grandes y decorativos** (iconos, franjas, texto ≥ 24 px) |
| `primary-strong` | `#b8542a` | Terracota oscuro. Botón sólido, enlaces, texto de marca |
| `primary-soft` | `#fff0e5` | Fondo tintado: chip, fila seleccionada |
| `on-primary` | `#ffffff` | Texto sobre `primary-strong` |
| `on-primary-soft` | `#9a4520` | Texto sobre `primary-soft` |
| `accent` | `#7a9b76` | Salvia. **Solo decorativo**: ilustraciones, franjas, iconos grandes sobre blanco |
| `accent-soft` | `#edf2eb` | Panel tintado de salvia |
| `on-accent-soft` | `#3f6e3b` | Texto sobre `accent-soft` |
| `success` / `success-soft` / `on-success` | `#3f6e3b` / `#e8efe6` / `#ffffff` | Estado correcto. Verde derivado de la salvia, oscurecido hasta pasar como texto |
| `warning` / `warning-soft` / `on-warning` | `#8a5312` / `#fdf0d5` / `#ffffff` | Aviso, pendiente de revisión |
| `danger` / `danger-soft` / `on-danger` | `#a52a1f` / `#fbe5e2` / `#ffffff` | Error y **acciones destructivas** |
| `focus` | `#b8542a` | Anillo de foco visible |

### 1.2 Quiosco (fondo oscuro, lectura a distancia)

Pantalla de pared que se mira de lejos, con mala luz y guantes. Fondo oscuro y cálido derivado del color de texto de la paleta; contrastes muy por encima del mínimo a propósito.

| Token (`--kq-color-kiosk-*`) | Valor | Papel |
|---|---|---|
| `surface` | `#241a15` | Fondo de la pantalla |
| `surface-raised` | `#3a2e28` | Paneles destacados |
| `text` | `#fff7ed` | Texto principal (crema) |
| `text-muted` | `#d4c3b5` | Texto secundario |
| `border` | `#8a7a6f` | Bordes de controles |
| `primary` | `#d66c3a` | Acento grande y decorativo |
| `primary-strong` | `#f0a27a` | Botón sólido sobre fondo oscuro |
| `on-primary` | `#241a15` | Texto sobre `primary-strong` |
| `focus` | `#fff7ed` | Anillo de foco |

**Los cinco colores de confirmación del quiosco no están aquí y no se tocan**: entrada `#064e3b`, salida `#1e3a8a`, pendiente `#312e81`, aviso `#78350f`, error `#7f1d1d`. Son semánticos, están medidos contra el blanco (todos > 9:1) y viven en `frontend-kiosk/src/assets/main.css`. No son marca: un cliente no los cambia por estética, porque el empleado aprende que verde es entrada y azul es salida.

## 2. Contrastes medidos (WCAG 2.2 AA)

Mínimos: **≥ 4,5:1** en texto normal (1.4.3); **≥ 3:1** en texto grande (≥ 24 px, o ≥ 19 px en negrita) y en componentes de interfaz (1.4.11); sin exigencia en lo decorativo, que por definición no transmite información. Fórmula de luminancia relativa de la recomendación, implementada en `contrast.ts`.

**Lo que la paleta de origen no permite**, y por eso hay dos terracotas y un verde propio:

| Pareja | Contraste | Conclusión |
|---|---|---|
| Blanco sobre `#d66c3a` (terracota claro) | 3,46:1 | Falla AA en texto normal → `primary` es solo acento grande; los botones usan `primary-strong` |
| Blanco sobre `#b8542a` (terracota oscuro) | 4,84:1 | Pasa → `primary-strong` + `on-primary` |
| Blanco sobre `#7a9b76` (salvia) | 3,10:1 | Falla → la salvia nunca lleva texto |
| `#3a2e28` sobre `#7a9b76` | 3,79:1 | Falla por poco → tampoco con texto oscuro |
| `#7a9b76` sobre `#fff7ed` | 2,92:1 | Falla incluso como componente → sobre crema es solo decorativa |
| `#b8542a` sobre `#fff0e5` / `#fbeee0` | 4,34:1 / 4,24:1 | Falla → sobre fondos tintados el texto de marca es `on-primary-soft` o `text` |

Tabla completa, una fila por pareja declarada en `themePairs.ts` (la prueba falla si cualquiera baja del mínimo o si aparece un token de color que no está en ninguna pareja):

| Primer plano | Fondo | Exigencia | Medido | Uso |
|---|---|---|---|---|
| `text` `#3a2e28` | `surface` `#fff7ed` | texto (≥ 4,5) | **12,35:1** | body text on page background |
| `text` `#3a2e28` | `surface-raised` `#ffffff` | texto (≥ 4,5) | **13,11:1** | body text on cards and dialogs |
| `text` `#3a2e28` | `surface-alt` `#fbeee0` | texto (≥ 4,5) | **11,50:1** | body text on alternate rows |
| `text-muted` `#6b5d54` | `surface` `#fff7ed` | texto (≥ 4,5) | **5,96:1** | secondary text on page background |
| `text-muted` `#6b5d54` | `surface-raised` `#ffffff` | texto (≥ 4,5) | **6,33:1** | secondary text on cards |
| `text-muted` `#6b5d54` | `surface-alt` `#fbeee0` | texto (≥ 4,5) | **5,55:1** | secondary text on alternate rows |
| `border` `#eaddcf` | `surface` `#fff7ed` | decorativo (sin mínimo) | **1,26:1** | dividers on page background |
| `border` `#eaddcf` | `surface-raised` `#ffffff` | decorativo (sin mínimo) | **1,33:1** | dividers on cards |
| `border-strong` `#8f7b6d` | `surface` `#fff7ed` | grande/componente (≥ 3) | **3,79:1** | input and outlined-button borders on page |
| `border-strong` `#8f7b6d` | `surface-raised` `#ffffff` | grande/componente (≥ 3) | **4,02:1** | input and outlined-button borders on cards |
| `border-strong` `#8f7b6d` | `surface-alt` `#fbeee0` | grande/componente (≥ 3) | **3,53:1** | input borders on alternate rows |
| `primary` `#d66c3a` | `surface` `#fff7ed` | grande/componente (≥ 3) | **3,26:1** | large accents and icons on page |
| `primary` `#d66c3a` | `surface-raised` `#ffffff` | grande/componente (≥ 3) | **3,46:1** | large accents and icons on cards |
| `primary` `#d66c3a` | `surface-alt` `#fbeee0` | grande/componente (≥ 3) | **3,03:1** | large accents on alternate rows |
| `on-primary` `#ffffff` | `primary-strong` `#b8542a` | texto (≥ 4,5) | **4,84:1** | label of a solid primary button |
| `primary-strong` `#b8542a` | `surface` `#fff7ed` | texto (≥ 4,5) | **4,56:1** | links and brand text on page |
| `primary-strong` `#b8542a` | `surface-raised` `#ffffff` | texto (≥ 4,5) | **4,84:1** | links and brand text on cards |
| `on-primary-soft` `#9a4520` | `primary-soft` `#fff0e5` | texto (≥ 4,5) | **5,81:1** | label of a primary chip or selected row |
| `text` `#3a2e28` | `primary-soft` `#fff0e5` | texto (≥ 4,5) | **11,77:1** | body text on a selected row |
| `text-muted` `#6b5d54` | `primary-soft` `#fff0e5` | texto (≥ 4,5) | **5,68:1** | secondary text on a selected row |
| `accent` `#7a9b76` | `surface` `#fff7ed` | decorativo (sin mínimo) | **2,92:1** | illustrations and stripes on page |
| `accent` `#7a9b76` | `surface-raised` `#ffffff` | grande/componente (≥ 3) | **3,10:1** | large icons on cards |
| `on-accent-soft` `#3f6e3b` | `accent-soft` `#edf2eb` | texto (≥ 4,5) | **5,28:1** | label of an accent chip |
| `text` `#3a2e28` | `accent-soft` `#edf2eb` | texto (≥ 4,5) | **11,55:1** | body text on an accent panel |
| `text-muted` `#6b5d54` | `accent-soft` `#edf2eb` | texto (≥ 4,5) | **5,57:1** | secondary text on an accent panel |
| `success` `#3f6e3b` | `surface` `#fff7ed` | texto (≥ 4,5) | **5,64:1** | success text on page |
| `success` `#3f6e3b` | `surface-raised` `#ffffff` | texto (≥ 4,5) | **5,99:1** | success text on cards |
| `success` `#3f6e3b` | `success-soft` `#e8efe6` | texto (≥ 4,5) | **5,11:1** | label of a success badge |
| `on-success` `#ffffff` | `success` `#3f6e3b` | texto (≥ 4,5) | **5,99:1** | label of a solid success control |
| `text` `#3a2e28` | `success-soft` `#e8efe6` | texto (≥ 4,5) | **11,19:1** | body text on a success notice |
| `text-muted` `#6b5d54` | `success-soft` `#e8efe6` | texto (≥ 4,5) | **5,40:1** | secondary text on a success notice |
| `warning` `#8a5312` | `surface` `#fff7ed` | texto (≥ 4,5) | **5,95:1** | warning text on page |
| `warning` `#8a5312` | `surface-raised` `#ffffff` | texto (≥ 4,5) | **6,31:1** | warning text on cards |
| `warning` `#8a5312` | `warning-soft` `#fdf0d5` | texto (≥ 4,5) | **5,59:1** | label of a warning badge |
| `on-warning` `#ffffff` | `warning` `#8a5312` | texto (≥ 4,5) | **6,31:1** | label of a solid warning control |
| `text` `#3a2e28` | `warning-soft` `#fdf0d5` | texto (≥ 4,5) | **11,62:1** | body text on a warning notice |
| `text-muted` `#6b5d54` | `warning-soft` `#fdf0d5` | texto (≥ 4,5) | **5,60:1** | secondary text on a warning notice |
| `danger` `#a52a1f` | `surface` `#fff7ed` | texto (≥ 4,5) | **6,70:1** | error text on page |
| `danger` `#a52a1f` | `surface-raised` `#ffffff` | texto (≥ 4,5) | **7,12:1** | error text on cards |
| `danger` `#a52a1f` | `danger-soft` `#fbe5e2` | texto (≥ 4,5) | **5,90:1** | label of an error badge |
| `on-danger` `#ffffff` | `danger` `#a52a1f` | texto (≥ 4,5) | **7,12:1** | label of a solid destructive button |
| `text` `#3a2e28` | `danger-soft` `#fbe5e2` | texto (≥ 4,5) | **10,87:1** | body text on an error notice |
| `text-muted` `#6b5d54` | `danger-soft` `#fbe5e2` | texto (≥ 4,5) | **5,25:1** | secondary text on an error notice |
| `focus` `#b8542a` | `surface` `#fff7ed` | grande/componente (≥ 3) | **4,56:1** | focus ring on page |
| `focus` `#b8542a` | `surface-raised` `#ffffff` | grande/componente (≥ 3) | **4,84:1** | focus ring on cards |
| `focus` `#b8542a` | `surface-alt` `#fbeee0` | grande/componente (≥ 3) | **4,24:1** | focus ring on alternate rows |
| `kiosk-text` `#fff7ed` | `kiosk-surface` `#241a15` | texto (≥ 4,5) | **16,03:1** | kiosk text on wall screen |
| `kiosk-text` `#fff7ed` | `kiosk-surface-raised` `#3a2e28` | texto (≥ 4,5) | **12,35:1** | kiosk text on raised panels |
| `kiosk-text-muted` `#d4c3b5` | `kiosk-surface` `#241a15` | texto (≥ 4,5) | **9,95:1** | kiosk secondary text |
| `kiosk-text-muted` `#d4c3b5` | `kiosk-surface-raised` `#3a2e28` | texto (≥ 4,5) | **7,66:1** | kiosk secondary text on panels |
| `kiosk-border` `#8a7a6f` | `kiosk-surface` `#241a15` | grande/componente (≥ 3) | **4,13:1** | kiosk control borders |
| `kiosk-border` `#8a7a6f` | `kiosk-surface-raised` `#3a2e28` | grande/componente (≥ 3) | **3,18:1** | kiosk control borders on panels |
| `kiosk-primary` `#d66c3a` | `kiosk-surface` `#241a15` | grande/componente (≥ 3) | **4,92:1** | kiosk large accents |
| `kiosk-primary` `#d66c3a` | `kiosk-surface-raised` `#3a2e28` | grande/componente (≥ 3) | **3,79:1** | kiosk large accents on panels |
| `kiosk-primary-strong` `#f0a27a` | `kiosk-surface` `#241a15` | texto (≥ 4,5) | **8,23:1** | kiosk accent text |
| `kiosk-primary-strong` `#f0a27a` | `kiosk-surface-raised` `#3a2e28` | texto (≥ 4,5) | **6,34:1** | kiosk accent text on panels |
| `kiosk-on-primary` `#241a15` | `kiosk-primary-strong` `#f0a27a` | texto (≥ 4,5) | **8,23:1** | label of a solid kiosk button |
| `kiosk-focus` `#fff7ed` | `kiosk-surface` `#241a15` | grande/componente (≥ 3) | **16,03:1** | kiosk focus ring |
| `kiosk-focus` `#fff7ed` | `kiosk-surface-raised` `#3a2e28` | grande/componente (≥ 3) | **12,35:1** | kiosk focus ring on panels |

**Parejas que no existen a propósito** (y que la prueba obliga a añadir, y medir, antes de usarlas): `primary-strong` como texto sobre `surface-alt` o sobre `primary-soft` (4,24 y 4,34:1, ambas por debajo); `accent` como componente sobre `surface`; cualquier texto sobre `primary` o sobre `accent` sólidos.

## 3. Tipografía

| Token | Valor | Uso |
|---|---|---|
| `--kq-font-heading` | `'Poppins', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif` | Títulos y cifras destacadas, pesos 600 y 700 |
| `--kq-font-body` | `'Inter Variable', 'Inter', ui-sans-serif, system-ui, …, sans-serif` | Todo lo demás, pesos 400 a 600 (Inter es variable) |

Las fuentes se **sirven desde la instalación** (`@fontsource/poppins`, `@fontsource-variable/inter`, importadas en `fonts.css`), nunca desde Google Fonts ni ningún CDN: el quiosco funciona sin red, el producto es *on-premise* (doc 01 §6.7) y una petición a un tercero desde el navegador del empleado sería una comunicación de datos que nadie ha autorizado (RGPD). Cada `@font-face` lleva `unicode-range`, así que el navegador descarga solo el subconjunto que usa: latin para español e inglés, latin-ext para catalán y rumano. Ninguna de las dos cubre árabe (doc 01 §6.6): ahí actúa la pila de respaldo del sistema, y por eso toda pila termina en `system-ui, …, sans-serif`.

Tamaños mínimos: en el quiosco, texto de confirmación ≥ 24 px y objetivos táctiles ≥ 48 px (RF-KI-06, doc 01 §6.5); esos tokens (`--text-confirm-*`, `--kiosk-touch-target`) siguen en `frontend-kiosk/src/assets/main.css` porque son accesibilidad, no marca.

## 4. Forma: radios y sombras

| Token | Valor | Utilidad Tailwind | Uso |
|---|---|---|---|
| `--kq-radius` | `14px` | `rounded-kq` | Tarjetas, diálogos, botones grandes |
| `--kq-radius-sm` | `8px` | `rounded-kq-sm` | Campos, chips, botones pequeños |
| `--kq-radius-lg` | `22px` | `rounded-kq-lg` | Paneles destacados del quiosco |
| `--kq-shadow-soft` | `0 8px 24px rgba(58,46,40,.08)` | `shadow-kq-soft` | Tarjetas en reposo |
| `--kq-shadow-raised` | `0 12px 32px rgba(58,46,40,.14)` | `shadow-kq-raised` | Diálogos y menús flotantes |

Las sombras tintan con el color de texto (`#3a2e28`), no con negro: es lo que mantiene la sensación cálida. Sin degradados fuertes.

## 5. Cómo se consume desde una SPA

En `src/assets/main.css` de cada aplicación, después de Tailwind:

```css
@import 'tailwindcss';
@import '@kronoqr/web-kit/theme.css';
```

Eso trae los tokens (`:root`), las fuentes (`fonts.css`), la capa base (`base.css`: fondo y color de `body`, encabezados, foco visible, `prefers-reduced-motion`) y el bloque `@theme inline` que expone los tokens como utilidades. **`inline` es deliberado**: la utilidad `bg-kq-surface` emite `background-color: var(--kq-color-surface)` y lee el valor vigente al pintar; con `@theme` a secas Tailwind copiaría el valor en su propia variable y la marca blanca (§7) no llegaría a las utilidades.

### 5.1 La capa base (`base.css`)

`body`, `h1`/`h2`/`h3`, `:focus-visible` y `prefers-reduced-motion` eran, hasta esta versión, texto idéntico en `main.css` del panel y del portal, y el quiosco repetía además su propia regla de `:focus-visible` con su propio color. `packages/web-kit/src/base.css` la declara una sola vez; `theme.css` la importa, así que basta con el `@import` de arriba.

El quiosco es la única SPA de fondo oscuro. En vez de que `base.css` decida por selector de aplicación, expone una indirección de tres (más una) tokens de página:

| Token | Por defecto (panel y portal) | Quién lo redefine |
|---|---|---|
| `--kq-page-bg` | `var(--kq-color-surface)` | `frontend-kiosk/src/assets/main.css` → `var(--kq-color-kiosk-surface)` |
| `--kq-page-text` | `var(--kq-color-text)` | → `var(--kq-color-kiosk-text)` |
| `--kq-page-focus` | `var(--kq-color-focus)` | → `var(--kq-color-kiosk-focus)` |
| `--kq-page-focus-offset` | `2px` | → `3px` (se opera con guantes, hace falta más separación) |

El quiosco redefine esos cuatro tokens en un `:root { … }` de su propio `main.css`, **después** del `@import '@kronoqr/web-kit/theme.css'`, para ganar la cascada sobre el valor por defecto de `base.css`. No repite la regla de `body` ni de `:focus-visible`: solo cambia a qué token apuntan.

Estos tokens no son marca ni paleta (no viven en `theme.css`, no los mide `themePairs.ts`): son una redirección hacia un token que ya está declarado y medido allí, y por eso no llevan el prefijo `--kq-color-` que exige ser hexadecimal.

Utilidades disponibles (verificado compilando con Tailwind 4.3 desde `frontend-admin`):

- **Colores**, con cualquier prefijo de color de Tailwind (`bg-`, `text-`, `border-`, `ring-`, `outline-`, `fill-`, `stroke-`, `decoration-`, `divide-`, `placeholder-`, `accent-`, `caret-`, `shadow-`), y con modificador de opacidad (`bg-kq-primary-strong/50` compila a `color-mix()`):
  `kq-surface`, `kq-surface-raised`, `kq-surface-alt`, `kq-text`, `kq-text-muted`, `kq-border`, `kq-border-strong`, `kq-primary`, `kq-primary-strong`, `kq-primary-soft`, `kq-on-primary`, `kq-on-primary-soft`, `kq-accent`, `kq-accent-soft`, `kq-on-accent-soft`, `kq-success`, `kq-success-soft`, `kq-on-success`, `kq-warning`, `kq-warning-soft`, `kq-on-warning`, `kq-danger`, `kq-danger-soft`, `kq-on-danger`, `kq-focus`, `kq-kiosk-surface`, `kq-kiosk-surface-raised`, `kq-kiosk-text`, `kq-kiosk-text-muted`, `kq-kiosk-border`, `kq-kiosk-primary`, `kq-kiosk-primary-strong`, `kq-kiosk-on-primary`, `kq-kiosk-focus`.
- **Tipografía**: `font-heading`, `font-body`.
- **Radios**: `rounded-kq`, `rounded-kq-sm`, `rounded-kq-lg` (y las variantes por lado y esquina de Tailwind: `rounded-t-kq`, `rounded-tl-kq-sm`…).
- **Sombras**: `shadow-kq-soft`, `shadow-kq-raised`.

El fondo y el color de `body`, la tipografía de encabezados, `:focus-visible` y `prefers-reduced-motion` **ya no los declara cada SPA**: son la capa base (§5.1), común a las tres. Lo único que sigue en `main.css` de cada aplicación es lo que de verdad le es exclusivo: el quiosco redefine los tokens de página a los tokens oscuros (`--kq-page-*`, §5.1) y conserva sus tokens de accesibilidad (`--text-confirm-*`, `--kiosk-touch-target`) y sus cinco colores de confirmación; el panel y el portal no añaden nada. Ninguna SPA declara un color propio: si una pantalla necesita un tono nuevo, es un token nuevo en `theme.css` con su pareja en `themePairs.ts`.

## 6. Reglas de uso

1. **Un botón primario por pantalla** (`bg-kq-primary-strong text-kq-on-primary`). Las acciones secundarias van con borde (`border-kq-border-strong text-kq-text`) o como texto (`text-kq-primary-strong`).
2. **Las acciones destructivas van en peligro** (`bg-kq-danger text-kq-on-danger`) y piden confirmación con el patrón «qué cambia, desde qué, hacia qué» que ya tiene cada SPA. Nunca se disfrazan de primario.
3. **El terracota claro (`kq-primary`) no lleva texto encima** ni es texto de tamaño normal. Es para iconos, franjas, cifras grandes y decoración. Enlaces y texto de marca: `kq-primary-strong`.
4. **La salvia (`kq-accent`) es decorativa**: ilustraciones, franjas, iconos grandes sobre blanco. Un estado correcto es `kq-success`, no la salvia.
5. **Estados**: badge = `bg-kq-{estado}-soft text-kq-{estado}` (con `kq-on-accent-soft` en el caso del acento); control sólido = `bg-kq-{estado} text-kq-on-{estado}`; texto suelto = `text-kq-{estado}`. Nunca un estado solo por color: siempre con icono o texto (WCAG 1.4.1).
6. **Bordes**: `kq-border` separa; `kq-border-strong` delimita un control. Un campo de formulario con `kq-border` no se ve como un campo (1,3:1).
7. **Foco visible** en todo control, con `--kq-page-focus` (que resuelve a `--kq-color-focus`, o a `--kq-color-kiosk-focus` en el quiosco) y desplazamiento `--kq-page-focus-offset` ≥ 2 px (WCAG 2.4.7 y 2.4.11). El quiosco lo dibuja en crema y grueso (3 px) porque se opera con guantes; la regla vive una sola vez en `base.css` (§5.1), ninguna SPA la repite.
8. **Jerarquía antes que decoración**: títulos en `font-heading`, resto en `font-body`. Lo que no se puede usar no se muestra. Los estados vacío, de carga y de error están diseñados (`EmptyState`, `LoadingPanel`, `ErrorNotice` del paquete) y dicen qué ha pasado y qué hacer.
9. **Cada aplicación conserva su contexto** (doc 01 §3.7 y §6.5): el quiosco es oscuro, grande y táctil; el panel es denso y legible en tablas; el portal se lee de un vistazo desde el móvil, con el registro horario como protagonista.
10. **Respeta `prefers-reduced-motion`** y no uses animación como único canal de información.

## 7. Relación con la marca blanca (tarea 5.8, RF-PD-08)

Lo diseñado aquí es **el valor por defecto** que esa tarea hará configurable. Está preparado para que no haya que tocar `theme.css`:

- Todo lo que un cliente puede cambiar es una *custom property* `--kq-*` en `:root`. La tarea 5.8 recibirá la marca del endpoint de configuración y la aplicará **en tiempo de ejecución** (`document.documentElement.style.setProperty('--kq-color-primary-strong', …)` o una hoja `:root { … }` inyectada). Recompilar por cliente sería una variante del producto (ADR-017).
- Como las utilidades son `@theme inline`, esa sobreescritura llega a todas las clases `*-kq-*` sin más.
- **Contraste avisado, no impuesto** (paso 9 de 5.8): la tarea reutiliza `themePairs.ts` y `contrast.ts` para pasar los colores del cliente por las mismas parejas y avisar de las que no alcanzan el mínimo. La tabla de §2 es la lista de comprobaciones.
- **Qué no es marca y no se configura**: los cinco colores de confirmación del quiosco (§1.2), los tokens de accesibilidad del quiosco (tamaños mínimos y objetivo táctil), los colores de estado `success`/`warning`/`danger` en su papel semántico (un cliente puede afinar el tono, pero verde sigue significando correcto y rojo peligro), y los identificadores técnicos (`FH1`, nombres de tabla, rutas).
- El logotipo y el nombre de la aplicación no son parte de este documento: son ficheros y cadenas configuradas (`BRANDING_LOGO_PATH`, `BRANDING_APP_NAME`), y ningún componente lleva el nombre del producto escrito a mano.

## 8. Verificación

```bash
cd packages/web-kit && npm run type-check && npm run lint && npm run test:unit
```

`theme.spec.ts` lee `theme.css` y comprueba: cada pareja de `themePairs.ts` alcanza su mínimo; todo token de color está en al menos una pareja; todo token tiene su utilidad `@theme inline` apuntando a él y ninguna utilidad apunta a un token inexistente; los colores son hexadecimales (sobreescribibles y medibles); y ni `theme.css` ni `fonts.css` cargan nada por `http(s)://`. `contrast.spec.ts` valida la fórmula contra valores publicados (negro/blanco 21:1, `#767676` 4,54:1). `base.spec.ts` comprueba que `base.css` existe y no contiene hexadecimales sueltos (solo `var(--kq-*)`), que `theme.css` lo importa, y lee el `main.css` de `frontend-admin`, `frontend-portal` y `frontend-kiosk` para verificar que ninguno vuelve a declarar la capa base ni un `:focus-visible` con un color literal.
