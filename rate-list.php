<?php
require 'config/auth.php';
require 'config/database.php';
require 'config/constants.php';

$isAdmin = ($_SESSION['role'] === 'admin');

if (!$isAdmin) {
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Access denied. Admin only.']);
        exit;
    }
    header('Location: dashboard.php');
    exit;
}

$action  = $_GET['action'] ?? 'list';
$id      = $_GET['id'] ?? null;
$isAjax  = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

/* ============================
   CREATE TABLE IF NOT EXISTS
============================ */
$conn->query("
    CREATE TABLE IF NOT EXISTS rate_list (
        id INT PRIMARY KEY AUTO_INCREMENT,
        item_name VARCHAR(255) NOT NULL,
        rate DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )
");

/* ============================
   ADD RATE (ADMIN ONLY)
============================ */
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add') {
    $item_name = trim($_POST['item_name']);
    $rate = floatval($_POST['rate'] ?? 0);

    if ($item_name !== '') {
        $stmt = $conn->prepare("
            INSERT INTO rate_list (item_name, rate)
            VALUES (?, ?)
        ");
        $stmt->bind_param("sd", $item_name, $rate);
        $stmt->execute();
    }

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Rate added successfully']);
        exit;
    }
    
    header("Location: rate-list.php");
    exit;
}

/* ============================
   EDIT RATE (ADMIN ONLY)
============================ */
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'edit' && $id) {
    $item_name = trim($_POST['item_name']);
    $rate = floatval($_POST['rate'] ?? 0);

    if ($item_name !== '') {
        $stmt = $conn->prepare("
            UPDATE rate_list 
            SET item_name = ?, rate = ?
            WHERE id = ?
        ");
        $stmt->bind_param("sdi", $item_name, $rate, $id);
        $stmt->execute();
    }

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Rate updated successfully']);
        exit;
    }
    
    header("Location: rate-list.php");
    exit;
}

/* ============================
   DELETE RATE (ADMIN ONLY)
============================ */
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete' && $id) {
    $stmt = $conn->prepare("DELETE FROM rate_list WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Rate deleted successfully']);
        exit;
    }
    
    header("Location: rate-list.php");
    exit;
}

/* ============================
   GET RATE DATA (AJAX)
============================ */
if ($isAjax && $action === 'get' && $id) {
    $rate = $conn->query("SELECT * FROM rate_list WHERE id = $id")->fetch_assoc();
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $rate]);
    exit;
}

if (!$isAjax) {
    include 'include/header.php';
    include 'include/sidebar.php';
} else {
    ob_start();
}
?>
<div id="mobileSidebarOverlay" class="md:hidden" onclick="closeMobileSidebar()"></div>

<div id="page-content" class="flex-1 flex flex-col min-w-0">

<!-- Top Bar - Responsive Header -->
<header class="sticky top-0 z-40 w-full bg-white/80 dark:bg-slate-900/80 backdrop-blur-md border-b border-slate-200 dark:border-slate-800">
    <div class="flex items-center justify-between px-4 md:px-8 h-16">
        <!-- Mobile Menu Button -->
        <button type="button" onclick="toggleMobileSidebar()" class="flex md:hidden items-center justify-center h-11 w-11 rounded-lg hover:bg-slate-200/50 dark:hover:bg-slate-800/50 transition-colors" aria-label="Open navigation menu">
            <span class="material-symbols-outlined text-slate-700 dark:text-slate-300">menu</span>
        </button>
        
        <!-- Title Section -->
        <div class="flex items-center gap-2 md:gap-3 flex-1 md:flex-initial">
            <div class="hidden sm:flex size-8 md:size-10 rounded-lg bg-gradient-to-br from-primary to-blue-700 items-center justify-center text-white shadow-lg shadow-blue-500/20">
                <span class="material-symbols-outlined">price_change</span>
            </div>
            <div>
                <h2 class="text-base sm:text-lg md:text-xl lg:text-2xl font-bold tracking-tight text-slate-900 dark:text-white">Rate List</h2>
                <p class="hidden md:block text-xs text-slate-500 dark:text-slate-400">Manage item pricing</p>
            </div>
        </div>
        
        <!-- Desktop Actions - Right Aligned -->
        <div class="hidden lg:flex items-center gap-4 ml-auto">
            <?php if ($isAdmin): ?>
            <button onclick="openAddModal()" class="bg-primary text-white px-4 py-2 rounded-lg text-sm font-bold flex items-center gap-2 shadow-lg shadow-primary/20 hover:scale-[1.02] active:scale-[0.98] transition-all">
                <span class="material-symbols-outlined text-lg">add_circle</span>
                <span>Add Rate</span>
            </button>
            <?php endif; ?>
        </div>
        
        <!-- Mobile/Tablet Actions -->
        <div class="flex items-center gap-2">
            <?php if ($isAdmin): ?>
            <button onclick="openAddModal()" class="lg:hidden bg-primary text-white h-11 px-4 rounded-lg font-bold flex items-center gap-2 shadow-lg shadow-primary/20 active:scale-[0.95] transition-all">
                <span class="material-symbols-outlined">add_circle</span>
                <span class="hidden sm:inline text-sm">Add</span>
            </button>
            <?php endif; ?>
        </div>
    </div>
