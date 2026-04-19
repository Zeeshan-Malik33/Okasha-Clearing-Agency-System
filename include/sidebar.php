<?php
// Get current page
$currentPage = basename($_SERVER['REQUEST_URI']);
$currentPage = explode('?', $currentPage)[0]; // Remove query string

function isActive($page, $currentPage) {
    return $page === $currentPage || ($page === 'dashboard.php' && $currentPage === '');
}

function getActiveClass($page, $currentPage) {
    return isActive($page, $currentPage) ? 'bg-primary/10 text-primary font-semibold' : 'text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800';
}
?>

<div class="flex h-screen overflow-hidden">
<!-- SideNavBar -->
<aside id="sidebar" class="w-64 border-r border-slate-200 dark:border-slate-800 flex flex-col h-screen bg-white dark:bg-slate-900 transition-all duration-300">
    <!-- Logo Section -->
    <div class="pt-4 pb-4 px-6">
        <div class="glass-effect p-3 rounded-xl border border-white/40 shadow-sm flex items-center gap-3">
            <?php 
            $systemName = getSystemSetting('system_name', 'Container Hub');
            $systemLogo = getSystemSetting('system_logo');
            ?>
            <?php if (!empty($systemLogo)): ?>
            <div class="w-10 h-10 rounded-lg bg-primary flex items-center justify-center text-white shrink-0 overflow-hidden">
                <img src="uploads/system/<?= htmlspecialchars($systemLogo) ?>" 
                     alt="<?= htmlspecialchars($systemName) ?>" 
                     class="w-full h-full object-contain">
            </div>
            <?php else: ?>
            <div class="w-10 h-10 rounded-lg bg-primary flex items-center justify-center text-white shrink-0">
                <span class="material-symbols-outlined">clear_all</span>
            </div>
            <?php endif; ?>
            <div class="overflow-hidden sidebar-brand-text">
                <h1 class="text-sm font-bold truncate"><?= htmlspecialchars($systemName) ?></h1>
                <p class="text-[10px] text-slate-500 uppercase tracking-wider sidebar-brand-subtitle">Management Portal</p>
            </div>
        </div>
    </div>

    <!-- Navigation -->
    <nav class="px-4 space-y-1">
        <a href="dashboard.php" 
           data-page="dashboard.php"
           title="Dashboard"
           class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg transition-all cursor-pointer <?= getActiveClass('dashboard.php', $currentPage) ?>">
            <span class="material-symbols-outlined">dashboard</span>
            <span class="text-sm sidebar-label">Dashboard</span>
        </a>
        <a href="customers.php" 
           data-page="customers.php"
           title="Customers"
           class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg transition-all cursor-pointer <?= getActiveClass('customers.php', $currentPage) ?>">
            <span class="material-symbols-outlined">person</span>
            <span class="text-sm sidebar-label">Customers</span>
        </a>
        <a href="partners.php" 
           data-page="partners.php"
           title="Partners"
           class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg transition-all cursor-pointer <?= getActiveClass('partners.php', $currentPage) ?>">
            <span class="material-symbols-outlined">groups</span>
            <span class="text-sm sidebar-label">Partners</span>
        </a>
        <a href="agents.php" 
           data-page="agents.php"
           title="Agents"
           class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg transition-all cursor-pointer <?= getActiveClass('agents.php', $currentPage) ?>">
            <span class="material-symbols-outlined">support_agent</span>
            <span class="text-sm sidebar-label">Agents</span>
        </a>
        <?php if ($_SESSION['role'] === 'admin' || getSystemSetting('show_expense_page', '1') === '1'): ?>
        <a href="daily-expenses.php" 
           data-page="daily-expenses.php"
           title="Daily Expenses"
           class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg transition-all cursor-pointer <?= getActiveClass('daily-expenses.php', $currentPage) ?>">
            <span class="material-symbols-outlined">payments</span>
            <span class="text-sm sidebar-label">Daily Expenses</span>
        </a>
        <?php endif; ?>
        <?php if ($_SESSION['role'] === 'admin'): ?>
        <a href="rate-list.php" 
           data-page="rate-list.php"
           title="Rate List"
           class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg transition-all cursor-pointer <?= getActiveClass('rate-list.php', $currentPage) ?>">
            <span class="material-symbols-outlined">list_alt</span>
            <span class="text-sm sidebar-label">Rate List</span>
        </a>
        <a href="settings.php" 
           data-page="settings.php"
           title="Settings"
           class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg transition-all cursor-pointer <?= getActiveClass('settings.php', $currentPage) ?>">
            <span class="material-symbols-outlined">settings</span>
            <span class="text-sm sidebar-label">Settings</span>
        </a>
        <?php endif; ?>
    </nav>

    <!-- Footer with Logout -->
    <div class="px-4 pt-3 pb-4 mt-4 border-t border-slate-100 dark:border-slate-800">
        <a href="logout.php" 
           title="Logout"
           class="flex items-center gap-3 px-3 py-2.5 rounded-lg bg-red-600 text-white hover:bg-red-700 transition-all cursor-pointer mb-1">
            <span class="material-symbols-outlined">logout</span>
            <span class="text-sm sidebar-label">Logout</span>
        </a>
        <button onclick="toggleSidebar()" class="mt-4 w-full hidden md:flex items-center justify-center gap-2 py-2 text-xs font-bold text-slate-500 bg-slate-50 dark:bg-slate-800 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 transition-colors duration-300">
            <span id="collapseIcon" class="material-symbols-outlined text-sm">keyboard_double_arrow_left</span>
            <span id="collapseText" class="sidebar-toggle-text">Collapse</span>
        </button>
    </div>
</aside>

<!-- Main Content Wrapper -->
<main id="mainContent" class="flex-1 flex flex-col min-w-0 overflow-y-auto h-screen">
