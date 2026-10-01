<nav aria-label="Glavna navigacija">
    <a class="nav-link <?= $activeNavigation === 'dashboard' ? 'active' : '' ?>" href="/dashboard.php" <?= $activeNavigation === 'dashboard' ? 'aria-current="page"' : '' ?>>Dashboard</a>
    <a class="nav-link <?= $activeNavigation === 'contacts' ? 'active' : '' ?>" href="/contacts.php" <?= $activeNavigation === 'contacts' ? 'aria-current="page"' : '' ?>>Kontakti</a>
    <a class="nav-link <?= $activeNavigation === 'cities' ? 'active' : '' ?>" href="/cities.php" <?= $activeNavigation === 'cities' ? 'aria-current="page"' : '' ?>>Gradovi</a>
</nav>
