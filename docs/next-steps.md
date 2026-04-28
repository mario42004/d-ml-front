# Siguientes pasos

## Prioridades inmediatas

- Consolidar `Audioprint` como solucion principal del portal
- Persistir mejor el historico de analisis para tendencias y deteccion de anomalias
- Endurecer la administracion global de usuarios y permisos
- Preparar una estrategia clara de despliegue para API y portal
- definir el MVP integrado de `Smart Tales` sobre el sistema comun de productos y acceso

## Frente de solucion

- Afinar la experiencia de `Audioprint` con mas contexto historico y comparativas
- Definir alertas simples sobre drift, clipping, silencio y cambios de patron
- Separar claramente panel, analisis por audio y vistas de tendencia
- cerrar el contrato de `Smart Tales` para perfiles infantiles, voces autorizadas, generacion de cuentos y biblioteca de audio

## Frente tecnico

- Revisar migraciones y semillas para dejar solo productos activos
- Eliminar restos de codigo legado que ya no formen parte del stack actual
- Documentar mejor el contrato entre PHP y `audio_scalogram_api`
- desacoplar los servicios de LLM, TTS y almacenamiento de `Smart Tales` para que el portal no cargue logica pesada