</header>

<!-- Page Body - Responsive Container -->
<div class="p-4 md:p-6 lg:p-8 space-y-6 md:space-y-8 pb-20 md:pb-8">

<!-- Rate List Table -->
<div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 shadow-sm overflow-hidden">
    <div class="px-4 md:px-6 py-4 md:py-5 border-b border-slate-200 dark:border-slate-800 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 bg-slate-50/50 dark:bg-slate-800/20">
        <div>
            <h4 class="text-base md:text-lg font-bold text-slate-900 dark:text-slate-100">Items and Rates</h4>
            <?php $rateCount = $conn->query("SELECT COUNT(*) AS total FROM rate_list")->fetch_assoc(); ?>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Total listed items: <?= intval($rateCount['total'] ?? 0) ?></p>
        </div>
        <div class="flex items-center gap-2">
            <button id="filterToggleBtn" onclick="toggleFilterPanel()" class="px-3 py-2 md:py-1.5 text-xs font-bold text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg flex items-center justify-center gap-1 border border-slate-200 dark:border-slate-700 transition-colors">
                <span class="material-symbols-outlined text-[16px]">filter_list</span>
                <span class="hidden sm:inline">Filter</span>
            </button>
            <span class="px-2.5 py-1 bg-primary/10 text-primary rounded-lg text-xs font-bold border border-primary/20">Active Pricing</span>
        </div>
    </div>
    
    <!-- Filter Panel -->
    <div id="filterPanel" class="hidden px-4 md:px-6 py-4 border-b border-slate-200 dark:border-slate-800 bg-slate-50/30 dark:bg-slate-800/10">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
            <div>
                <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 mb-1">Item Name</label>
                <input type="text" id="filterItemName" oninput="applyFilters()" placeholder="Search by item name..." class="w-full px-3 py-1.5 text-sm border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 focus:ring-2 focus:ring-primary/50 focus:border-primary">
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 mb-1">Rate</label>
                <input type="text" id="filterRate" oninput="applyFilters()" placeholder="Search by rate..." class="w-full px-3 py-1.5 text-sm border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 focus:ring-2 focus:ring-primary/50 focus:border-primary">
            </div>
            <div class="flex items-end">
                <button onclick="clearFilters()" class="w-full px-3 py-1.5 text-xs font-bold text-slate-600 dark:text-slate-400 hover:bg-slate-200 dark:hover:bg-slate-700 rounded-lg border border-slate-200 dark:border-slate-700 transition-colors flex items-center justify-center gap-1">
                    <span class="material-symbols-outlined text-[16px]">clear</span>
                    <span>Clear Filters</span>
                </button>
            </div>
        </div>
        <div id="filterStats" class="mt-3 text-xs text-slate-500 dark:text-slate-400"></div>
    </div>

    <!-- Mobile Card View -->
    <div class="md:hidden p-4 space-y-3">
    <?php
    $rates_mobile = $conn->query("SELECT * FROM rate_list ORDER BY item_name");
    if ($rates_mobile->num_rows === 0):
    ?>
        <div class="text-center py-12 text-slate-500 dark:text-slate-400">
            <span class="material-symbols-outlined mx-auto text-6xl text-slate-400 mb-4 block">inventory_2</span>
            <p class="text-lg font-medium text-slate-700 dark:text-slate-300">No rates found</p>
            <p class="text-sm mt-1">Get started by adding your first rate</p>
        </div>
    <?php
    else:
    while ($r = $rates_mobile->fetch_assoc()):
    ?>
        <div class="rate-card bg-slate-50 dark:bg-slate-800/50 rounded-xl p-4 border border-slate-200 dark:border-slate-700 shadow-sm" data-item-name="<?= strtolower(htmlspecialchars($r['item_name'])) ?>" data-rate="<?= number_format($r['rate'], 2) ?>">
            <!-- Item Header -->
            <div class="flex items-start justify-between mb-3">
                <div class="flex items-center gap-3 flex-1">
                    <div class="flex-shrink-0 h-12 w-12 bg-primary/10 dark:bg-primary/20 rounded-full flex items-center justify-center text-primary">
                        <span class="material-symbols-outlined">label</span>
                    </div>
                    <div class="flex-1 min-w-0">
                        <h5 class="text-base font-bold text-slate-900 dark:text-slate-100 truncate"><?= htmlspecialchars($r['item_name']) ?></h5>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Item ID: #<?= str_pad($r['id'], 3, '0', STR_PAD_LEFT) ?></p>
                    </div>
                </div>
            </div>
            
            <!-- Rate Display -->
            <div class="pt-3 border-t border-slate-200 dark:border-slate-700 flex items-center justify-between mb-3">
                <span class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Rate</span>
                <span class="text-xl font-bold text-green-600">Rs. <?= number_format($r['rate'], 2) ?></span>
            </div>
            
            <!-- Actions -->
            <?php if ($isAdmin): ?>
            <div class="flex gap-2">
                <button onclick="openEditModal(<?= $r['id'] ?>)"
                        class="flex-1 h-10 rounded-lg bg-amber-50 dark:bg-amber-900/20 text-amber-600 dark:text-amber-400 font-bold text-sm flex items-center justify-center gap-2 active:scale-95 transition-transform">
                    <span class="material-symbols-outlined text-lg">edit</span>
                    Edit
                </button>
                <button onclick="deleteRate(<?= $r['id'] ?>, '<?= htmlspecialchars($r['item_name'], ENT_QUOTES) ?>')"
                        class="h-10 w-10 rounded-lg bg-red-50 dark:bg-red-900/20 flex items-center justify-center text-red-600 active:scale-95 transition-transform">
                    <span class="material-symbols-outlined">delete</span>
                </button>
            </div>
            <?php endif; ?>
        </div>
    <?php endwhile; endif; ?>
    </div>

    <!-- Desktop Table View -->
    <div class="hidden md:block overflow-auto max-h-[420px]">
    <table class="w-full min-w-[640px] text-left">
        <thead>
            <tr class="bg-slate-50 dark:bg-slate-900/60 border-b border-slate-200 dark:border-slate-800">
                <th class="sticky top-0 z-10 bg-slate-50 dark:bg-slate-900 px-4 md:px-6 py-3 md:py-4 text-left text-xs font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wider">
                    Item Name
                </th>
                <th class="sticky top-0 z-10 bg-slate-50 dark:bg-slate-900 px-4 md:px-6 py-3 md:py-4 text-right text-xs font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wider">
                    Rate
                </th>
                <?php if ($isAdmin): ?>
                <th class="sticky top-0 z-10 bg-slate-50 dark:bg-slate-900 px-4 md:px-6 py-3 md:py-4 text-center text-xs font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wider">
                    Actions
                </th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody id="ratesTableBody" class="divide-y divide-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900">

