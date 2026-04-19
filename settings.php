<?php
require 'config/auth.php';
require 'config/database.php';
require 'config/constants.php';

/* ============================
   ADMIN ONLY ACCESS
============================ */
if ($_SESSION['role'] !== 'admin') {
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Access denied. Admin only.']);
        exit;
    }
    header('Location: dashboard.php');
    exit;
}

/* ============================
   SCHEMA MIGRATION - SYSTEM SETTINGS
============================ */
// Create system_settings table if not exists
$conn->query("
    CREATE TABLE IF NOT EXISTS system_settings (
        id INT PRIMARY KEY AUTO_INCREMENT,
        setting_key VARCHAR(100) UNIQUE NOT NULL,
        setting_value TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )
");

// Insert default settings if not exist
$defaultSettings = [
    'system_name' => 'Container',
    'system_location' => '',
    'system_contact' => '',
    'system_email' => '',
    'system_logo' => '',
    'show_expense_page' => '1'
];

foreach ($defaultSettings as $key => $value) {
    $check = $conn->query("SELECT id FROM system_settings WHERE setting_key = '$key'");
    if ($check->num_rows === 0) {
        $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
        $stmt->bind_param("ss", $key, $value);
        $stmt->execute();
    }
}

$action = $_GET['action'] ?? 'list';
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

/* ============================
   GET FUNDS DATA
============================ */
if ($action === 'get_funds' && $isAjax) {
    $fundType = $_GET['fund_type'] ?? 'dashboard';

    if ($fundType === 'dashboard') {
        $query = "
            SELECT fa.*, p.name as partner_name_from_table
            FROM funds_account fa
            LEFT JOIN partners p ON fa.partner_id = p.id
            WHERE fa.fund_type IN ('dashboard', 'general_fund')
            ORDER BY fa.transaction_date DESC, fa.id DESC
            LIMIT 100
        ";
        $stmt = $conn->prepare($query);
    } else {
        $query = "
            SELECT fa.*, p.name as partner_name_from_table
            FROM funds_account fa
            LEFT JOIN partners p ON fa.partner_id = p.id
            WHERE fa.fund_type = ?
            ORDER BY fa.transaction_date DESC, fa.id DESC
            LIMIT 100
        ";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $fundType);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    
    $funds = [];
    while ($row = $result->fetch_assoc()) {
        $funds[] = $row;
    }
    $stmt->close();
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'funds' => $funds]);
    exit;
}

/* ============================
   DELETE FUND
============================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_fund' && $isAjax) {
    $fundId = (int)($_POST['fund_id'] ?? 0);
    
    if ($fundId <= 0) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid fund ID.']);
        exit;
    }
    
    // Fetch the fund record to get details
    $fundStmt = $conn->prepare("SELECT * FROM funds_account WHERE id = ?");
    $fundStmt->bind_param("i", $fundId);
    $fundStmt->execute();
    $fundResult = $fundStmt->get_result();
    
    if ($fundResult->num_rows === 0) {
        $fundStmt->close();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Fund record not found.']);
        exit;
    }
    
    $fund = $fundResult->fetch_assoc();
    $fundStmt->close();
    
    $partnerId = (int)($fund['partner_id'] ?? 0);
    $amount = (float)($fund['amount'] ?? 0);
    $referenceNumber = $fund['reference_number'] ?? '';
    $proofFile = $fund['proof'] ?? '';
    $fundType = $fund['fund_type'] ?? '';
    
    $conn->begin_transaction();
    
    try {
        // Reverse partner account whenever the fund record is linked to a partner.
        if ($partnerId > 0) {
            // Delete matching partner ledger entry when reference exists.
            if (!empty($referenceNumber)) {
                $deleteTxStmt = $conn->prepare("
                    DELETE FROM partner_transactions 
                    WHERE partner_id = ? 
                    AND reference_number = ? 
                    AND amount = ? 
                    AND transaction_type = 'debit'
                    LIMIT 1
                ");
                $deleteTxStmt->bind_param("isd", $partnerId, $referenceNumber, $amount);
                if (!$deleteTxStmt->execute()) {
                    throw new Exception('Failed to delete partner transaction.');
                }
                $deleteTxStmt->close();
            }

            // Reverse partner totals and remaining balance.
            $updatePartnerStmt = $conn->prepare("
                UPDATE partners 
                SET total = total - ?, remaining = remaining - ? 
                WHERE id = ?
            ");
            $updatePartnerStmt->bind_param("ddi", $amount, $amount, $partnerId);
            if (!$updatePartnerStmt->execute()) {
                throw new Exception('Failed to update partner account.');
            }
            if ($updatePartnerStmt->affected_rows === 0) {
                $updatePartnerStmt->close();
                throw new Exception('Partner account not found for this fund record.');
            }
            $updatePartnerStmt->close();
        }
        
        // Reverse expense account whenever the fund type is expense_account
        if ($fundType === 'expense_account') {
            // Subtract the amount from expense_account balance
            $updateExpenseStmt = $conn->prepare("
                UPDATE expense_account 
                SET total_amount = total_amount - ?, 
                    updated_at = NOW() 
                WHERE id = (SELECT MIN(id) FROM expense_account)
            ");
            $updateExpenseStmt->bind_param("d", $amount);
            if (!$updateExpenseStmt->execute()) {
                throw new Exception('Failed to update expense account.');
            }
            $updateExpenseStmt->close();
        }
        
        // Delete from funds_account
        $deleteFundStmt = $conn->prepare("DELETE FROM funds_account WHERE id = ?");
        $deleteFundStmt->bind_param("i", $fundId);
        if (!$deleteFundStmt->execute()) {
            throw new Exception('Failed to delete fund record.');
        }
        $deleteFundStmt->close();
        
        // Delete proof file if exists
        if (!empty($proofFile)) {
            $proofPath = __DIR__ . '/uploads/partner_transaction_proofs/' . $proofFile;
            if (file_exists($proofPath)) {
                unlink($proofPath);
            }
        }
        
        $conn->commit();
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Fund deleted successfully.']);
        exit;
        
    } catch (Exception $e) {
        $conn->rollback();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

/* ============================
   UPDATE SYSTEM SETTINGS
============================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update_settings') {
    
    $system_name = trim($_POST['system_name']);
    $system_location = trim($_POST['system_location']);
    $system_contact = trim($_POST['system_contact']);
    $system_email = trim($_POST['system_email']);
    
    // Handle logo upload
    $logoFile = $_POST['existing_logo'] ?? '';
    if (!empty($_FILES['system_logo']['name'])) {
        $ext = strtolower(pathinfo($_FILES['system_logo']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        
        if (in_array($ext, $allowed)) {
            $targetDir = "uploads/system/";
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0777, true);
            }
            $logoFile = "logo_" . time() . "." . $ext;
            move_uploaded_file(
                $_FILES['system_logo']['tmp_name'],
                $targetDir . $logoFile
            );
        }
    }
    
    // Update settings
    $settings = [
        'system_name' => $system_name,
        'system_location' => $system_location,
        'system_contact' => $system_contact,
        'system_email' => $system_email,
        'system_logo' => $logoFile
    ];
    
    foreach ($settings as $key => $value) {
        $stmt = $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?");
        $stmt->bind_param("ss", $value, $key);
        $stmt->execute();
    }
    
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Settings updated successfully']);
        exit;
    }
    
    header("Location: settings.php");
    exit;
}

/* ============================
   CREATE USER
============================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add') {

    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? ''); // plain for now
    $role     = $_POST['role'] ?? 'user';

    if ($name && $email && $password) {
        try {
            $stmt = $conn->prepare("
                INSERT INTO users (name, email, password, role, status)
                VALUES (?, ?, ?, ?, 1)
            ");
            $stmt->bind_param("ssss", $name, $email, $password, $role);
            
            if ($stmt->execute()) {
                $stmt->close();
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'message' => 'User added successfully']);
                    exit;
                }
                header("Location: settings.php");
                exit;
            } else {
                $error = $stmt->error;
                $stmt->close();
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'Database error: ' . $error]);
                    exit;
                }
            }
        } catch (Exception $e) {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
                exit;
            }
        }
    } else {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Please fill in all required fields']);
            exit;
        }
    }

    if (!$isAjax) {
        header("Location: settings.php");
        exit;
    }
}

/* ============================
   UPDATE USER
============================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'edit') {

    $id       = intval($_POST['id'] ?? 0);
    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? ''); 
    $role     = $_POST['role'] ?? 'user';

    if ($name && $email && $id > 0) {
        // Check if trying to edit the default admin (ID=1) - prevent it
        if ($id == 1) {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Cannot edit default admin user']);
                exit;
            }
            header("Location: settings.php");
            exit;
        }
        
        try {
            if (!empty($password)) {
                // Update with new password
                $stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, password = ?, role = ? WHERE id = ?");
                $stmt->bind_param("ssssi", $name, $email, $password, $role, $id);
            } else {
                // Update without password change
                $stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, role = ? WHERE id = ?");
                $stmt->bind_param("sssi", $name, $email, $role, $id);
            }
            
            if ($stmt->execute()) {
                $stmt->close();
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'message' => 'User updated successfully']);
                    exit;
                }
                header("Location: settings.php");
                exit;
            } else {
                $error = $stmt->error;
                $stmt->close();
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'Database error: ' . $error]);
                    exit;
                }
            }
        } catch (Exception $e) {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
                exit;
            }
        }
    } else {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Please fill in all required fields']);
            exit;
        }
    }

    if (!$isAjax) {
        header("Location: settings.php");
        exit;
    }
}

/* ============================
   DELETE USER
============================ */
if ($action === 'delete' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    
    // Check if trying to delete the default admin (ID=1) - prevent it
    if ($id != 1) {
        $conn->query("DELETE FROM users WHERE id = $id");
    }
    
    if (!$isAjax) {
        header("Location: settings.php");
        exit;
    }
    
    // For AJAX, set action to list and let page render below
    $action = 'list';
}

