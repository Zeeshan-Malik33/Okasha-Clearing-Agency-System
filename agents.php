<?php
require 'config/auth.php';
require 'config/database.php';
require 'config/constants.php';

/* ============================
   SCHEMA MIGRATION AND UPDATES
   (Merged from update_transaction_schema.php & update_agents_schema.php)
============================ */

// 1. Add columns to agent_transactions table
$trans_columns = [
    'total_amount' => "DECIMAL(10,2) DEFAULT 0.00",
    'total_paid' => "DECIMAL(10,2) DEFAULT 0.00",
    'remaining_amount' => "DECIMAL(10,2) DEFAULT 0.00",
    'container_id' => "INT NULL",
    'expense_id' => "INT NULL",
    'proof' => "VARCHAR(255) NULL"
];
foreach ($trans_columns as $col => $def) {
    try {
        // Suppress errors if column exists
        @$conn->query("ALTER TABLE agent_transactions ADD COLUMN $col $def");
    } catch (Exception $e) { }
}

// 2. Add columns to agents table
$agent_columns = [
    'total_amount' => "DECIMAL(10,2) DEFAULT 0.00",
    'total_paid' => "DECIMAL(10,2) DEFAULT 0.00",
    'remaining_amount' => "DECIMAL(10,2) DEFAULT 0.00"
];
foreach ($agent_columns as $col => $def) {
    try {
        @$conn->query("ALTER TABLE agents ADD COLUMN $col $def");
    } catch (Exception $e) { }
}

// 3. Backfill/Recalculate Agent Totals (Silent)
// This runs on every load as requested, ensuring consistency
$all_agents = $conn->query("SELECT id FROM agents");
if ($all_agents) {
    while ($a = $all_agents->fetch_assoc()) {
        $aid = $a['id'];
        $ledger = $conn->query("
            SELECT 
                SUM(credit) AS credit,
                SUM(debit) AS debit
            FROM agent_transactions
            WHERE agent_id = $aid
        ")->fetch_assoc();
        
        $credit = $ledger['credit'] ?? 0;
        $debit = $ledger['debit'] ?? 0;
        $balance = $credit - $debit; 
        
        $limit_stmt = $conn->prepare("UPDATE agents SET total_amount = ?, total_paid = ?, remaining_amount = ? WHERE id = ?");
        $limit_stmt->bind_param("dddi", $credit, $debit, $balance, $aid);
        $limit_stmt->execute();
    }
}
/* ============================
   END SCHEMA UPDATES
============================ */

$isAdmin = ($_SESSION['role'] === 'admin');
$action  = $_GET['action'] ?? 'list';
$id      = $_GET['id'] ?? null;
$isAjax  = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

/* ============================
   ADD AGENT (ADMIN ONLY)
============================ */
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add') {

    $name    = trim($_POST['name']);
    $contact = trim($_POST['contact']);
    $notes   = trim($_POST['notes']);

    if ($name !== '') {
        $newAgentId = getNextReusableId($conn, 'agents');
        $stmt = $conn->prepare("
            INSERT INTO agents (id, name, contact, notes)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param("isss", $newAgentId, $name, $contact, $notes);
        $stmt->execute();
    }

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Agent added successfully']);
        exit;
    }
    
    header("Location: agents.php");
    exit;
}

/* ============================
   EDIT AGENT (ADMIN ONLY)
============================ */
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'edit' && $id) {

    $name    = trim($_POST['name']);
    $contact = trim($_POST['contact']);
    $notes   = trim($_POST['notes']);

    if ($name !== '') {
        $stmt = $conn->prepare("
            UPDATE agents 
            SET name = ?, contact = ?, notes = ?
            WHERE id = ?
        ");
        $stmt->bind_param("sssi", $name, $contact, $notes, $id);
        $stmt->execute();
    }

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Agent updated successfully']);
        exit;
    }
    
    header("Location: agents.php");
    exit;
}

/* ============================
   DELETE AGENT (ADMIN ONLY)
============================ */
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete' && $id) {
    // First delete all transactions for this agent
    $stmt = $conn->prepare("DELETE FROM agent_transactions WHERE agent_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    
    // Then delete the agent
    $stmt = $conn->prepare("DELETE FROM agents WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Agent deleted successfully']);
        exit;
    }
    
    header("Location: agents.php");
    exit;
}

/* ============================
   DELETE TRANSACTION (ADMIN ONLY)
============================ */
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_transaction') {
    $transaction_id = $_POST['transaction_id'] ?? null;
    
    if ($transaction_id) {
        // Get transaction details before deleting
        $tx = $conn->query("SELECT agent_id, credit, debit FROM agent_transactions WHERE id = $transaction_id")->fetch_assoc();
        
        if ($tx) {
            $agent_id = $tx['agent_id'];
            
            // Delete the transaction
            $conn->query("DELETE FROM agent_transactions WHERE id = $transaction_id");
            
            // Recalculate agent totals
            $ledger = $conn->query("
                SELECT 
                    SUM(credit) AS credit,
                    SUM(debit) AS debit
                FROM agent_transactions
                WHERE agent_id = $agent_id
            ")->fetch_assoc();
            
            $credit = $ledger['credit'] ?? 0;
            $debit = $ledger['debit'] ?? 0;
            $balance = $credit - $debit;
            
            // Update agent totals
            $stmt = $conn->prepare("UPDATE agents SET total_amount = ?, total_paid = ?, remaining_amount = ? WHERE id = ?");
            $stmt->bind_param("dddi", $credit, $debit, $balance, $agent_id);
            $stmt->execute();
        }
    }
    
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Transaction deleted successfully']);
        exit;
    }
    
    header("Location: agents.php");
    exit;
}

/* ============================
   ADD PAYMENT (ADMIN ONLY)
============================ */
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add_payment') {
    $agent_id = $_POST['agent_id'];
    $amount   = floatval($_POST['amount']);
    $note     = trim($_POST['note']);
    $date     = $_POST['date'] ?: date('Y-m-d');

    if ($agent_id && $amount > 0) {
        // Handle proof file upload
        $proofFile = null;
        if (!empty($_FILES['proof']['name'])) {
            $ext = strtolower(pathinfo($_FILES['proof']['name'], PATHINFO_EXTENSION));
            $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp'];
            
            if (in_array($ext, $allowed)) {
                $targetDir = "uploads/agent_payments/";
                if (!is_dir($targetDir)) {
                    mkdir($targetDir, 0777, true);
                }
                $proofFile = time() . "_agent{$agent_id}_" . uniqid() . "." . $ext;
                move_uploaded_file(
                    $_FILES['proof']['tmp_name'],
                    $targetDir . $proofFile
                );
            }
        }
        
        // Fetch current agent stats
        $agentQ = $conn->query("SELECT total_amount, total_paid, remaining_amount FROM agents WHERE id = $agent_id");
        if ($ag = $agentQ->fetch_assoc()) {
            $current_total = $ag['total_amount'];
            $new_paid      = $ag['total_paid'] + $amount;
            $new_rem       = $ag['remaining_amount'] - $amount;

            // Insert Transaction with snapshot and proof
            $stmt = $conn->prepare("
                INSERT INTO agent_transactions (agent_id, description, debit, created_at, total_amount, total_paid, remaining_amount, proof)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $desc = "Payment" . ($note ? ": $note" : "");
            $stmt->bind_param("isdsddds", $agent_id, $desc, $amount, $date, $current_total, $new_paid, $new_rem, $proofFile);
            $stmt->execute();
            
            // Update Agent Totals
            $upd = $conn->prepare("
                UPDATE agents 
                SET total_paid = ?,
                    remaining_amount = ? 
                WHERE id = ?
            ");
            $upd->bind_param("ddi", $new_paid, $new_rem, $agent_id);
            $upd->execute();
        }
        
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Payment added successfully']);
            exit;
        }
    }
    
    header("Location: agents.php");
    exit;
}

/* ============================
   GET AGENT DATA (AJAX)
============================ */
if ($isAjax && $action === 'get' && $id) {
    $agent = $conn->query("SELECT * FROM agents WHERE id = $id")->fetch_assoc();
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $agent]);
    exit;
}

/* ============================
   GET LEDGER DATA (AJAX)
============================ */
if ($isAjax && $action === 'get_ledger' && $id) {
    // Fetch Agent
    $agent = $conn->query("SELECT * FROM agents WHERE id = $id")->fetch_assoc();
    
    // Fetch Transactions
    $transactions_query = $conn->query("
        SELECT * FROM agent_transactions
        WHERE agent_id = $id
        ORDER BY created_at DESC
    ");
    
    $transactions = [];
    while ($t = $transactions_query->fetch_assoc()) {
        $transactions[] = $t;
    }
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true, 
        'agent' => $agent,
        'stats' => [
            'total_credit' => $agent['total_amount'],
            'total_debit' => $agent['total_paid'],
            'balance' => $agent['remaining_amount']
        ],
        'transactions' => $transactions
    ]);
    exit;
}