<?php
$rates = $conn->query("SELECT * FROM rate_list ORDER BY item_name");
if ($rates->num_rows === 0):
?>
    <tr>
        <td colspan="<?= $isAdmin ? 3 : 2 ?>" class="px-6 py-12 text-center text-slate-500 dark:text-slate-400">
            <span class="material-symbols-outlined mx-auto text-6xl text-slate-400 mb-4 block">inventory_2</span>
            <p class="text-lg font-medium text-slate-700 dark:text-slate-300">No rates found</p>
            <p class="text-sm mt-1">Get started by adding your first rate</p>
        </td>
    </tr>
<?php
else:
while ($r = $rates->fetch_assoc()):
?>
<tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors duration-150">
    <td class="px-4 md:px-6 py-3 md:py-4">
        <div class="flex items-center">
            <div class="flex-shrink-0 h-9 w-9 md:h-10 md:w-10 bg-primary/10 dark:bg-primary/20 rounded-full flex items-center justify-center text-primary font-bold text-base md:text-lg">
                <span class="material-symbols-outlined text-[18px] md:text-[20px]">label</span>
            </div>
            <div class="ml-3 md:ml-4">
                <div class="text-sm font-semibold text-slate-900 dark:text-slate-100"><?= htmlspecialchars($r['item_name']) ?></div>
                <div class="text-xs text-slate-500 dark:text-slate-400">ID: #<?= str_pad($r['id'], 3, '0', STR_PAD_LEFT) ?></div>
            </div>
        </div>
    </td>
    <td class="px-4 md:px-6 py-3 md:py-4 text-right">
        <span class="text-base md:text-lg font-bold text-green-600">Rs. <?= number_format($r['rate'], 2) ?></span>
    </td>
    <?php if ($isAdmin): ?>
    <td class="px-4 md:px-6 py-3 md:py-4 text-center">
        <div class="flex justify-center items-center gap-1.5 md:gap-2">
            <button onclick="openEditModal(<?= $r['id'] ?>)" 
                    class="inline-flex items-center justify-center size-8 text-amber-600 dark:text-amber-400 hover:bg-amber-50 dark:hover:bg-amber-900/20 rounded-lg transition-colors" 
                    title="Edit Rate">
                <span class="material-symbols-outlined text-[18px]">edit</span>
            </button>
            <button onclick="deleteRate(<?= $r['id'] ?>, '<?= htmlspecialchars($r['item_name'], ENT_QUOTES) ?>')" 
                    class="inline-flex items-center justify-center size-8 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition-colors" 
                    title="Delete Rate">
                <span class="material-symbols-outlined text-[18px]">delete</span>
            </button>
        </div>
    </td>
    <?php endif; ?>
