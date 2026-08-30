# Verificaciones manuales de KronoQR

Lo que **ninguna prueba automatizada puede afirmar** y aun así forma parte de la
Definición de Terminado (doc 02 §10.3).

Esta carpeta de procedimientos existe por la misma razón que los runbooks: una
comprobación que no está escrita no se hace, y una que se hace y no se registra
no se puede volver a hacer igual dentro de seis meses. La norma es la misma que
gobierna las convenciones de código (doc 02 §3.5): **una comprobación que no
verifica una herramienta es una sugerencia** — y cuando la herramienta no puede
existir, lo mínimo es que el procedimiento sea literal y que quede constancia de
quién lo pasó y con qué versión.

Cada verificación dice: **qué requisito cubre**, **por qué no se automatiza**,
**cómo se prepara el material**, **qué hay que mirar exactamente** y **qué
resultado se registra**.

---

## VM-01 — El informe exportado abre bien en Excel y en LibreOffice

| | |
|---|---|
| **Requisito** | RF-IN-04 (tarea 2.9) |
| **Quién** | `qa-testing`, en el cierre de la Fase 2 y en cada cambio del escritor de CSV o de XLSX |
| **Frecuencia** | Al cerrar la fase, y ante cualquier cambio en `CsvDialect`, `PeriodReportCsv`, `PeriodReportXlsx` o `PeriodReportLayout` |

### Por qué no se automatiza

La suite ya comprueba todo lo que es comprobable desde PHP: que el CSV lleva BOM,
que el separador depende del idioma, que el fin de línea es `\r\n`, que las
duraciones son texto `HH:MM` y que el XLSX se vuelve a abrir con la misma
librería que lo escribió y contiene las celdas esperadas
(`tests/Feature/Reporting/PeriodReportExportTest.php`,
`tests/Unit/Shared/Infrastructure/CsvDialectTest.php`).

Lo que **no** puede afirmar ninguna de esas pruebas es lo único que le importa a
quien recibe el fichero: **qué se ve al abrirlo con doble clic**. La codificación,
el separador y la interpretación de las celdas las decide el programa de hoja de
cálculo a partir de la configuración regional del ordenador de esa persona, no el
fichero. Un CSV correcto byte a byte se abre con todas las columnas en una sola
celda si la configuración regional no es la que el separador espera, y un
`07:45` correcto se convierte en una hora del reloj —o en `#####`— según el
programa. Reproducir eso exigiría instalar Excel en la CI, que no es viable ni
legalmente cómodo.

### Material

Se generan sobre la semilla del entorno de desarrollo, con el sistema levantado:

```bash
make up
docker compose --env-file .env -f infra/compose.dev.yaml exec -T app \
  php artisan tinker --execute="require '/tmp/muestras.php';" < /dev/null
```

…o, más sencillo y más parecido a lo que hace una persona, **desde el panel**:
entrar como RRHH, ir a *Informes*, generar un mes con granularidad **Mes** y
descargar los tres formatos con los botones de «Descargar este informe».

Interesa **el informe mensual y no el diario**: el diario de una plantilla real
son varios miles de filas y el PDF resultante pasa de 30 MB, que no es un
documento que nadie vaya a imprimir (ver «Deuda conocida», abajo).

### Qué hay que mirar, exactamente

**CSV, en Excel con configuración regional española:**

1. Abrir con **doble clic**, no con «Datos → Obtener datos». El doble clic es lo
   que hace una persona, y es donde se nota si falta el BOM o si el separador no
   es el que Excel espera.
2. **Los acentos.** La cabecera dice `Desviación`, `Días con actividad`,
   `Código de empleado`. Si sale `DesviaciÃ³n`, falta el BOM.
3. **Las columnas separadas**, una por celda. Si todo el contenido cae en la
   columna A, el separador no es el que espera esa configuración regional.
4. **Las horas como texto.** `162:00`, `-68:34`, `00:00`. Ninguna celda alineada
   a la derecha como número, ninguna con formato de hora, ninguna con coma
   decimal y **ninguna con un apóstrofo delante** (`'-68:34` es un fallo:
   ver `CsvDialect::neutralized()`).
5. **El bloque de criterios**, arriba, antes de la línea en blanco: periodo,
   zona horaria, emisor, huella y la lista de criterios de inclusión.
6. **La huella** (`Huella SHA-256 del contenido`) coincide con la del XLSX y con
   la del pie del PDF descargados del **mismo** informe.

**CSV, en LibreOffice Calc:** el diálogo de importación debe llegar ya con
*Juego de caracteres: Unicode (UTF-8)* y el separador correcto marcados. Si hay
que cambiarlos a mano, el fichero está mal.

**CSV, con la instalación en inglés** (`APP_LOCALE=en`): el separador tiene que
ser `,` y no `;`. Es el caso que rompe si alguien «arregla» el separador
fijándolo.

**XLSX, en Excel y en LibreOffice Calc:**

1. Dos hojas: **Horas** y **Criterios**.
2. En *Horas*, **la cabecera se queda fija** al desplazarse hacia abajo.
3. **Los anchos de columna** son legibles: ninguna fecha en `#####`, ningún
   nombre cortado a la mitad.
4. **Las duraciones son texto**: pulsar en una celda de `Trabajado` y comprobar
   que la barra de fórmulas muestra `162:00` y no `1899-12-30 18:00` ni `6,75`.
5. Ningún aviso de «archivo dañado» al abrir.

**PDF, en cualquier visor:**

1. **El pie aparece en todas las páginas**, no solo en la última: fecha de
   generación en la zona del centro, emisor, periodo, número de página y huella.
2. La fecha del pie es la **hora local del centro**, no UTC.
3. La tabla **repite su cabecera** en cada página.
4. Ninguna fila partida por la mitad entre dos páginas.

### Qué se registra

En el acta de cierre de fase: fecha, versión del producto, versión de Excel y de
LibreOffice usadas, configuración regional del equipo, y **la huella de los
ficheros verificados**. Con la huella, cualquiera puede volver a generar el mismo
informe y comprobar que se está hablando de los mismos datos.

### Deuda conocida

- **El PDF de un informe diario de plantilla completa es enorme.** Medido sobre
  la semilla de desarrollo: 6.168 filas → 38 MB y varios cientos de páginas. No
  es un documento que nadie vaya a imprimir. El producto no lo impide hoy porque
  el techo síncrono de `config/reporting.php` es el mismo para los tres formatos;
  si se decide acotar el PDF por separado, es una decisión de producto y necesita
  su requisito, no un número puesto aquí.
- **La verificación es manual y por tanto puntual.** Lo que sí está automatizado
  —y es lo que evita la regresión silenciosa— son los bytes: BOM, separador, fin
  de línea, neutralización de fórmulas y celdas de texto del XLSX.
