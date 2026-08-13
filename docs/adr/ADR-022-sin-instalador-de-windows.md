# ADR-022 — No se entrega instalador de Windows

| Campo | Valor |
|---|---|
| **Estado** | Aceptada |
| **Fecha** | 12 de agosto de 2026 |
| **Decide** | `producto-licencia` |
| **Afecta a** | Tareas 5.4 y 5.11 · [documento 02](../02-stack-tecnologico-y-plan-implementacion.md) §11.6.1 |
| **Requisitos** | RF-PD-02, RQ-11, RNF-M-06 |

## Contexto

El [documento 02](../02-stack-tecnologico-y-plan-implementacion.md) §11.6.1 lista entre los entregables al cliente `install.sh / install.ps1`, es decir, un instalador de shell y otro de PowerShell. Al desarrollar la tarea 5.4 del plan apareció que ese segundo instalador **no tiene dónde apoyarse**:

- **Los requisitos publicados no contemplan Windows.** El §11.6.2 exige «Linux con Docker 24+ y Compose v2», y el documento 05 §10.1 repite lo mismo al cliente. Un instalador para un sistema operativo que la documentación declara no soportado es una promesa que nadie ha decidido hacer.
- **No hay cadena de calidad que lo verifique.** El §3.5 define convenciones para los scripts de operación —`set -euo pipefail`, `IFS`, guía de estilo de Shell de Google, `shfmt -i 2`— y las ata a ShellCheck y shfmt. Ninguna de las dos herramientas analiza `.ps1`. El §9.2 fija el umbral bloqueante en «0 hallazgos» de ShellCheck y `shfmt -i 2 -d` **aplicado a `infra/scripts/` y a los scripts entregados al cliente**: un `.ps1` pasaría esa puerta sin ser examinado.
- **La etapa 8 de la CI probaría una sola vía.** El §10.1 exige verificar instalación limpia y actualización antes de publicar. Verificarlas en Linux y no en Windows dejaría un entregable sin cobertura, contra RQ-11.

El principio del agente `producto-licencia` es tajante y aquí se aplica en su forma más literal: *un instalador que falla a medias es peor que uno que no arranca.* Un instalador que además nadie revisa ni prueba es la peor de las tres opciones.

## Decisión

**Se retira `install.ps1` del paquete de entrega.** El producto se instala en Linux con Docker y Compose, tal como declaran los requisitos publicados. El paquete del §11.6.1 entrega `install.sh` y ningún equivalente de PowerShell.

Soportar Windows Server sería una **decisión de producto**, no un fichero más: exigiría ampliar los requisitos publicados, elegir y configurar un analizador estático y un formateador para PowerShell, atarlos al §9.2 con su umbral bloqueante, y duplicar la etapa 8 de la CI para probar instalación y actualización en ese sistema. Si algún día un cliente lo impone, se toma con su propio ADR y su propio coste.

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **Mantener `install.ps1` sin verificación** | Un entregable que ninguna herramienta revisa y ninguna etapa de CI prueba, en manos de un IT que no conoce el producto. Es el modo de fallo que el §3.5 existe para evitar |
| **Mantenerlo marcado como «no soportado»** | Un fichero llamado `install.ps1` dentro del paquete se ejecuta, diga lo que diga el `README`. La documentación no protege de lo que el paquete invita a hacer |
| **Ampliar el soporte a Windows ahora** | Coste real —requisitos, cadena de calidad, segunda etapa 8— para una demanda que ningún cliente ha expresado. Y el §11.6.2 mide el producto contra un servidor Linux desde el principio |

## Consecuencias

- **El §11.6.1 del documento 02 cambia:** el árbol del paquete de entrega pierde `/ install.ps1`.
- **La documentación de instalación (tarea 5.11) enuncia el requisito sin ambigüedad**, en lugar de dejar al lector deducirlo de la ausencia de un fichero.
- **Un cliente con solo infraestructura Windows no puede instalar el producto tal cual.** La vía practicable es una máquina virtual Linux o WSL 2, y eso se dice en la documentación en lugar de descubrirse durante la instalación. Es un límite conocido y declarado, no un fallo.
- **La máquina de desarrollo sigue siendo Windows**, y no hay contradicción: el desarrollo ocurre dentro de contenedores Linux, y el entorno de trabajo del desarrollador no es un entregable del producto.
- **La etapa 8 de la CI queda con una sola vía que verificar**, que es la que se entrega.

## Verificación

- El paquete generado por `release.yml` no contiene ningún fichero `.ps1`.
- ShellCheck y `shfmt -i 2 -d` cubren el 100 % de los scripts del paquete, sin exclusiones.
- La etapa 8 prueba instalación limpia y actualización desde cada versión soportada, en Linux, y es verde antes de publicar (RQ-11).