if (!$isAjax) {
    include 'include/header.php';
    include 'include/sidebar.php';
}

if ($isAjax) {
    ob_start();
}

// Get company details for printing
$systemSettings = getSystemSettings();
$companyDetails = [
    'name' => $systemSettings['system_name'] ?? 'Container Management',
    'location' => $systemSettings['system_location'] ?? '',
    'contact' => $systemSettings['system_contact'] ?? '',
    'email' => $systemSettings['system_email'] ?? '',
    'logo' => $systemSettings['system_logo'] ? BASE_URL . 'uploads/system/' . $systemSettings['system_logo'] : ''
];
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
        <div class="flex items-center gap-3">
            <div class="hidden md:flex size-10 rounded-lg bg-gradient-to-br from-primary to-blue-700 items-center justify-center text-white shadow-lg shadow-blue-500/20">
                <span class="material-symbols-outlined">groups</span>
            </div>
            <div>
                <h2 class="text-lg md:text-xl font-bold tracking-tight text-slate-900 dark:text-white">Agents</h2>
                <p class="hidden md:block text-xs text-slate-500 dark:text-slate-400">Manage agents and transactions</p>
            </div>
            <div class="hidden lg:flex items-center px-3 py-1 bg-slate-100 dark:bg-slate-800 rounded-full text-[10px] font-bold text-slate-500 uppercase tracking-wider">
                <span class="material-symbols-outlined text-xs mr-1">badge</span> MANAGEMENT
            </div>
        </div>
        
        <!-- Desktop Actions - Right Aligned -->
        <div class="hidden lg:flex items-center gap-4 ml-auto">
            <?php if ($isAdmin): ?>
            <button onclick="openAddModal()" class="bg-primary text-white px-4 py-2 rounded-lg text-sm font-bold flex items-center gap-2 shadow-lg shadow-primary/20 hover:scale-[1.02] active:scale-[0.98] transition-all">
                <span class="material-symbols-outlined text-lg">person_add</span>
                <span>Add Agent</span>
            </button>
            <?php endif; ?>
        </div>
        
        <!-- Mobile/Tablet Actions -->
        <div class="flex items-center gap-2">
            <?php if ($isAdmin): ?>
            <button onclick="openAddModal()" class="lg:hidden bg-primary text-white h-11 px-4 rounded-lg font-bold flex items-center gap-2 shadow-lg shadow-primary/20 active:scale-[0.95] transition-all">
                <span class="material-symbols-outlined">person_add</span>
                <span class="hidden sm:inline text-sm">Add</span>
            </button>
            <?php endif; ?>
        </div>
    </div>
</header>

<!-- Page Body - Responsive Container -->
<div class="p-4 md:p-6 lg:p-8 space-y-6 md:space-y-8 pb-20 md:pb-8">

<!-- AGENT LIST -->
<div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 shadow-sm overflow-hidden">
<div class="px-4 md:px-6 py-4 md:py-5 border-b border-slate-200 dark:border-slate-800 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 bg-slate-50/50 dark:bg-slate-800/20">
    <div>
        <h4 class="text-base md:text-lg font-bold text-slate-900 dark:text-slate-100">Agents Directory</h4>
        <?php $agentCount = $conn->query("SELECT COUNT(*) AS total FROM agents")->fetch_assoc(); ?>
        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Total registered agents: <?= intval($agentCount['total'] ?? 0) ?></p>
    </div>
    <div class="flex items-center gap-2">
        <button id="filterToggleBtn" onclick="toggleFilterPanel()" class="px-3 py-2 md:py-1.5 text-xs font-bold text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg flex items-center justify-center gap-1 border border-slate-200 dark:border-slate-700 transition-colors">
            <span class="material-symbols-outlined text-[16px]">filter_list</span>
            <span class="hidden sm:inline">Filter</span>
        </button>
        <span class="px-2.5 py-1 bg-primary/10 text-primary rounded-lg text-xs font-bold border border-primary/20">Live Records</span>
    </div>
</div>

<!-- Filter Panel - Responsive -->
<div id="filterPanel" class="hidden px-4 md:px-6 py-4 border-b border-slate-200 dark:border-slate-800 bg-slate-50/30 dark:bg-slate-800/10">
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
        <div>
            <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 mb-1">Agent Name</label>
            <input type="text" id="filterName" oninput="applyFilters()" placeholder="Search by name..." class="w-full px-3 py-1.5 text-sm border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 focus:ring-2 focus:ring-primary/50 focus:border-primary">
        </div>
        <div>
            <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 mb-1">Contact Info</label>
            <input type="text" id="filterContact" oninput="applyFilters()" placeholder="Search by contact..." class="w-full px-3 py-1.5 text-sm border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 focus:ring-2 focus:ring-primary/50 focus:border-primary">
        </div>
        <div>
            <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 mb-1">Notes</label>
            <input type="text" id="filterNotes" oninput="applyFilters()" placeholder="Search by notes..." class="w-full px-3 py-1.5 text-sm border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 focus:ring-2 focus:ring-primary/50 focus:border-primary">
        </div>
        <div class="flex items-end sm:col-span-2 lg:col-span-1">
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
$agents_mobile = $conn->query("SELECT * FROM agents ORDER BY name");
if ($agents_mobile->num_rows === 0):
?>
    <div class="text-center py-12 text-slate-500 dark:text-slate-400">
        <span class="material-symbols-outlined mx-auto text-6xl text-slate-400 mb-4 block">group_off</span>
        <p class="text-lg font-medium text-slate-700 dark:text-slate-300">No agents found</p>
        <p class="text-sm mt-1">Get started by adding your first agent</p>
    </div>
<?php
else:
while ($a = $agents_mobile->fetch_assoc()):
    $balance = $a['remaining_amount'];
?>
    <div class="agent-card bg-slate-50 dark:bg-slate-800/50 rounded-xl p-4 border border-slate-200 dark:border-slate-700 shadow-sm active:scale-[0.98] transition-transform cursor-pointer"
         data-agent-name="<?= htmlspecialchars(strtolower($a['name'])) ?>"
         data-agent-contact="<?= htmlspecialchars(strtolower($a['contact'] ?? '')) ?>"
         data-agent-notes="<?= htmlspecialchars(strtolower($a['notes'] ?? '')) ?>"
         onclick="viewLedger(<?= $a['id'] ?>, '<?= htmlspecialchars($a['name'], ENT_QUOTES) ?>')">
        <!-- Agent Header -->
        <div class="flex items-start justify-between mb-3">
            <div class="flex items-center gap-3 flex-1">
                <div class="flex-shrink-0 h-12 w-12 bg-primary/10 dark:bg-primary/20 rounded-full flex items-center justify-center text-primary font-bold text-lg">
                    <?= strtoupper(substr($a['name'], 0, 1)) ?>
                </div>
                <div class="flex-1 min-w-0">
                    <h5 class="text-base font-bold text-slate-900 dark:text-slate-100 truncate"><?= htmlspecialchars($a['name']) ?></h5>
                    <p class="text-xs text-slate-500 dark:text-slate-400">ID: A-<?= str_pad($a['id'], 4, '0', STR_PAD_LEFT) ?></p>
                </div>
            </div>
            <?php if ($isAdmin): ?>
            <div class="flex gap-1 ml-2">
                <button onclick="event.stopPropagation(); openEditModal(<?= $a['id'] ?>)"
                        class="p-2 text-amber-600 dark:text-amber-400 hover:bg-amber-50 dark:hover:bg-amber-900/20 rounded-lg transition-colors"
                        title="Edit">
                    <span class="material-symbols-outlined text-[20px]">edit</span>
                </button>
                <button onclick="event.stopPropagation(); deleteAgent(<?= $a['id'] ?>, '<?= htmlspecialchars($a['name'], ENT_QUOTES) ?>')"
                        class="p-2 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition-colors"
                        title="Delete">
                    <span class="material-symbols-outlined text-[20px]">delete</span>
                </button>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Agent Details -->
        <div class="space-y-2 mb-3">
            <div class="flex items-start gap-2">
                <span class="material-symbols-outlined text-slate-400 text-[18px] flex-shrink-0 mt-0.5">contact_phone</span>
                <div class="text-sm text-slate-700 dark:text-slate-300 flex-1">
                    <?= nl2br(htmlspecialchars($a['contact'] ?: 'N/A')) ?>
                </div>
            </div>
            <?php if (!empty($a['notes'])): ?>
            <div class="flex items-start gap-2">
                <span class="material-symbols-outlined text-slate-400 text-[18px] flex-shrink-0 mt-0.5">description</span>
                <p class="text-sm text-slate-600 dark:text-slate-400 flex-1">
                    <?= htmlspecialchars(substr($a['notes'], 0, 60)) ?><?= strlen($a['notes']) > 60 ? '...' : '' ?>
                </p>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Balance Footer -->
        <div class="pt-3 border-t border-slate-200 dark:border-slate-700 flex items-center justify-between">
            <span class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Balance</span>
            <span class="text-lg font-bold <?= $balance >= 0 ? 'text-green-600' : 'text-red-600' ?>">
                Rs. <?= number_format($balance, 2) ?>
            </span>
        </div>
    </div>