</tr>
<?php endwhile; endif; ?>
        </tbody>
    </table>
    </div>
</div>

</div>
</div>

<!-- Add Rate Modal -->
<div id="addRateModal" class="hidden fixed inset-0 bg-black/60 backdrop-blur-sm z-[9999] flex items-center justify-center p-4 animate-fadeIn">
    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-md mx-auto overflow-hidden transform transition-all animate-slideIn max-h-[90vh] flex flex-col">
        <!-- Modal Header -->
        <div class="bg-gradient-to-r from-indigo-600 via-blue-600 to-purple-600 text-white px-5 py-3">
            <div class="flex justify-between items-center">
                <h3 class="text-lg font-bold flex items-center gap-2">
                    <span class="material-symbols-outlined">add_circle</span>
                    Add New Rate Item
                </h3>
                <button type="button" onclick="closeAddModal()" class="text-white/90 hover:text-white hover:bg-white/20 rounded-lg p-1.5 transition-all">
                    <span class="material-symbols-outlined text-xl">close</span>
                </button>
            </div>
        </div>
        
        <!-- Modal Body -->
        <form id="addRateForm" class="p-5 space-y-4 overflow-y-auto">
            <!-- Item Name -->
            <div class="space-y-1.5">
                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300">
                    <span class="flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-base text-blue-600">label</span>
                        Item Name
                        <span class="text-red-500">*</span>
                    </span>
                </label>
                <input type="text" name="item_name" required
                       class="w-full px-3 py-2 border-2 border-slate-200 dark:border-slate-700 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent dark:bg-slate-800 dark:text-white transition-all text-sm"
                       placeholder="e.g., Container 20ft, Documentation fee">
            </div>
            
            <!-- Rate Amount -->
            <div class="space-y-1.5">
                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300">
                    <span class="flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-base text-green-600">payments</span>
                        Rate Amount (PKR)
                        <span class="text-red-500">*</span>
                    </span>
                </label>
                <div class="relative">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 dark:text-slate-400 font-semibold">Rs.</span>
                    <input type="number" name="rate" step="0.01" min="0" required
                           class="w-full pl-12 pr-3 py-2 border-2 border-slate-200 dark:border-slate-700 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent dark:bg-slate-800 dark:text-white transition-all text-sm"
                           placeholder="0.00">
                </div>
                <p class="text-xs text-slate-500 dark:text-slate-400 flex items-center gap-1">
                    <span class="material-symbols-outlined text-xs">info</span>
                    Enter the rate amount in Pakistani Rupees
                </p>
            </div>
            
            <!-- Form Actions -->
            <div class="flex gap-2.5 pt-3 border-t border-slate-200 dark:border-slate-700">
                <button type="button" onclick="closeAddModal()"
                        class="flex-1 px-3 py-2.5 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded-lg font-semibold text-sm hover:bg-slate-200 dark:hover:bg-slate-700 transition-all">
                    Cancel
                </button>
                <button type="submit"
                        class="flex-1 px-3 py-2.5 bg-gradient-to-r from-blue-600 to-indigo-600 text-white rounded-lg font-semibold text-sm hover:from-blue-700 hover:to-indigo-700 shadow-lg shadow-blue-500/30 transition-all flex items-center justify-center gap-1.5">
                    <span class="material-symbols-outlined text-base">save</span>
                    Save Rate
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Rate Modal -->
<div id="editRateModal" class="hidden fixed inset-0 bg-black/60 backdrop-blur-sm z-[9999] flex items-center justify-center p-4 animate-fadeIn">
    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-md mx-auto overflow-hidden transform transition-all animate-slideIn max-h-[90vh] flex flex-col">
        <!-- Modal Header -->
        <div class="bg-gradient-to-r from-amber-500 via-orange-500 to-yellow-600 text-white px-5 py-3">
            <div class="flex justify-between items-center">
                <h3 class="text-lg font-bold flex items-center gap-2">
                    <span class="material-symbols-outlined">edit</span>
                    Edit Rate Item
                </h3>
                <button type="button" onclick="closeEditModal()" class="text-white/90 hover:text-white hover:bg-white/20 rounded-lg p-1.5 transition-all">
                    <span class="material-symbols-outlined text-xl">close</span>
                </button>
            </div>
        </div>
        
        <!-- Modal Body -->
        <form id="editRateForm" class="p-5 space-y-4 overflow-y-auto">
            <input type="hidden" name="id" id="editRateId">
            
            <!-- Item Name -->
            <div class="space-y-1.5">
                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300">
                    <span class="flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-base text-amber-600">label</span>
                        Item Name
                        <span class="text-red-500">*</span>
                    </span>
                </label>
                <input type="text" name="item_name" id="editItemName" required
                       class="w-full px-3 py-2 border-2 border-slate-200 dark:border-slate-700 rounded-lg focus:ring-2 focus:ring-amber-500 focus:border-transparent dark:bg-slate-800 dark:text-white transition-all text-sm"
                       placeholder="Enter item name">
            </div>
            
            <!-- Rate Amount -->
            <div class="space-y-1.5">
                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300">
                    <span class="flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-base text-green-600">payments</span>
                        Rate Amount (PKR)
                        <span class="text-red-500">*</span>
                    </span>
                </label>
                <div class="relative">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 dark:text-slate-400 font-semibold">Rs.</span>
                    <input type="number" name="rate" id="editRate" step="0.01" min="0" required
                           class="w-full pl-12 pr-3 py-2 border-2 border-slate-200 dark:border-slate-700 rounded-lg focus:ring-2 focus:ring-amber-500 focus:border-transparent dark:bg-slate-800 dark:text-white transition-all text-sm"
                           placeholder="0.00">
                </div>
            </div>
            
            <!-- Form Actions -->
            <div class="flex gap-2.5 pt-3 border-t border-slate-200 dark:border-slate-700">
                <button type="button" onclick="closeEditModal()"
                        class="flex-1 px-3 py-2.5 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded-lg font-semibold text-sm hover:bg-slate-200 dark:hover:bg-slate-700 transition-all">
                    Cancel
                </button>
                <button type="submit"
                        class="flex-1 px-3 py-2.5 bg-gradient-to-r from-amber-500 to-orange-600 text-white rounded-lg font-semibold text-sm hover:from-amber-600 hover:to-orange-700 shadow-lg shadow-amber-500/30 transition-all flex items-center justify-center gap-1.5">
                    <span class="material-symbols-outlined text-base">update</span>
                    Update
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteConfirmModal" class="hidden fixed inset-0 bg-black/60 backdrop-blur-sm z-[9999] flex items-center justify-center p-4 animate-fadeIn">
    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-md mx-auto overflow-hidden transform transition-all animate-slideIn">
        <!-- Modal Header -->
        <div class="bg-gradient-to-r from-red-600 via-red-600 to-rose-700 text-white px-5 py-3">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-2xl">warning</span>
                <h3 class="text-lg font-bold">Confirm Deletion</h3>
            </div>
        </div>
        
        <!-- Modal Body -->
        <div class="p-5">
            <p class="text-slate-600 dark:text-slate-400 text-sm mb-3">Are you sure you want to delete this rate item?</p>
            <div class="bg-slate-100 dark:bg-slate-800 rounded-lg p-3 mb-4">
                <p class="text-base font-bold text-slate-900 dark:text-slate-100" id="deleteItemName"></p>
            </div>
            <div class="bg-gradient-to-r from-yellow-50 to-orange-50 dark:from-yellow-900/20 dark:to-orange-900/20 border-2 border-yellow-300 dark:border-yellow-700 rounded-xl p-3.5 flex gap-3">
                <span class="material-symbols-outlined text-yellow-700 dark:text-yellow-400 text-xl flex-shrink-0">info</span>
                <div>
                    <p class="text-sm font-semibold text-yellow-900 dark:text-yellow-200 mb-1">Warning</p>
                    <p class="text-xs text-yellow-800 dark:text-yellow-300">This action cannot be undone. The rate item will be permanently deleted from the system.</p>
                </div>
            </div>
        </div>
        
        <!-- Modal Footer -->
        <div class="flex gap-2.5 p-4 border-t-2 border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900">
            <button type="button" onclick="closeDeleteModal()"
                    class="flex-1 px-4 py-2.5 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded-lg hover:bg-slate-200 dark:hover:bg-slate-700 transition-all font-semibold text-sm">
                Cancel
            </button>
            <button type="button" onclick="confirmDelete()"
                    class="flex-1 px-4 py-2.5 bg-gradient-to-r from-red-600 to-rose-700 text-white rounded-lg hover:from-red-700 hover:to-rose-800 transition-all shadow-lg shadow-red-500/30 flex items-center justify-center gap-2 font-semibold text-sm">
                <span class="material-symbols-outlined text-base">delete</span>
                Delete
            </button>
        </div>
    </div>
