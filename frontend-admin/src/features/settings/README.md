# settings

Configuracion de la instalacion, perfil de cumplimiento, **licencia** y marca
(RF-PD-01, RF-PD-04, RF-PD-05, RF-PD-07, RF-PD-08). Tareas 5.1, 5.2, 5.3 y 5.8.

Hoy viven aqui dos pantallas:

- **`ComplianceProfileView.vue`** — los umbrales **legales** del centro
  (tarea 5.2).
- **`LicenseView.vue`** — el estado de la licencia y la activacion de una clave
  (tarea 5.3), con su `license.store.ts` y su `license.api.ts`.

Y un componente que **no** es una pantalla: **`LicenseNotice.vue`**, el aviso
persistente que `AppShellView` pinta en **todas** las secciones del panel. Vive
aqui y no en `shared/ui` porque su contenido es de esta _feature_ —comparte
store, textos y destino con la pantalla de licencia—; lo unico que aporta el
marco es el sitio donde colgarlo.

La configuracion de la instalacion (marca, idiomas y umbrales operativos) es
backend y contrato desde la 5.1 y **todavia no tiene pantalla**: llega con la 5.8.

Los tres son recursos distintos a proposito: un umbral legal lo fija la
jurisdiccion, uno operativo lo fija el hotel (doc 01 §4) y la licencia dice **que
se contrato**, que no es un ajuste sino un hecho comercial — por eso tiene ambito
propio (`license:*`) y no `settings:*`.

> **Lo que ninguna de estas pantallas puede hacer:** apagar el registro horario.
> La de licencia lo dice en voz alta, arriba y en todos los estados, porque quien
> llega a ella suele llegar por un aviso y esa es la pregunta que trae
> (ADR-019, regla dura 15).

Carpeta por _feature_, no por tipo de fichero (doc 02 §3.5).