<?php endwhile; endif; ?>
</div>

<!-- Desktop Table View -->
<div class="hidden md:block overflow-auto max-h-[620px]">
<table class="w-full min-w-[640px]">
    <thead>
        <tr class="bg-slate-50 dark:bg-slate-900/60 border-b border-slate-200 dark:border-slate-800">
        <tr class="bg-slate-50 dark:bg-slate-900/60 border-b border-slate-200 dark:border-slate-800">
            <th class="sticky top-0 z-10 bg-slate-50 dark:bg-slate-900 px-4 md:px-6 py-3 md:py-4 text-left text-xs font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wider">
                Agent Name
            </th>
            <th class="sticky top-0 z-10 bg-slate-50 dark:bg-slate-900 px-4 md:px-6 py-3 md:py-4 text-left text-xs font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wider hidden lg:table-cell">
                Contact
            </th>
            <th class="sticky top-0 z-10 bg-slate-50 dark:bg-slate-900 px-4 md:px-6 py-3 md:py-4 text-left text-xs font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wider hidden xl:table-cell">
                Notes
            </th>
            <th class="sticky top-0 z-10 bg-slate-50 dark:bg-slate-900 px-4 md:px-6 py-3 md:py-4 text-right text-xs font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wider">
                Balance
            </th>
            <?php if ($isAdmin): ?>
            <th class="sticky top-0 z-10 bg-slate-50 dark:bg-slate-900 px-4 md:px-6 py-3 md:py-4 text-center text-xs font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wider">
                Actions
            </th>
            <?php endif; ?>
        </tr>
    </thead>
    <tbody id="agentsTableBody" class="divide-y divide-slate-200 dark:divide-slate-800 bg-white dark:bg-slate-900">

<?php
$agents = $conn->query("SELECT * FROM agents ORDER BY name");
if ($agents->num_rows === 0):
?>
    <tr>
        <td colspan="<?= $isAdmin ? '5' : '4' ?>" class="px-6 py-12 text-center text-slate-500 dark:text-slate-400">
            <span class="material-symbols-outlined mx-auto text-6xl text-slate-400 mb-4 block">group_off</span>
            <p class="text-lg font-medium text-slate-700 dark:text-slate-300">No agents found</p>
            <p class="text-sm mt-1">Get started by adding your first agent</p>
        </td>
    </tr>
<?php
else:
while ($a = $agents->fetch_assoc()):
    $balance = $a['remaining_amount'];
?>
<tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors duration-150 cursor-pointer" 
    onclick="viewLedger(<?= $a['id'] ?>, '<?= htmlspecialchars($a['name'], ENT_QUOTES) ?>')">
    <td class="px-4 md:px-6 py-3 md:py-4">
        <div class="flex items-center">
            <div class="flex-shrink-0 h-9 w-9 md:h-10 md:w-10 bg-primary/10 dark:bg-primary/20 rounded-full flex items-center justify-center text-primary font-bold text-base md:text-lg">
                <?= strtoupper(substr($a['name'], 0, 1)) ?>
            </div>
            <div class="ml-3 md:ml-4">
                <div class="text-sm font-semibold text-slate-900 dark:text-slate-100"><?= htmlspecialchars($a['name']) ?></div>
                <div class="text-xs text-slate-500 dark:text-slate-400">ID: A-<?= str_pad($a['id'], 4, '0', STR_PAD_LEFT) ?></div>
                <div class="lg:hidden text-xs text-slate-600 dark:text-slate-400 mt-1">
                    <?= nl2br(htmlspecialchars($a['contact'] ?: 'N/A')) ?>
                </div>
            </div>
        </div>
    </td>
    <td class="px-4 md:px-6 py-3 md:py-4 text-sm text-slate-700 dark:text-slate-300 hidden lg:table-cell">
        <?= nl2br(htmlspecialchars($a['contact'] ?: 'N/A')) ?>
    </td>
    <td class="px-4 md:px-6 py-3 md:py-4 text-sm text-slate-600 dark:text-slate-400 hidden xl:table-cell">
        <?= htmlspecialchars(substr($a['notes'], 0, 40)) ?><?= strlen($a['notes']) > 40 ? '...' : '' ?>
    </td>
    <td class="px-4 md:px-6 py-3 md:py-4 text-right">
        <span class="text-base md:text-lg font-bold <?= $balance >= 0 ? 'text-green-600' : 'text-red-600' ?>">
            Rs. <?= number_format($balance, 2) ?>
        </span>
    </td>
    <?php if ($isAdmin): ?>
    <td class="px-4 md:px-6 py-3 md:py-4 text-center">
        <div class="flex justify-center items-center gap-1.5 md:gap-2">
                <button onclick="event.stopPropagation(); openEditModal(<?= $a['id'] ?>)"
                    class="inline-flex items-center justify-center size-8 text-amber-600 dark:text-amber-400 hover:bg-amber-50 dark:hover:bg-amber-900/20 rounded-lg transition-colors" 
                    title="Edit Agent">
                    <span class="material-symbols-outlined text-[18px]">edit</span>
                </button>
                <button onclick="event.stopPropagation(); deleteAgent(<?= $a['id'] ?>, '<?= htmlspecialchars($a['name'], ENT_QUOTES) ?>')" 
                    class="inline-flex items-center justify-center size-8 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition-colors" 
                    title="Delete Agent">
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

/* Custom Scrollbar */
.custom-scrollbar::-webkit-scrollbar {
    width: 8px;
}

.custom-scrollbar::-webkit-scrollbar-track {
    background: #f1f5f9;
}

.custom-scrollbar::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 4px;
}

.custom-scrollbar::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}

.dark .custom-scrollbar::-webkit-scrollbar-track {
    background: #1e293b;
}

.dark .custom-scrollbar::-webkit-scrollbar-thumb {
    background: #475569;
}

.dark .custom-scrollbar::-webkit-scrollbar-thumb:hover {
    background: #64748b;
}
</style>

<!-- Ledger View Removed (Replaced by Modal) -->

</div>
</div>