</div>

<?php
// If AJAX request, return just the HTML
if ($isAjax) {
    $html = ob_get_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'html' => $html]);
    exit;
}

// Otherwise, include footer for regular page load
include 'include/footer.php';
?>

<!-- Modal Animations -->
<style>
@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

@keyframes slideIn {
    from {
        transform: translateY(-50px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

.animate-fadeIn {
    animation: fadeIn 0.3s ease-out;
}

.animate-slideIn {
    animation: slideIn 0.3s ease-out;
}

/* Custom Scrollbar for Modal Forms */
.overflow-y-auto::-webkit-scrollbar {
    width: 8px;
}

.overflow-y-auto::-webkit-scrollbar-track {
    background: #f1f5f9;
}

.overflow-y-auto::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 4px;
}

.overflow-y-auto::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}

.dark .overflow-y-auto::-webkit-scrollbar-track {
    background: #1e293b;
}

.dark .overflow-y-auto::-webkit-scrollbar-thumb {
    background: #475569;
}

.dark .overflow-y-auto::-webkit-scrollbar-thumb:hover {
    background: #64748b;
}

/* Smooth modal transitions */
#addRateModal, #editRateModal, #deleteConfirmModal {
    transition: opacity 0.3s ease-out;
}

/* Focus styles for better accessibility */
input:focus, textarea:focus, select:focus {
    outline: none;
}

