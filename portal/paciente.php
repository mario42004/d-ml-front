<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';

require_role('paciente');
render_app_header('d-ml | Portal paciente');
?>
<section class="page-stack">
  <section class="hero">
    <div class="portal-hero">
      <div>
        <span class="role-badge">Paciente</span>
        <h1>Tu espacio para seguir el estudio con mas claridad.</h1>
        <p class="lead">Aqui podras consultar el avance del estudio y la informacion que el equipo decida compartir contigo de forma simple y comprensible.</p>
      </div>
      <aside class="route-card">
        <strong>Acceso confirmado</strong>
        <code>/portal/paciente.php</code>
        <p class="route-note">Este entorno esta reservado para el seguimiento del estudio y la informacion habilitada para ti.</p>
      </aside>
    </div>
  </section>

  <section class="portal-card-grid">
    <article class="card">
      <span class="tag">Seguimiento</span>
      <h3>Estado del estudio</h3>
      <p>Podras ver en que punto se encuentra tu estudio de forma sencilla.</p>
    </article>
    <article class="card">
      <span class="tag">Resultados</span>
      <h3>Informacion habilitada</h3>
      <p>Aqui se mostrara la informacion que el equipo clinico habilite para ti.</p>
    </article>
    <article class="card">
      <span class="tag">Mensajes</span>
      <h3>Comunicacion del equipo</h3>
      <p>Mensajes utiles para mantenerte informado en cada etapa.</p>
    </article>
    <article class="card">
      <span class="tag">Historial</span>
      <h3>Linea temporal</h3>
      <p>Un resumen claro de fechas, hitos y documentos disponibles.</p>
    </article>
  </section>
</section>
<?php render_app_footer(); ?>