<!-- Add Agent Modal -->
<div id="addAgentModal" class="hidden fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center p-4 animate-fadeIn">
    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-md mx-auto overflow-hidden transform transition-all animate-slideIn max-h-[90vh] flex flex-col">
        <!-- Modal Header -->
        <div class="bg-gradient-to-r from-indigo-600 via-blue-600 to-purple-600 text-white px-5 py-3">
            <div class="flex justify-between items-center">
                <h3 class="text-lg font-bold flex items-center gap-2">
                    <span class="material-symbols-outlined">person_add</span>
                    Add New Agent
                </h3>
                <button type="button" onclick="closeAddModal()" class="text-white/90 hover:text-white hover:bg-white/20 rounded-lg p-1.5 transition-all">
                    <span class="material-symbols-outlined text-xl">close</span>
                </button>
            </div>
        </div>
        
        <!-- Modal Body -->
        <form id="addAgentForm" class="p-5 space-y-4 overflow-y-auto">
            <!-- Agent Name -->
            <div class="space-y-1.5">
                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300">
                    <span class="flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-base">badge</span>
                        Agent Name
                        <span class="text-red-500">*</span>
                    </span>
                </label>
                <input type="text" name="name" required
                       class="w-full px-3 py-2 border-2 border-slate-200 dark:border-slate-700 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent dark:bg-slate-800 dark:text-white transition-all text-sm"
                       placeholder="Enter agent name">
            </div>
            
            <!-- Contact Information -->
            <div class="space-y-1.5">
                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300">
                    <span class="flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-base">contact_phone</span>
                        Contact Information
                    </span>
                </label>
                <textarea name="contact" rows="2"
                          class="w-full px-3 py-2 border-2 border-slate-200 dark:border-slate-700 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent dark:bg-slate-800 dark:text-white transition-all text-sm resize-none"
                          placeholder="Phone, email, address, etc."></textarea>
            </div>
            
            <!-- Notes -->
            <div class="space-y-1.5">
                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300">
                    <span class="flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-base">description</span>
                        Notes
                    </span>
                </label>
                <textarea name="notes" rows="2"
                          class="w-full px-3 py-2 border-2 border-slate-200 dark:border-slate-700 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent dark:bg-slate-800 dark:text-white transition-all text-sm resize-none"
                          placeholder="Additional notes or comments..."></textarea>
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
                    Save Agent
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Agent Modal -->
<div id="editAgentModal" class="hidden fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center p-4 animate-fadeIn">
    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-md mx-auto overflow-hidden transform transition-all animate-slideIn max-h-[90vh] flex flex-col">
        <!-- Modal Header -->
        <div class="bg-gradient-to-r from-amber-500 via-orange-500 to-yellow-600 text-white px-5 py-3">
            <div class="flex justify-between items-center">
                <h3 class="text-lg font-bold flex items-center gap-2">
                    <span class="material-symbols-outlined">edit</span>
                    Edit Agent
                </h3>
                <button type="button" onclick="closeEditModal()" class="text-white/90 hover:text-white hover:bg-white/20 rounded-lg p-1.5 transition-all">
                    <span class="material-symbols-outlined text-xl">close</span>
                </button>
            </div>
        </div>
        
        <!-- Modal Body -->
        <form id="editAgentForm" class="p-5 space-y-4 overflow-y-auto">
            <input type="hidden" name="id" id="editAgentId">
            
            <!-- Agent Name -->
            <div class="space-y-1.5">
                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300">
                    <span class="flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-base">badge</span>
                        Agent Name
                        <span class="text-red-500">*</span>
                    </span>
                </label>
                <input type="text" name="name" id="editAgentName" required
                       class="w-full px-3 py-2 border-2 border-slate-200 dark:border-slate-700 rounded-lg focus:ring-2 focus:ring-amber-500 focus:border-transparent dark:bg-slate-800 dark:text-white transition-all text-sm"
                       placeholder="Enter agent name">
            </div>
            
            <!-- Contact Information -->
            <div class="space-y-1.5">
                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300">
                    <span class="flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-base">contact_phone</span>
                        Contact Information
                    </span>
                </label>
                <textarea name="contact" id="editAgentContact" rows="2"
                          class="w-full px-3 py-2 border-2 border-slate-200 dark:border-slate-700 rounded-lg focus:ring-2 focus:ring-amber-500 focus:border-transparent dark:bg-slate-800 dark:text-white transition-all text-sm resize-none"
                          placeholder="Phone, email, address, etc."></textarea>
            </div>
            
            <!-- Notes -->
            <div class="space-y-1.5">
                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300">
                    <span class="flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-base">description</span>
                        Notes
                    </span>
                </label>
                <textarea name="notes" id="editAgentNotes" rows="2"
                          class="w-full px-3 py-2 border-2 border-slate-200 dark:border-slate-700 rounded-lg focus:ring-2 focus:ring-amber-500 focus:border-transparent dark:bg-slate-800 dark:text-white transition-all text-sm resize-none"
                          placeholder="Additional notes or comments..."></textarea>
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

<!-- View Agent Ledger Modal -->
<div id="viewAgentModal" class="hidden fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center p-4 animate-fadeIn">
    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-4xl mx-auto overflow-hidden transform transition-all animate-slideIn max-h-[90vh] flex flex-col">
        <!-- Modal Header -->
        <div class="bg-gradient-to-r from-emerald-600 via-teal-600 to-cyan-600 text-white px-5 py-3">
            <div class="flex justify-between items-center">
                <div>
                    <h3 class="text-lg font-bold flex items-center gap-2" id="viewAgentName">
                        <span class="material-symbols-outlined">account_circle</span>
                        Agent Details
                    </h3>
                    <p class="text-white/80 text-xs mt-0.5">Account Overview & Transactions</p>
                </div>
                <button onclick="closeViewModal()" class="text-white/90 hover:text-white hover:bg-white/20 rounded-lg p-1.5 transition-all">
                    <span class="material-symbols-outlined text-xl">close</span>
                </button>
            </div>
        </div>
        
        <!-- Modal Body -->
        <div class="p-5 overflow-y-auto custom-scrollbar">
            <!-- Add Payment Section (Admin Only) -->
            <?php if ($isAdmin): ?>
            <div class="bg-gradient-to-br from-green-50 to-emerald-50 dark:from-green-900/20 dark:to-emerald-900/20 border-2 border-green-200 dark:border-green-700 rounded-xl p-4 mb-6 shadow-sm">
                <div class="flex items-center justify-between mb-3">
                    <h4 class="font-bold text-gray-800 dark:text-gray-100 flex items-center gap-2">
                        <span class="material-symbols-outlined text-green-600 dark:text-green-400">payments</span>
                        Add Payment
                    </h4>
                    <button type="button" id="togglePaymentForm" onclick="togglePaymentSection()" class="text-green-600 dark:text-green-400 hover:text-green-800 dark:hover:text-green-300 transition-colors p-1 hover:bg-green-100 dark:hover:bg-green-900/30 rounded-lg">
                        <span id="paymentToggleIcon" class="material-symbols-outlined">expand_more</span>
                    </button>
                </div>
                <div id="paymentFormSection" class="hidden">
                    <form id="inlinePaymentForm" class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <input type="hidden" name="agent_id" id="inlinePaymentAgentId">
                        <div class="space-y-1.5">
                            <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300">
                                <span class="flex items-center gap-1">
                                    <span class="material-symbols-outlined text-sm">attach_money</span>
                                    Amount <span class="text-red-500">*</span>
                                </span>
                            </label>
                            <input type="number" name="amount" required step="0.01" min="0.01" placeholder="0.00"
                                   class="w-full px-3 py-2 border-2 border-slate-200 dark:border-slate-700 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent dark:bg-slate-800 dark:text-white text-sm">
                        </div>
                        <div class="space-y-1.5">
                            <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300">
                                <span class="flex items-center gap-1">
                                    <span class="material-symbols-outlined text-sm">calendar_today</span>
                                    Date
                                </span>
                            </label>
                            <input type="date" name="date" value="<?= date('Y-m-d') ?>"
                                   class="w-full px-3 py-2 border-2 border-slate-200 dark:border-slate-700 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent dark:bg-slate-800 dark:text-white text-sm">
                        </div>
                        <div class="space-y-1.5">
                            <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300">
                                <span class="flex items-center gap-1">
                                    <span class="material-symbols-outlined text-sm">note</span>
                                    Note
                                </span>
                            </label>
                            <input type="text" name="note" placeholder="Optional note"
                                   class="w-full px-3 py-2 border-2 border-slate-200 dark:border-slate-700 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent dark:bg-slate-800 dark:text-white text-sm">
                        </div>
                        <div class="space-y-1.5">
                            <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300">
                                <span class="flex items-center gap-1">
                                    <span class="material-symbols-outlined text-sm">receipt_long</span>
                                    Payment Proof
                                </span>
                            </label>
                            <input type="file" name="proof" accept=".pdf,.jpg,.jpeg,.png,.gif,.webp"
                                   class="w-full px-3 py-2 border-2 border-slate-200 dark:border-slate-700 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent dark:bg-slate-800 dark:text-white text-sm file:mr-2 file:py-1 file:px-3 file:rounded-lg file:border-0 file:text-xs file:bg-green-100 dark:file:bg-green-900/50 file:text-green-700 dark:file:text-green-300 hover:file:bg-green-200 dark:hover:file:bg-green-900/70 file:transition-all">
                        </div>
                        <div class="md:col-span-2 flex justify-end">
                            <button type="submit"
                                    class="px-4 py-2 bg-gradient-to-r from-green-600 to-emerald-600 text-white rounded-lg hover:from-green-700 hover:to-emerald-700 transition-all shadow-lg shadow-green-500/30 text-sm font-semibold flex items-center gap-2">
                                <span class="material-symbols-outlined text-base">check_circle</span>
                                Record Payment
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <!-- Stats Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 md:gap-4 mb-6">
                <div class="bg-gradient-to-br from-blue-50 to-indigo-50 dark:from-blue-900/20 dark:to-indigo-900/20 rounded-xl p-3 md:p-4 border-2 border-blue-200 dark:border-blue-700 shadow-sm">
                    <div class="flex items-center gap-2 mb-2">
                        <span class="material-symbols-outlined text-blue-600 dark:text-blue-400 text-lg md:text-xl">account_balance</span>
                        <p class="text-xs font-bold text-blue-700 dark:text-blue-300 uppercase tracking-wider">Total Balance</p>
                    </div>
                    <p class="text-xl md:text-2xl font-bold text-gray-900 dark:text-gray-100" id="viewTotalBalance">0.00</p>
                </div>
                <div class="bg-gradient-to-br from-green-50 to-emerald-50 dark:from-green-900/20 dark:to-emerald-900/20 rounded-xl p-3 md:p-4 border-2 border-green-200 dark:border-green-700 shadow-sm">
                    <div class="flex items-center gap-2 mb-2">
                        <span class="material-symbols-outlined text-green-600 dark:text-green-400 text-lg md:text-xl">trending_up</span>
                        <p class="text-xs font-bold text-green-700 dark:text-green-300 uppercase tracking-wider">Received</p>
                    </div>
                    <p class="text-xl md:text-2xl font-bold text-gray-900 dark:text-gray-100" id="viewReceived">0.00</p>
                </div>
                <div class="bg-gradient-to-br from-purple-50 to-pink-50 dark:from-purple-900/20 dark:to-pink-900/20 rounded-xl p-3 md:p-4 border-2 border-purple-200 dark:border-purple-700 shadow-sm sm:col-span-2 lg:col-span-1">
                    <div class="flex items-center gap-2 mb-2">
                        <span class="material-symbols-outlined text-purple-600 dark:text-purple-400 text-lg md:text-xl">pending</span>
                        <p class="text-xs font-bold text-purple-700 dark:text-purple-300 uppercase tracking-wider">Remaining</p>
                    </div>
                    <p class="text-xl md:text-2xl font-bold text-gray-900 dark:text-gray-100" id="viewRemaining">0.00</p>
                </div>
            </div>

            <!-- Transactions Table -->
            <h4 class="font-bold text-gray-800 dark:text-gray-100 mb-4 flex items-center gap-2 text-sm md:text-base">
                <span class="material-symbols-outlined text-gray-600 dark:text-gray-400">receipt</span>
                Transaction History
            </h4>
            <div class="overflow-x-auto border-2 border-slate-200 dark:border-slate-700 rounded-xl shadow-sm">
                <table class="w-full text-sm text-left min-w-[650px]">
                    <thead class="bg-gradient-to-r from-slate-50 to-slate-100 dark:from-slate-800 dark:to-slate-700 text-gray-700 dark:text-gray-200 font-semibold border-b-2 border-slate-200 dark:border-slate-600">
                        <tr>
                            <th class="px-3 md:px-5 py-2 md:py-3 text-xs md:text-sm">Date</th>
                            <th class="px-3 md:px-5 py-2 md:py-3 text-xs md:text-sm">Container</th>
                            <th class="px-3 md:px-5 py-2 md:py-3 text-xs md:text-sm">Description</th>
                            <th class="px-3 md:px-5 py-2 md:py-3 text-right text-xs md:text-sm">Credit</th>
                            <th class="px-3 md:px-5 py-2 md:py-3 text-right text-xs md:text-sm">Debit</th>
                            <th class="px-3 md:px-5 py-2 md:py-3 text-center text-xs md:text-sm">Proof</th>
                            <?php if ($isAdmin): ?>
                            <th class="px-3 md:px-5 py-2 md:py-3 text-center text-xs md:text-sm">Actions</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-700 bg-white dark:bg-slate-900" id="viewTransactionsBody">
                        <!-- Transactions will be loaded here -->
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Modal Footer -->
        <div class="flex flex-col sm:flex-row justify-end gap-2 md:gap-2.5 p-3 md:p-4 border-t-2 border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900 flex-shrink-0">
            <button type="button" onclick="printAgentTransactionHistory()"
                    class="w-full sm:w-auto px-4 md:px-5 py-2 md:py-2.5 bg-gradient-to-r from-blue-600 to-indigo-600 text-white rounded-lg hover:from-blue-700 hover:to-indigo-700 transition-all shadow-lg shadow-blue-500/30 flex items-center justify-center gap-2 font-semibold text-sm">
                <span class="material-symbols-outlined text-base">print</span>
                Print
            </button>
            <button type="button" onclick="closeViewModal()"
                    class="w-full sm:w-auto px-4 md:px-5 py-2 md:py-2.5 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded-lg hover:bg-slate-200 dark:hover:bg-slate-700 transition-all font-semibold text-sm">
                Close
            </button>
        </div>
    </div>