/* Better hover effects for form inputs */
input:hover:not(:disabled), textarea:hover:not(:disabled), select:hover:not(:disabled) {
    border-color: #94a3b8;
}

.dark input:hover:not(:disabled), .dark textarea:hover:not(:disabled), .dark select:hover:not(:disabled) {
    border-color: #64748b;
}
</style>

<script>
(function() {
    'use strict';

    // Update page content via AJAX
    function updatePageContent() {
        fetch('rate-list.php', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success && data.html) {
                document.getElementById('page-content').innerHTML = data.html;
                initializeAjax();
            }
        })
        .catch(function(error) {
            console.error('Error:', error);
            location.reload();
        });
    }

    // Modal Functions
    window.openAddModal = function() {
        document.getElementById('addRateModal').classList.remove('hidden');
        document.getElementById('addRateModal').classList.add('flex');
        document.getElementById('addRateForm').reset();
    };

    window.closeAddModal = function() {
        document.getElementById('addRateModal').classList.add('hidden');
        document.getElementById('addRateModal').classList.remove('flex');
    };

    window.openEditModal = function(id) {
        fetch('rate-list.php?action=get&id=' + id, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                document.getElementById('editRateId').value = data.data.id;
                document.getElementById('editItemName').value = data.data.item_name;
                document.getElementById('editRate').value = data.data.rate;
                
                document.getElementById('editRateModal').classList.remove('hidden');
                document.getElementById('editRateModal').classList.add('flex');
            }
        });
    };

    window.closeEditModal = function() {
        document.getElementById('editRateModal').classList.add('hidden');
        document.getElementById('editRateModal').classList.remove('flex');
    };

    window.deleteRate = function(id, name) {
        window.deleteId = id;
        document.getElementById('deleteItemName').textContent = name;
        document.getElementById('deleteConfirmModal').classList.remove('hidden');
        document.getElementById('deleteConfirmModal').classList.add('flex');
    };

    window.closeDeleteModal = function() {
        document.getElementById('deleteConfirmModal').classList.add('hidden');
        document.getElementById('deleteConfirmModal').classList.remove('flex');
        window.deleteId = null;
    };

    window.confirmDelete = function() {
        var id = window.deleteId;
        if (!id) return;

        var formData = new FormData();
        fetch('rate-list.php?action=delete&id=' + id, {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                closeDeleteModal();
                updatePageContent();
            }
        })
        .catch(function(error) {
            console.error('Error:', error);
            alert('Error deleting rate');
        });
    };

    // Form Handlers
    function initializeAjax() {
        var addForm = document.getElementById('addRateForm');
        if (addForm) {
            addForm.addEventListener('submit', function(e) {
                e.preventDefault();
                var formData = new FormData(this);
                
                fetch('rate-list.php?action=add', {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (data.success) {
                        closeAddModal();
                        updatePageContent();
                    } else {
                        alert('Error adding rate');
                    }
                })
                .catch(function(error) {
                    console.error('Error:', error);
                    alert('Error adding rate');
                });
            });
        }

        var editForm = document.getElementById('editRateForm');
        if (editForm) {
            editForm.addEventListener('submit', function(e) {
                e.preventDefault();
                var formData = new FormData(this);
                var id = document.getElementById('editRateId').value;
                
                fetch('rate-list.php?action=edit&id=' + id, {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (data.success) {
                        closeEditModal();
                        updatePageContent();
                    } else {
                        alert('Error updating rate');
                    }
                })
                .catch(function(error) {
                    console.error('Error:', error);
                    alert('Error updating rate');
                });
            });
        }
    }

    // Initialize on page load
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeAjax);
    } else {
        initializeAjax();
    }

    // Close modals on outside click
    window.addEventListener('click', function(e) {
        if (e.target.id === 'addRateModal') {
            closeAddModal();
        }
        if (e.target.id === 'editRateModal') {
            closeEditModal();
        }
        if (e.target.id === 'deleteConfirmModal') {
            closeDeleteModal();
        }
    });

    // Close modals on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeAddModal();
            closeEditModal();
            closeDeleteModal();
        }
    });
    
    // Filter Functions
    window.toggleFilterPanel = function() {
        var panel = document.getElementById('filterPanel');
        var btn = document.getElementById('filterToggleBtn');
        
        if (panel.classList.contains('hidden')) {
            panel.classList.remove('hidden');
            btn.classList.add('bg-primary', 'text-white');
            btn.classList.remove('text-slate-600', 'dark:text-slate-400');
        } else {
            panel.classList.add('hidden');
            btn.classList.remove('bg-primary', 'text-white');
            btn.classList.add('text-slate-600', 'dark:text-slate-400');
        }
    };
    
    window.applyFilters = function() {
        var itemNameFilter = document.getElementById('filterItemName').value.toLowerCase().trim();
        var rateFilter = document.getElementById('filterRate').value.toLowerCase().trim();
        
        // Filter desktop table rows
        var tbody = document.getElementById('ratesTableBody');
        if (!tbody) {
            console.error('Rates table body not found');
            return;
        }
        
        var rows = tbody.querySelectorAll('tr');
        var visibleCount = 0;
        var totalCount = 0;
        
        rows.forEach(function(row) {
            // Skip the "no rates found" row
            if (row.cells.length < 2) {
                return;
            }
            
            totalCount++;
            
            // Get text content from cells
            var nameCell = row.cells[0].textContent.toLowerCase();
            var rateCell = row.cells[1].textContent.toLowerCase();
            
            // Check if row matches all active filters
            var nameMatch = !itemNameFilter || nameCell.includes(itemNameFilter);
            var rateMatch = !rateFilter || rateCell.includes(rateFilter);
            
            if (nameMatch && rateMatch) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });
        
        // Filter mobile cards
        var mobileCards = document.querySelectorAll('.rate-card');
        var mobileVisibleCount = 0;
        var mobileTotalCount = mobileCards.length;
        
        mobileCards.forEach(function(card) {
            var cardName = card.getAttribute('data-item-name') || '';
            var cardRate = card.getAttribute('data-rate') || '';
            
            // Check if card matches all active filters
            var nameMatch = !itemNameFilter || cardName.includes(itemNameFilter);
            var rateMatch = !rateFilter || cardRate.includes(rateFilter);
            
            if (nameMatch && rateMatch) {
                card.style.display = '';
                mobileVisibleCount++;
            } else {
                card.style.display = 'none';
            }
        });
        
        // Update filter stats (use desktop count if available, otherwise mobile count)
        var statsDiv = document.getElementById('filterStats');
        var displayCount = totalCount > 0 ? visibleCount : mobileVisibleCount;
        var displayTotal = totalCount > 0 ? totalCount : mobileTotalCount;
        
        if (itemNameFilter || rateFilter) {
            statsDiv.textContent = 'Showing ' + displayCount + ' of ' + displayTotal + ' items';
        } else {
            statsDiv.textContent = '';
        }
    };
    
    window.clearFilters = function() {
        document.getElementById('filterItemName').value = '';
        document.getElementById('filterRate').value = '';
        applyFilters();
    };
})();

