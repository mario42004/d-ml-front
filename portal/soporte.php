<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';

require_role('soporte');
render_app_header('d-ml | Portal soporte');
?>
<section class="page-stack">
  <section class="hero">
    <div class="portal-hero">
      <div>
        <span class="role-badge">Soporte</span>
        <h1>Un espacio pensado para cuidar la operación sin perder contexto.</h1>
        <p class="lead">Aquí se concentrará el seguimiento de incidencias, el apoyo operativo y la continuidad del flujo cuando algo necesite atención.</p>
      </div>
      <aside class="route-card">
        <strong>Acceso confirmado</strong>
        <code>/portal/soporte.php</code>
        <p class="route-note">Este entorno está reservado para el seguimiento tecnico y operativo del sistema.</p>
      </aside>
    </div>
  </section>

  <section class="portal-card-grid">
    <article class="card">
      <span class="tag">Operación</span>
      <h3>Incidencias abiertas</h3>
      <p>Aquí se reunirán los casos que necesitan revisión o apoyo operativo.</p>
    </article>
    <article class="card">
      <span class="tag">Pipeline</span>
      <h3>Reprocesado</h3>
      <p>Herramientas para volver a lanzar procesos cuando un caso lo necesite.</p>
    </article>
    <article class="card">
      <span class="tag">Diagnostico</span>
      <h3>Logs y estado</h3>
      <p>Informacion tecnica para entender rapido que está pasando en el sistema.</p>
    </article>
    <article class="card">
      <span class="tag">Escalado</span>
      <h3>Clasíficacion tecnica</h3>
      <p>Una forma más clara de ordenar incidencias y decidir el siguiente paso.</p>
    </article>
  </section>
</section>
<?php render_app_footer(); ?>