</div>

<!-- Proof Preview Modal -->
<div id="proofPreviewModal" class="hidden fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center p-4 animate-fadeIn">
    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-4xl mx-auto overflow-hidden transform transition-all animate-slideIn max-h-[90vh] flex flex-col">
        <!-- Modal Header -->
        <div class="bg-gradient-to-r from-blue-600 via-indigo-600 to-purple-600 text-white px-5 py-3 flex items-center justify-between">
            <h3 class="text-lg font-bold flex items-center gap-2">
                <span class="material-symbols-outlined">description</span>
                Proof Preview
            </h3>
            <div class="flex items-center gap-2">
                <button type="button" id="downloadProofBtn" onclick="downloadProof()" class="text-white/90 hover:text-white hover:bg-white/20 rounded-lg px-3 py-1.5 transition-all flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-[20px]">download</span>
                    <span class="hidden sm:inline text-sm font-semibold">Download</span>
                </button>
                <button type="button" onclick="closeProofPreview()" class="text-white/90 hover:text-white hover:bg-white/20 rounded-lg p-1.5 transition-all">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
        </div>
        
        <!-- Modal Body -->
        <div class="p-5 overflow-y-auto custom-scrollbar flex-1 bg-slate-50 dark:bg-slate-900">
            <div id="proofPreviewContent" class="flex items-center justify-center min-h-[400px]">
                <div class="text-slate-500 dark:text-slate-400">Loading...</div>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteConfirmModal" class="hidden fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center p-4 animate-fadeIn">
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
            <p class="text-slate-600 dark:text-slate-400 text-sm mb-3">Are you sure you want to delete this agent?</p>
            <div class="bg-slate-100 dark:bg-slate-800 rounded-lg p-3 mb-4">
                <p class="text-base font-bold text-slate-900 dark:text-slate-100" id="deleteAgentName"></p>
            </div>
            <div class="bg-gradient-to-r from-yellow-50 to-orange-50 dark:from-yellow-900/20 dark:to-orange-900/20 border-2 border-yellow-300 dark:border-yellow-700 rounded-xl p-3.5 flex gap-3">
                <span class="material-symbols-outlined text-yellow-700 dark:text-yellow-400 text-xl flex-shrink-0">info</span>
                <div>
                    <p class="text-sm font-semibold text-yellow-900 dark:text-yellow-200 mb-1">Warning!</p>
                    <p class="text-xs text-yellow-800 dark:text-yellow-300">
                        This will permanently delete the agent and all associated transactions. This action cannot be undone.
                    </p>
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