// Mobile sidebar toggle functions
window.toggleMobileSidebar = function() {
    var sidebar = document.getElementById('sidebar');
    var overlay = document.getElementById('mobileSidebarOverlay');
    if (!sidebar || !overlay || window.innerWidth >= 768) return;
    var willOpen = !sidebar.classList.contains('mobile-open');
    sidebar.classList.toggle('mobile-open', willOpen);
    overlay.classList.toggle('open', willOpen);
    document.body.classList.toggle('mobile-sidebar-open', willOpen);
};

window.closeMobileSidebar = function() {
    var sidebar = document.getElementById('sidebar');
    var overlay = document.getElementById('mobileSidebarOverlay');
    if (!sidebar || !overlay) return;
    sidebar.classList.remove('mobile-open');
    overlay.classList.remove('open');
    document.body.classList.remove('mobile-sidebar-open');
};

// Auto-close mobile sidebar on window resize
window.addEventListener('resize', function() {
    if (window.innerWidth >= 768) {
        closeMobileSidebar();
    }
});
</script>

<style>
/* Desktop: Fixed height with internal scrolling */
@media (min-width: 768px) {
    html,
    body {
        height: 100%;
        margin: 0 !important;
        padding: 0 !important;
        overflow: hidden;
    }

    #page-content {
        height: 100vh;
        overflow-y: auto !important;
        overflow-x: hidden;
    }
}

