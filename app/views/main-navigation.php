<?php $baseUrl = rtrim($config['app']['base_url'], '/'); ?>

<nav aria-label="Glavna navigacija">
    <a class="nav-link <?= $activeNavigation === 'dashboard' ? 'active' : '' ?>" href="<?= $baseUrl ?>/dashboard.php" <?= $activeNavigation === 'dashboard' ? 'aria-current="page"' : '' ?>>Dashboard</a>
    <a class="nav-link <?= $activeNavigation === 'contacts' ? 'active' : '' ?>" href="<?= $baseUrl ?>/contacts.php" <?= $activeNavigation === 'contacts' ? 'aria-current="page"' : '' ?>>Kontakti</a>
    <a class="nav-link <?= $activeNavigation === 'cities' ? 'active' : '' ?>" href="<?= $baseUrl ?>/cities.php" <?= $activeNavigation === 'cities' ? 'aria-current="page"' : '' ?>>Gradovi</a>
    <a class="nav-link <?= $activeNavigation === 'tags' ? 'active' : '' ?>" href="<?= $baseUrl ?>/tags.php" <?= $activeNavigation === 'tags' ? 'aria-current="page"' : '' ?>>Tagovi</a>
</nav>