<script>
(function() {
    'use strict';

    // Company details for printing
    var printCompanyDetails = <?php echo json_encode($companyDetails); ?>;
    
    // Store current agent data for printing
    window.currentAgentAccount = null;

    // Modal Functions
    window.openAddModal = function() {
        document.getElementById('addAgentModal').classList.remove('hidden');
        document.getElementById('addAgentModal').classList.add('flex');
        document.getElementById('addAgentForm').reset();
    };

    window.closeAddModal = function() {
        document.getElementById('addAgentModal').classList.add('hidden');
        document.getElementById('addAgentModal').classList.remove('flex');
    };

    window.openEditModal = function(id) {
        fetch('agents.php?action=get&id=' + id, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                document.getElementById('editAgentId').value = data.data.id;
                document.getElementById('editAgentName').value = data.data.name;
                document.getElementById('editAgentContact').value = data.data.contact || '';
                document.getElementById('editAgentNotes').value = data.data.notes || '';
                
                document.getElementById('editAgentModal').classList.remove('hidden');
                document.getElementById('editAgentModal').classList.add('flex');
            }
        });
    };

    window.closeEditModal = function() {
        document.getElementById('editAgentModal').classList.add('hidden');
        document.getElementById('editAgentModal').classList.remove('flex');
    };

    // Store delete agent ID temporarily
    var deleteAgentId = null;

    window.deleteAgent = function(id, name) {
        deleteAgentId = id;
        document.getElementById('deleteAgentName').textContent = name;
        document.getElementById('deleteConfirmModal').classList.remove('hidden');
        document.getElementById('deleteConfirmModal').classList.add('flex');
    };

    window.closeDeleteModal = function() {
        deleteAgentId = null;
        document.getElementById('deleteConfirmModal').classList.add('hidden');
        document.getElementById('deleteConfirmModal').classList.remove('flex');
    };

    window.confirmDelete = function() {
        if (!deleteAgentId) return;
        
        var formData = new FormData();
        fetch('agents.php?action=delete&id=' + deleteAgentId, {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                closeDeleteModal();
                location.reload();
            } else {
                alert('Error deleting agent. Please try again.');
            }
        })
        .catch(function(error) {
            console.error('Error:', error);
            alert('Error deleting agent. Please try again.');
        });
    };

    window.viewLedger = function(id, name) {
        document.getElementById('viewAgentName').textContent = name;
        
        // Show modal immediately with loading state if desired, or wait for fetch
        // For now, let's just fetch
        
        fetch('agents.php?action=get_ledger&id=' + id, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                // Store data for printing
                window.currentAgentAccount = {
                    agent: data.agent,
                    agentName: name,
                    stats: data.stats,
                    transactions: data.transactions
                };
                
                document.getElementById('viewTotalBalance').textContent = formatCurrency(data.stats.total_credit); 
                document.getElementById('viewReceived').textContent = formatCurrency(data.stats.total_debit);
                document.getElementById('viewRemaining').textContent = formatCurrency(data.stats.balance);
                refreshAgentBalanceInList(id, data.stats.balance);
                
                var tbody = document.getElementById('viewTransactionsBody');
                tbody.innerHTML = '';
                
                if (data.transactions.length === 0) {
                    var colspan = <?php echo $isAdmin ? '7' : '6'; ?>;
                    tbody.innerHTML = '<tr><td colspan="' + colspan + '" class="px-6 py-4 text-center text-gray-500">No transactions found</td></tr>';
                } else {
                    data.transactions.forEach(function(t) {
                        var tr = document.createElement('tr');
                        tr.className = 'hover:bg-gray-50 dark:hover:bg-slate-800/50';
                        
                        // Make container reference plain text
                        var description = t.description || '-';
                        
                        // Build container cell
                        var containerCell = '';
                        if (t.container_id) {
                            containerCell = '<span class="inline-flex items-center px-2 py-1 rounded-md bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-300 text-xs font-semibold">C-' + String(t.container_id).padStart(4, '0') + '</span>';
                        } else {
                            containerCell = '<span class="text-slate-400 text-xs">N/A</span>';
                        }
                        
                        // Build proof cell content
                        var proofCell = '';
                        if (t.proof) {
                            var proofUrl = 'uploads/agent_payments/' + t.proof;
                            var ext = t.proof.split('.').pop().toLowerCase();
                            
                            proofCell = '<button onclick="openProofPreview(\'' + proofUrl + '\', \'' + t.proof + '\')" ' +
                                'class="px-3 py-1.5 bg-blue-50 dark:bg-blue-900/20 text-blue-600 dark:text-blue-400 rounded-lg hover:bg-blue-100 dark:hover:bg-blue-900/40 transition-colors flex items-center gap-1.5 text-xs font-semibold mx-auto">' +
                                '<span class="material-symbols-outlined text-[16px]">visibility</span>' +
                                '<span>View</span>' +
                                '</button>';
                        } else {
                            proofCell = '<span class="text-slate-400 text-xs">N/A</span>';
                        }
                        
                        tr.innerHTML = 
                            '<td class="px-6 py-3 text-sm text-gray-900 dark:text-gray-100">' + formatDate(t.created_at) + '</td>' +
                            '<td class="px-6 py-3 text-sm">' + containerCell + '</td>' +
                            '<td class="px-6 py-3 text-sm text-gray-700 dark:text-gray-300">' + description + '</td>' +
                            '<td class="px-6 py-3 text-sm text-right font-semibold text-green-600 dark:text-green-400">' + (parseFloat(t.credit) > 0 ? 'Rs. ' + formatCurrency(t.credit) : '-') + '</td>' +
                            '<td class="px-6 py-3 text-sm text-right font-semibold text-red-600 dark:text-red-400">' + (parseFloat(t.debit) > 0 ? 'Rs. ' + formatCurrency(t.debit) : '-') + '</td>' +
                            '<td class="px-6 py-3 text-center">' + proofCell + '</td>' +
                            <?php if ($isAdmin): ?>
                            '<td class="px-6 py-3 text-center">' +
                            '<button onclick="deleteTransaction(' + t.id + ', ' + id + ')" ' +
                            'class="text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-300 hover:bg-red-50 dark:hover:bg-red-900/20 p-1.5 rounded transition-colors" title="Delete Transaction">' +
                            '<span class="material-symbols-outlined text-[18px]">delete</span>' +
                            '</button>' +
                            '</td>' +
                            <?php endif; ?>
                            '';
                        tbody.appendChild(tr);
                    });
                }
                
                // Set agent ID for inline payment form
                var inlinePaymentAgentId = document.getElementById('inlinePaymentAgentId');
                if (inlinePaymentAgentId) {
                    inlinePaymentAgentId.value = id;
                }
                
                document.getElementById('viewAgentModal').classList.remove('hidden');
                document.getElementById('viewAgentModal').classList.add('flex');
            }
        });
    };

    window.closeViewModal = function() {
        document.getElementById('viewAgentModal').classList.add('hidden');
        document.getElementById('viewAgentModal').classList.remove('flex');
        // Reset payment form section
        var paymentSection = document.getElementById('paymentFormSection');
        if (paymentSection) {
            paymentSection.classList.add('hidden');
        }
        var toggleIcon = document.getElementById('paymentToggleIcon');
        if (toggleIcon) {
            toggleIcon.textContent = 'expand_more';
        }
    };
    
    window.togglePaymentSection = function() {
        var section = document.getElementById('paymentFormSection');
        var icon = document.getElementById('paymentToggleIcon');
        
        if (section.classList.contains('hidden')) {
            section.classList.remove('hidden');
            icon.textContent = 'expand_less';
        } else {
            section.classList.add('hidden');
            icon.textContent = 'expand_more';
        }
    };
    
    window.printAgentTransactionHistory = function() {
        if (!window.currentAgentAccount) {
            alert('No agent data available to print.');
            return;
        }

        var account = window.currentAgentAccount;
        var agent = account.agent || {};
        var stats = account.stats || { total_credit: 0, total_debit: 0, balance: 0 };
        var transactions = Array.isArray(account.transactions) ? account.transactions.slice() : [];

        var esc = function(value) {
            return String(value == null ? '' : value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        };

        // Sort transactions by date
        transactions.sort(function(a, b) {
            var aDate = new Date(a.created_at || 0);
            var bDate = new Date(b.created_at || 0);
            return aDate - bDate;
        });

        var rowsHtml = '';
        if (transactions.length === 0) {
            rowsHtml = '<tr><td colspan="6" style="text-align:center;padding:12px;color:#666;">No transactions found</td></tr>';
        } else {
            var runningBalance = 0;
            transactions.forEach(function(tx, idx) {
                var credit = parseFloat(tx.credit || 0);
                var debit = parseFloat(tx.debit || 0);
                runningBalance += credit - debit;
                var isLastRow = idx === (transactions.length - 1);
                var balanceCellStyle = isLastRow
                    ? 'text-align:right;color:#b91c1c;font-weight:700;'
                    : 'text-align:right;';
                
                var containerStr = tx.container_id ? 'C-' + String(tx.container_id).padStart(4, '0') : 'N/A';

                rowsHtml += '<tr>' +
                    '<td>' + esc(new Date(tx.created_at).toLocaleDateString('en-GB')) + '</td>' +
                    '<td>' + esc(containerStr) + '</td>' +
                    '<td>' + esc(tx.description || '-') + '</td>' +
                    '<td style="text-align:right;">' + (credit > 0 ? credit.toFixed(2) : '-') + '</td>' +
                    '<td style="text-align:right;">' + (debit > 0 ? debit.toFixed(2) : '-') + '</td>' +
                    '<td style="' + balanceCellStyle + '">' + runningBalance.toFixed(2) + '</td>' +
                '</tr>';
            });
        }

        var companyName = esc(printCompanyDetails.name || 'Company');
        var companyLocation = esc(printCompanyDetails.location || '');
        var companyContact = esc(printCompanyDetails.contact || '');
        var companyEmail = esc(printCompanyDetails.email || '');
        var logoHtml = printCompanyDetails.logo
            ? '<img src="' + esc(printCompanyDetails.logo) + '" alt="Logo" style="width:82px;height:82px;object-fit:contain;">'
            : '';

        var today = new Date();
        var printedOn = today.toLocaleDateString('en-GB');

        var printWindow = window.open('', '_blank', 'width=1100,height=800');
        if (!printWindow) {
            alert('Please allow popups to print the statement.');
            return;
        }

        var html = '<!DOCTYPE html><html><head><title>Agent Transaction History</title>' +
            '<style>' +
            'body{font-family:Arial,sans-serif;color:#111;margin:0;padding:0;font-size:12px;}' +
            '.page{padding:0 28px 28px;}' +
            '.header{display:flex;align-items:center;justify-content:center;gap:20px;margin-bottom:10px;}' +
            '.logo-wrap{flex-shrink:0;}' +
            '.company-details{flex-shrink:0;}' +
            '.company-name{font-size:24px;font-weight:700;letter-spacing:0.4px;}' +
            '.company-meta{font-size:12px;color:#333;line-height:1.5;}' +
            '.divider{border-top:1.5px solid #111;margin:14px 0 10px;}' +
            '.report-title{text-align:center;font-size:18px;font-weight:700;margin:10px 0 14px;letter-spacing:0.3px;}' +
            '.agent-info{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px 24px;margin-bottom:10px;}' +
            '.agent-info div{font-size:12px;}' +
            '.agent-summary{margin:6px 0 12px;color:#b91c1c;font-weight:700;font-size:12px;text-align:center;}' +
            'table{width:100%;border-collapse:collapse;}' +
            'th,td{border:1px solid #222;padding:7px 8px;font-size:11px;vertical-align:middle;text-align:center;}' +
            'th{background:#f2f2f2;text-transform:uppercase;}' +
            '.text-right{text-align:right;}' +
            '@media print{@page{margin:0;size:auto;} body{margin:0;padding:0;} .page{padding:8mm 14mm 14mm;} .no-print{display:none;}}' +
            '</style></head><body>' +
            '<div class="page">' +
            '<div class="header">' +
                '<div class="logo-wrap">' + logoHtml + '</div>' +
                '<div class="company-details">' +
                    '<div class="company-name">' + companyName + '</div>' +
                    '<div class="company-meta">' + companyLocation + '</div>' +
                    '<div class="company-meta">' + companyContact + (companyEmail ? ' | ' + companyEmail : '') + '</div>' +
                '</div>' +
            '</div>' +
            '<div class="divider"></div>' +
            '<div class="report-title">Agent Transaction History</div>' +
            '<div class="agent-info">' +
                '<div><strong>Agent Name:</strong> ' + esc(agent.name || account.agentName || '-') + '</div>' +
                '<div style="text-align:right;"><strong>Phone:</strong> ' + esc(agent.contact || '-') + '</div>' +
                '<div><strong>Address:</strong> ' + esc(agent.notes || '-') + '</div>' +
                '<div style="text-align:right;"></div>' +
            '</div>' +
            '<div class="agent-summary">' +
                'Total: Rs. ' + parseFloat(stats.total_credit || 0).toFixed(2) +
                ' &nbsp;|&nbsp; Paid: Rs. ' + parseFloat(stats.total_debit || 0).toFixed(2) +
                ' &nbsp;|&nbsp; Remaining: Rs. ' + parseFloat(stats.balance || 0).toFixed(2) +
            '</div>' +
            '<table>' +
                '<thead><tr>' +
                    '<th style="width:12%;">Date</th>' +
                    '<th style="width:12%;">Container</th>' +
                    '<th>Description</th>' +
                    '<th style="width:12%;" class="text-right">Credit</th>' +
                    '<th style="width:12%;" class="text-right">Debit</th>' +
                    '<th style="width:12%;" class="text-right">Balance</th>' +
                '</tr></thead>' +
                '<tbody>' + rowsHtml + '</tbody>' +
            '</table>' +
            '</div>' +
            '<script>window.onload=function(){window.print();setTimeout(function(){window.close();},300);};<\/script>' +
            '</body></html>';

        printWindow.document.write(html);
        printWindow.document.close();
    };
    
    function formatCurrency(amount) {
        return new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(amount);
    }
    
    function formatDate(dateString) {
        var date = new Date(dateString);
        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    }

    function refreshAgentBalanceInList(agentId, balance) {
        var numericBalance = parseFloat(balance || 0);
        var formatted = 'Rs. ' + formatCurrency(numericBalance);

        // Update desktop table row balance.
        var desktopRow = document.querySelector('tr[onclick*="viewLedger(' + agentId + ',"]');
        if (desktopRow && desktopRow.cells.length >= 4) {
            var balanceCellSpan = desktopRow.cells[3].querySelector('span');
            if (balanceCellSpan) {
                balanceCellSpan.textContent = formatted;
                balanceCellSpan.classList.remove('text-green-600', 'text-red-600');
                balanceCellSpan.classList.add(numericBalance >= 0 ? 'text-green-600' : 'text-red-600');
            }
        }

        // Update mobile card balance.
        var mobileCard = document.querySelector('.agent-card[onclick*="viewLedger(' + agentId + ',"]');
        if (mobileCard) {
            var balanceSpan = mobileCard.querySelector('div.pt-3.border-t span.text-lg.font-bold');
            if (balanceSpan) {
                balanceSpan.textContent = formatted;
                balanceSpan.classList.remove('text-green-600', 'text-red-600');
                balanceSpan.classList.add(numericBalance >= 0 ? 'text-green-600' : 'text-red-600');
            }
        }
    }
    
    function escapeHtml(text) {
        var map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    }

    function showSmallNotification(message, type) {
        // Prefer global toast when available.
        if (window.showToast && typeof window.showToast === 'function') {
            window.showToast(message, type || 'success');
            return;
        }

        var toast = document.createElement('div');
        var isError = type === 'error';
        toast.className = 'fixed top-4 right-4 z-[80] px-4 py-2 rounded-lg text-sm font-semibold shadow-lg transition-opacity duration-300 ' +
            (isError ? 'bg-red-600 text-white' : 'bg-emerald-600 text-white');
        toast.textContent = message;
        document.body.appendChild(toast);

        setTimeout(function() {
            toast.style.opacity = '0';
            setTimeout(function() {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            }, 300);
        }, 2200);
    }
    
    // Proof preview functions
    var currentProofUrl = '';
    var currentProofFileName = '';
    
    window.openProofPreview = function(url, fileName) {
        currentProofUrl = url;
        currentProofFileName = fileName;
        
        var ext = fileName.split('.').pop().toLowerCase();
        var isPdf = ext === 'pdf';
        var isImage = ['jpg', 'jpeg', 'png', 'gif', 'webp'].indexOf(ext) !== -1;
        
        var content = document.getElementById('proofPreviewContent');
        
        if (isPdf) {
            content.innerHTML = '<iframe src="' + url + '" class="w-full h-[600px] rounded-lg border-2 border-slate-200 dark:border-slate-700"></iframe>';
        } else if (isImage) {
            content.innerHTML = '<img src="' + url + '" alt="Proof" class="max-w-full max-h-[600px] rounded-lg shadow-lg mx-auto">';
        } else {
            content.innerHTML = '<div class="text-center py-12">' +
                '<span class="material-symbols-outlined text-6xl text-slate-400 mb-4 block">description</span>' +
                '<p class="text-slate-600 dark:text-slate-400 mb-4">Preview not available for this file type</p>' +
                '<button onclick="downloadProof()" class="px-4 py-2 bg-primary text-white rounded-lg hover:bg-primary/90 transition-colors">' +
                '<span class="material-symbols-outlined text-[18px] inline-block mr-1">download</span>Download File</button>' +
                '</div>';
        }
        
        document.getElementById('proofPreviewModal').classList.remove('hidden');
        document.getElementById('proofPreviewModal').classList.add('flex');
    };
    
    window.closeProofPreview = function() {
        document.getElementById('proofPreviewModal').classList.add('hidden');
        document.getElementById('proofPreviewModal').classList.remove('flex');
        currentProofUrl = '';
        currentProofFileName = '';
    };
    
    window.downloadProof = function() {
        if (!currentProofUrl) return;
        
        var ext = currentProofFileName.split('.').pop();
        var agentName = (window.currentAgentAccount && window.currentAgentAccount.agent && window.currentAgentAccount.agent.name)
            ? window.currentAgentAccount.agent.name.replace(/[^a-zA-Z0-9]/g, '_')
            : 'agent';
        var downloadName = agentName + '_agent_transaction_Proof.' + ext;
        
        var a = document.createElement('a');
        a.href = currentProofUrl;
        a.download = downloadName;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        
        // Show toast notification
        if (window.showToast) {
            window.showToast('Proof downloaded successfully', 'success');
        }
    };
    
    window.downloadProofDirect = function(url, fileName) {
        var ext = fileName.split('.').pop();
        var agentName = (window.currentAgentAccount && window.currentAgentAccount.agent && window.currentAgentAccount.agent.name)
            ? window.currentAgentAccount.agent.name.replace(/[^a-zA-Z0-9]/g, '_')
            : 'agent';
        var downloadName = agentName + '_agent_transaction_Proof.' + ext;
        
        var a = document.createElement('a');
        a.href = url;
        a.download = downloadName;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        
        // Show toast notification
        if (window.showToast) {
            window.showToast('Proof downloaded successfully', 'success');
        }
    };
    
    // Delete Transaction Function
    window.deleteTransaction = function(transactionId, agentId) {
        var formData = new FormData();
        formData.append('transaction_id', transactionId);
        
        fetch('agents.php?action=delete_transaction', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                // Refresh modal data instantly so stats and history stay in sync.
                var agentName = document.getElementById('viewAgentName').textContent;
                viewLedger(agentId, agentName);

                showSmallNotification('Transaction deleted successfully', 'success');
            } else {
                showSmallNotification(data.message || 'Error deleting transaction. Please try again.', 'error');
            }
        })
        .catch(function(error) {
            console.error('Error:', error);
            showSmallNotification('Error deleting transaction. Please try again.', 'error');
        });
    };

    // Form Handlers
    document.getElementById('addAgentForm').addEventListener('submit', function(e) {
        e.preventDefault();
        var formData = new FormData(this);
        
        fetch('agents.php?action=add', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                closeAddModal();
                location.reload();
            }
        });
    });

    document.getElementById('editAgentForm').addEventListener('submit', function(e) {
        e.preventDefault();
        var formData = new FormData(this);
        var id = document.getElementById('editAgentId').value;
        
        fetch('agents.php?action=edit&id=' + id, {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                closeEditModal();
                location.reload();
            }
        });
    });

    // Inline payment form handler
    var inlinePaymentForm = document.getElementById('inlinePaymentForm');
    if (inlinePaymentForm) {
        inlinePaymentForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var formData = new FormData(this);
            
            // Show loading state
            var submitBtn = this.querySelector('button[type="submit"]');
            var originalBtnText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<svg class="animate-spin h-4 w-4 mr-2 inline" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>Processing...';
            
            fetch('agents.php?action=add_payment', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalBtnText;
                if (data.success) {
                    // Reset form and collapse payment section
                    inlinePaymentForm.reset();
                    var paymentSection = document.getElementById('paymentFormSection');
                    if (paymentSection) paymentSection.classList.add('hidden');
                    var toggleIcon = document.getElementById('paymentToggleIcon');
                    if (toggleIcon) toggleIcon.textContent = 'expand_more';

                    // Refresh modal in-place without closing
                    var agentId = document.getElementById('inlinePaymentAgentId').value;
                    var agentName = document.getElementById('viewAgentName').textContent;
                    viewLedger(agentId, agentName);
                    showSmallNotification('Payment added successfully', 'success');
                } else {
                    alert('Error: ' + (data.message || 'Failed to add payment'));
                }
            })
            .catch(function(error) {
                console.error('Error:', error);
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalBtnText;
                alert('Error adding payment. Please try again.');
            });
        });
    }

    // Close modals on outside click
    window.addEventListener('click', function(e) {
        if (e.target.id === 'addAgentModal') {
            closeAddModal();
        }
        if (e.target.id === 'editAgentModal') {
            closeEditModal();
        }
        if (e.target.id === 'viewAgentModal') {
            closeViewModal();
        }
        if (e.target.id === 'deleteConfirmModal') {
            closeDeleteModal();
        }
        if (e.target.id === 'proofPreviewModal') {
            closeProofPreview();
        }
    });

    // Close modals on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeAddModal();
            closeEditModal();
            closeViewModal();
            closeDeleteModal();
            closeProofPreview();
        }
    });
    
    // Filter Panel Functions
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
        var nameFilter = document.getElementById('filterName').value.toLowerCase().trim();
        var contactFilter = document.getElementById('filterContact').value.toLowerCase().trim();
        var notesFilter = document.getElementById('filterNotes').value.toLowerCase().trim();
        
        // Filter desktop table rows
        var tbody = document.getElementById('agentsTableBody');
        if (!tbody) {
            console.error('Agents table body not found');
            return;
        }
        
        var rows = tbody.querySelectorAll('tr');
        var visibleCount = 0;
        var totalCount = 0;
        
        rows.forEach(function(row) {
            // Skip the "no agents found" row (partner view has 4 cols, admin has 5)
            if (row.cells.length < 4) {
                return;
            }
            
            totalCount++;
            
            // Get text content from cells
            var nameCell = row.cells[0].textContent.toLowerCase();
            var contactCell = row.cells[1] ? row.cells[1].textContent.toLowerCase() : '';
            var notesCell = row.cells[2] ? row.cells[2].textContent.toLowerCase() : '';
            
            // Check if row matches all active filters
            var nameMatch = !nameFilter || nameCell.includes(nameFilter);
            var contactMatch = !contactFilter || contactCell.includes(contactFilter) || nameCell.includes(contactFilter);
            var notesMatch = !notesFilter || notesCell.includes(notesFilter) || nameCell.includes(notesFilter);
            
            if (nameMatch && contactMatch && notesMatch) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });
        
        // Filter mobile cards
        var mobileCards = document.querySelectorAll('.agent-card');
        var mobileVisibleCount = 0;
        var mobileTotalCount = mobileCards.length;
        
        mobileCards.forEach(function(card) {
            var cardName = card.getAttribute('data-agent-name') || '';
            var cardContact = card.getAttribute('data-agent-contact') || '';
            var cardNotes = card.getAttribute('data-agent-notes') || '';
            
            // Check if card matches all active filters
            var nameMatch = !nameFilter || cardName.includes(nameFilter);
            var contactMatch = !contactFilter || cardContact.includes(contactFilter);
            var notesMatch = !notesFilter || cardNotes.includes(notesFilter);
            
            if (nameMatch && contactMatch && notesMatch) {
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
        
        if (nameFilter || contactFilter || notesFilter) {
            statsDiv.textContent = 'Showing ' + displayCount + ' of ' + displayTotal + ' agents';
        } else {
            statsDiv.textContent = '';
        }
    };
    
    window.clearFilters = function() {
        document.getElementById('filterName').value = '';
        document.getElementById('filterContact').value = '';
        document.getElementById('filterNotes').value = '';
        applyFilters();
    };
})();
</script>