/* ============================
   TOGGLE EXPENSE PAGE VISIBILITY
============================ */
if ($action === 'toggle_expense') {
    $currentValue = getSystemSetting('show_expense_page', '1');
    $newValue = $currentValue === '1' ? '0' : '1';
    
    $stmt = $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'show_expense_page'");
    $stmt->bind_param("s", $newValue);
    $stmt->execute();
    
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'value' => $newValue]);
        exit;
    }
    
    header("Location: settings.php");
    exit;
}

/* ============================
   CLEAR SYSTEM LOGO
============================ */
if ($action === 'clear_logo') {
    $stmt = $conn->prepare("UPDATE system_settings SET setting_value = '' WHERE setting_key = 'system_logo'");
    $stmt->execute();
    
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Logo removed successfully']);
        exit;
    }
    
    header("Location: settings.php");
    exit;
}

/* ============================
   TOGGLE USER STATUS
============================ */
if ($action === 'toggle' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    
    // Check if trying to toggle the default admin (ID=1) - prevent it
    if ($id != 1) {
        $conn->query("
            UPDATE users 
            SET status = IF(status=1, 0, 1)
            WHERE id = $id
        ");
    }
    
    if (!$isAjax) {
        header("Location: settings.php");
        exit;
    }
    
    // For AJAX, set action to list and let page render below
    $action = 'list';
}

/* ============================
   CLEAR ALL CHATS
============================ */
if ($action === 'clearChats') {
    $conn->query("DELETE FROM notifications");
    
    if (!$isAjax) {
        header("Location: settings.php");
        exit;
    }
    
    // For AJAX, set action to list and let page render below
    $action = 'list';
}

// Fetch current system settings
$systemSettings = [];
$settingsQuery = $conn->query("SELECT setting_key, setting_value FROM system_settings");
while ($row = $settingsQuery->fetch_assoc()) {
    $systemSettings[$row['setting_key']] = $row['setting_value'];
}

if (!$isAjax) {
    include 'include/header.php';
    include 'include/sidebar.php';
}

if ($isAjax) {
    ob_start();
}
?>

<div id="page-content" class="flex-1 flex flex-col min-w-0 overflow-y-auto">

<!-- Header - Responsive -->
<header class="sticky top-0 z-40 w-full bg-white/80 dark:bg-slate-900/80 backdrop-blur-md border-b border-slate-200 dark:border-slate-800">
  <div class="flex items-center justify-between px-4 md:px-8 h-16 md:h-auto md:py-4">
    <!-- Mobile Menu Button -->
    <button type="button" onclick="toggleMobileSidebar()" class="flex md:hidden items-center justify-center h-11 w-11 rounded-lg hover:bg-slate-200/50 dark:hover:bg-slate-800/50 transition-colors" aria-label="Open navigation menu">
      <span class="material-symbols-outlined text-slate-700 dark:text-slate-300">menu</span>
    </button>
    
    <!-- Title Section -->
    <div class="flex items-center gap-3 flex-1 md:flex-initial">
        <div class="hidden md:flex size-10 rounded-lg bg-gradient-to-br from-primary to-blue-600 items-center justify-center text-white shadow-lg shadow-blue-500/20">
            <span class="material-symbols-outlined">settings</span>
        </div>
        <div>
            <h2 class="text-lg md:text-2xl font-bold text-slate-900 dark:text-white tracking-tight">Settings</h2>
            <p class="hidden md:block text-xs text-slate-500 dark:text-slate-400">Manage system configuration and user access</p>
        </div>
    </div>
    
    <!-- Actions -->
    <div class="flex items-center gap-2 md:gap-3">
        <?php 
        $showExpensePage = getSystemSetting('show_expense_page', '1');
        $isExpenseOn = $showExpensePage === '1';
        ?>
        <button onclick="toggleExpensePage()" id="expenseToggleBtn" 
                class="flex items-center gap-1.5 px-3 md:px-4 py-2 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded-lg font-semibold text-sm hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors border border-slate-200 dark:border-slate-700">
            <span class="material-symbols-outlined text-base"><?= $isExpenseOn ? 'visibility' : 'visibility_off' ?></span>
            <span id="expenseToggleText" class="hidden sm:inline"><?= $isExpenseOn ? 'Show' : 'Hide' ?> Expense Page</span>
        </button>
        <button onclick="openAddUserModal()" class="bg-primary text-white h-11 px-3 md:px-4 py-2 rounded-lg font-bold text-sm hover:bg-blue-700 transition-colors flex items-center gap-2 shadow-lg shadow-blue-500/20 active:scale-[0.95]">
            <span class="material-symbols-outlined text-sm">add</span>
            <span class="hidden sm:inline">Add User</span>
        </button>
    </div>
  </div>
</header>

<!-- Page Body - Responsive Container -->
<div class="p-4 md:p-6 lg:p-8 max-w-7xl mx-auto w-full space-y-4 md:space-y-6 pb-20 md:pb-8">

<!-- System Configuration -->
<section class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 shadow-sm overflow-hidden">
    <details class="group">
        <summary onclick="toggleSystemSettings()" class="flex items-center justify-between p-4 md:p-6 cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors">
            <div class="flex items-center gap-2 md:gap-3">
                <span class="material-symbols-outlined text-slate-500 text-xl md:text-2xl">admin_panel_settings</span>
                <h3 class="text-base md:text-lg font-semibold">System Configuration</h3>
            </div>
            <span id="systemSettingsArrow" class="material-symbols-outlined transition-transform group-open:rotate-180 text-xl md:text-2xl">expand_more</span>
        </summary>
        <div id="systemSettingsContent" class="px-4 md:px-6 pb-4 md:pb-6 pt-2 border-t border-slate-100 dark:border-slate-800 hidden">
            <p class="text-slate-500 dark:text-slate-400 text-sm mb-6">Manage global application behavior, API endpoints, and environmental overrides for the entire organization.</p>
    
    <form method="POST" action="settings.php?action=update_settings" enctype="multipart/form-data" id="systemSettingsForm" class="space-y-4">
        <input type="hidden" name="existing_logo" value="<?= htmlspecialchars($systemSettings['system_logo'] ?? '') ?>">
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">System Name *</label>
                <input name="system_name" type="text" value="<?= htmlspecialchars($systemSettings['system_name'] ?? '') ?>" 
                       placeholder="Enter system name" required
                       class="w-full border border-gray-300 p-2 rounded focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                <input name="system_email" type="email" value="<?= htmlspecialchars($systemSettings['system_email'] ?? '') ?>" 
                       placeholder="company@example.com"
                       class="w-full border border-gray-300 p-2 rounded focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            </div>
        </div>
        
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Location/Address</label>
            <textarea name="system_location" rows="2" placeholder="Enter company address"
                      class="w-full border border-gray-300 p-2 rounded focus:ring-2 focus:ring-blue-500 focus:border-blue-500"><?= htmlspecialchars($systemSettings['system_location'] ?? '') ?></textarea>
        </div>
        
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Contact Details</label>
            <textarea name="system_contact" rows="2" placeholder="Phone numbers, fax, etc."
                      class="w-full border border-gray-300 p-2 rounded focus:ring-2 focus:ring-blue-500 focus:border-blue-500"><?= htmlspecialchars($systemSettings['system_contact'] ?? '') ?></textarea>
        </div>
        
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Company Logo</label>
            <?php if (!empty($systemSettings['system_logo'])): ?>
            <div class="mb-2 relative inline-block">
                <img src="uploads/system/<?= htmlspecialchars($systemSettings['system_logo']) ?>" 
                     alt="Current Logo" class="h-20 border rounded p-2 bg-gray-50">
                <button type="button" onclick="clearLogo()" 
                        class="absolute -top-2 -right-2 bg-red-600 hover:bg-red-700 text-white rounded-full p-1 shadow-lg transition-all" 
                        title="Remove logo">
                    <span class="material-symbols-outlined text-[16px]">close</span>
                </button>
                <p class="text-xs text-gray-500 mt-1">Current logo</p>
            </div>
            <?php endif; ?>
            <input name="system_logo" type="file" accept=".jpg,.jpeg,.png,.gif,.webp"
                   class="w-full border border-gray-300 p-2 rounded focus:ring-2 focus:ring-blue-500 focus:border-blue-500 file:mr-2 file:py-1 file:px-3 file:rounded file:border-0 file:text-sm file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
            <p class="text-xs text-gray-500 mt-1">Upload new logo to replace current one (JPG, PNG, GIF, WEBP)</p>
        </div>
        
        <div class="flex justify-end pt-4 border-t border-slate-200 dark:border-slate-800">
            <button type="submit" 
                    class="bg-primary hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-semibold shadow-md transition-all flex items-center gap-2">
                <span class="material-symbols-outlined text-[18px]">check</span>
                Save Settings
            </button>
        </div>
    </form>
        </div>
    </details>
</section>

<!-- User Management -->
<section class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 shadow-sm overflow-hidden">
    <div class="p-4 md:p-6 border-b border-slate-100 dark:border-slate-800">
        <div class="flex items-center gap-2 md:gap-3">
            <span class="material-symbols-outlined text-slate-500 text-xl md:text-2xl">manage_accounts</span>
            <h3 class="text-base md:text-lg font-semibold">User Management</h3>
        </div>
    </div>
    
    <!-- Desktop Table View -->
    <div class="hidden md:block overflow-x-auto">
        <table class="w-full text-left">
            <thead>
                <tr class="bg-slate-50 dark:bg-slate-800/50 text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">
                    <th class="px-6 py-4 font-semibold">Name</th>
                    <th class="px-6 py-4 font-semibold">Email</th>
                    <th class="px-6 py-4 font-semibold">Role</th>
                    <th class="px-6 py-4 font-semibold text-center">Status</th>
                    <th class="px-6 py-4 font-semibold text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
        <?php
        $users = $conn->query("SELECT * FROM users ORDER BY id DESC");
        while ($u = $users->fetch_assoc()):
        ?>
        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors">
            <td class="px-6 py-4 text-sm font-medium"><?= htmlspecialchars($u['name']) ?></td>
            <td class="px-6 py-4 text-sm text-slate-500 dark:text-slate-400"><?= htmlspecialchars($u['email']) ?></td>
            <td class="px-6 py-4 text-sm">
                <span class="px-2.5 py-1 rounded-full <?= $u['role'] === 'admin' ? 'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-400' ?> text-xs font-bold">
                    <?= ucfirst($u['role']) ?>
                </span>
            </td>
            <td class="px-6 py-4 text-center">
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full <?= $u['status'] ? 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400' : 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400' ?> text-xs font-bold">
                    <span class="size-1.5 rounded-full <?= $u['status'] ? 'bg-emerald-500' : 'bg-red-500' ?>"></span>
                    <?= $u['status'] ? 'Active' : 'Inactive' ?>
                </span>
            </td>
            <td class="px-6 py-4 text-right">
                <?php if (intval($u['id']) === 1): ?>
                    <!-- Default admin (ID=1) - no actions available -->
                <?php else: ?>
                <div class="flex justify-end gap-2 text-slate-400">
                    <button onclick='openEditUserModal(<?= json_encode($u) ?>)' 
                            class="p-1.5 hover:bg-slate-100 dark:hover:bg-slate-700 rounded-lg hover:text-primary transition-colors" title="Edit">
                        <span class="material-symbols-outlined text-[20px]">edit</span>
                    </button>
                    <a href="settings.php?action=toggle&id=<?= $u['id'] ?>"
                       class="ajax-link p-1.5 hover:bg-slate-100 dark:hover:bg-slate-700 rounded-lg hover:text-primary transition-colors" title="Toggle Status">
                        <span class="material-symbols-outlined text-[20px]">swap_horiz</span>
                    </a>
                    <a href="settings.php?action=delete&id=<?= $u['id'] ?>"
                       data-delete-user="true"
                       class="ajax-link p-1.5 hover:bg-slate-100 dark:hover:bg-slate-700 rounded-lg hover:text-red-600 transition-colors" title="Delete">
                        <span class="material-symbols-outlined text-[20px]">delete</span>
                    </a>
                </div>
                <?php endif; ?>
            </td>
        </tr>
        <?php endwhile; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Mobile Card View -->
    <div class="md:hidden divide-y divide-slate-100 dark:divide-slate-800">
        <?php
        $users_mobile = $conn->query("SELECT * FROM users ORDER BY id DESC");
        while ($u = $users_mobile->fetch_assoc()):
        ?>
        <div class="p-4 hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors">
            <div class="flex items-start justify-between mb-3">
                <div class="flex-1">
                    <h4 class="font-semibold text-slate-900 dark:text-white mb-1"><?= htmlspecialchars($u['name']) ?></h4>
                    <p class="text-sm text-slate-500 dark:text-slate-400 mb-2"><?= htmlspecialchars($u['email']) ?></p>
                </div>
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full <?= $u['status'] ? 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400' : 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400' ?> text-xs font-bold">
                    <span class="material-symbols-outlined text-xs"><?= $u['status'] ? 'check_circle' : 'cancel' ?></span>
                    <?= $u['status'] ? 'Active' : 'Inactive' ?>
                </span>
            </div>
            <div class="flex items-center justify-between">
                <span class="px-2.5 py-1 rounded-full <?= $u['role'] === 'admin' ? 'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-400' ?> text-xs font-bold">
                    <span class="material-symbols-outlined text-xs align-middle"><?= $u['role'] === 'admin' ? 'admin_panel_settings' : 'person' ?></span>
                    <?= ucfirst($u['role']) ?>
                </span>
                <?php if (intval($u['id']) !== 1): ?>
                <div class="flex gap-2">
                    <button onclick='openEditUserModal(<?= json_encode($u) ?>)' class="text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 p-2" title="Edit">
                        <span class="material-symbols-outlined text-lg">edit</span>
                    </button>
                    <a href="settings.php?action=toggle&id=<?= $u['id'] ?>" class="ajax-link text-amber-600 dark:text-amber-400 hover:text-amber-800 dark:hover:text-amber-300 p-2" data-page="settings" title="Toggle Status">
                        <span class="material-symbols-outlined text-lg">sync</span>
                    </a>
                    <a href="settings.php?action=delete&id=<?= $u['id'] ?>" class="ajax-link text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-300 p-2" data-page="settings" data-delete-user="true" title="Delete">
                        <span class="material-symbols-outlined text-lg">delete</span>
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endwhile; ?>
    </div>
</section>

<!-- Chat Management -->
<section class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 shadow-sm">
    <div class="p-4 md:p-6">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div class="flex items-center gap-2 md:gap-3">
                <span class="material-symbols-outlined text-slate-500 text-xl md:text-2xl">forum</span>
                <div>
                    <h3 class="text-base md:text-lg font-semibold">Chat Management</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Maintenance tasks for support channels and internal funds.</p>
                </div>
            </div>
            <div class="flex flex-wrap gap-2 md:gap-3">
                <button onclick="openClearChatsModal()" class="flex-1 sm:flex-none px-3 md:px-4 py-2 bg-red-600 text-white rounded-lg font-semibold text-xs md:text-sm hover:bg-red-700 transition-colors flex items-center justify-center gap-2">
                    <span class="material-symbols-outlined text-sm">delete_sweep</span>
                    <span class="hidden sm:inline">Clear Chats</span>
                </button>
                <button onclick="openFundsModal()" class="flex-1 sm:flex-none px-3 md:px-4 py-2 bg-primary text-white rounded-lg font-semibold text-xs md:text-sm hover:bg-blue-700 transition-colors flex items-center justify-center gap-2 shadow-lg shadow-blue-500/20">
                    <span class="material-symbols-outlined text-sm">payments</span>
                    <span class="hidden sm:inline">Show Funds</span>
                </button>
            </div>
        </div>
    </div>
</section>
</div>
</div>

<!-- Mobile Sidebar Overlay -->
<div id="mobileSidebarOverlay" class="md:hidden" onclick="closeMobileSidebar()"></div>

<div id="addUserModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4 animate-fadeIn">
    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-md mx-auto overflow-hidden transform transition-all animate-slideIn max-h-[90vh] flex flex-col">
        <!-- Modal Header -->
        <div class="bg-gradient-to-r from-blue-600 via-indigo-600 to-purple-600 text-white px-5 py-3">
            <div class="flex justify-between items-center">
                <h3 class="text-lg font-bold flex items-center gap-2">
                    <span class="material-symbols-outlined">person_add</span>
                    Add New User
                </h3>
                <button type="button" onclick="closeAddUserModal()" class="text-white/90 hover:text-white hover:bg-white/20 rounded-lg p-1.5 transition-all">
                    <span class="material-symbols-outlined text-xl">close</span>
                </button>
            </div>
        </div>
        
        <!-- Modal Body -->
        <form method="POST" action="settings.php?action=add" id="userAddForm" class="p-5 space-y-4 overflow-y-auto">
            <!-- Name -->
            <div class="space-y-1.5">
                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300">
                    <span class="flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-base text-blue-600">badge</span>
                        Full Name
                        <span class="text-red-500">*</span>
                    </span>
                </label>
                <input name="name" type="text" required
                       class="w-full px-3 py-2 border-2 border-slate-200 dark:border-slate-700 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent dark:bg-slate-800 dark:text-white transition-all text-sm"
                       placeholder="Enter full name">
            </div>
            
            <!-- Email -->
            <div class="space-y-1.5">
                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300">
                    <span class="flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-base text-green-600">email</span>
                        Email Address
                        <span class="text-red-500">*</span>
                    </span>
                </label>
                <input name="email" type="email" required
                       class="w-full px-3 py-2 border-2 border-slate-200 dark:border-slate-700 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent dark:bg-slate-800 dark:text-white transition-all text-sm"
                       placeholder="user@example.com">
            </div>
            
            <!-- Password -->
            <div class="space-y-1.5">
                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300">
                    <span class="flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-base text-amber-600">lock</span>
                        Password
                        <span class="text-red-500">*</span>
                    </span>
                </label>
                <div class="relative">
                    <input name="password" id="add_password" type="password" required
                           class="w-full px-3 py-2 pr-10 border-2 border-slate-200 dark:border-slate-700 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent dark:bg-slate-800 dark:text-white transition-all text-sm"
                           placeholder="Minimum 6 characters">
                    <button type="button" onclick="togglePasswordVisibility('add_password', 'add_password_icon')" 
                            class="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 p-1 rounded transition-colors">
                        <span id="add_password_icon" class="material-symbols-outlined text-[20px]">visibility</span>
                    </button>
                </div>
                <p class="text-xs text-slate-500 dark:text-slate-400 flex items-center gap-1">
                    <span class="material-symbols-outlined text-xs">info</span>
                    Choose a strong password for security
                </p>
            </div>
            
            <!-- Role -->
            <div class="space-y-1.5">
                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300">
                    <span class="flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-base text-purple-600">admin_panel_settings</span>
                        User Role
                        <span class="text-red-500">*</span>
                    </span>
                </label>
                <select name="role" class="w-full px-3 py-2 border-2 border-slate-200 dark:border-slate-700 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent dark:bg-slate-800 dark:text-white transition-all text-sm">
                    <option value="partner">Partner - Partner Access</option>
                    <option value="admin">Admin - Full Access</option>
                </select>
            </div>
            
            <!-- Form Actions -->
            <div class="flex gap-2.5 pt-3 border-t border-slate-200 dark:border-slate-700">
                <button type="button" onclick="closeAddUserModal()"
                        class="flex-1 px-3 py-2.5 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded-lg font-semibold text-sm hover:bg-slate-200 dark:hover:bg-slate-700 transition-all">
                    Cancel
                </button>
                <button type="submit"
                        class="flex-1 px-3 py-2.5 bg-gradient-to-r from-blue-600 to-indigo-600 text-white rounded-lg font-semibold text-sm hover:from-blue-700 hover:to-indigo-700 shadow-lg shadow-blue-500/30 transition-all flex items-center justify-center gap-1.5">
                    <span class="material-symbols-outlined text-base">person_add</span>
                    Create User
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Edit User Modal -->
<div id="editUserModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4 animate-fadeIn">
    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-md mx-auto overflow-hidden transform transition-all animate-slideIn max-h-[90vh] flex flex-col">
        <!-- Modal Header -->
        <div class="bg-gradient-to-r from-amber-500 via-orange-500 to-yellow-600 text-white px-5 py-3">
            <div class="flex justify-between items-center">
                <h3 class="text-lg font-bold flex items-center gap-2">
                    <span class="material-symbols-outlined">edit</span>
                    Edit User
                </h3>
                <button type="button" onclick="closeEditUserModal()" class="text-white/90 hover:text-white hover:bg-white/20 rounded-lg p-1.5 transition-all">
                    <span class="material-symbols-outlined text-xl">close</span>
                </button>
            </div>
        </div>
        
        <!-- Modal Body -->
        <form method="POST" action="settings.php?action=edit" id="userEditForm" class="p-5 space-y-4 overflow-y-auto">
            <input type="hidden" name="id" id="edit_id">
            
            <!-- Name -->
            <div class="space-y-1.5">
                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300">
                    <span class="flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-base text-amber-600">badge</span>
                        Full Name
                        <span class="text-red-500">*</span>
                    </span>
                </label>
                <input name="name" id="edit_name" type="text" required
                       class="w-full px-3 py-2 border-2 border-slate-200 dark:border-slate-700 rounded-lg focus:ring-2 focus:ring-amber-500 focus:border-transparent dark:bg-slate-800 dark:text-white transition-all text-sm"
                       placeholder="Enter full name">
            </div>
            
            <!-- Email -->
            <div class="space-y-1.5">
                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300">
                    <span class="flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-base text-green-600">email</span>
                        Email Address
                        <span class="text-red-500">*</span>
                    </span>
                </label>
                <input name="email" id="edit_email" type="email" required
                       class="w-full px-3 py-2 border-2 border-slate-200 dark:border-slate-700 rounded-lg focus:ring-2 focus:ring-amber-500 focus:border-transparent dark:bg-slate-800 dark:text-white transition-all text-sm"
                       placeholder="user@example.com">
            </div>
            
            <!-- Password -->
            <div class="space-y-1.5">
                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300">
                    <span class="flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-base text-blue-600">lock</span>
                        New Password
                    </span>
                </label>
                <div class="relative">
                    <input name="password" id="edit_password" type="password"
                           class="w-full px-3 py-2 pr-10 border-2 border-slate-200 dark:border-slate-700 rounded-lg focus:ring-2 focus:ring-amber-500 focus:border-transparent dark:bg-slate-800 dark:text-white transition-all text-sm"
                           placeholder="Leave blank to keep current">
                    <button type="button" onclick="togglePasswordVisibility('edit_password', 'edit_password_icon')" 
                            class="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 p-1 rounded transition-colors">
                        <span id="edit_password_icon" class="material-symbols-outlined text-[20px]">visibility</span>
                    </button>
                </div>
                <p class="text-xs text-slate-500 dark:text-slate-400 flex items-center gap-1">
                    <span class="material-symbols-outlined text-xs">info</span>
                    Only fill if you want to change the password
                </p>
            </div>
            
            <!-- Role -->
            <div class="space-y-1.5">
                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300">
                    <span class="flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-base text-purple-600">admin_panel_settings</span>
                        User Role
                        <span class="text-red-500">*</span>
                    </span>
                </label>
                <select name="role" id="edit_role" class="w-full px-3 py-2 border-2 border-slate-200 dark:border-slate-700 rounded-lg focus:ring-2 focus:ring-amber-500 focus:border-transparent dark:bg-slate-800 dark:text-white transition-all text-sm">
                    <option value="partner">Partner - Partner Access</option>
                    <option value="admin">Admin - Full Access</option>
                </select>
            </div>
            
            <!-- Form Actions -->
            <div class="flex gap-2.5 pt-3 border-t border-slate-200 dark:border-slate-700">
                <button type="button" onclick="closeEditUserModal()"
                        class="flex-1 px-3 py-2.5 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded-lg font-semibold text-sm hover:bg-slate-200 dark:hover:bg-slate-700 transition-all">
                    Cancel
                </button>
                <button type="submit"
                        class="flex-1 px-3 py-2.5 bg-gradient-to-r from-amber-500 to-orange-600 text-white rounded-lg font-semibold text-sm hover:from-amber-600 hover:to-orange-700 shadow-lg shadow-amber-500/30 transition-all flex items-center justify-center gap-1.5">
                    <span class="material-symbols-outlined text-base">update</span>
                    Update User
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteConfirmModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4 animate-fadeIn">
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
            <p class="text-slate-600 dark:text-slate-400 text-sm mb-3">Are you sure you want to delete this user?</p>
            <div class="bg-slate-100 dark:bg-slate-800 rounded-lg p-3 mb-4">
                <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">This user will be permanently removed from the system.</p>
            </div>
            <div class="bg-gradient-to-r from-yellow-50 to-orange-50 dark:from-yellow-900/20 dark:to-orange-900/20 border-2 border-yellow-300 dark:border-yellow-700 rounded-xl p-3.5 flex gap-3">
                <span class="material-symbols-outlined text-yellow-700 dark:text-yellow-400 text-xl flex-shrink-0">info</span>
                <div>
                    <p class="text-sm font-semibold text-yellow-900 dark:text-yellow-200 mb-1">Warning</p>
                    <p class="text-xs text-yellow-800 dark:text-yellow-300">This action cannot be undone. All user data and permissions will be lost.</p>
                </div>
            </div>
        </div>
        
        <!-- Modal Footer -->
        <div class="flex gap-2.5 p-4 border-t-2 border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900">
            <button type="button" onclick="closeDeleteConfirmModal()"
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

<!-- Clear Chats Confirmation Modal -->
<div id="clearChatsModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4 animate-fadeIn">
    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-md mx-auto overflow-hidden transform transition-all animate-slideIn">
        <!-- Modal Header -->
        <div class="bg-gradient-to-r from-red-600 via-red-600 to-rose-700 text-white px-5 py-3">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-2xl">delete_sweep</span>
                <h3 class="text-lg font-bold">Clear All Chats</h3>
            </div>
        </div>
        
        <!-- Modal Body -->
        <div class="p-5">
            <p class="text-slate-600 dark:text-slate-400 text-sm mb-3">Are you sure you want to delete all chat messages?</p>
            <div class="bg-slate-100 dark:bg-slate-800 rounded-lg p-3 mb-4">
                <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">All notification messages will be permanently deleted.</p>
            </div>
            <div class="bg-gradient-to-r from-red-50 to-rose-50 dark:from-red-900/20 dark:to-rose-900/20 border-2 border-red-300 dark:border-red-700 rounded-xl p-3.5 flex gap-3">
                <span class="material-symbols-outlined text-red-700 dark:text-red-400 text-xl flex-shrink-0">warning</span>
                <div>
                    <p class="text-sm font-semibold text-red-900 dark:text-red-200 mb-1">Permanent Action</p>
                    <p class="text-xs text-red-800 dark:text-red-300">This will remove all chat history from the system. This action cannot be undone.</p>
                </div>
            </div>
        </div>
        
        <!-- Modal Footer -->
        <div class="flex gap-2.5 p-4 border-t-2 border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900">
            <button type="button" onclick="closeClearChatsModal()"
                    class="flex-1 px-4 py-2.5 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded-lg hover:bg-slate-200 dark:hover:bg-slate-700 transition-all font-semibold text-sm">
                Cancel
            </button>
            <button type="button" onclick="confirmClearChats()"
                    class="flex-1 px-4 py-2.5 bg-gradient-to-r from-red-600 to-rose-700 text-white rounded-lg hover:from-red-700 hover:to-rose-800 transition-all shadow-lg shadow-red-500/30 flex items-center justify-center gap-2 font-semibold text-sm">
                <span class="material-symbols-outlined text-base">delete_sweep</span>
                Clear All
            </button>
        </div>
    </div>
</div>

<!-- Custom Alert Modal -->
<div id="customAlertModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-[70] hidden flex items-center justify-center p-4 animate-fadeIn">
    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-sm mx-auto overflow-hidden transform transition-all animate-slideIn">
        <!-- Modal Header -->
        <div class="bg-gradient-to-r from-blue-600 via-indigo-600 to-blue-700 text-white px-5 py-3">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-xl" id="alertIcon">info</span>
                <h3 id="alertTitle" class="text-lg font-bold">Alert</h3>
            </div>
        </div>
        
        <!-- Modal Body -->
        <div class="p-5">
            <p id="alertMessage" class="text-slate-700 dark:text-slate-300 text-sm mb-4"></p>
        </div>
        
        <!-- Modal Footer -->
        <div class="flex justify-center p-4 border-t-2 border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900">
            <button onclick="closeCustomAlertModal()" 
                    class="px-6 py-2.5 bg-gradient-to-r from-blue-600 to-indigo-600 text-white rounded-lg hover:from-blue-700 hover:to-indigo-700 shadow-lg shadow-blue-500/30 font-semibold text-sm transition-all">
                OK
            </button>
        </div>
    </div>
</div>

<!-- Custom Confirm Modal -->
<div id="customConfirmModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-[70] hidden flex items-center justify-center p-4 animate-fadeIn">
    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-md mx-auto overflow-hidden transform transition-all animate-slideIn">
        <!-- Modal Header -->
        <div class="bg-gradient-to-r from-amber-500 via-orange-500 to-yellow-600 text-white px-5 py-3">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-xl">help</span>
                <h3 id="confirmTitle" class="text-lg font-bold">Confirm</h3>
            </div>
        </div>
        
        <!-- Modal Body -->
        <div class="p-5">
            <p id="confirmMessage" class="text-slate-700 dark:text-slate-300 text-sm mb-4"></p>
        </div>
        
        <!-- Modal Footer -->
        <div class="flex gap-2.5 p-4 border-t-2 border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900">
            <button onclick="closeCustomConfirmModal()" 
                    class="flex-1 px-4 py-2.5 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded-lg hover:bg-slate-200 dark:hover:bg-slate-700 transition-all font-semibold text-sm">
                Cancel
            </button>
            <button id="confirmYesBtn" onclick="executeConfirmAction()" 
                    class="flex-1 px-4 py-2.5 bg-gradient-to-r from-amber-500 to-orange-600 text-white rounded-lg hover:from-amber-600 hover:to-orange-700 shadow-lg shadow-amber-500/30 transition-all font-semibold text-sm flex items-center justify-center gap-2">
                <span class="material-symbols-outlined text-base">check_circle</span>
                Yes
            </button>
        </div>
    </div>
</div>

<!-- Funds Modal -->
<div id="fundsModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 hidden flex items-center justify-center p-2 sm:p-4 animate-fadeIn">
    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-4xl max-h-[95vh] sm:max-h-[90vh] overflow-hidden flex flex-col animate-slideIn">
        <!-- Header -->
        <div class="bg-gradient-to-r from-emerald-600 via-green-600 to-teal-600 text-white px-4 py-3 sm:px-6 sm:py-4">
            <div class="flex justify-between items-center">
                <div class="flex items-center gap-2 sm:gap-3">
                    <div class="bg-white/20 p-2 sm:p-2.5 rounded-xl backdrop-blur-sm hidden sm:flex">
                        <span class="material-symbols-outlined text-2xl sm:text-3xl">account_balance_wallet</span>
                    </div>
                    <div>
                        <h3 class="text-base sm:text-xl font-bold flex items-center gap-2">
                            Funds Management
                        </h3>
                        <p class="text-white/90 text-xs sm:text-sm mt-0.5 hidden sm:block">View and manage fund transactions</p>
                    </div>
                </div>
                <button onclick="closeFundsModal()" class="text-white/90 hover:text-white hover:bg-white/20 p-2 rounded-lg transition-all">
                    <span class="material-symbols-outlined text-xl sm:text-2xl">close</span>
                </button>
            </div>
        </div>
        
        <!-- Tab Buttons -->
        <div class="px-3 pt-3 pb-2 sm:px-6 sm:pt-5 sm:pb-3 bg-slate-50 dark:bg-slate-800/50 border-b border-slate-200 dark:border-slate-700">
            <div class="flex gap-2 sm:gap-3">
                <button onclick="switchFundTab('dashboard')" id="tabDashboard" 
                        class="flex-1 px-3 py-2 sm:px-5 sm:py-3 rounded-xl font-bold text-xs sm:text-sm transition-all duration-200 bg-gradient-to-r from-blue-600 to-indigo-600 text-white shadow-lg shadow-blue-500/30 hover:shadow-xl hover:from-blue-700 hover:to-indigo-700 flex items-center justify-center gap-1.5 sm:gap-2">
                    <span class="material-symbols-outlined text-base sm:text-lg">dashboard</span>
                    <span class="hidden xs:inline">Dashboard </span>Funds
                </button>
                <button onclick="switchFundTab('expense_account')" id="tabExpense" 
                        class="flex-1 px-3 py-2 sm:px-5 sm:py-3 rounded-xl font-bold text-xs sm:text-sm transition-all duration-200 bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-300 border-2 border-slate-200 dark:border-slate-700 hover:border-blue-400 hover:bg-blue-50 dark:hover:bg-slate-700 flex items-center justify-center gap-1.5 sm:gap-2">
                    <span class="material-symbols-outlined text-base sm:text-lg">receipt_long</span>
                    <span class="hidden xs:inline">Expense </span>Funds
                </button>
            </div>
        </div>
        
        <!-- Content Area -->
        <div class="flex-1 overflow-auto p-3 sm:p-6 bg-slate-50 dark:bg-slate-900">
            <div id="fundsLoadingIndicator" class="text-center py-16 hidden transition-opacity duration-300">
                <div class="inline-block">
                    <div class="relative">
                        <div class="w-16 h-16 border-4 border-blue-200 dark:border-blue-900 border-t-blue-600 dark:border-t-blue-400 rounded-full animate-spin"></div>
                    </div>
                    <p class="mt-4 text-slate-600 dark:text-slate-400 font-semibold text-sm">Loading funds data...</p>
                </div>
            </div>
            
            <div id="fundsTableContainer" class="transition-opacity duration-300 ease-in-out">
                <!-- Table will be inserted here -->
            </div>
        </div>
    </div>
</div>

<!-- Proof Preview Modal -->
<div id="proofPreviewModal" class="fixed inset-0 bg-black/70 backdrop-blur-sm z-[60] hidden flex items-center justify-center p-4 animate-fadeIn">
    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-5xl max-h-[90vh] overflow-hidden flex flex-col">
        <div class="bg-gradient-to-r from-blue-600 to-blue-700 text-white px-5 py-4 flex justify-between items-center">
            <h3 class="text-lg font-bold flex items-center gap-2">
                <span class="material-symbols-outlined text-xl">description</span>
                Proof Preview
            </h3>
            <button type="button" onclick="closeProofPreview()" class="text-white hover:bg-white hover:bg-opacity-20 p-2 rounded-lg transition-all">
                <span class="material-symbols-outlined text-xl">close</span>
            </button>
        </div>
        <div class="flex-1 bg-gray-100 p-4 overflow-auto flex items-center justify-center">
            <img id="proofPreviewImage" src="" alt="Proof" class="max-w-full max-h-[75vh] object-contain hidden rounded border bg-white">
            <iframe id="proofPreviewFrame" src="" class="w-full h-[75vh] hidden rounded border bg-white"></iframe>
            <p id="proofPreviewFallback" class="text-sm text-gray-600 hidden">Preview not available for this file.</p>
        </div>
    </div>
</div>

<style>
/* Mobile Sidebar Styles */
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

@keyframes fadeIn {
    from {
        opacity: 0;
        transform: scale(0.95);
    }
    to {
        opacity: 1;
        transform: scale(1);
    }
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
    animation: fadeIn 0.2s ease-out;
}

.animate-slideIn {
    animation: slideIn 0.3s ease-out;
}
</style>

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

    // Update sidebar active state
    function updateSidebarActive(page) {
        var sidebarLinks = document.querySelectorAll('.sidebar-link');
        for (var i = 0; i < sidebarLinks.length; i++) {
            var link = sidebarLinks[i];
            var linkPage = link.getAttribute('data-page');
            if (linkPage === page) {
                link.classList.remove('hover:bg-gray-700');
                link.classList.add('bg-gradient-to-r', 'from-blue-600', 'to-blue-500', 'border-l-4', 'border-blue-300');
            } else {
                link.classList.remove('bg-gradient-to-r', 'from-blue-600', 'to-blue-500', 'border-l-4', 'border-blue-300');
                link.classList.add('hover:bg-gray-700');
            }
        }
    }

    // Mobile Sidebar Toggle Functions
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

    // Toggle System Settings Dropdown
    window.toggleSystemSettings = function() {
        var content = document.getElementById('systemSettingsContent');
        var arrow = document.getElementById('systemSettingsArrow');
        
        if (content.classList.contains('hidden')) {
            content.classList.remove('hidden');
            arrow.classList.add('rotate-180');
        } else {
            content.classList.add('hidden');
            arrow.classList.remove('rotate-180');
        }
    };

    // Toggle Expense Page Visibility
    window.toggleExpensePage = function() {
        fetch('settings.php?action=toggle_expense', {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                var btn = document.getElementById('expenseToggleBtn');
                var text = document.getElementById('expenseToggleText');
                
                var icon = btn.querySelector('.material-symbols-outlined');
                if (data.value === '1') {
                    text.textContent = 'Show Expense Page';
                    if (icon) icon.textContent = 'visibility';
                } else {
                    text.textContent = 'Hide Expense Page';
                    if (icon) icon.textContent = 'visibility_off';
                }
                
                // Show success message
                var msg = document.createElement('div');
                msg.className = 'fixed top-4 right-4 bg-emerald-500 text-white px-6 py-3 rounded-lg shadow-lg z-50 flex items-center gap-2';
                msg.innerHTML = '<span class="material-symbols-outlined text-[20px]">check_circle</span><span>Expense page visibility updated!</span>';
                document.body.appendChild(msg);
                
                setTimeout(function() {
                    msg.style.opacity = '0';
                    msg.style.transform = 'translateY(-10px)';
                    msg.style.transition = 'all 0.3s ease';
                    setTimeout(function() {
                        msg.remove();
                    }, 300);
                }, 2000);
            }
        })
        .catch(function(error) {
            console.error('Error:', error);
        });
    };

    // Clear Logo
    window.clearLogo = function() {
        // Hide the logo preview
        var logoContainer = document.querySelector('.relative.inline-block');
        if (logoContainer) {
            logoContainer.style.display = 'none';
        }
        
        // Clear the existing logo hidden input
        var existingLogoInput = document.querySelector('input[name="existing_logo"]');
        if (existingLogoInput) {
            existingLogoInput.value = '';
        }
    };

    // Toggle Password Visibility
    window.togglePasswordVisibility = function(inputId, iconId) {
        var passwordInput = document.getElementById(inputId);
        var icon = document.getElementById(iconId);
        
        if (passwordInput && icon) {
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.textContent = 'visibility_off';
            } else {
                passwordInput.type = 'password';
                icon.textContent = 'visibility';
            }
        }
    };

    // Modal functions (accessible globally)
    window.openAddUserModal = function() {
        document.getElementById('addUserModal').classList.remove('hidden');
        document.getElementById('userAddForm').reset();
    };

    window.closeAddUserModal = function() {
        document.getElementById('addUserModal').classList.add('hidden');
    };

    window.openEditUserModal = function(user) {
        document.getElementById('edit_id').value = user.id;
        document.getElementById('edit_name').value = user.name;
        document.getElementById('edit_email').value = user.email;
        document.getElementById('edit_role').value = user.role;
        document.getElementById('edit_password').value = user.password;
        document.getElementById('editUserModal').classList.remove('hidden');
    };

    window.closeEditUserModal = function() {
        document.getElementById('editUserModal').classList.add('hidden');
    };

    // Delete confirmation modal
    var deleteUrl = null;

    window.openDeleteConfirmModal = function(url) {
        deleteUrl = url;
        document.getElementById('deleteConfirmModal').classList.remove('hidden');
    };

    window.closeDeleteConfirmModal = function() {
        document.getElementById('deleteConfirmModal').classList.add('hidden');
        deleteUrl = null;
    };

    window.confirmDelete = function() {
        if (deleteUrl) {
            fetch(deleteUrl, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.success && data.html) {
                    closeDeleteConfirmModal();
                    showMiniToast('User deleted successfully.', 'success');
                    document.getElementById('page-content').innerHTML = data.html;
                    initializeAjax();
                }
            })
            .catch(function(error) {
                console.error('Error:', error);
                closeDeleteConfirmModal();
                showMiniToast('Failed to delete user.', 'error');
            });
        }
    };

    // Clear chats modal
    window.openClearChatsModal = function() {
        document.getElementById('clearChatsModal').classList.remove('hidden');
    };

    window.closeClearChatsModal = function() {
        document.getElementById('clearChatsModal').classList.add('hidden');
    };

    window.confirmClearChats = function() {
        fetch('settings.php?action=clearChats', {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success && data.html) {
                closeClearChatsModal();
                showMiniToast('All chats cleared successfully.', 'success');
                document.getElementById('page-content').innerHTML = data.html;
                initializeAjax();
            }
        })
        .catch(function(error) {
            console.error('Error:', error);
            closeClearChatsModal();
            showMiniToast('Failed to clear chats.', 'error');
        });
    };

    // Custom Alert Modal Functions
    window.openCustomAlertModal = function(title, message) {
        document.getElementById('alertTitle').textContent = title;
        document.getElementById('alertMessage').textContent = message;
        var icon = document.getElementById('alertIcon');
        
        // Change icon based on title type
        if (title === 'Error') {
            icon.textContent = 'error';
        } else if (title === 'Success') {
            icon.textContent = 'check_circle';
        } else {
            icon.textContent = 'info';
        }
        
        document.getElementById('customAlertModal').classList.remove('hidden');
    };

    window.closeCustomAlertModal = function() {
        document.getElementById('customAlertModal').classList.add('hidden');
    };

    // Custom Confirm Modal Functions
    var confirmAction = null;

    window.openCustomConfirmModal = function(title, message, callback) {
        document.getElementById('confirmTitle').textContent = title;
        document.getElementById('confirmMessage').textContent = message;
        confirmAction = callback;
        document.getElementById('customConfirmModal').classList.remove('hidden');
    };

    window.closeCustomConfirmModal = function() {
        document.getElementById('customConfirmModal').classList.add('hidden');
        confirmAction = null;
    };

    window.executeConfirmAction = function() {
        if (confirmAction && typeof confirmAction === 'function') {
            confirmAction();
        }
        closeCustomConfirmModal();
    };

    // Funds Modal Functions
    var currentFundType = 'dashboard';

    window.openFundsModal = function() {
        document.getElementById('fundsModal').classList.remove('hidden');
        currentFundType = 'dashboard';
        loadFundsData('dashboard');
    };

    window.closeFundsModal = function() {
        document.getElementById('fundsModal').classList.add('hidden');
    };

    window.openProofPreview = function(filePath) {
        var modal = document.getElementById('proofPreviewModal');
        var img = document.getElementById('proofPreviewImage');
        var frame = document.getElementById('proofPreviewFrame');
        var fallback = document.getElementById('proofPreviewFallback');

        img.classList.add('hidden');
        frame.classList.add('hidden');
        fallback.classList.add('hidden');
        img.src = '';
        frame.src = '';

        var lower = (filePath || '').toLowerCase();
        if (lower.endsWith('.jpg') || lower.endsWith('.jpeg') || lower.endsWith('.png') || lower.endsWith('.gif') || lower.endsWith('.webp')) {
            img.src = filePath;
            img.classList.remove('hidden');
        } else if (lower.endsWith('.pdf')) {
            frame.src = filePath;
            frame.classList.remove('hidden');
        } else {
            fallback.classList.remove('hidden');
        }

        modal.classList.remove('hidden');
    };

    window.closeProofPreview = function() {
        var modal = document.getElementById('proofPreviewModal');
        var img = document.getElementById('proofPreviewImage');
        var frame = document.getElementById('proofPreviewFrame');
        modal.classList.add('hidden');
        img.src = '';
        frame.src = '';
    };

    function executeFundDelete(fundId) {
        fetch('settings.php?action=delete_fund', {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: 'fund_id=' + fundId
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                showMiniToast(data.message || 'Fund deleted successfully.', 'success');
                loadFundsData(currentFundType);
            } else {
                showMiniToast(data.message || 'Failed to delete fund.', 'error');
            }
        })
        .catch(function(error) {
            console.error('Error:', error);
            showMiniToast('Failed to delete fund.', 'error');
        });
    }

    window.deleteFund = function(fundId) {
        openCustomConfirmModal(
            'Delete Fund Entry',
            'Delete this fund transaction? Partner account updates will be reversed automatically as before.',
            function() {
                executeFundDelete(fundId);
            }
        );
    };

    window.switchFundTab = function(type) {
        currentFundType = type;
        
        // Update tab buttons
        var dashboardTab = document.getElementById('tabDashboard');
        var expenseTab = document.getElementById('tabExpense');
        
        if (type === 'dashboard') {
            dashboardTab.classList.remove('bg-white', 'dark:bg-slate-800', 'text-slate-700', 'dark:text-slate-300', 'border-2', 'border-slate-200', 'dark:border-slate-700', 'hover:border-blue-400', 'hover:bg-blue-50', 'dark:hover:bg-slate-700');
            dashboardTab.classList.add('bg-gradient-to-r', 'from-blue-600', 'to-indigo-600', 'text-white', 'shadow-lg', 'shadow-blue-500/30', 'hover:shadow-xl', 'hover:from-blue-700', 'hover:to-indigo-700');
            expenseTab.classList.remove('bg-gradient-to-r', 'from-blue-600', 'to-indigo-600', 'text-white', 'shadow-lg', 'shadow-blue-500/30', 'hover:shadow-xl', 'hover:from-blue-700', 'hover:to-indigo-700');
            expenseTab.classList.add('bg-white', 'dark:bg-slate-800', 'text-slate-700', 'dark:text-slate-300', 'border-2', 'border-slate-200', 'dark:border-slate-700', 'hover:border-blue-400', 'hover:bg-blue-50', 'dark:hover:bg-slate-700');
        } else {
            expenseTab.classList.remove('bg-white', 'dark:bg-slate-800', 'text-slate-700', 'dark:text-slate-300', 'border-2', 'border-slate-200', 'dark:border-slate-700', 'hover:border-blue-400', 'hover:bg-blue-50', 'dark:hover:bg-slate-700');
            expenseTab.classList.add('bg-gradient-to-r', 'from-blue-600', 'to-indigo-600', 'text-white', 'shadow-lg', 'shadow-blue-500/30', 'hover:shadow-xl', 'hover:from-blue-700', 'hover:to-indigo-700');
            dashboardTab.classList.remove('bg-gradient-to-r', 'from-blue-600', 'to-indigo-600', 'text-white', 'shadow-lg', 'shadow-blue-500/30', 'hover:shadow-xl', 'hover:from-blue-700', 'hover:to-indigo-700');
            dashboardTab.classList.add('bg-white', 'dark:bg-slate-800', 'text-slate-700', 'dark:text-slate-300', 'border-2', 'border-slate-200', 'dark:border-slate-700', 'hover:border-blue-400', 'hover:bg-blue-50', 'dark:hover:bg-slate-700');
        }
        
        // Load data for selected tab
        loadFundsData(type);
    };

    function loadFundsData(type) {
        var loadingIndicator = document.getElementById('fundsLoadingIndicator');
        var tableContainer = document.getElementById('fundsTableContainer');
        
        // Start loading immediately
        loadingIndicator.classList.remove('hidden');
        
        // Fade out current content while loading
        tableContainer.style.opacity = '0';
        
        fetch('settings.php?action=get_funds&fund_type=' + type, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            // Small delay to ensure fade out completes
            setTimeout(function() {
                loadingIndicator.classList.add('hidden');
                
                if (data.success) {
                    renderFundsTable(data.funds, type);
                } else {
                    tableContainer.innerHTML = '<div class=\"bg-red-50 dark:bg-red-900/20 border-2 border-red-200 dark:border-red-800 rounded-lg p-6 text-center\"><span class=\"material-symbols-outlined text-red-500 dark:text-red-400 mx-auto block\" style=\"font-size: 3rem;\">error</span><p class=\"text-red-700 dark:text-red-300 font-semibold mt-3\">Error loading funds data</p></div>';
                }
                
                // Fade in new content
                setTimeout(function() {
                    tableContainer.style.opacity = '1';
                }, 50);
            }, 300);
        })
        .catch(function(error) {
            console.error('Error:', error);
            
            // Small delay to ensure fade out completes
            setTimeout(function() {
                loadingIndicator.classList.add('hidden');
                tableContainer.innerHTML = '<div class=\"bg-red-50 dark:bg-red-900/20 border-2 border-red-200 dark:border-red-800 rounded-lg p-6 text-center\"><span class=\"material-symbols-outlined text-red-500 dark:text-red-400 mx-auto block\" style=\"font-size: 3rem;\">error</span><p class=\"text-red-700 dark:text-red-300 font-semibold mt-3\">Error loading funds data</p></div>';
                
                // Fade in error message
                setTimeout(function() {
                    tableContainer.style.opacity = '1';
                }, 50);
            }, 300);
        });
    }

    function renderFundsTable(funds, type) {
        var tableContainer = document.getElementById('fundsTableContainer');

        if (funds.length === 0) {
            tableContainer.innerHTML = '<div class=\"bg-white dark:bg-slate-800 rounded-lg shadow-sm border-2 border-slate-200 dark:border-slate-700 text-center py-16\"><span class=\"material-symbols-outlined text-slate-300 dark:text-slate-600 mx-auto block\" style=\"font-size: 4rem;\">receipt_long</span><p class=\"text-lg font-semibold text-slate-600 dark:text-slate-300 mt-4\">No records found</p><p class=\"text-sm text-slate-500 dark:text-slate-400 mt-1\">There are no fund transactions to display</p></div>';
            return;
        }

        var total = 0;
        var mobileCards = '';
        var tableRows = '';

        funds.forEach(function(fund) {
            total += parseFloat(fund.amount || 0);
            var amtColor = type === 'dashboard'
                ? 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400'
                : 'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400';

            // ── Mobile card ──
            mobileCards += '<div class=\"bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-3 shadow-sm\">';
            mobileCards += '<div class=\"flex items-start justify-between gap-2 mb-2\">';
            mobileCards += '<div>';
            if (type === 'dashboard') {
                mobileCards += '<p class=\"text-xs font-semibold text-slate-800 dark:text-slate-100\">' + escapeHtml(fund.partner_name || fund.partner_name_from_table || 'N/A') + '</p>';
            }
            mobileCards += '<p class=\"text-xs text-slate-500 dark:text-slate-400 mt-0.5\">' + formatDate(fund.transaction_date) + '</p>';
            mobileCards += '</div>';
            mobileCards += '<span class=\"inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold ' + amtColor + '\">PKR ' + formatNumber(fund.amount) + '</span>';
            mobileCards += '</div>';
            if (fund.reference_number) {
                mobileCards += '<p class=\"text-xs text-slate-500 dark:text-slate-400 mb-2\"><span class=\"font-medium\">Ref:</span> ' + escapeHtml(fund.reference_number) + '</p>';
            }
            mobileCards += '<div class=\"flex items-center justify-between gap-2 mt-2 pt-2 border-t border-slate-100 dark:border-slate-700\">';
            if (type === 'dashboard' && fund.proof) {
                var pp = 'uploads/partner_transaction_proofs/' + encodeURIComponent(fund.proof);
                mobileCards += '<button type=\"button\" onclick=\"openProofPreview(\'' + pp + '\')\" class=\"inline-flex items-center gap-1 text-blue-600 dark:text-blue-400 text-xs font-medium\"><span class=\"material-symbols-outlined text-sm\">visibility</span>Proof</button>';
            } else {
                mobileCards += '<span></span>';
            }
            mobileCards += '<button type=\"button\" onclick=\"deleteFund(' + fund.id + ')\" class=\"inline-flex items-center gap-1 px-2.5 py-1.5 bg-red-600 hover:bg-red-700 text-white rounded-md text-xs font-medium transition-colors\"><span class=\"material-symbols-outlined text-sm\">delete</span>Delete</button>';
            mobileCards += '</div></div>';

            // ── Desktop table row ──
            tableRows += '<tr class=\"hover:bg-blue-50 dark:hover:bg-slate-700 transition-colors duration-150\">';
            if (type === 'dashboard') {
                tableRows += '<td class=\"p-4 text-sm text-slate-900 dark:text-slate-100 font-medium\">' + formatDate(fund.transaction_date) + '</td>';
                tableRows += '<td class=\"p-4 text-sm text-slate-700 dark:text-slate-300\">' + escapeHtml(fund.partner_name || fund.partner_name_from_table || 'N/A') + '</td>';
                tableRows += '<td class=\"p-4 text-right\"><span class=\"inline-flex items-center px-3 py-1 rounded-full text-sm font-bold ' + amtColor + '\">PKR ' + formatNumber(fund.amount) + '</span></td>';
                tableRows += '<td class=\"p-4\"><span class=\"inline-block px-2 py-1 text-xs font-mono bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300 rounded\">' + escapeHtml(fund.reference_number || 'N/A') + '</span></td>';
                if (fund.proof) {
                    var proofPath = 'uploads/partner_transaction_proofs/' + encodeURIComponent(fund.proof);
                    tableRows += '<td class=\"p-4 text-center\"><button type=\"button\" onclick=\"openProofPreview(\'' + proofPath + '\')\" class=\"inline-flex items-center gap-1 text-blue-600 dark:text-blue-400 hover:text-blue-800 font-medium text-sm\"><span class=\"material-symbols-outlined text-base\">visibility</span> View</button></td>';
                } else {
                    tableRows += '<td class=\"p-4 text-center\"><span class=\"text-slate-400 text-xs\">—</span></td>';
                }
                tableRows += '<td class=\"p-4 text-xs text-slate-500 dark:text-slate-400\">' + formatDateTime(fund.created_at) + '</td>';
                tableRows += '<td class=\"p-4 text-center\"><button type=\"button\" onclick=\"deleteFund(' + fund.id + ')\" class=\"inline-flex items-center gap-1 px-3 py-1.5 bg-red-600 hover:bg-red-700 text-white rounded-md text-xs font-medium transition-colors shadow-sm\"><span class=\"material-symbols-outlined text-sm\">delete</span></button></td>';
            } else {
                tableRows += '<td class=\"p-4 text-sm text-slate-900 dark:text-slate-100 font-medium\">' + formatDate(fund.transaction_date) + '</td>';
                tableRows += '<td class=\"p-4 text-right\"><span class=\"inline-flex items-center px-3 py-1 rounded-full text-sm font-bold ' + amtColor + '\">PKR ' + formatNumber(fund.amount) + '</span></td>';
                tableRows += '<td class=\"p-4\"><span class=\"inline-block px-2 py-1 text-xs font-mono bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300 rounded\">' + escapeHtml(fund.reference_number || 'N/A') + '</span></td>';
                tableRows += '<td class=\"p-4 text-xs text-slate-500 dark:text-slate-400\">' + formatDateTime(fund.created_at) + '</td>';
                tableRows += '<td class=\"p-4 text-center\"><button type=\"button\" onclick=\"deleteFund(' + fund.id + ')\" class=\"inline-flex items-center gap-1 px-3 py-1.5 bg-red-600 hover:bg-red-700 text-white rounded-md text-xs font-medium transition-colors shadow-sm\"><span class=\"material-symbols-outlined text-sm\">delete</span></button></td>';
            }
            tableRows += '</tr>';
        });

        // Desktop table header
        var thead = '<thead><tr class=\"bg-gradient-to-r from-slate-50 to-slate-100 dark:from-slate-700 dark:to-slate-600 border-b-2 border-slate-200 dark:border-slate-600\">';
        if (type === 'dashboard') {
            thead += '<th class=\"p-4 text-left text-xs font-bold text-slate-700 dark:text-slate-200 uppercase tracking-wider\">Date</th>';
            thead += '<th class=\"p-4 text-left text-xs font-bold text-slate-700 dark:text-slate-200 uppercase tracking-wider\">Partner</th>';
            thead += '<th class=\"p-4 text-right text-xs font-bold text-slate-700 dark:text-slate-200 uppercase tracking-wider\">Amount</th>';
            thead += '<th class=\"p-4 text-left text-xs font-bold text-slate-700 dark:text-slate-200 uppercase tracking-wider\">Reference</th>';
            thead += '<th class=\"p-4 text-center text-xs font-bold text-slate-700 dark:text-slate-200 uppercase tracking-wider\">Proof</th>';
            thead += '<th class=\"p-4 text-left text-xs font-bold text-slate-700 dark:text-slate-200 uppercase tracking-wider\">Created</th>';
            thead += '<th class=\"p-4 text-center text-xs font-bold text-slate-700 dark:text-slate-200 uppercase tracking-wider\">Actions</th>';
        } else {
            thead += '<th class=\"p-4 text-left text-xs font-bold text-slate-700 dark:text-slate-200 uppercase tracking-wider\">Date</th>';
            thead += '<th class=\"p-4 text-right text-xs font-bold text-slate-700 dark:text-slate-200 uppercase tracking-wider\">Amount</th>';
            thead += '<th class=\"p-4 text-left text-xs font-bold text-slate-700 dark:text-slate-200 uppercase tracking-wider\">Reference Number</th>';
            thead += '<th class=\"p-4 text-left text-xs font-bold text-slate-700 dark:text-slate-200 uppercase tracking-wider\">Created</th>';
            thead += '<th class=\"p-4 text-center text-xs font-bold text-slate-700 dark:text-slate-200 uppercase tracking-wider\">Actions</th>';
        }
        thead += '</tr></thead>';

        // Total footer
        var tfoot = '<tr class=\"bg-gradient-to-r from-slate-100 to-slate-50 dark:from-slate-700 dark:to-slate-600 border-t-2 border-slate-200 dark:border-slate-600\">';
        tfoot += (type === 'dashboard')
            ? '<td class=\"p-4 font-bold text-sm text-slate-700 dark:text-slate-200 uppercase\" colspan=\"2\">Total Amount</td>'
            : '<td class=\"p-4 font-bold text-sm text-slate-700 dark:text-slate-200 uppercase\">Total Amount</td>';
        tfoot += '<td class=\"p-4 text-right\"><span class=\"inline-flex items-center px-4 py-2 rounded-lg text-base font-bold bg-gradient-to-r from-green-500 to-green-600 text-white shadow-md\">PKR ' + formatNumber(total) + '</span></td>';
        tfoot += '<td class=\"p-4\" colspan=\"' + (type === 'dashboard' ? '4' : '3') + '\"></td>';
        tfoot += '</tr>';

        var html = '';
        // Mobile cards (visible only on mobile)
        html += '<div class=\"sm:hidden space-y-2\">' + mobileCards;
        html += '<div class=\"flex items-center justify-between bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 px-4 py-3 mt-1\">';
        html += '<span class=\"text-xs font-bold text-slate-600 dark:text-slate-300 uppercase\">Total</span>';
        html += '<span class=\"inline-flex items-center px-3 py-1.5 rounded-lg text-sm font-bold bg-gradient-to-r from-green-500 to-green-600 text-white shadow-md\">PKR ' + formatNumber(total) + '</span>';
        html += '</div></div>';
        // Desktop table (hidden on mobile)
        html += '<div class=\"hidden sm:block bg-white dark:bg-slate-800 rounded-lg shadow-sm border-2 border-slate-200 dark:border-slate-700 overflow-x-auto\">';
        html += '<table class=\"w-full\">' + thead + '<tbody class=\"divide-y divide-slate-100 dark:divide-slate-700\">' + tableRows + tfoot + '</tbody></table>';
        html += '</div>';

        tableContainer.innerHTML = html;
    }

    function formatDate(dateString) {
        if (!dateString) return 'N/A';
        var date = new Date(dateString);
        return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
    }

    function formatDateTime(dateString) {
        if (!dateString) return 'N/A';
        var date = new Date(dateString);
        return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) + ' ' +
               date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
    }

    function formatNumber(num) {
        return parseFloat(num).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
    }

    function showMiniToast(message, type) {
        var toast = document.createElement('div');
        var isError = type === 'error';
        toast.className = 'fixed top-4 right-4 z-[80] px-4 py-2 rounded-lg text-sm font-semibold shadow-lg transition-all duration-300 ' +
            (isError ? 'bg-red-600 text-white' : 'bg-emerald-600 text-white');
        toast.textContent = message;
        document.body.appendChild(toast);

        setTimeout(function() {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(-8px)';
            setTimeout(function() {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            }, 300);
        }, 1800);
    }

    function escapeHtml(text) {
        if (!text) return '';
        var map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.toString().replace(/[&<>"']/g, function(m) { return map[m]; });
    }

    // Close modals on overlay click
    document.addEventListener('click', function(e) {
        if (e.target.id === 'addUserModal') {
            closeAddUserModal();
        }
        if (e.target.id === 'editUserModal') {
            closeEditUserModal();
        }
        if (e.target.id === 'deleteConfirmModal') {
            closeDeleteConfirmModal();
        }
        if (e.target.id === 'clearChatsModal') {
            closeClearChatsModal();
        }
        if (e.target.id === 'customAlertModal') {
            closeCustomAlertModal();
        }
        if (e.target.id === 'customConfirmModal') {
            closeCustomConfirmModal();
        }
        if (e.target.id === 'fundsModal') {
            closeFundsModal();
        }
        if (e.target.id === 'proofPreviewModal') {
            closeProofPreview();
        }
    });

    // Close modals on ESC key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeAddUserModal();
            closeEditUserModal();
            closeDeleteConfirmModal();
            closeClearChatsModal();
            closeCustomAlertModal();
            closeCustomConfirmModal();
            closeFundsModal();
            closeProofPreview();
        }
    });

    // AJAX form submission handler
    function handleFormSubmit(form, callback) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            var formData = new FormData(form);
            var url = form.action || window.location.href;
            
            fetch(url, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.success) {
                    if (callback) callback();
                    // Reload the entire page to show updated content
                    window.location.reload();
                } else if (data.message) {
                    openCustomAlertModal('Error', data.message);
                }
            })
            .catch(function(error) {
                console.error('Error:', error);
                openCustomAlertModal('Error', 'An error occurred. Please try again.');
            });
        });
    }

    // AJAX navigation handler using event delegation
    document.addEventListener('click', function(e) {
        var link = e.target.closest('.ajax-link');
        if (!link) return;
        
        e.preventDefault();
        
        // Check if this is a delete action
        if (link.getAttribute('data-delete-user') === 'true') {
            openDeleteConfirmModal(link.href);
            return;
        }
        
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
        // Handle user add form
        var addForm = document.getElementById('userAddForm');
        if (addForm) {
            handleFormSubmit(addForm, function() {
                closeAddUserModal();
            });
        }

        // Handle user edit form
        var editForm = document.getElementById('userEditForm');
        if (editForm) {
            handleFormSubmit(editForm, function() {
                closeEditUserModal();
            });
        }
        
        // Handle system settings form
        var settingsForm = document.getElementById('systemSettingsForm');
        if (settingsForm) {
            settingsForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                var formData = new FormData(this);
                var submitBtn = this.querySelector('button[type="submit"]');
                var originalBtnText = submitBtn.innerHTML;
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<svg class="animate-spin h-5 w-5 mr-2 inline" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>Saving...';
                
                fetch('settings.php?action=update_settings', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (data.success) {
                        // Show success message
                        var successMsg = document.createElement('div');
                        successMsg.className = 'fixed top-4 right-4 bg-green-500 text-white px-6 py-3 rounded-lg shadow-lg z-50';
                        successMsg.textContent = data.message || 'Settings updated successfully!';
                        document.body.appendChild(successMsg);
                        
                        setTimeout(function() {
                            successMsg.remove();
                            location.reload();
                        }, 2000);
                    } else {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = originalBtnText;
                        openCustomAlertModal('Error', 'Error: ' + (data.message || 'Failed to update settings'));
                    }
                })
                .catch(function(error) {
                    console.error('Error:', error);
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalBtnText;
                    openCustomAlertModal('Error', 'Error updating settings. Please try again.');
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

    // Handle browser back/forward
    window.addEventListener('popstate', function() {
        location.reload();
    });
})();
</script>

<?php
}
?>


