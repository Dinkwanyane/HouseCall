<?php
$pageTitle = 'Home';
require_once 'includes/header.php';
?>
<section class="hero">
  <h1>HouseCall</h1>
  <p>Find a trusted plumber, gardener, cleaner or tutor near you and book them for the day you need, in a few simple steps.</p>
  <div class="btn-row" style="justify-content:center">
    <a class="btn btn-accent" href="<?= $u && $u['role'] === 'client' ? 'client.php' : 'login.php?role=client' ?>">Make a booking</a>
    <a class="btn btn-outline" href="about.php">About HouseCall</a>
  </div>
</section>

<h2>How it works</h2>
<div class="grid">
  <div class="card"><h3>1. Search</h3><p>Choose a service and your area, then compare rates, ratings and availability.</p></div>
  <div class="card"><h3>2. Book</h3><p>Pick a day, describe the job and add pictures if you like.</p></div>
  <div class="card"><h3>3. Confirmed</h3><p>The service provider is notified and confirms. You get a notification straight away.</p></div>
</div>

<h2 id="join">Create an account</h2>
<?php if ($u): ?>
  <p class="card">You are logged in as <strong><?= e($u['name']) ?></strong>. <a href="<?= e(dashboard_for($u['role'])) ?>">Go to your dashboard</a>.</p>
<?php else: ?>
<div class="grid">
  <div class="card role-card">
    <h3>I need a service</h3>
    <p>Create a <strong>client</strong> account to find and book service providers.</p>
    <a class="btn" href="register.php?role=client">Sign up as a client</a>
  </div>
  <div class="card role-card">
    <h3>I offer a service</h3>
    <p>Create a <strong>service provider</strong> account to receive bookings and advertise your work.</p>
    <a class="btn" href="register.php?role=provider">Sign up as a service provider</a>
  </div>
  <div class="card role-card">
    <h3>Already registered?</h3>
    <p>Log in as a client, service provider or administrator.</p>
    <a class="btn btn-outline" href="login.php">Log in</a>
  </div>
</div>
<?php endif; ?>
<?php require_once 'includes/footer.php'; ?>