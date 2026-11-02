<?php
$pageTitle = 'About';
require_once 'includes/header.php';
?>
<h1>About HouseCall</h1>
<div class="card">
  <p>HouseCall links households with trusted local service providers in real time. We are built to be simple: large text, clear buttons and calm colours, so everyone can use it comfortably on a phone or a computer.</p>
</div>
<h2>Services</h2>
<div class="grid">
  <?php foreach (['🔧 Plumbers' => 'Leaks, geysers, blocked drains and repairs.', '🌱 Gardeners' => 'Lawn care, planting and garden clean-ups.', '🧹 Cleaners' => 'Regular and once-off home cleaning.', '📚 Tutors' => 'High school and tertiary level subjects.'] as $t => $d): ?>
    <div class="card"><h3><?= e($t) ?></h3><p><?= e($d) ?></p></div>
  <?php endforeach; ?>
</div>
<h2>Where we work</h2>
<p class="card"><?= e(implode(', ', AREAS)) ?>.</p>
<h2>Who uses HouseCall</h2>
<div class="grid">
  <div class="card"><h3>Clients</h3><p>Search by service, see who is available, describe the job with pictures, book and rate the service.</p></div>
  <div class="card"><h3>Service providers</h3><p>Show your rates, areas and working days, receive bookings, confirm them and advertise your work.</p></div>
  <div class="card"><h3>Administrators</h3><p>Look after accounts, track service delivery and view reports.</p></div>
</div>
<p><a class="btn" href="index.php#join">Create an account</a></p>
<?php require_once 'includes/footer.php'; ?>