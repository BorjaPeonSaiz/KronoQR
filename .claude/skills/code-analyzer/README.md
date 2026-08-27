# Code Analyzer Skill for KronoQR Project

Esta skill está diseñada para analizar el proyecto KronoQR en busca de duplicaciones, problemas en la lógica de dominio, fallos en el manejo de excepciones y posibles optimizaciones.

## Funcionalidades

- **Análisis de Duplicaciones**: Detecta patrones repetidos en estructuras de DTOs y handlers
- **Verificación de Lógica de Dominio**: Revisa la consistencia y corrección de la lógica de negocio
- **Análisis de Excepciones**: Identifica problemas en el manejo de errores y excepciones
- **Optimizaciones Potenciales**: Sugiere mejoras en rendimiento y uso de recursos

## Uso

Para ejecutar el análisis:

```bash
claude analyze-code --project-path /ruta/al/proyecto --analysis-type all
```

Opciones para `analysis-type`:
- `duplication`: Solo búsqueda de duplicaciones
- `domain_logic`: Análisis de lógica de dominio
- `exceptions`: Verificación del manejo de excepciones
- `optimizations`: Búsqueda de optimizaciones
- `all`: Ejecuta todos los análisis (por defecto)

## Ejemplo de salida

```json
{
  "findings": [
    {
      "type": "validation_pattern_duplication",
      "description": "Se detectaron patrones de validación comunes que podrían ser refactorizados en una clase base",
      "severity": "medium",
      "suggestion": "Crear una clase base común para validaciones de entrada"
    }
  ],
  "severity_summary": {
    "high": 1,
    "medium": 1,
    "low": 0
  }
}
```