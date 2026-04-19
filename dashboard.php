<?php
require 'config/auth.php';
require 'config/database.php';
require 'config/constants.php';

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
$isAdmin = (($_SESSION['role'] ?? '') === 'admin');
$action = $_GET['action'] ?? 'list';

// Handle global search
if ($action === 'global_search' && $isAjax) {
    $query = trim($_GET['q'] ?? '');
    $results = [
        'partners' => [],
        'customers' => [],
        'containers' => [],
        'agents' => [],
        'expenses' => [],
        'rate_items' => []
    ];

    if (strlen($query) >= 2) {
        $searchPattern = '%' . $conn->real_escape_string($query) . '%';

        // Search Partners
        try {
            $partnerStmt = $conn->prepare("SELECT id, name, total, remaining FROM partners WHERE name LIKE ? LIMIT 5");
            if ($partnerStmt) {
                $partnerStmt->bind_param("s", $searchPattern);
                $partnerStmt->execute();
                $partnerResult = $partnerStmt->get_result();
                while ($row = $partnerResult->fetch_assoc()) {
                    $results['partners'][] = [
                        'id' => $row['id'],
                        'name' => $row['name'],
                        'total' => number_format($row['total'], 2),
                        'remaining' => number_format($row['remaining'], 2)
                    ];
                }
                $partnerStmt->close();
            }
        } catch (Exception $e) {
            // Continue even if this fails
        }

        // Search Customers
        try {
            $customerStmt = $conn->prepare("SELECT id, name, total_amount, remaining_amount FROM customers WHERE name LIKE ? OR contact_info LIKE ? LIMIT 5");
            if ($customerStmt) {
                $customerStmt->bind_param("ss", $searchPattern, $searchPattern);
                $customerStmt->execute();
                $customerResult = $customerStmt->get_result();
                while ($row = $customerResult->fetch_assoc()) {
                    $results['customers'][] = [
                        'id' => $row['id'],
                        'name' => $row['name'],
                        'total' => number_format($row['total_amount'], 2),
                        'remaining' => number_format($row['remaining_amount'], 2)
                    ];
                }
                $customerStmt->close();
            }
        } catch (Exception $e) {
            // Continue even if this fails
        }

        // Search Containers
        try {
            $containerStmt = $conn->prepare("
                SELECT c.id, c.container_number, c.bl_number, c.gross_weight, c.net_weight, cu.name as customer_name
                FROM containers c
                LEFT JOIN customers cu ON cu.id = c.customer_id
                WHERE c.container_number LIKE ? OR c.bl_number LIKE ?
                LIMIT 5
            ");
            if ($containerStmt) {
                $containerStmt->bind_param("ss", $searchPattern, $searchPattern);
                $containerStmt->execute();
                $containerResult = $containerStmt->get_result();
                while ($row = $containerResult->fetch_assoc()) {
                    $results['containers'][] = [
                        'id' => $row['id'],
                        'container_number' => $row['container_number'],
                        'bl_number' => $row['bl_number'],
                        'customer_name' => $row['customer_name'],
                        'gross_weight' => number_format($row['gross_weight'], 2),
                        'net_weight' => number_format($row['net_weight'], 2)
                    ];
                }
                $containerStmt->close();
            }
        } catch (Exception $e) {
            // Continue even if this fails
        }

        // Search Agents
        try {
            $agentCheck = $conn->query("SHOW TABLES LIKE 'agents'");
            if ($agentCheck && $agentCheck->num_rows > 0) {
                $agentStmt = $conn->prepare("SELECT id, name, contact, notes FROM agents WHERE name LIKE ? OR contact LIKE ? LIMIT 5");
                if ($agentStmt) {
                    $agentStmt->bind_param("ss", $searchPattern, $searchPattern);
                    $agentStmt->execute();
                    $agentResult = $agentStmt->get_result();
                    while ($row = $agentResult->fetch_assoc()) {
                        $results['agents'][] = [
                            'id' => $row['id'],
                            'name' => $row['name'],
                            'contact' => $row['contact'],
                            'notes' => substr($row['notes'] ?? '', 0, 50)
                        ];
                    }
                    $agentStmt->close();
                }
            }
        } catch (Exception $e) {
            // Continue even if this fails
        }

        // Search Rate List Items
        try {
            $rateStmt = $conn->prepare("SELECT id, item_name, rate FROM rate_list WHERE item_name LIKE ? LIMIT 5");
            if ($rateStmt) {
                $rateStmt->bind_param("s", $searchPattern);
                $rateStmt->execute();
                $rateResult = $rateStmt->get_result();
                while ($row = $rateResult->fetch_assoc()) {
                    $results['rate_items'][] = [
                        'id' => $row['id'],
                        'item_name' => $row['item_name'],
                        'rate' => number_format($row['rate'], 0)
                    ];
                }
                $rateStmt->close();
            }
        } catch (Exception $e) {
            // Continue even if this fails
        }

        // Search Container Expenses
        try {
            $expenseCheck = $conn->query("SHOW TABLES LIKE 'container_expenses'");
            if ($expenseCheck && $expenseCheck->num_rows > 0) {
                $expenseStmt = $conn->prepare("
                    SELECT ce.id, ce.description, ce.amount, c.container_number
                    FROM container_expenses ce
                    LEFT JOIN containers c ON c.id = ce.container_id
                    WHERE ce.description LIKE ?
                    LIMIT 5
                ");
                if ($expenseStmt) {
                    $expenseStmt->bind_param("s", $searchPattern);
                    $expenseStmt->execute();
                    $expenseResult = $expenseStmt->get_result();
                    while ($row = $expenseResult->fetch_assoc()) {
                        $results['expenses'][] = [
                            'id' => $row['id'],
                            'description' => $row['description'],
                            'amount' => number_format($row['amount'], 2),
                            'container_number' => $row['container_number']
                        ];
                    }
                    $expenseStmt->close();
                }
            }
        } catch (Exception $e) {
            // Continue even if this fails
        }
    }

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'results' => $results, 'query' => $query]);
    exit;
}

$partner_proof_dir = __DIR__ . '/uploads/partner_transaction_proofs/';
if (!is_dir($partner_proof_dir)) {
    mkdir($partner_proof_dir, 0755, true);
}