/* Mobile: Allow full page scrolling */
@media (max-width: 767px) {
    html,
    body {
        margin: 0 !important;
        padding: 0 !important;
        overflow-x: hidden;
        overflow-y: auto;
    }

    #page-content {
        min-height: 100vh;
        overflow: visible;
    }
}

/* Ensure proper scrolling behavior */
#page-content > * {
    flex-shrink: 0;
}

@media (max-width: 767px) {
    body.mobile-sidebar-open {
        overflow: hidden;
    }

    #sidebar {
        position: fixed;
        top: 0;
        left: 0;
        z-index: 60;
        height: 100vh;
        width: min(20rem, 84vw);
        transform: translateX(-110%);
        transition: transform 0.25s ease;
        box-shadow: 0 20px 40px rgba(15, 23, 42, 0.2);
    }

    #sidebar.mobile-open {
        transform: translateX(0);
    }

    #mobileSidebarOverlay {
        position: fixed;
        inset: 0;
        z-index: 50;
        background: rgba(15, 23, 42, 0.45);
        opacity: 0;
        pointer-events: none;
        transition: opacity 0.2s ease;
    }

    #mobileSidebarOverlay.open {
        opacity: 1;
        pointer-events: auto;
    }
}

/* Responsive table styles */
@media (max-width: 767px) {
    /* Smooth touch interactions for cards */
    .bg-slate-50 {
        -webkit-tap-highlight-color: transparent;
    }
    
    /* Better spacing on very small screens */
    @media (max-width: 374px) {
        .bg-slate-50 {
            padding: 0.75rem;
        }
    }
}

/* Ensure modal content is readable on small screens */
@media (max-width: 640px) {
    .modal-body {
        font-size: 0.875rem;
    }
}
</style>