<style>
html,
body {
    min-height: 100%;
    margin: 0 !important;
    padding: 0 !important;
    overflow-x: hidden;
}

/* Desktop Layout - Static Sidebar, Scrolling Content */
@media (min-width: 768px) {
    html,
    body {
        height: 100vh;
        overflow: hidden;
    }
    
    #mainContent {
        height: 100vh;
        overflow-y: auto;
    }
    
    #sidebar {
        height: 100vh;
        overflow-y: auto;
    }
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
        transform: translateX(-100%);
        transition: transform 0.25s ease;
        box-shadow: 0 20px 40px rgba(15, 23, 42, 0.2);
        /* Prevent flash on page load */
        visibility: visible;
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
@media (max-width: 1023px) {
    .table-responsive {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
}

/* Mobile card styles */
@media (max-width: 767px) {
    /* Smooth touch interactions for cards */
    .agent-card {
        -webkit-tap-highlight-color: transparent;
    }
    
    /* Better spacing on very small screens */
    @media (max-width: 374px) {
        .agent-card {
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

<script>
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

// Ensure sidebar is closed on page load for mobile
(function() {
    if (window.innerWidth < 768) {
        var sidebar = document.getElementById('sidebar');
        var overlay = document.getElementById('mobileSidebarOverlay');
        if (sidebar) sidebar.classList.remove('mobile-open');
        if (overlay) overlay.classList.remove('open');
        document.body.classList.remove('mobile-sidebar-open');
    }
})();

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

// Page scroll lock for modals
function setPageScrollLock(locked) {
    document.documentElement.classList.toggle('overflow-hidden', locked);
    document.body.style.overflow = locked ? 'hidden' : '';
    document.documentElement.style.overflow = locked ? 'hidden' : '';
}
</script>
</div>
</div>

<?php
if ($isAjax) {
    $html = ob_get_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'html' => $html]);
} else {
    include 'include/footer.php';
}
?>
