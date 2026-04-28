# d-ml-front

Portal PHP de `d-ml`.

Este proyecto contiene la capa web publica y privada del portal:

- landing y paginas de autenticacion
- dashboard y vistas por rol
- modulo `Audioprint`
- integracion con la API publica `https://api.d-ml.eu/audioanalisys`

## Configuracion

Copiar `.env.example` a `.env` en el entorno de despliegue y ajustar credenciales reales.

Variables relevantes para Audioprint:

```env
AUDIOPRINT_AUDIOANALISYS_API_URL=https://api.d-ml.eu/audioanalisys
AUDIOPRINT_MAX_UPLOAD_MB=25
AUDIOPRINT_UPLOAD_TIMEOUT_SECONDS=120
```

## Estructura

- `assets/`: CSS y JavaScript del portal
- `config/`: bootstrap, entorno y conexion de base de datos
- `db/`: schema y migraciones SQL
- `includes/`: componentes compartidos y modulos
- `portal/`: paginas internas por funcionalidad/rol
- `storage/`: carpetas runtime ignoradas salvo `.gitkeep`
- `workers/`: espacio reservado para procesos auxiliares