$partnerTxTable = $conn->query("SHOW TABLES LIKE 'partner_transactions'");
if ($partnerTxTable && $partnerTxTable->num_rows == 0) {
    $conn->query(" 
        CREATE TABLE partner_transactions (
            id INT NOT NULL AUTO_INCREMENT,
            partner_id INT NOT NULL,
            transaction_type ENUM('credit','debit') NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            reference_number VARCHAR(100) DEFAULT NULL,
            description VARCHAR(255) DEFAULT NULL,
            transaction_date DATE NOT NULL,
            proof VARCHAR(255) DEFAULT NULL,
            created_by INT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_partner_transactions_partner_date (partner_id, transaction_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

$partnerTxProof = $conn->query("SHOW COLUMNS FROM partner_transactions LIKE 'proof'");
if ($partnerTxProof && $partnerTxProof->num_rows == 0) {
    $conn->query("ALTER TABLE partner_transactions ADD COLUMN proof VARCHAR(255) DEFAULT NULL AFTER transaction_date");
}

// Check and migrate funds_account table
$fundsAccountTable = $conn->query("SHOW TABLES LIKE 'funds_account'");
if ($fundsAccountTable && $fundsAccountTable->num_rows > 0) {
    // Table exists, check if it needs migration
    $fundTypeCol = $conn->query("SHOW COLUMNS FROM funds_account LIKE 'fund_type'");
    if ($fundTypeCol && $fundTypeCol->num_rows == 0) {
        // Need to add new columns for transaction logging
        $conn->query("ALTER TABLE funds_account 
            ADD COLUMN fund_type ENUM('dashboard', 'expense_account', 'general_fund') NULL AFTER id,
            ADD COLUMN partner_id INT NULL COMMENT 'For dashboard funds only',
            ADD COLUMN partner_name VARCHAR(255) NULL COMMENT 'For dashboard funds only',
            ADD COLUMN amount DECIMAL(12,2) NULL,
            ADD COLUMN transaction_date DATE NULL,
            ADD COLUMN proof VARCHAR(255) NULL COMMENT 'For dashboard funds - file path',
            ADD COLUMN reference_number VARCHAR(100) NULL COMMENT 'For expense account funds',
            ADD COLUMN created_by INT NULL,
            ADD INDEX idx_funds_account_type_date (fund_type, transaction_date),
            ADD INDEX idx_funds_account_partner (partner_id)");
    } else {
        // Update existing fund_type ENUM to include 'general_fund' if not present
        $conn->query("ALTER TABLE funds_account MODIFY fund_type ENUM('dashboard', 'expense_account', 'general_fund')");
    }
} else {
    // Create new table with proper structure
    $conn->query(" 
        CREATE TABLE funds_account (
            id INT NOT NULL AUTO_INCREMENT,
            fund_type ENUM('dashboard', 'expense_account', 'general_fund') NOT NULL,
            partner_id INT NULL COMMENT 'For dashboard funds only',
            partner_name VARCHAR(255) NULL COMMENT 'For dashboard funds only',
            amount DECIMAL(12,2) NOT NULL,
            transaction_date DATE NOT NULL,
            proof VARCHAR(255) NULL COMMENT 'For dashboard funds - file path',
            reference_number VARCHAR(100) NULL COMMENT 'For expense account funds',
            created_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_funds_account_type_date (fund_type, transaction_date),
            KEY idx_funds_account_partner (partner_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add_dashboard_fund') {
    $partnerId = (int)($_POST['partner_id'] ?? 0);
    $amount = (float)($_POST['amount'] ?? 0);
    $transactionDate = trim($_POST['transaction_date'] ?? date('Y-m-d'));
    $proofFile = null;

    if ($amount <= 0) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Valid amount is required.']);
        exit;
    }

    $proofPath = null;
    if (!empty($_FILES['proof']) && $_FILES['proof']['error'] === UPLOAD_ERR_OK) {
        $fileTmp = $_FILES['proof']['tmp_name'];
        $fileName = $_FILES['proof']['name'];
        $fileSize = $_FILES['proof']['size'];
        $fileType = mime_content_type($fileTmp);
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];
        $maxSize = 5 * 1024 * 1024;

        if (!in_array($fileType, $allowedTypes, true) || $fileSize > $maxSize) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Invalid proof file. Allowed: JPG, PNG, GIF, PDF up to 5MB.']);
            exit;
        }

        $fileExt = pathinfo($fileName, PATHINFO_EXTENSION);
        $proofFile = 'dashboard_fund_' . $partnerId . '_' . time() . '.' . $fileExt;
        $proofPath = $partner_proof_dir . $proofFile;

        if (!move_uploaded_file($fileTmp, $proofPath)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Failed to upload proof file.']);
            exit;
        }
    }

    $referenceNumber = 'DASH-FUND-' . date('YmdHis');
    $userId = $_SESSION['user_id'] ?? 0;
    $fundAccountRecordId = 0;
    $savedFundType = '';

    $conn->begin_transaction();

    try {
        if ($partnerId > 0) {
            $description = 'Fund added to dashboard';

            $stmt = $conn->prepare(" 
                INSERT INTO partner_transactions
                (partner_id, transaction_type, amount, reference_number, description, transaction_date, proof, created_by)
                VALUES (?, 'debit', ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("idssssi", $partnerId, $amount, $referenceNumber, $description, $transactionDate, $proofFile, $userId);
            if (!$stmt->execute()) {
                throw new Exception('Failed to record partner transaction.');
            }
            $stmt->close();

            $partnerUpdate = $conn->prepare("UPDATE partners SET total = total + ?, remaining = remaining + ? WHERE id = ?");
            $partnerUpdate->bind_param("ddi", $amount, $amount, $partnerId);
            if (!$partnerUpdate->execute()) {
                throw new Exception('Failed to update partner account.');
            }
            // Verify the update affected exactly one row
            if ($partnerUpdate->affected_rows === 0) {
                $partnerUpdate->close();
                throw new Exception('Partner account not found or values unchanged.');
            }
            $partnerUpdate->close();

            $partnerNameStmt = $conn->prepare("SELECT name FROM partners WHERE id = ?");
            $partnerNameStmt->bind_param("i", $partnerId);
            if (!$partnerNameStmt->execute()) {
                throw new Exception('Failed to load partner details.');
            }
            $partnerNameResult = $partnerNameStmt->get_result();
            $partnerName = $partnerNameResult->num_rows > 0 ? $partnerNameResult->fetch_assoc()['name'] : '';
            $partnerNameStmt->close();

            $fundsStmt = $conn->prepare(" 
                INSERT INTO funds_account
                (fund_type, partner_id, partner_name, amount, transaction_date, proof, reference_number, created_by)
                VALUES ('dashboard', ?, ?, ?, ?, ?, ?, ?)
            ");
            $fundsStmt->bind_param("isdsssi", $partnerId, $partnerName, $amount, $transactionDate, $proofFile, $referenceNumber, $userId);
            if (!$fundsStmt->execute()) {
                throw new Exception('Failed to record fund in funds account.');
            }
            $fundAccountRecordId = (int)$fundsStmt->insert_id;
            $savedFundType = 'dashboard';
            $fundsStmt->close();
        } else {
            $fundsStmt = $conn->prepare(" 
                INSERT INTO funds_account
                (fund_type, partner_id, partner_name, amount, transaction_date, proof, reference_number, created_by)
                VALUES ('general_fund', NULL, 'General Fund', ?, ?, ?, ?, ?)
            ");
            $fundsStmt->bind_param("dsssi", $amount, $transactionDate, $proofFile, $referenceNumber, $userId);
            if (!$fundsStmt->execute()) {
                throw new Exception('Failed to record fund in funds account.');
            }
            $fundAccountRecordId = (int)$fundsStmt->insert_id;
            $savedFundType = 'general_fund';
            $fundsStmt->close();
        }

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        if (!empty($proofPath) && file_exists($proofPath)) {
            unlink($proofPath);
        }
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => 'Dashboard fund added successfully.',
        'reference_number' => $referenceNumber,
        'fund_account_id' => $fundAccountRecordId,
        'fund_type' => $savedFundType
    ]);
    exit;
}

/* ============================
   TOTAL FINAL AMOUNT
============================ */
$totalFinal = 0;
$containerAmount = 0;
$res = $conn->query("
    SELECT c.net_weight, cu.rate
    FROM containers c
    JOIN customers cu ON cu.id = c.customer_id
");
while ($r = $res->fetch_assoc()) {
    $containerAmount += ($r['net_weight'] * $r['rate']);
}
$totalFinal += $containerAmount;

/* ============================
   ADD ALL DASHBOARD FUNDS (WITH & WITHOUT PARTNER)
   Excludes expense_account type which is tracked separately
============================ */
$allDashboardFunds = 0;
$dashboardFundsRow = $conn->query(" 
    SELECT COALESCE(SUM(amount), 0) AS total
    FROM funds_account
    WHERE fund_type IS NULL 
       OR fund_type = 'general_fund' 
       OR fund_type = 'dashboard'
");
if ($dashboardFundsRow) {
    $allDashboardFunds = (float)($dashboardFundsRow->fetch_assoc()['total'] ?? 0);
}
$totalFinal += $allDashboardFunds;

/* ============================
   NOTE: expense_account.total_amount is NOT subtracted here because
   those expenses are already included in the Total Expenses calculation
   below (from funds_account where fund_type='expense_account').
   Subtracting here would double-count expenses.
============================ */

/* ============================
   TOTAL PAID AMOUNT
============================ */
$totalPaid = 0;
$customerTxTable = $conn->query("SHOW TABLES LIKE 'customer_transactions'");
$containerTxTable = $conn->query("SHOW TABLES LIKE 'container_transactions'");

if ($customerTxTable && $customerTxTable->num_rows > 0) {
    $paidRow = $conn->query("
        SELECT COALESCE(SUM(amount), 0) AS total
        FROM customer_transactions
        WHERE transaction_type = 'credit'
    ");
    if ($paidRow) {
        $totalPaid = (float)($paidRow->fetch_assoc()['total'] ?? 0);
    }
} elseif ($containerTxTable && $containerTxTable->num_rows > 0) {
    $paidRow = $conn->query("
        SELECT COALESCE(SUM(amount), 0) AS total
        FROM container_transactions
        WHERE transaction_type = 'credit'
    ");
    if ($paidRow) {
        $totalPaid = (float)($paidRow->fetch_assoc()['total'] ?? 0);
    }
}

// Note: Dashboard funds (with or without partner) are included in Account Total,
// not in Total Expenses.

/* ============================
   ACCOUNT REMAINING
   Formula: Account Total - Total Expenses + Customer Payments
============================ */
$accountRemaining = 0;

/* ============================
   MARKET REMAINING
============================ */
$marketRemaining = 0;
$marketRemainingRow = $conn->query("
    SELECT COALESCE(SUM(remaining_amount), 0) AS total
    FROM customers
");
if ($marketRemainingRow) {
    $marketRemaining = (float)($marketRemainingRow->fetch_assoc()['total'] ?? 0);
}

/* ============================
    TOTAL REGISTERED CONTAINERS
============================ */
$totalContainers = 0;
$containerCountResult = $conn->query("SELECT COUNT(*) AS total FROM containers");
if ($containerCountResult) {
     $totalContainers = (int)($containerCountResult->fetch_assoc()['total'] ?? 0);
}

/* ============================
    TOTAL REVENUE
============================ */
$totalRevenue = 0;
$revenueRow = $conn->query("
    SELECT SUM(i.invoice_amount - COALESCE((SELECT SUM(ce.amount) FROM container_expenses ce WHERE ce.container_id = i.container_id), 0)) AS profit 
    FROM invoices i
");
if ($revenueRow) {
    $totalRevenue = (float)($revenueRow->fetch_assoc()['profit'] ?? 0);
}

/* ============================
   PARTNER ACCOUNTS
============================ */
$partners = $conn->query("
    SELECT id, name, total, paid, remaining
    FROM partners
    ORDER BY name
");

/* ============================
   QUICK RATE LIST
============================ */
$rates = $conn->query("
    SELECT item_name, rate FROM rate_list
    ORDER BY rate DESC
");

$fundPartners = $conn->query("SELECT id, name FROM partners ORDER BY name ASC");
/* ============================
   TOTAL EXPENSES (SYSTEM-WIDE)
============================ */
$totalExpenses = 0;
$agentTxTable = $conn->query("SHOW TABLES LIKE 'agent_transactions'");
if ($agentTxTable && $agentTxTable->num_rows > 0) {
    $agentExpenseRow = $conn->query("SELECT COALESCE(SUM(debit), 0) AS total FROM agent_transactions");
    if ($agentExpenseRow) {
        $totalExpenses += (float)($agentExpenseRow->fetch_assoc()['total'] ?? 0);
    }
}
$partnerTxTableCheck = $conn->query("SHOW TABLES LIKE 'partner_transactions'");
if ($partnerTxTableCheck && $partnerTxTableCheck->num_rows > 0) {
    $partnerExpenseRow = $conn->query("
        SELECT COALESCE(SUM(amount), 0) AS total
        FROM partner_transactions
        WHERE transaction_type = 'credit'
    ");
    if ($partnerExpenseRow) {
        $totalExpenses += (float)($partnerExpenseRow->fetch_assoc()['total'] ?? 0);
    }
}
$containerExpenseTable = $conn->query("SHOW TABLES LIKE 'container_expenses'");
if ($containerExpenseTable && $containerExpenseTable->num_rows > 0) {
    $containerExpenseRow = $conn->query("SELECT COALESCE(SUM(amount), 0) AS total FROM container_expenses WHERE agent_id IS NULL");
    if ($containerExpenseRow) {
        $totalExpenses += (float)($containerExpenseRow->fetch_assoc()['total'] ?? 0);
    }
}
$fundsAccountTableCheck = $conn->query("SHOW TABLES LIKE 'funds_account'");
if ($fundsAccountTableCheck && $fundsAccountTableCheck->num_rows > 0) {
    $expenseFundRow = $conn->query("
        SELECT COALESCE(SUM(amount), 0) AS total
        FROM funds_account
        WHERE fund_type = 'expense_account'
    ");
    if ($expenseFundRow) {
        $totalExpenses += (float)($expenseFundRow->fetch_assoc()['total'] ?? 0);
    }
}

/* ============================
   CALCULATE ACCOUNT REMAINING
   Account Remaining = (Account Total - Total Expenses) + Customer Payments
============================ */
$accountRemaining = ($totalFinal - $totalExpenses) + $totalPaid;

if (!$isAjax) {
    include 'include/header.php';
    include 'include/sidebar.php';
}
if ($isAjax) {
    ob_start();
}
?>
<style>
/* Extra small screens breakpoint */
@media (min-width: 400px) {
    .xs\:block {
        display: block;
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

/* iPhone SE and similar small devices */
@media (max-width: 375px) {
    header {
        font-size: 0.875rem;
    }
}
</style>

<div id="mobileSidebarOverlay" class="md:hidden" onclick="closeMobileSidebar()"></div>

<!-- Top Bar -->
<header class="sticky top-0 z-40 w-full bg-white/80 dark:bg-slate-900/80 backdrop-blur-md border-b border-slate-200 dark:border-slate-800">
  <div class="flex items-center justify-between px-3 sm:px-4 md:px-6 lg:px-8 h-14 sm:h-16">
    <div class="flex items-center gap-2 sm:gap-3 md:gap-6">
        <button type="button" onclick="toggleMobileSidebar()" class="md:hidden h-9 w-9 sm:h-11 sm:w-11 rounded-lg flex items-center justify-center hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors" aria-label="Open navigation menu">
            <span class="material-symbols-outlined text-slate-700 dark:text-slate-300 text-xl sm:text-2xl">menu</span>
        </button>
        <h2 class="text-base sm:text-xl font-bold tracking-tight hidden xs:block">Dashboard</h2>
        <!-- Mobile Search Button -->
        <button type="button" onclick="openMobileSearch()" class="lg:hidden h-9 w-9 sm:h-11 sm:w-11 rounded-lg flex items-center justify-center hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors" aria-label="Search">
            <span class="material-symbols-outlined text-slate-700 dark:text-slate-300 text-xl sm:text-2xl">search</span>
        </button>
        <!-- Desktop Search -->
        <div class="relative hidden lg:block">
            <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm">search</span>
            <input id="globalSearchInput" class="pl-10 pr-4 py-2 bg-slate-100 dark:bg-slate-800 border-none rounded-lg text-sm w-64 focus:ring-2 focus:ring-primary/20" placeholder="Search anything..." type="text" autocomplete="off"/>
            <span id="searchLoader" class="hidden material-symbols-outlined absolute right-3 top-1/2 -translate-y-1/2 text-primary text-sm animate-spin">progress_activity</span>
            <div id="searchResults" class="hidden absolute top-full mt-2 left-0 w-96 max-h-[500px] overflow-y-auto bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl shadow-2xl z-[9999]"></div>
        </div>
    </div>
    <div class="flex items-center gap-1.5 sm:gap-2 md:gap-4">
        <?php if ($isAdmin): ?>
        <button type="button" onclick="openDashboardFundModal()" class="bg-primary text-white px-2 sm:px-3 md:px-4 py-1.5 sm:py-2 rounded-lg text-[10px] sm:text-xs md:text-sm font-bold flex items-center gap-1 sm:gap-2 shadow-lg shadow-primary/20 hover:scale-[1.02] active:scale-[0.98] transition-all">
            <span class="material-symbols-outlined text-sm sm:text-base">add_circle</span>
            <span class="hidden md:inline">Add Dashboard Fund</span>
            <span class="hidden sm:inline md:hidden">Add Fund</span>
            <span class="sm:hidden">Fund</span>
        </button>
        <?php endif; ?>
        <div class="hidden md:block w-px h-6 bg-slate-200 dark:border-slate-700 mx-2"></div>
        <div class="flex items-center gap-2 sm:gap-3">
            <div class="text-right hidden sm:block">
                <p class="text-xs font-bold"><?= htmlspecialchars($_SESSION['username'] ?? 'User') ?></p>
                <p class="text-[10px] text-slate-500"><?= ucfirst(htmlspecialchars($_SESSION['role'] ?? 'user')) ?></p>
            </div>
            <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-full bg-slate-200 dark:bg-slate-700 border-2 border-white dark:border-slate-800 shadow-sm overflow-hidden flex items-center justify-center">
                <span class="material-symbols-outlined text-slate-600 text-lg sm:text-2xl">person</span>
            </div>
        </div>
    </div>
  </div>
</header>

<!-- Mobile Search Panel - Below Header -->
<div id="mobileSearchPanel" class="hidden lg:hidden px-4 py-4 border-b border-slate-200 dark:border-slate-800 bg-slate-50/30 dark:bg-slate-800/10">
    <div class="relative mb-3">
        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm">search</span>
        <input id="mobileSearchInput" class="w-full pl-10 pr-4 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-sm text-slate-900 dark:text-slate-100 placeholder-slate-400 focus:ring-2 focus:ring-primary/20" placeholder="Search anything..." type="text" autocomplete="off"/>
    </div>
    <div id="mobileSearchResults" class="overflow-y-auto max-h-[400px]"></div>
</div>

<!-- Page Body -->
<div class="p-4 sm:p-6 lg:p-8 space-y-6 lg:space-y-8">

<?php if ($isAdmin): ?>
<div id="dashboardFundModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4" onclick="if(event.target === this) closeDashboardFundModal()">
    <div class="bg-white dark:bg-slate-900 rounded-xl shadow-2xl w-full max-w-md overflow-hidden">
        <div class="bg-gradient-to-r from-blue-600 to-blue-700 text-white px-5 py-3 flex items-center justify-between">
            <h3 class="text-lg font-bold flex items-center gap-2">
                <span class="material-symbols-outlined">account_balance_wallet</span>
                Add Fund to Dashboard
            </h3>
            <button type="button" onclick="closeDashboardFundModal()" class="text-white hover:text-gray-200 text-xl">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <form id="dashboardFundForm" enctype="multipart/form-data" class="p-5 space-y-4">
            <div>
                <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Partner</label>
                <select name="partner_id" class="w-full border border-gray-300 dark:border-slate-700 dark:bg-slate-800 dark:text-white rounded-lg px-3 py-2 text-sm">
                    <option value="0">No Partner / General Fund</option>
                    <?php if ($fundPartners): while ($partnerOpt = $fundPartners->fetch_assoc()): ?>
                        <option value="<?= (int)$partnerOpt['id'] ?>"><?= htmlspecialchars($partnerOpt['name']) ?></option>
                    <?php endwhile; endif; ?>
                </select>
                <p class="text-xs text-gray-500 mt-1">Select 'No Partner' to add funds directly to total invoiced amount</p>
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Amount <span class="text-red-500">*</span></label>
                <input type="number" name="amount" min="0.01" step="0.01" required class="w-full border border-gray-300 dark:border-slate-700 dark:bg-slate-800 dark:text-white rounded-lg px-3 py-2 text-sm" placeholder="0.00">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Date</label>
                <input type="date" name="transaction_date" value="<?= date('Y-m-d') ?>" class="w-full border border-gray-300 dark:border-slate-700 dark:bg-slate-800 dark:text-white rounded-lg px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Proof</label>
                <input type="file" name="proof" accept=".jpg,.jpeg,.png,.gif,.pdf" class="w-full border border-gray-300 dark:border-slate-700 dark:bg-slate-800 dark:text-white rounded-lg px-3 py-2 text-sm">
                <p class="text-xs text-gray-500 mt-1">Optional. Allowed: JPG, PNG, GIF, PDF (Max 5MB)</p>
            </div>
            <div class="flex justify-end gap-2 pt-2 border-t dark:border-slate-700">
                <button type="button" onclick="closeDashboardFundModal()" class="px-4 py-2 border border-gray-300 dark:border-slate-700 rounded-lg text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-slate-800">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm flex items-center gap-1">
                    <span class="material-symbols-outlined text-sm">save</span>
                    Save Fund
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- KPI Cards -->
<!-- <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-5 gap-4">
    <div class="bg-white dark:bg-slate-900 p-5 rounded-xl border border-slate-200 dark:border-slate-800 shadow-sm relative overflow-hidden group min-h-[100px] flex flex-col justify-between">
        <p class="text-xs font-medium text-slate-500 mb-1">Account Total</p>
        <h3 id="dashboardAccountTotal" class="text-2xl font-bold" style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; line-height: 1.2;">Rs <?= number_format($totalFinal, 2) ?></h3>
        <div class="mt-3 flex items-center gap-1 text-[10px] font-bold text-emerald-600">
            <span class="material-symbols-outlined text-xs">trending_up</span>
            Includes all funds
        </div>
        <div class="absolute bottom-0 left-0 w-full h-1 sparkline-gradient"></div>
    </div>

    <div class="bg-white dark:bg-slate-900 p-5 rounded-xl border border-slate-200 dark:border-slate-800 shadow-sm relative overflow-hidden group min-h-[100px] flex flex-col justify-between">
        <p class="text-xs font-medium text-slate-500 mb-1">Total Expenses</p>
        <h3 id="dashboardTotalExpenses" class="text-2xl font-bold" style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; line-height: 1.2;">Rs <?= number_format($totalExpenses, 2) ?></h3>
        <div class="mt-3 flex items-center gap-1 text-[10px] font-bold text-slate-600">
            <span class="material-symbols-outlined text-xs">payments</span>
            System-wide expenses
        </div>
        <div class="absolute bottom-0 left-0 w-full h-1 sparkline-gradient opacity-40"></div>
    </div> -->

    <div class="bg-white dark:bg-slate-900 p-5 rounded-xl border border-slate-200 dark:border-slate-800 shadow-sm relative overflow-hidden group min-h-[100px] flex flex-col justify-between">
        <p class="text-xs font-medium text-slate-500 mb-1">Account Remaining</p>
        <h3 id="dashboardAccountRemaining" class="text-2xl font-bold text-emerald-600" style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; line-height: 1.2;">Rs <?= number_format($accountRemaining, 2) ?></h3>
        <div class="mt-3 flex items-center gap-1 text-[10px] font-bold text-<?= $accountRemaining >= 0 ? 'emerald' : 'rose' ?>-600">
            <span class="material-symbols-outlined text-xs">
                <?= $accountRemaining >= 0 ? 'trending_up' : 'trending_down' ?>
            </span>
            <?= $accountRemaining >= 0 ? 'Positive balance' : 'Deficit' ?>
        </div>
        <div class="absolute bottom-0 left-0 w-full h-1 sparkline-gradient opacity-60"></div>
    </div>

    <div class="bg-white dark:bg-slate-900 p-5 rounded-xl border border-slate-200 dark:border-slate-800 shadow-sm relative overflow-hidden group min-h-[100px] flex flex-col justify-between">
        <p class="text-xs font-medium text-slate-500 mb-1">Market Remaining</p>
        <h3 id="dashboardMarketRemaining" class="text-2xl font-bold text-red-600" style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; line-height: 1.2;">Rs <?= number_format($marketRemaining, 2) ?></h3>
        <div class="mt-3 flex items-center gap-1 text-[10px] font-bold text-emerald-600">
            <span class="material-symbols-outlined text-xs">trending_up</span>
            Customer balances
        </div>
        <div class="absolute bottom-0 left-0 w-full h-1 sparkline-gradient opacity-30"></div>
    </div>

    <div class="bg-white dark:bg-slate-900 p-5 rounded-xl border border-slate-200 dark:border-slate-800 shadow-sm relative overflow-hidden group min-h-[100px] flex flex-col justify-between">
        <p class="text-xs font-medium text-slate-500 mb-1">Total Revenue</p>
        <h3 id="dashboardTotalRevenue" class="text-2xl font-bold text-blue-600" style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; line-height: 1.2;">Rs <?= number_format($totalRevenue, 2) ?></h3>
        <div class="mt-3 flex items-center gap-1 text-[10px] font-bold text-emerald-600">
            <span class="material-symbols-outlined text-xs">savings</span>
            Containers' profit
        </div>
        <div class="absolute bottom-0 left-0 w-full h-1 sparkline-gradient opacity-50"></div>
    </div>

    <div class="bg-white dark:bg-slate-900 p-5 rounded-xl border border-slate-200 dark:border-slate-800 shadow-sm relative overflow-hidden group min-h-[100px] flex flex-col justify-between">
        <p class="text-xs font-medium text-slate-500 mb-1">Total Containers</p>
        <h3 id="dashboardTotalContainers" class="text-2xl font-bold" style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; line-height: 1.2;"><?= number_format($totalContainers) ?></h3>
        <div class="mt-3 flex items-center gap-1 text-[10px] font-bold text-emerald-600">
            <span class="material-symbols-outlined text-xs">local_shipping</span>
            Registered containers
        </div>
        <div class="absolute bottom-0 left-0 w-full h-1 sparkline-gradient opacity-80"></div>
    </div>
</div>

<!-- Lower Dashboard Grid -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
<!-- Partner Accounts Section -->
<div class="lg:col-span-2 space-y-4">
    <div class="flex items-center justify-between">
        <h3 class="text-lg font-bold">Partner Accounts</h3>
        <a href="partners.php" class="text-primary text-xs font-bold hover:underline">
            <span class="hidden sm:inline">View All Partners</span>
            <span class="sm:hidden">View All</span>
        </a>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <?php if ($partners && $partners->num_rows > 0): ?>
            <?php while ($p = $partners->fetch_assoc()): ?>
                <?php
                    $pTotal = $p['total'] ?? 0;
                    $pPaid = $p['paid'] ?? 0;
                    $pRemaining = $p['remaining'] ?? 0;
                    $paidPercent = $pTotal > 0 ? round(($pPaid / $pTotal) * 100) : 0;
                    
                    // Generate initials from partner name
                    $partnerName = $p['name'] ?? '';
                    $nameParts = array_filter(explode(' ', trim($partnerName)));
                    $initials = '';
                    if (count($nameParts) >= 2) {
                        // Take first letter of first two words
                        $initials = strtoupper(substr($nameParts[0], 0, 1) . substr($nameParts[1], 0, 1));
                    } elseif (count($nameParts) === 1) {
                        // Take first letter only
                        $initials = strtoupper(substr($nameParts[0], 0, 1));
                    } else {
                        $initials = 'P';
                    }
                ?>
                <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-4 flex gap-4 hover:shadow-md transition-shadow">
                    <div class="w-16 h-16 rounded-lg bg-gradient-to-br from-primary/90 to-primary flex items-center justify-center flex-shrink-0 shadow-md">
                        <span class="text-white text-xl font-bold"><?= $initials ?></span>
                    </div>
                    <div class="flex-1">
                        <h4 class="font-bold text-sm"><?= htmlspecialchars($p['name']) ?></h4>
                        <div class="mt-2 grid grid-cols-2 gap-2">
                            <div>
                                <p class="text-[10px] text-slate-500 uppercase">Total</p>
                                <p class="text-xs font-bold">Rs <?= number_format($pTotal, 0) ?></p>
                            </div>
                            <div>
                                <p class="text-[10px] text-slate-500 uppercase">Paid</p>
                                <p class="text-xs font-bold text-emerald-600">Rs <?= number_format($pPaid, 0) ?></p>
                            </div>
                            <div class="col-span-2 mt-1">
                                <p class="text-[10px] text-slate-500 uppercase">Remaining</p>
                                <div class="flex items-center gap-2">
                                    <div class="flex-1 h-1.5 bg-slate-100 dark:bg-slate-800 rounded-full overflow-hidden">
                                        <div class="bg-primary h-full" style="width: <?= $paidPercent ?>%"></div>
                                    </div>
                                    <p class="text-xs font-bold">Rs <?= number_format($pRemaining, 0) ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="col-span-2 bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6 text-center text-sm text-slate-500">
                No partner accounts available.
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Rate List Side Card -->
<div class="space-y-4">
    <div class="flex items-center justify-between">
        <h3 class="text-lg font-bold">
            <span class="hidden sm:inline">Rate List</span>
            <span class="sm:hidden">Live Rates</span>
        </h3>
    </div>
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden shadow-sm">
        <table class="w-full text-left border-collapse">
            <thead>
                <tr class="bg-slate-50 dark:bg-slate-800/50">
                    <th class="px-4 py-3 text-[10px] font-bold text-slate-500 uppercase tracking-wider">Market Area</th>
                    <th class="px-4 py-3 text-[10px] font-bold text-slate-500 uppercase tracking-wider text-right">Standard Rate</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                <?php if ($rates && $rates->num_rows > 0): ?>
                    <?php while ($r = $rates->fetch_assoc()): ?>
                    <tr>
                        <td class="px-4 py-3 text-xs font-medium"><?= htmlspecialchars($r['item_name']) ?></td>
                        <td class="px-4 py-3 text-xs font-bold text-right">Rs <?= number_format($r['rate'], 0) ?></td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="2" class="px-4 py-3 text-xs text-center text-slate-500">No rates available</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</div>

</div> <!-- End page body -->

<!-- CUSTOM ALERT MODAL -->
<div id="customAlertModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-[70] flex items-center justify-center overflow-hidden" style="display: none;">
    <div class="bg-white dark:bg-slate-800 rounded-lg shadow-2xl w-full max-w-md mx-4 animate-fade-in">
        <div id="customAlertHeader" class="bg-gradient-to-r from-blue-600 to-blue-700 text-white px-6 py-4 rounded-t-lg flex items-center gap-3">
            <i id="customAlertIcon" class="fas fa-info-circle text-2xl"></i>
            <h3 id="customAlertTitle" class="text-lg font-bold">Alert</h3>
        </div>
        <div class="p-6">
            <p id="customAlertMessage" class="text-gray-700 dark:text-slate-300 text-base leading-relaxed whitespace-pre-line"></p>
        </div>
        <div class="px-6 pb-6 flex justify-end">
            <button onclick="closeCustomAlert()" class="bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 text-white px-6 py-2.5 rounded-lg shadow-md transition-all font-semibold">
                <i class="fas fa-check mr-2"></i>OK
            </button>
        </div>
    </div>
</div>

<?php
if ($isAjax) {
    $html = ob_get_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'html' => $html]);
} else {
    include 'include/footer.php';
?>

<script>
(function() {
    'use strict';

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

    window.openMobileSearch = function() {
        var panel = document.getElementById('mobileSearchPanel');
        var input = document.getElementById('mobileSearchInput');
        if (!panel || !input) return;
        panel.classList.remove('hidden');
        setTimeout(function() {
            input.focus();
        }, 100);
    };

    window.closeMobileSearch = function() {
        var panel = document.getElementById('mobileSearchPanel');
        var input = document.getElementById('mobileSearchInput');
        var results = document.getElementById('mobileSearchResults');
        if (!panel) return;
        panel.classList.add('hidden');
        if (input) input.value = '';
        if (results) results.innerHTML = '';
    };

    window.showCustomAlert = function(message, type = 'info') {
        var modal = document.getElementById('customAlertModal');
        var header = document.getElementById('customAlertHeader');
        var icon = document.getElementById('customAlertIcon');
        var title = document.getElementById('customAlertTitle');
        var messageEl = document.getElementById('customAlertMessage');
        
        if (!modal) return;
        
        // Set message
        messageEl.textContent = message;
        
        // Set type-specific styling
        if (type === 'success') {
            header.className = 'bg-gradient-to-r from-green-600 to-green-700 text-white px-6 py-4 rounded-t-lg flex items-center gap-3';
            icon.className = 'fas fa-check-circle text-2xl';
            title.textContent = 'Success';
        } else if (type === 'error') {
            header.className = 'bg-gradient-to-r from-red-600 to-red-700 text-white px-6 py-4 rounded-t-lg flex items-center gap-3';
            icon.className = 'fas fa-exclamation-circle text-2xl';
            title.textContent = 'Error';
        } else if (type === 'warning') {
            header.className = 'bg-gradient-to-r from-yellow-600 to-yellow-700 text-white px-6 py-4 rounded-t-lg flex items-center gap-3';
            icon.className = 'fas fa-exclamation-triangle text-2xl';
            title.textContent = 'Warning';
        } else {
            header.className = 'bg-gradient-to-r from-blue-600 to-blue-700 text-white px-6 py-4 rounded-t-lg flex items-center gap-3';
            icon.className = 'fas fa-info-circle text-2xl';
            title.textContent = 'Information';
        }
        
        // Show modal
        modal.classList.remove('hidden');
        modal.style.display = 'flex';
        document.body.classList.add('overflow-hidden');
    };
    
    window.closeCustomAlert = function() {
        var modal = document.getElementById('customAlertModal');
        if (modal) {
            modal.classList.add('hidden');
            modal.style.display = 'none';
            document.body.classList.remove('overflow-hidden');
        }
    };

    window.openDashboardFundModal = function() {
        var modal = document.getElementById('dashboardFundModal');
        if (!modal) return;
        modal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
    };

    window.closeDashboardFundModal = function() {
        var modal = document.getElementById('dashboardFundModal');
        if (!modal) return;
        modal.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
        var form = document.getElementById('dashboardFundForm');
        if (form) form.reset();
    };

    var dashboardFundForm = document.getElementById('dashboardFundForm');
    if (dashboardFundForm) {
        dashboardFundForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var formData = new FormData(this);

            fetch('dashboard.php?action=add_dashboard_fund', {
                method: 'POST',
                headers: {'X-Requested-With': 'XMLHttpRequest'},
                body: formData
            })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.success) {
                    var message = data.message || 'Dashboard fund added successfully.';
                    var details = [];
                    if (data.reference_number) details.push('Reference: ' + data.reference_number);
                    if (data.fund_account_id) details.push('Fund Account ID: ' + data.fund_account_id);
                    if (details.length > 0) {
                        message += '\n\n' + details.join('\n');
                    }
                    closeDashboardFundModal();
                    showCustomAlert(message, 'success');
                    setTimeout(function() { location.reload(); }, 1500);
                } else {
                    showCustomAlert(data.message || 'Failed to add fund.', 'error');
                }
            })
            .catch(function() {
                showCustomAlert('Failed to add fund. Please try again.', 'error');
            });
        });
    }

    // Update sidebar active state
    function updateSidebarActive(page) {
        var sidebarLinks = document.querySelectorAll('.sidebar-link');
        for (var i = 0; i < sidebarLinks.length; i++) {
            var link = sidebarLinks[i];
            var linkPage = link.getAttribute('data-page');
            if (linkPage === page) {
                link.classList.remove('text-slate-600', 'dark:text-slate-400', 'hover:bg-slate-100', 'dark:hover:bg-slate-800');
                link.classList.add('bg-primary/10', 'text-primary', 'font-semibold');
            } else {
                link.classList.remove('bg-primary/10', 'text-primary', 'font-semibold');
                link.classList.add('text-slate-600', 'dark:text-slate-400', 'hover:bg-slate-100', 'dark:hover:bg-slate-800');
            }
        }
    }

    // AJAX navigation handler using event delegation
    document.addEventListener('click', function(e) {
        var link = e.target.closest('.ajax-link');
        if (!link) return;

        closeMobileSidebar();
        
        e.preventDefault();
        
        var url = link.href;
        var page = link.getAttribute('data-page');
        
        fetch(url, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success && data.html) {
                document.getElementById('page-content').innerHTML = data.html;
                updateSidebarActive(page);
                initializeAjax();
                history.pushState({}, '', url);
            }
        })
        .catch(function(error) {
            console.error('Error:', error);
        });
    });

    // Initialize AJAX handlers
    function initializeAjax() {
        // All ajax-link handling is done via event delegation above
    }

    // Initialize on page load
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeAjax);
    } else {
        initializeAjax();
    }

    // Handle browser back/forward
    window.addEventListener('popstate', function() {
        location.reload();
    });

    window.addEventListener('resize', function() {
        if (window.innerWidth >= 768) {
            closeMobileSidebar();
        }
    });

    // Dynamic stat value sizing
    function setDashboardStatValue(elementId, value, isNumber) {
        var elem = document.getElementById(elementId);
        if (!elem) return;
        var formattedValue;
        if (isNumber) {
            formattedValue = parseFloat(value).toLocaleString();
        } else {
            formattedValue = 'Rs ' + parseFloat(value).toFixed(2);
        }
        elem.textContent = formattedValue;
        elem.style.whiteSpace = 'nowrap';
        elem.style.overflow = 'hidden';
        elem.style.textOverflow = 'ellipsis';
        
        var textLength = formattedValue.length;
        if (textLength <= 10) {
            elem.style.fontSize = 'clamp(1.25rem, 4vw, 1.875rem)';
        } else if (textLength <= 13) {
            elem.style.fontSize = 'clamp(1.125rem, 3.5vw, 1.5rem)';
        } else if (textLength <= 16) {
            elem.style.fontSize = 'clamp(1rem, 3vw, 1.25rem)';
        } else if (textLength <= 20) {
            elem.style.fontSize = 'clamp(0.875rem, 2.5vw, 1.125rem)';
        } else {
            elem.style.fontSize = 'clamp(0.75rem, 2vw, 1rem)';
        }
    }

    // Initialize stat values on page load
    function initializeDashboardStats() {
        var accountTotal = <?= json_encode($totalFinal) ?>;
        var totalExpenses = <?= json_encode($totalExpenses) ?>;
        var accountRemaining = <?= json_encode($accountRemaining) ?>;
        var marketRemaining = <?= json_encode($marketRemaining) ?>;
        var totalRevenue = <?= json_encode($totalRevenue) ?>;
        var totalContainers = <?= json_encode($totalContainers) ?>;

        setDashboardStatValue('dashboardAccountTotal', accountTotal, false);
        setDashboardStatValue('dashboardTotalExpenses', totalExpenses, false);
        setDashboardStatValue('dashboardAccountRemaining', accountRemaining, false);
        setDashboardStatValue('dashboardMarketRemaining', marketRemaining, false);
        setDashboardStatValue('dashboardTotalRevenue', totalRevenue, false);
        setDashboardStatValue('dashboardTotalContainers', totalContainers, true);
    }

    // Initialize stats
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeDashboardStats);
    } else {
        initializeDashboardStats();
    }

    // Global Search Functionality
    var globalSearchInput = document.getElementById('globalSearchInput');
    var searchResults = document.getElementById('searchResults');
    var searchLoader = document.getElementById('searchLoader');
    var mobileSearchInput = document.getElementById('mobileSearchInput');
    var mobileSearchResults = document.getElementById('mobileSearchResults');
    var searchTimeout = null;

    function performGlobalSearch(query, isMobile) {
        var resultsContainer = isMobile ? mobileSearchResults : searchResults;
        
        if (query.length < 2) {
            if (resultsContainer) {
                resultsContainer.classList.add('hidden');
                resultsContainer.innerHTML = '';
            }
            if (!isMobile && searchLoader) {
                searchLoader.classList.add('hidden');
            }
            return;
        }

        // Show loading indicator
        if (!isMobile && searchLoader) {
            searchLoader.classList.remove('hidden');
        }

        fetch('dashboard.php?action=global_search&q=' + encodeURIComponent(query), {
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        })
        .then(function(response) {
            console.log('Response status:', response.status); // Debug log
            console.log('Response headers:', response.headers.get('content-type')); // Debug log
            
            if (!response.ok) {
                throw new Error('HTTP error! status: ' + response.status);
            }
            
            return response.text(); // Get as text first to see what we're receiving
        })
        .then(function(text) {
            console.log('Response text:', text); // Debug log
            
            try {
                var data = JSON.parse(text);
                
                // Hide loading indicator
                if (!isMobile && searchLoader) {
                    searchLoader.classList.add('hidden');
                }
                
                if (data.success) {
                    renderSearchResults(data.results, query, isMobile);
                } else {
                    console.error('Search failed:', data);
                    if (resultsContainer) {
                        resultsContainer.innerHTML = '<div class="p-6 text-center"><p class="text-sm text-red-500">Search returned no success flag</p></div>';
                        resultsContainer.classList.remove('hidden');
                    }
                }
            } catch (e) {
                console.error('JSON parse error:', e);
                console.error('Response was:', text);
                if (resultsContainer) {
                    resultsContainer.innerHTML = '<div class="p-6 text-center"><p class="text-sm text-red-500">Invalid response from server. Check console.</p></div>';
                    resultsContainer.classList.remove('hidden');
                }
            }
        })
        .catch(function(error) {
            console.error('Search error:', error);
            // Hide loading indicator
            if (!isMobile && searchLoader) {
                searchLoader.classList.add('hidden');
            }
            // Show error message
            if (resultsContainer) {
                resultsContainer.innerHTML = '<div class="p-6 text-center"><p class="text-sm text-red-500">Search failed: ' + error.message + '</p></div>';
                resultsContainer.classList.remove('hidden');
            }
        });
    }

    function renderSearchResults(results, query, isMobile) {
        var resultsContainer = isMobile ? mobileSearchResults : searchResults;
        if (!resultsContainer) {
            console.error('Results container not found');
            return;
        }
        
        var html = '';
        var hasResults = false;

        // Partners Section
        if (results.partners && results.partners.length > 0) {
            hasResults = true;
            html += '<div class="' + (isMobile ? 'mb-3' : 'p-3 border-b border-slate-200 dark:border-slate-800') + '">';
            html += '<p class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-2">Partners</p>';
            for (var i = 0; i < results.partners.length; i++) {
                var p = results.partners[i];
                html += '<a href="partners.php" class="block px-3 py-2 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors mb-1" onclick="' + (isMobile ? 'closeMobileSearch()' : '') + '">';
                html += '<p class="text-sm font-semibold text-slate-900 dark:text-slate-100">' + escapeHtml(p.name) + '</p>';
                html += '<p class="text-xs text-slate-500 dark:text-slate-400">Total: Rs ' + p.total + ' | Remaining: Rs ' + p.remaining + '</p>';
                html += '</a>';
            }
            html += '</div>';
        }

        // Customers Section
        if (results.customers && results.customers.length > 0) {
            hasResults = true;
            html += '<div class="' + (isMobile ? 'mb-3' : 'p-3 border-b border-slate-200 dark:border-slate-800') + '">';
            html += '<p class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-2">Customers</p>';
            for (var i = 0; i < results.customers.length; i++) {
                var c = results.customers[i];
                html += '<a href="customers.php" class="block px-3 py-2 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors mb-1" onclick="' + (isMobile ? 'closeMobileSearch()' : '') + '">';
                html += '<p class="text-sm font-semibold text-slate-900 dark:text-slate-100">' + escapeHtml(c.name) + '</p>';
                html += '<p class="text-xs text-slate-500 dark:text-slate-400">Total: Rs ' + c.total + ' | Remaining: Rs ' + c.remaining + '</p>';
                html += '</a>';
            }
            html += '</div>';
        }

        // Containers Section
        if (results.containers && results.containers.length > 0) {
            hasResults = true;
            html += '<div class="' + (isMobile ? 'mb-3' : 'p-3 border-b border-slate-200 dark:border-slate-800') + '">';
            html += '<p class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-2">Containers</p>';
            for (var i = 0; i < results.containers.length; i++) {
                var cont = results.containers[i];
                html += '<a href="containers.php" class="block px-3 py-2 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors mb-1" onclick="' + (isMobile ? 'closeMobileSearch()' : '') + '">';
                html += '<p class="text-sm font-semibold text-slate-900 dark:text-slate-100">' + escapeHtml(cont.container_number) + '</p>';
                html += '<p class="text-xs text-slate-500 dark:text-slate-400">';
                html += 'BL: ' + escapeHtml(cont.bl_number) + ' | Customer: ' + escapeHtml(cont.customer_name || 'N/A');
                html += '</p>';
                html += '</a>';
            }
            html += '</div>';
        }

        // Agents Section
        if (results.agents && results.agents.length > 0) {
            hasResults = true;
            html += '<div class="' + (isMobile ? 'mb-3' : 'p-3 border-b border-slate-200 dark:border-slate-800') + '">';
            html += '<p class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-2">Agents</p>';
            for (var i = 0; i < results.agents.length; i++) {
                var a = results.agents[i];
                html += '<a href="agents.php" class="block px-3 py-2 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors mb-1" onclick="' + (isMobile ? 'closeMobileSearch()' : '') + '">';
                html += '<p class="text-sm font-semibold text-slate-900 dark:text-slate-100">' + escapeHtml(a.name) + '</p>';
                html += '<p class="text-xs text-slate-500 dark:text-slate-400">Contact: ' + escapeHtml(a.contact || 'N/A') + '</p>';
                html += '</a>';
            }
            html += '</div>';
        }

        // Rate Items Section
        if (results.rate_items && results.rate_items.length > 0) {
            hasResults = true;
            html += '<div class="' + (isMobile ? 'mb-3' : 'p-3 border-b border-slate-200 dark:border-slate-800') + '">';
            html += '<p class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-2">Rate List Items</p>';
            for (var i = 0; i < results.rate_items.length; i++) {
                var r = results.rate_items[i];
                html += '<a href="rate-list.php" class="block px-3 py-2 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors mb-1" onclick="' + (isMobile ? 'closeMobileSearch()' : '') + '">';
                html += '<p class="text-sm font-semibold text-slate-900 dark:text-slate-100">' + escapeHtml(r.item_name) + '</p>';
                html += '<p class="text-xs text-slate-500 dark:text-slate-400">Rate: Rs ' + r.rate + '</p>';
                html += '</a>';
            }
            html += '</div>';
        }

        // Expenses Section
        if (results.expenses && results.expenses.length > 0) {
            hasResults = true;
            html += '<div class="' + (isMobile ? '' : 'p-3') + '">';
            html += '<p class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-2">Expenses</p>';
            for (var i = 0; i < results.expenses.length; i++) {
                var e = results.expenses[i];
                html += '<div class="px-3 py-2 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors mb-1">';
                html += '<p class="text-sm font-semibold text-slate-900 dark:text-slate-100">' + escapeHtml(e.description) + '</p>';
                html += '<p class="text-xs text-slate-500 dark:text-slate-400">Amount: Rs ' + e.amount;
                if (e.container_number) {
                    html += ' | Container: ' + escapeHtml(e.container_number);
                }
                html += '</p>';
                html += '</div>';
            }
            html += '</div>';
        }

        if (!hasResults) {
            html = '<div class="p-6 text-center">';
            html += '<span class="material-symbols-outlined text-slate-400 text-4xl">search_off</span>';
            html += '<p class="text-sm text-slate-500 dark:text-slate-400 mt-2">No results found for "' + escapeHtml(query) + '"</p>';
            html += '</div>';
        }

        console.log('Rendering results, hasResults:', hasResults); // Debug log
        resultsContainer.innerHTML = html;
        if (!isMobile) {
            resultsContainer.classList.remove('hidden');
        }
    }

    function escapeHtml(text) {
        if (!text) return '';
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Desktop Search Event Listeners
    if (globalSearchInput && searchResults) {
        console.log('Search initialized'); // Debug log
        
        globalSearchInput.addEventListener('input', function(e) {
            var query = e.target.value.trim();
            console.log('Search input:', query); // Debug log
            
            if (query.length < 2) {
                searchResults.classList.add('hidden');
                if (searchLoader) searchLoader.classList.add('hidden');
                return;
            }
            
            // Show loading immediately
            if (searchLoader) searchLoader.classList.remove('hidden');
            
            // Debounced search
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(function() {
                performGlobalSearch(query, false);
            }, 200);
        });

        globalSearchInput.addEventListener('focus', function(e) {
            var query = e.target.value.trim();
            if (query.length >= 2) {
                performGlobalSearch(query, false);
            }
        });

        // Close search results when clicking outside
        document.addEventListener('click', function(e) {
            if (searchResults && !globalSearchInput.contains(e.target) && !searchResults.contains(e.target)) {
                searchResults.classList.add('hidden');
            }
        });

        // Handle ESC key to close search
        globalSearchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                searchResults.classList.add('hidden');
                if (searchLoader) searchLoader.classList.add('hidden');
                globalSearchInput.blur();
            }
        });
    } else {
        console.error('Search elements not found:', {
            input: !!globalSearchInput,
            results: !!searchResults
        });
    }

    // Mobile Search Event Listeners
    if (mobileSearchInput && mobileSearchResults) {
        mobileSearchInput.addEventListener('input', function(e) {
            var query = e.target.value.trim();
            
            if (query.length < 2) {
                mobileSearchResults.innerHTML = '';
                return;
            }
            
            // Debounced search
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(function() {
                performGlobalSearch(query, true);
            }, 200);
        });

        // Handle ESC key to close mobile search
        mobileSearchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeMobileSearch();
            }
        });
    }

    // Close mobile search when clicking outside
    document.addEventListener('click', function(e) {
        var panel = document.getElementById('mobileSearchPanel');
        if (panel && !panel.classList.contains('hidden') && !panel.contains(e.target)) {
            var searchBtn = e.target.closest('button[onclick="openMobileSearch()"]');
            if (!searchBtn) {
                closeMobileSearch();
            }
        }
    });
})();
</script>

<?php
}
?>

