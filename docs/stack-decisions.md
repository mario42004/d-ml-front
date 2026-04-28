# Decisiones de stack

## Version 1

### Elegido

- Frontend: Next.js + TypeScript
- Backend API: FastAPI
- ORM: SQLAlchemy
- Base de datos: MySQL 8
- Cola y estados rapidos: Redis
- Contenedores: Docker Compose
- Procesamiento: servicios Python especializados en analisis

### Motivos

- Next.js permite construir una interfaz moderna y clara con rapidez.
- FastAPI encaja muy bien con APIs de subida de archivos y jobs asincronos.
- MySQL ya esta disponible y validado en el entorno.
- Redis simplifica la gestion de trabajos y estados intermedios.
- Docker Compose facilita el desarrollo local y la integracion entre servicios.

### Evitamos por ahora

- Monolito PHP en `html/`
- Logica pesada de procesamiento dentro del frontend
- Mezclar productos activos con codigo legado no mantenido
- Despliegue principal sobre hosting FTP

### Objetivo del siguiente paso

Definir el esquema inicial de base de datos y el contrato minimo de la API.
