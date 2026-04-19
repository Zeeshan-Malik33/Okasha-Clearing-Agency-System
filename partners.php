<?php
ob_start(); // Start output buffering to prevent any accidental output
require 'config/auth.php';
require 'config/database.php';
require 'config/constants.php';

$systemSettings = getSystemSettings();
$companyDetails = [
    'name' => $systemSettings['system_name'] ?: APP_NAME,
    'location' => $systemSettings['system_location'] ?: '',
    'contact' => $systemSettings['system_contact'] ?: '',
    'email' => $systemSettings['system_email'] ?: '',
    'logo' => !empty($systemSettings['system_logo']) ? BASE_URL . 'uploads/system/' . $systemSettings['system_logo'] : ''
];

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

$isAdmin = ($_SESSION['role'] === 'admin');
$action  = $_GET['action'] ?? 'list';
$id      = $_GET['id'] ?? null;
$isAjax  = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || isset($_GET['ajax']);

// Handle all AJAX requests first, before any HTML output
if ($isAjax) {
    ob_clean(); // Clear any buffered output
    header('Content-Type: application/json');
    
    /* ============================
       GET PARTNER DATA (AJAX)
    ============================ */
    if ($action === 'get' && $id && is_numeric($id)) {
        $stmt = $conn->prepare("SELECT * FROM partners WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $partner = $result->fetch_assoc();
        
        if ($partner) {
            // Keep remaining aligned with formula: remaining = total - paid.
            $totalEarned = (float)($partner['total'] ?? 0);
            $totalPaid = (float)($partner['paid'] ?? 0);
            $totalRemaining = $totalEarned - $totalPaid;

            $syncStmt = $conn->prepare("UPDATE partners SET remaining = ? WHERE id = ?");
            $syncStmt->bind_param("di", $totalRemaining, $id);
            $syncStmt->execute();

            $partner['remaining'] = $totalRemaining;
            echo json_encode(['success' => true, 'data' => $partner]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Partner not found with ID: ' . $id]);
        }
        exit;
    }
    
    /* ============================
       GET PARTNER ACCOUNT (AJAX)
    ============================ */
    if ($action === 'account' && $id) {
        $stmt = $conn->prepare("SELECT * FROM partners WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $partner = $stmt->get_result()->fetch_assoc();
        
        $stmt = $conn->prepare("
            SELECT pp.*, c.container_number, pp.created_at, pp.status
            FROM partner_profits pp
            JOIN containers c ON c.id = pp.container_id
            WHERE pp.partner_id = ?
            ORDER BY pp.created_at DESC
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $profits = $stmt->get_result();
        
        $profitList = [];
        while ($row = $profits->fetch_assoc()) {
            $profitList[] = $row;
        }

        $stmt = $conn->prepare("
            SELECT id, transaction_type, amount, reference_number, description, transaction_date, created_at, proof
            FROM partner_transactions
            WHERE partner_id = ?
            ORDER BY transaction_date DESC, created_at DESC
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $transactions = $stmt->get_result();

        $transactionList = [];
        while ($row = $transactions->fetch_assoc()) {
            $transactionList[] = $row;
        }
        
        // In account popup, always derive remaining from total and paid,
        // then sync the computed value back to the partners table.
        $totalEarned = (float)($partner['total'] ?? 0);
        $totalPaid = (float)($partner['paid'] ?? 0);
        $totalRemaining = $totalEarned - $totalPaid;

        $syncStmt = $conn->prepare("UPDATE partners SET remaining = ? WHERE id = ?");
        $syncStmt->bind_param("di", $totalRemaining, $id);
        $syncStmt->execute();

        // Keep response partner data aligned with the synced database value.
        $partner['remaining'] = $totalRemaining;
        
        echo json_encode([
            'success' => true, 
            'partner' => $partner,
            'profits' => $profitList,
            'transactions' => $transactionList,
            'summary' => [
                'total' => $totalEarned,
                'paid' => $totalPaid,
                'remaining' => $totalRemaining
            ]
        ]);
        exit;
    }

    /* ============================
       ADD PARTNER TRANSACTION (ADMIN ONLY - AJAX)
    ============================ */
    if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add_partner_transaction') {
        $partner_id = (int)($_POST['partner_id'] ?? 0);
        $transaction_type = trim($_POST['transaction_type'] ?? '');
        $amount = floatval($_POST['amount'] ?? 0);
        $reference_number = trim($_POST['reference_number'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $transaction_date = trim($_POST['transaction_date'] ?? date('Y-m-d'));
        $proof_file = null;

        if (!empty($_FILES['proof']) && $_FILES['proof']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = 'uploads/partner_transaction_proofs/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            $file_tmp = $_FILES['proof']['tmp_name'];
            $file_name = $_FILES['proof']['name'];
            $file_size = $_FILES['proof']['size'];

            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];
            $file_type = mime_content_type($file_tmp);
            $max_size = 5 * 1024 * 1024;

            if (in_array($file_type, $allowed_types, true) && $file_size <= $max_size) {
                $file_ext = pathinfo($file_name, PATHINFO_EXTENSION);
                $unique_filename = 'partner_proof_' . $partner_id . '_' . time() . '.' . $file_ext;
                $file_path = $upload_dir . $unique_filename;

                if (move_uploaded_file($file_tmp, $file_path)) {
                    $proof_file = $unique_filename;
                }
            }
        }

        if ($partner_id && $amount > 0 && in_array($transaction_type, ['credit', 'debit'], true)) {
            $stmt = $conn->prepare("
                INSERT INTO partner_transactions
                (partner_id, transaction_type, amount, reference_number, description, transaction_date, proof, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $userId = $_SESSION['user_id'] ?? 0;
            $stmt->bind_param("isdssssi", $partner_id, $transaction_type, $amount, $reference_number, $description, $transaction_date, $proof_file, $userId);

            if ($stmt->execute()) {
                if ($transaction_type === 'credit') {
                    // Payment to partner: increase paid, decrease remaining
                    $upStmt = $conn->prepare("UPDATE partners SET paid = paid + ?, remaining = GREATEST(0, remaining - ?) WHERE id = ?");
                    $upStmt->bind_param("ddi", $amount, $amount, $partner_id);
                    $upStmt->execute();
                } else {
                    // Charge for partner: increase total and increase remaining
                    $upStmt = $conn->prepare("UPDATE partners SET total = total + ?, remaining = remaining + ? WHERE id = ?");
                    $upStmt->bind_param("ddi", $amount, $amount, $partner_id);
                    $upStmt->execute();
                }

                echo json_encode(['success' => true, 'message' => 'Transaction recorded successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to add transaction']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid transaction data. Amount must be positive and type must be credit or debit.']);
        }
        exit;
    }

    /* ============================
       DELETE PROFIT RECORD (ADMIN ONLY - AJAX)
    ============================ */
    if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_profit') {
        $profit_id = (int)($_POST['profit_id'] ?? 0);
        $partner_id = (int)($_POST['partner_id'] ?? 0);

        if ($profit_id > 0 && $partner_id > 0) {
            // Fetch the profit record
            $stmt = $conn->prepare("SELECT * FROM partner_profits WHERE id = ? AND partner_id = ?");
            $stmt->bind_param("ii", $profit_id, $partner_id);
            $stmt->execute();
            $profit = $stmt->get_result()->fetch_assoc();

            if ($profit) {
                $profitAmount = (float)$profit['profit'];
                $invoiceNumber = $profit['invoice_number'] ?? '';

                // Find and delete the associated transaction (created during profit distribution)
                if (!empty($invoiceNumber)) {
                    $stmt = $conn->prepare("SELECT * FROM partner_transactions WHERE partner_id = ? AND reference_number = ?");
                    $stmt->bind_param("is", $partner_id, $invoiceNumber);
                    $stmt->execute();
                    $transaction = $stmt->get_result()->fetch_assoc();

                    if ($transaction) {
                        // Delete the transaction record
                        $stmt = $conn->prepare("DELETE FROM partner_transactions WHERE id = ?");
                        $stmt->bind_param("i", $transaction['id']);
                        $stmt->execute();
                    }
                }

                // Decrease total and remaining by the profit amount (paid stays unchanged)
                $stmt = $conn->prepare("UPDATE partners SET total = GREATEST(0, total - ?), remaining = GREATEST(0, remaining - ?) WHERE id = ?");
                $stmt->bind_param("ddi", $profitAmount, $profitAmount, $partner_id);
                $stmt->execute();

                $stmt = $conn->prepare("DELETE FROM partner_profits WHERE id = ?");
                $stmt->bind_param("i", $profit_id);
                $stmt->execute();

                echo json_encode(['success' => true, 'message' => 'Profit record and associated transaction deleted successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Profit record not found']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid request']);
        }
        exit;
    }

    /* ============================
       DELETE PARTNER TRANSACTION (ADMIN ONLY - AJAX)
    ============================ */
    if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_partner_transaction') {
        $tx_id     = (int)($_POST['tx_id'] ?? 0);
        $partner_id = (int)($_POST['partner_id'] ?? 0);

        if ($tx_id > 0 && $partner_id > 0) {
            // Fetch the transaction so we can reverse its balance effect
            $stmt = $conn->prepare("SELECT * FROM partner_transactions WHERE id = ? AND partner_id = ?");
            $stmt->bind_param("ii", $tx_id, $partner_id);
            $stmt->execute();
            $tx = $stmt->get_result()->fetch_assoc();

            if ($tx) {
                $txAmount = (float)$tx['amount'];

                // Reverse the balance effect
                if ($tx['transaction_type'] === 'credit') {
                    // Was: paid += amount, remaining -= amount  →  reverse: paid -= amount, remaining += amount
                    $upStmt = $conn->prepare("UPDATE partners SET paid = GREATEST(0, paid - ?), remaining = remaining + ? WHERE id = ?");
                    $upStmt->bind_param("ddi", $txAmount, $txAmount, $partner_id);
                    $upStmt->execute();
                } else {
                    // Was: total += amount, remaining += amount  →  reverse both
                    $upStmt = $conn->prepare("UPDATE partners SET total = GREATEST(0, total - ?), remaining = GREATEST(0, remaining - ?) WHERE id = ?");
                    $upStmt->bind_param("ddi", $txAmount, $txAmount, $partner_id);
                    $upStmt->execute();
                }

                // Delete proof file if present
                if (!empty($tx['proof'])) {
                    $proofPath = __DIR__ . '/uploads/partner_transaction_proofs/' . $tx['proof'];
                    if (file_exists($proofPath)) {
                        unlink($proofPath);
                    }
                }

                // Delete the transaction record
                $stmt = $conn->prepare("DELETE FROM partner_transactions WHERE id = ?");
                $stmt->bind_param("i", $tx_id);
                $stmt->execute();

                echo json_encode(['success' => true, 'message' => 'Transaction deleted and balance reversed']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Transaction not found']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid request']);
        }
        exit;
    }
    
    /* ============================
       ADD PARTNER (ADMIN ONLY - AJAX)
    ============================ */
    if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add') {
        $name    = trim($_POST['name']);
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $contact = trim($_POST['contact'] ?? '');
        $share_percentage = floatval($_POST['share_percentage'] ?? 0);

        if ($name !== '') {
            $newPartnerId = getNextReusableId($conn, 'partners');
            $stmt = $conn->prepare("
                INSERT INTO partners (id, name, phone, address, contact, share_percentage)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("issssd", $newPartnerId, $name, $phone, $address, $contact, $share_percentage);
            $stmt->execute();
        }
        
        echo json_encode(['success' => true, 'message' => 'Partner added successfully']);
        exit;
    }
    
    /* ============================
       EDIT PARTNER (ADMIN ONLY - AJAX)
    ============================ */
    if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'edit' && $id) {
        $name    = trim($_POST['name']);
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $contact = trim($_POST['contact'] ?? '');
        $share_percentage = floatval($_POST['share_percentage'] ?? 0);

        if ($name !== '') {
            $stmt = $conn->prepare("
                UPDATE partners 
                SET name = ?, phone = ?, address = ?, contact = ?, share_percentage = ?
                WHERE id = ?
            ");
            $stmt->bind_param("ssssdi", $name, $phone, $address, $contact, $share_percentage, $id);
            $stmt->execute();
        }
        
        echo json_encode(['success' => true, 'message' => 'Partner updated successfully']);
        exit;
    }
    
    /* ============================
       DELETE PARTNER (ADMIN ONLY - AJAX)
    ============================ */
    if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete' && $id) {
        $stmt = $conn->prepare("DELETE FROM partners WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        
        echo json_encode(['success' => true, 'message' => 'Partner deleted successfully']);
        exit;
    }
    
    /* ============================
       DISTRIBUTE PROFIT (ADMIN - AJAX)
    ============================ */
    if ($isAdmin && $action === 'distribute' && $id) {
        // Check if already distributed
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM partner_profits WHERE container_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $check = $stmt->get_result()->fetch_assoc();
        
        if ($check['count'] > 0) {
            echo json_encode(['success' => false, 'message' => 'Profit already distributed for this container']);
            exit;
        }
        
        // Get container with its rate
        $stmt = $conn->prepare("
            SELECT c.*
            FROM containers c
            WHERE c.id = ?
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $container = $stmt->get_result()->fetch_assoc();

        // Total expenses
        $stmt = $conn->prepare("
            SELECT SUM(amount) AS total FROM container_expenses
            WHERE container_id = ?
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $exp = $stmt->get_result()->fetch_assoc();

        $totalExpenses = $exp['total'] ?? 0;
        $finalAmount   = ($container['gross_weight'] ?? 0) * ($container['rate'] ?? 0);
        $profit        = $finalAmount - $totalExpenses;

        // Get all partners with their share percentages
        $stmt = $conn->prepare("SELECT * FROM partners");
        $stmt->execute();
        $partners = $stmt->get_result();
        $count    = $partners->num_rows;

        if ($count > 0 && $profit > 0) {
            $invoiceNo = 'INV-' . str_pad($id, 5, '0', STR_PAD_LEFT);
            $today = date('Y-m-d');

            while ($p = $partners->fetch_assoc()) {
                // Calculate each partner's share based on their percentage
                $partnerShare = ($profit * $p['share_percentage']) / 100;
                
                $stmt = $conn->prepare("
                    INSERT INTO partner_profits
                    (partner_id, container_id, invoice_number, profit, created_at, status)
                    VALUES (?, ?, ?, ?, ?, 'pending')
                ");
                $stmt->bind_param(
                    "iisds",
                    $p['id'],
                    $id,
                    $invoiceNo,
                    $partnerShare,
                    $today
                );
                $stmt->execute();
                
                // Update partner's total, remaining columns
                $newTotal = ($p['total'] ?? 0) + $partnerShare;
                $newRemaining = ($p['remaining'] ?? 0) + $partnerShare;
                
                $stmt = $conn->prepare("
                    UPDATE partners 
                    SET total = ?, remaining = ?
                    WHERE id = ?
                ");
                $stmt->bind_param("ddi", $newTotal, $newRemaining, $p['id']);
                $stmt->execute();
            }
        }
        
        echo json_encode(['success' => true, 'message' => 'Profit distributed successfully']);
        exit;
    }

    /* ============================
       GET UN-DISTRIBUTED CONTAINERS (ADMIN - AJAX)
    ============================ */
    if ($isAdmin && $action === 'undistributed_containers') {
        $stmt = $conn->prepare("
            SELECT c.id, c.container_number, c.created_at, cu.name as customer_name
            FROM containers c
            LEFT JOIN partner_profits pp ON pp.container_id = c.id
            LEFT JOIN customers cu ON c.customer_id = cu.id
            WHERE pp.id IS NULL
            ORDER BY c.container_number
        ");
        $stmt->execute();
        $result = $stmt->get_result();

        $containers = [];
        while ($row = $result->fetch_assoc()) {
            $containers[] = $row;
        }

        echo json_encode(['success' => true, 'containers' => $containers]);
        exit;
    }

    /* ============================
       GET CONTAINER PROFIT (ADMIN - AJAX)
    ============================ */
    if ($isAdmin && $action === 'container_profit' && $id) {
        $stmt = $conn->prepare("
            SELECT c.id, c.container_number, c.total_amount
            FROM containers c
            WHERE c.id = ?
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $container = $stmt->get_result()->fetch_assoc();

        if (!$container) {
            echo json_encode(['success' => false, 'message' => 'Container not found']);
            exit;
        }

        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(amount), 0) AS total
            FROM container_expenses
            WHERE container_id = ?
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $exp = $stmt->get_result()->fetch_assoc();

        $invoiceTotal = (float)($container['total_amount'] ?? 0);
        $totalExpenses = (float)($exp['total'] ?? 0);
        $profit = $invoiceTotal - $totalExpenses;

        echo json_encode([
            'success' => true,
            'container' => [
                'id' => $container['id'],
                'container_number' => $container['container_number'],
                'invoice_total' => $invoiceTotal,
                'expense_total' => $totalExpenses,
                'profit' => $profit
            ]
        ]);
        exit;
    }

    /* ============================
       DISTRIBUTE PROFIT (ADMIN - AJAX, MODAL)
    ============================ */
    if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'distribute_profit') {
        $containerId = (int)($_POST['container_id'] ?? 0);
        if ($containerId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid container']);
            exit;
        }

        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM partner_profits WHERE container_id = ?");
        $stmt->bind_param("i", $containerId);
        $stmt->execute();
        $check = $stmt->get_result()->fetch_assoc();
        if (($check['count'] ?? 0) > 0) {
            echo json_encode(['success' => false, 'message' => 'Profit already distributed for this container']);
            exit;
        }

        $stmt = $conn->prepare("
            SELECT c.id, c.total_amount
            FROM containers c
            WHERE c.id = ?
        ");
        $stmt->bind_param("i", $containerId);
        $stmt->execute();
        $container = $stmt->get_result()->fetch_assoc();

        if (!$container) {
            echo json_encode(['success' => false, 'message' => 'Container not found']);
            exit;
        }

        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(amount), 0) AS total
            FROM container_expenses
            WHERE container_id = ?
        ");
        $stmt->bind_param("i", $containerId);
        $stmt->execute();
        $exp = $stmt->get_result()->fetch_assoc();

        $invoiceTotal = (float)($container['total_amount'] ?? 0);
        $totalExpenses = (float)($exp['total'] ?? 0);
        $profit = $invoiceTotal - $totalExpenses;

        $stmt = $conn->prepare("SELECT * FROM partners");
        $stmt->execute();
        $partners = $stmt->get_result();
        if ($partners->num_rows === 0) {
            echo json_encode(['success' => false, 'message' => 'No partners found to distribute profit']);
            exit;
        }
        if ($invoiceTotal <= 0) {
            echo json_encode(['success' => false, 'message' => 'Container has no invoice total to distribute']);
            exit;
        }

        $invoiceNo = 'INV-' . str_pad($containerId, 5, '0', STR_PAD_LEFT);
        $today = date('Y-m-d');
        $userId = $_SESSION['user_id'] ?? 0;

        while ($p = $partners->fetch_assoc()) {
            $partnerShare = ($profit * ($p['share_percentage'] ?? 0)) / 100;

            $stmt = $conn->prepare("
                INSERT INTO partner_profits
                (partner_id, container_id, invoice_number, profit, created_at, status)
                VALUES (?, ?, ?, ?, ?, 'completed')
            ");
            $stmt->bind_param(
                "iisds",
                $p['id'],
                $containerId,
                $invoiceNo,
                $partnerShare,
                $today
            );
            $stmt->execute();

            $stmt = $conn->prepare("
                INSERT INTO partner_transactions
                (partner_id, transaction_type, amount, reference_number, description, transaction_date, proof, created_by)
                VALUES (?, 'debit', ?, ?, ?, ?, NULL, ?)
            ");
            $description = 'Profit distribution for ' . $invoiceNo;
            $stmt->bind_param(
                "idsssi",
                $p['id'],
                $partnerShare,
                $invoiceNo,
                $description,
                $today,
                $userId
            );
            $stmt->execute();

            $newTotal = ($p['total'] ?? 0) + $partnerShare;
            $newRemaining = ($p['remaining'] ?? 0) + $partnerShare;

            $stmt = $conn->prepare("
                UPDATE partners
                SET total = ?, remaining = ?
                WHERE id = ?
            ");
            $stmt->bind_param("ddi", $newTotal, $newRemaining, $p['id']);
            $stmt->execute();
        }

        echo json_encode(['success' => true, 'message' => 'Profit distributed successfully']);
        exit;
    }
    
    /* ============================
       MARK PROFIT AS PAID (AJAX)
    ============================ */
    if ($isAdmin && $action === 'complete' && $id) {
        // Get all partner profits for this container
        $stmt = $conn->prepare("
            SELECT partner_id, profit 
            FROM partner_profits 
            WHERE container_id = ? AND status = 'pending'
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $profits = $stmt->get_result();
        
        // Update each partner's paid and remaining
        while ($profit = $profits->fetch_assoc()) {
            // Get current partner data
            $stmt2 = $conn->prepare("SELECT paid, remaining FROM partners WHERE id = ?");
            $stmt2->bind_param("i", $profit['partner_id']);
            $stmt2->execute();
            $partner = $stmt2->get_result()->fetch_assoc();
            
            $newPaid = ($partner['paid'] ?? 0) + $profit['profit'];
            $newRemaining = ($partner['remaining'] ?? 0) - $profit['profit'];
            
            // Update partner
            $stmt2 = $conn->prepare("
                UPDATE partners 
                SET paid = ?, remaining = ?
                WHERE id = ?
            ");
            $stmt2->bind_param("ddi", $newPaid, $newRemaining, $profit['partner_id']);
            $stmt2->execute();
        }
        
        // Mark profits as completed
        $stmt = $conn->prepare("
            UPDATE partner_profits
            SET status='completed'
            WHERE container_id = ?
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        
        echo json_encode(['success' => true, 'message' => 'Profits marked as paid']);
        exit;
    }
    
    /* ============================
       MARK PARTNER PENDING PROFITS AS COMPLETED (ADMIN - AJAX)
       NOTE: Only called explicitly by admin, NOT triggered automatically on modal open.
    ============================ */
    if ($isAdmin && $action === 'mark_partner_profits_completed' && $id) {
        // Mark all pending profits for this partner as completed
        $stmt = $conn->prepare("
            UPDATE partner_profits
            SET status='completed'
            WHERE partner_id = ? AND status='pending'
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        
        // Update partner's paid and remaining amounts
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(profit), 0) as total_pending
            FROM partner_profits
            WHERE partner_id = ? AND status='completed'
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $totalCompleted = $result['total_pending'] ?? 0;
        
        $stmt = $conn->prepare("
            SELECT paid, remaining FROM partners WHERE id = ?
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $partner = $stmt->get_result()->fetch_assoc();
        
        $newPaid = $totalCompleted;
        $newRemaining = ($partner['total'] ?? 0) - $totalCompleted;
        
        $stmt = $conn->prepare("
            UPDATE partners
            SET paid = ?, remaining = ?
            WHERE id = ?
        ");
        $stmt->bind_param("ddi", $newPaid, $newRemaining, $id);
        $stmt->execute();
        
        echo json_encode(['success' => true, 'message' => 'Pending profits marked as completed']);
        exit;
    }
    
    /* ============================
       TEST PARTNERS (ADMIN - AJAX)
    ============================ */
    if ($isAdmin && $action === 'test') {
        $result = $conn->query("SELECT id, name, phone FROM partners LIMIT 10");
        
        if (!$result) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
            exit;
        }
        
        $partners = [];
        while ($row = $result->fetch_assoc()) {
            $partners[] = $row;
        }
        
        echo json_encode([
            'success' => true, 
            'total' => count($partners),
            'partners' => $partners
        ]);
        exit;
    }
    
    /* ============================
       FIX TABLE STRUCTURE (ADMIN - AJAX)
    ============================ */
    if ($isAdmin && $action === 'fix_structure') {
        $steps = [];
        
        // Check current table structure
        $result = $conn->query("SHOW CREATE TABLE partners");
        $row = $result->fetch_assoc();
        $createTable = $row['Create Table'];
        
        $hasAutoIncrement = strpos($createTable, 'AUTO_INCREMENT') !== false;
        $steps[] = "Has AUTO_INCREMENT: " . ($hasAutoIncrement ? "YES" : "NO");
        
        // Check current partner records
        $result = $conn->query("SELECT id, name FROM partners");
        $currentRecords = [];
        while ($p = $result->fetch_assoc()) {
            $currentRecords[] = "ID: {$p['id']} | Name: {$p['name']}";
        }
        $steps[] = "Current records: " . count($currentRecords);
        
        // Fix the table structure if needed
        $fixed = false;
        if (!$hasAutoIncrement) {
            // Assign temporary unique IDs to duplicate records
            $result = $conn->query("SELECT * FROM partners WHERE id = 0");
            $tempId = 1;
            while ($p = $result->fetch_assoc()) {
                $conn->query("UPDATE partners SET id = $tempId WHERE name = '{$p['name']}' AND phone = '{$p['phone']}' AND id = 0 LIMIT 1");
                $tempId++;
            }
            
            // Add PRIMARY KEY and AUTO_INCREMENT
            $conn->query("ALTER TABLE partners MODIFY COLUMN id INT NOT NULL AUTO_INCREMENT, ADD PRIMARY KEY (id)");
            $steps[] = "Table structure fixed: Added PRIMARY KEY and AUTO_INCREMENT";
            $fixed = true;
        } else {
            $steps[] = "Table structure is already correct";
        }
        
        // Verify the fix
        $result = $conn->query("SELECT id, name, phone FROM partners");
        $verifiedRecords = [];
        while ($p = $result->fetch_assoc()) {
            $verifiedRecords[] = "ID: {$p['id']} | Name: {$p['name']} | Phone: {$p['phone']}";
        }
        
        echo json_encode([
            'success' => true,
            'fixed' => $fixed,
            'steps' => $steps,
            'before' => $currentRecords,
            'after' => $verifiedRecords
        ]);
        exit;
    }
    
    // If no AJAX action matched, return error
    echo json_encode([
        'success' => false, 
        'message' => 'Invalid AJAX request - No matching handler found'
    ]);
    exit;
}

// Non-AJAX page requests
/* ============================
   TEST PARTNERS (ADMIN ONLY)
============================ */
if ($isAdmin && $action === 'test') {
    ob_clean();
    header('Content-Type: text/plain');
    
    echo "=== Testing Partners Database ===\n\n";
    
    $result = $conn->query("SELECT id, name, phone FROM partners LIMIT 10");
    
    if (!$result) {
        echo "ERROR: " . $conn->error . "\n";
        exit(1);
    }
    
    echo "Total Partners found: " . $result->num_rows . "\n\n";
    
    if ($result->num_rows === 0) {
        echo "No partners in database!\n";
    } else {
        while ($row = $result->fetch_assoc()) {
            printf("ID: %d | Name: %-20s | Phone: %s\n", 
                $row['id'], 
                $row['name'], 
                $row['phone'] ?? 'N/A'
            );
        }
    }
    
    echo "\n=== Test Complete ===\n";
    exit(0);
}

/* ============================
   FIX TABLE STRUCTURE (ADMIN ONLY)
============================ */
if ($isAdmin && $action === 'fix_structure') {
    ob_clean();
    header('Content-Type: text/plain');
    
    echo "=== FIXING PARTNERS TABLE IDs ===\n\n";
    
    // Step 1: Check current table structure
    echo "Step 1: Checking current table structure...\n";
    $result = $conn->query("SHOW CREATE TABLE partners");
    $row = $result->fetch_assoc();
    echo $row['Create Table'] . "\n\n";
    
    // Step 2: Check if id column has AUTO_INCREMENT
    $hasAutoIncrement = strpos($row['Create Table'], 'AUTO_INCREMENT') !== false;
    echo "Has AUTO_INCREMENT: " . ($hasAutoIncrement ? "YES" : "NO") . "\n\n";
    
    // Step 3: Check current partner records
    echo "Step 3: Current partner records:\n";
    $result = $conn->query("SELECT id, name FROM partners");
    while ($p = $result->fetch_assoc()) {
        echo "  ID: {$p['id']} | Name: {$p['name']}\n";
    }
    echo "\n";
    
    // Step 4: Fix the table structure if needed
    if (!$hasAutoIncrement) {
        echo "Step 4: Fixing table structure...\n";
        
        // First, give temporary unique IDs to records with id=0
        echo "  - Assigning temporary unique IDs to duplicate records...\n";
        $result = $conn->query("SELECT * FROM partners WHERE id = 0");
        $tempId = 1;
        while ($p = $result->fetch_assoc()) {
            $conn->query("UPDATE partners SET id = $tempId WHERE name = '{$p['name']}' AND phone = '{$p['phone']}' AND id = 0 LIMIT 1");
            $tempId++;
        }
        
        // Now add PRIMARY KEY and AUTO_INCREMENT
        echo "  - Adding PRIMARY KEY and AUTO_INCREMENT...\n";
        $conn->query("ALTER TABLE partners MODIFY COLUMN id INT NOT NULL AUTO_INCREMENT, ADD PRIMARY KEY (id)");
        
        echo "Structure fixed successfully!\n\n";
    } else {
        echo "Step 4: Table structure is already correct.\n\n";
    }
    
    // Step 5: Verify the fix
    echo "Step 5: Verification - Current partners:\n";
    $result = $conn->query("SELECT id, name, phone FROM partners");
    while ($p = $result->fetch_assoc()) {
        echo "  ID: {$p['id']} | Name: {$p['name']} | Phone: {$p['phone']}\n";
    }
    
    echo "\n=== FIX COMPLETED ===\n";
    exit(0);
}

/* ============================
   ADD PARTNER (ADMIN ONLY)
============================ */
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add') {
    $name    = trim($_POST['name']);
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $contact = trim($_POST['contact'] ?? '');
    $share_percentage = floatval($_POST['share_percentage'] ?? 0);

    if ($name !== '') {
        $newPartnerId = getNextReusableId($conn, 'partners');
        $stmt = $conn->prepare("
            INSERT INTO partners (id, name, phone, address, contact, share_percentage)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("issssd", $newPartnerId, $name, $phone, $address, $contact, $share_percentage);
        $stmt->execute();
    }
    
    header("Location: partners.php");
    exit;
}

/* ============================
   EDIT PARTNER (ADMIN ONLY)
============================ */
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'edit' && $id) {
    $name    = trim($_POST['name']);
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $contact = trim($_POST['contact'] ?? '');
    $share_percentage = floatval($_POST['share_percentage'] ?? 0);

    if ($name !== '') {
        $stmt = $conn->prepare("
            UPDATE partners 
            SET name = ?, phone = ?, address = ?, contact = ?, share_percentage = ?
            WHERE id = ?
        ");
        $stmt->bind_param("ssssdi", $name, $phone, $address, $contact, $share_percentage, $id);
        $stmt->execute();
    }
    
    header("Location: partners.php");
    exit;
}

/* ============================
   DELETE PARTNER (ADMIN ONLY)
============================ */
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete' && $id) {
    $stmt = $conn->prepare("DELETE FROM partners WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    
    header("Location: partners.php");
    exit;
}

/* ============================
   DISTRIBUTE PROFIT (ADMIN)
============================ */
if ($isAdmin && $action === 'distribute' && $id) {
    // Check if already distributed
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM partner_profits WHERE container_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $check = $stmt->get_result()->fetch_assoc();
    
    if ($check['count'] > 0) {
        header("Location: partners.php?error=already_distributed");
        exit;
    }
    
    // Get container with its rate
    $stmt = $conn->prepare("
        SELECT c.*
        FROM containers c
        WHERE c.id = ?
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $container = $stmt->get_result()->fetch_assoc();

    // Total expenses
    $stmt = $conn->prepare("
        SELECT SUM(amount) AS total FROM container_expenses
        WHERE container_id = ?
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $exp = $stmt->get_result()->fetch_assoc();

    $totalExpenses = $exp['total'] ?? 0;
    $finalAmount   = ($container['gross_weight'] ?? 0) * ($container['rate'] ?? 0);
    $profit        = $finalAmount - $totalExpenses;

    // Get all partners with their share percentages
    $stmt = $conn->prepare("SELECT * FROM partners");
    $stmt->execute();
    $partners = $stmt->get_result();
    $count    = $partners->num_rows;

    if ($count > 0 && $profit > 0) {
        $invoiceNo = 'INV-' . str_pad($id, 5, '0', STR_PAD_LEFT);
        $today = date('Y-m-d');

        while ($p = $partners->fetch_assoc()) {
            // Calculate each partner's share based on their percentage
            $partnerShare = ($profit * $p['share_percentage']) / 100;
            
            $stmt = $conn->prepare("
                INSERT INTO partner_profits
                (partner_id, container_id, invoice_number, profit, created_at, status)
                VALUES (?, ?, ?, ?, ?, 'pending')
            ");
            $stmt->bind_param(
                "iisds",
                $p['id'],
                $id,
                $invoiceNo,
                $partnerShare,
                $today
            );
            $stmt->execute();
            
            // Update partner's total, remaining columns
            $newTotal = ($p['total'] ?? 0) + $partnerShare;
            $newRemaining = ($p['remaining'] ?? 0) + $partnerShare;
            
            $stmt = $conn->prepare("
                UPDATE partners 
                SET total = ?, remaining = ?
                WHERE id = ?
            ");
            $stmt->bind_param("ddi", $newTotal, $newRemaining, $p['id']);
            $stmt->execute();
        }
    }
    
    header("Location: partners.php");
    exit;
}

/* ============================
   MARK PROFIT AS PAID
============================ */
if ($isAdmin && $action === 'complete' && $id) {
    // Get all partner profits for this container
    $stmt = $conn->prepare("
        SELECT partner_id, profit 
        FROM partner_profits 
        WHERE container_id = ? AND status = 'pending'
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $profits = $stmt->get_result();
    
    // Update each partner's paid and remaining
    while ($profit = $profits->fetch_assoc()) {
        // Get current partner data
        $stmt2 = $conn->prepare("SELECT paid, remaining FROM partners WHERE id = ?");
        $stmt2->bind_param("i", $profit['partner_id']);
        $stmt2->execute();
        $partner = $stmt2->get_result()->fetch_assoc();
        
        $newPaid = ($partner['paid'] ?? 0) + $profit['profit'];
        $newRemaining = ($partner['remaining'] ?? 0) - $profit['profit'];
        
        // Update partner
        $stmt2 = $conn->prepare("
            UPDATE partners 
            SET paid = ?, remaining = ?
            WHERE id = ?
        ");
        $stmt2->bind_param("ddi", $newPaid, $newRemaining, $profit['partner_id']);
        $stmt2->execute();
    }
    
    // Mark profits as completed
    $stmt = $conn->prepare("
        UPDATE partner_profits
        SET status='completed'
        WHERE container_id = ?
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    
    header("Location: partners.php");
    exit;
}

include 'include/header.php';
include 'include/sidebar.php';
?>
<div id="mobileSidebarOverlay" class="md:hidden" onclick="closeMobileSidebar()"></div>

<div id="page-content" class="flex-1 flex flex-col overflow-y-auto">

<!-- Header - Responsive -->
<header class="sticky top-0 z-40 w-full bg-white/80 dark:bg-slate-900/80 backdrop-blur-md border-b border-slate-200 dark:border-slate-800">
  <div class="flex items-center justify-between px-4 md:px-8 h-16">
    <div class="flex items-center gap-3 md:gap-6">
        <!-- Mobile Menu Button -->
        <button type="button" onclick="toggleMobileSidebar()" class="md:hidden h-11 w-11 rounded-lg flex items-center justify-center hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors" aria-label="Open navigation menu">
            <span class="material-symbols-outlined text-slate-700 dark:text-slate-300">menu</span>
        </button>
        
        <h2 class="text-lg md:text-xl font-bold text-slate-900 dark:text-slate-100">Partners Management</h2>
    </div>
    <div class="flex items-center gap-2 md:gap-3">
        <?php if ($isAdmin): ?>
        <button onclick="openDistributeProfitModal()" class="flex items-center gap-2 px-3 md:px-4 py-2 bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 dark:text-emerald-400 border border-emerald-100 dark:border-emerald-800 rounded-lg text-sm font-bold hover:bg-emerald-100 transition-colors">
            <span class="material-symbols-outlined text-[18px]">payments</span>
            <span class="hidden sm:inline">Distribute Profit</span>
        </button>
        <?php endif; ?>
    </div>
  </div>
</header>

<!-- Page Body - Responsive Container -->
<div class="p-4 md:p-6 lg:p-8 space-y-6 md:space-y-8 pb-20 md:pb-8">

<!-- Statistics Cards - Responsive -->
<section class="space-y-4">
  <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400 px-1">Overview</h2>
  <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <!-- Total Partners with Share Info -->
    <div class="bg-white dark:bg-slate-900 rounded-xl p-6 border border-slate-200 dark:border-slate-800 shadow-sm flex items-center justify-between group hover:border-primary/30 transition-all">
        <div class="space-y-1">
            <p class="text-sm font-medium text-slate-500 dark:text-slate-400">Total Partners</p>
            <h3 class="text-3xl font-bold text-slate-900 dark:text-slate-100">
                <?php
                $stmt = $conn->prepare("SELECT COUNT(*) as count FROM partners");
                $stmt->execute();
                $partnerCount = $stmt->get_result()->fetch_assoc();
                echo $partnerCount['count'];
                ?>
            </h3>
        </div>
        <div class="size-14 bg-primary/10 rounded-xl flex items-center justify-center text-primary group-hover:scale-110 transition-transform">
            <span class="material-symbols-outlined text-3xl" style="font-variation-settings: 'FILL' 1;">groups</span>
        </div>
    </div>
    
    <!-- Distributed Containers -->
    <div class="bg-white dark:bg-slate-900 rounded-xl p-6 border border-slate-200 dark:border-slate-800 shadow-sm flex items-center justify-between group hover:border-primary/30 transition-all">
        <div class="space-y-1">
            <p class="text-sm font-medium text-slate-500 dark:text-slate-400">Distributed Containers</p>
            <h3 class="text-3xl font-bold text-slate-900 dark:text-slate-100">
                <?php
                // Count containers with partner_profits (distributed)
                $stmt = $conn->prepare("
                    SELECT COUNT(DISTINCT pp.container_id) as count
                    FROM partner_profits pp
                ");
                $stmt->execute();
                $distributedCount = $stmt->get_result()->fetch_assoc();
                echo number_format($distributedCount['count']);
                ?>
            </h3>
        </div>
        <div class="size-14 bg-blue-100 dark:bg-blue-900/30 rounded-xl flex items-center justify-center text-blue-600 dark:text-blue-400 group-hover:scale-110 transition-transform">
            <span class="material-symbols-outlined text-3xl" style="font-variation-settings: 'FILL' 1;">inventory</span>
        </div>
    </div>
  </div>
</section>

<!-- Partners List Section - Responsive -->
<section class="space-y-4">
<div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 shadow-sm overflow-hidden">
    <div class="px-4 md:px-6 py-4 md:py-5 border-b border-slate-200 dark:border-slate-800 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 sm:gap-0 bg-slate-50/50 dark:bg-slate-800/20">
        <h4 class="text-base md:text-lg font-bold text-slate-900 dark:text-slate-100">Business Partners</h4>
        <div class="flex items-center gap-2 relative w-full sm:w-auto">
            <button id="filterToggleBtn" onclick="toggleFilterPanel()" class="flex-1 sm:flex-none px-3 py-2 md:py-1.5 text-xs font-bold text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg flex items-center justify-center gap-1 border border-slate-200 dark:border-slate-700 transition-colors">
                <span class="material-symbols-outlined text-[16px]">filter_list</span>
                <span class="hidden sm:inline">Filter</span>
            </button>
            <?php if ($isAdmin): ?>
            <button onclick="openAddModal()" class="flex-1 sm:flex-none px-3 py-2 md:py-1.5 text-xs font-bold bg-primary text-white hover:bg-primary/90 rounded-lg flex items-center justify-center gap-1">
                <span class="material-symbols-outlined text-[16px]">person_add</span>
                <span class="hidden sm:inline">Add Partner</span>
            </button>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Filter Panel - Responsive -->
    <div id="filterPanel" class="hidden px-4 md:px-6 py-4 border-b border-slate-200 dark:border-slate-800 bg-slate-50/30 dark:bg-slate-800/10">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
            <div>
                <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 mb-1">Partner Name</label>
                <input type="text" id="filterName" oninput="applyFilters()" placeholder="Search by name..." class="w-full px-3 py-1.5 text-sm border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 focus:ring-2 focus:ring-primary/50 focus:border-primary">
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 mb-1">Phone Number</label>
                <input type="text" id="filterPhone" oninput="applyFilters()" placeholder="Search by phone..." class="w-full px-3 py-1.5 text-sm border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 focus:ring-2 focus:ring-primary/50 focus:border-primary">
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 mb-1">Address/Contact</label>
                <input type="text" id="filterAddress" oninput="applyFilters()" placeholder="Search by address..." class="w-full px-3 py-1.5 text-sm border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 focus:ring-2 focus:ring-primary/50 focus:border-primary">
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
    <div class="lg:hidden flex flex-col gap-3 p-4">
        <?php
        $stmt_mobile = $conn->prepare("SELECT * FROM partners ORDER BY name");
        $stmt_mobile->execute();
        $partners_mobile = $stmt_mobile->get_result();
        if ($partners_mobile->num_rows === 0):
        ?>
        <div class="text-center py-12 text-slate-500">
            <span class="material-symbols-outlined mx-auto text-6xl text-slate-400 mb-4 block">group_off</span>
            <p class="text-lg font-medium text-slate-700 dark:text-slate-300">No partners found</p>
            <p class="text-sm mt-1 text-slate-500">Get started by adding your first business partner</p>
        </div>
        <?php
        else:
        while ($pm = $partners_mobile->fetch_assoc()):
            // Calculate profit
            $stmt_profit_mobile = $conn->prepare("
                SELECT 
                    SUM(profit) AS total,
                    SUM(CASE WHEN status='pending' THEN profit ELSE 0 END) AS pending
                FROM partner_profits
                WHERE partner_id = ?
            ");
            $stmt_profit_mobile->bind_param("i", $pm['id']);
            $stmt_profit_mobile->execute();
            $profitData_mobile = $stmt_profit_mobile->get_result()->fetch_assoc();
            $totalProfit_mobile = $profitData_mobile['total'] ?? 0;
            
            // Generate avatar color
            $colors_mobile = [
                ['bg' => 'bg-primary/10', 'text' => 'text-primary'],
                ['bg' => 'bg-purple-100 dark:bg-purple-900/30', 'text' => 'text-purple-600 dark:text-purple-400'],
                ['bg' => 'bg-slate-200 dark:bg-slate-700', 'text' => 'text-slate-700 dark:text-slate-300'],
                ['bg' => 'bg-orange-100 dark:bg-orange-900/30', 'text' => 'text-orange-600 dark:text-orange-400'],
                ['bg' => 'bg-green-100 dark:bg-green-900/30', 'text' => 'text-green-600 dark:text-green-400'],
            ];
            $colorIndex_mobile = $pm['id'] % count($colors_mobile);
            $avatarColor_mobile = $colors_mobile[$colorIndex_mobile];
            $nameParts_mobile = explode(' ', $pm['name']);
            if (count($nameParts_mobile) >= 2) {
                $initials_mobile = strtoupper(substr($nameParts_mobile[0], 0, 1) . substr($nameParts_mobile[1], 0, 1));
            } else {
                $initials_mobile = strtoupper(substr($pm['name'], 0, 2));
            }
        ?>
        <div class="partner-card bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-4 hover:border-primary/30 transition-all shadow-sm cursor-pointer"
             data-partner-name="<?= htmlspecialchars(strtolower($pm['name'])) ?>"
             data-partner-phone="<?= htmlspecialchars(strtolower($pm['phone'] ?? '')) ?>"
             data-partner-address="<?= htmlspecialchars(strtolower($pm['address'] ?? '')) ?>"
             onclick="viewPartnerAccount(<?= intval($pm['id']) ?>, '<?= htmlspecialchars($pm['name'], ENT_QUOTES) ?>')">
            <!-- Mobile Card Header -->
            <div class="flex items-start justify-between mb-3">
                <div class="flex items-center gap-3 flex-1">
                    <div class="size-12 rounded-lg <?= $avatarColor_mobile['bg'] ?> <?= $avatarColor_mobile['text'] ?> flex items-center justify-center font-bold text-base flex-shrink-0"><?= $initials_mobile ?></div>
                    <div class="flex flex-col min-w-0">
                        <span class="text-base font-bold text-slate-900 dark:text-slate-100 truncate"><?= htmlspecialchars($pm['name']) ?></span>
                        <span class="text-xs text-slate-500">ID: P-<?= str_pad($pm['id'], 5, '0', STR_PAD_LEFT) ?></span>
                    </div>
                </div>
                <?php if ($isAdmin): ?>
                <div class="flex items-center gap-1" onclick="event.stopPropagation()">
                    <button onclick="openEditModal(<?= intval($pm['id']) ?>)" 
                            class="p-2 text-amber-600 hover:bg-amber-50 dark:hover:bg-amber-900/20 rounded-lg transition-colors"
                            title="Edit partner">
                        <span class="material-symbols-outlined text-lg">edit</span>
                    </button>
                    <button onclick="deletePartner(<?= intval($pm['id']) ?>, '<?= htmlspecialchars($pm['name'], ENT_QUOTES) ?>')" 
                            class="p-2 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition-colors"
                            title="Delete partner">
                        <span class="material-symbols-outlined text-lg">delete</span>
                    </button>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Mobile Card Details -->
            <div class="space-y-2 border-t border-slate-100 dark:border-slate-800 pt-3">
                <div class="flex justify-between items-center">
                    <span class="text-xs text-slate-500">Phone & Address</span>
                    <span class="text-xs font-medium text-slate-700 dark:text-slate-300"><?= htmlspecialchars($pm['phone'] ?: 'N/A') ?></span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-xs text-slate-500">Share %</span>
                    <div class="flex items-center gap-2">
                        <div class="w-16 bg-slate-100 dark:bg-slate-800 rounded-full h-1.5">
                            <div class="bg-primary h-1.5 rounded-full" style="width: <?= min($pm['share_percentage'] ?? 0, 100) ?>%"></div>
                        </div>
                        <span class="text-xs font-bold text-slate-700 dark:text-slate-300"><?= number_format($pm['share_percentage'] ?? 0, 0) ?>%</span>
                    </div>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-xs text-slate-500">Total Earned</span>
                    <span class="px-2 py-0.5 bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 dark:text-emerald-400 rounded text-xs font-bold">Rs. <?= number_format($pm['total'] ?? 0, 0) ?></span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-xs text-slate-500">Remaining</span>
                    <span class="text-xs font-medium <?= ($pm['remaining'] ?? 0) > 0 ? 'text-slate-600 dark:text-slate-400' : 'text-slate-400 dark:text-slate-500' ?>">Rs. <?= number_format($pm['remaining'] ?? 0, 0) ?></span>
                </div>
            </div>
        </div>
        <?php endwhile; endif; ?>
    </div>
    
    <!-- Desktop Table View -->
    <div class="hidden lg:block overflow-x-auto">
        <table class="w-full text-left">
            <thead>
                <tr class="text-slate-400 dark:text-slate-500 uppercase text-[11px] font-bold tracking-widest bg-slate-50/30 dark:bg-slate-800/10">
                    <th class="px-6 py-4">Partner Name</th>
                    <th class="px-6 py-4">Phone & Address</th>
                    <th class="px-6 py-4">Share %</th>
                    <th class="px-6 py-4">Total Earned</th>
                    <th class="px-6 py-4">Remaining</th>
                    <?php if ($isAdmin): ?>
                    <th class="px-6 py-4 text-right">Actions</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody id="partnersTableBody" class="divide-y divide-slate-100 dark:divide-slate-800">

<?php
$stmt = $conn->prepare("SELECT * FROM partners ORDER BY name");
$stmt->execute();
$partners = $stmt->get_result();
if ($partners->num_rows === 0):
?>
    <tr>
        <td colspan="<?= $isAdmin ? 6 : 5 ?>" class="px-6 py-12 text-center text-slate-500">
            <span class="material-symbols-outlined mx-auto text-6xl text-slate-400 mb-4 block">group_off</span>
            <p class="text-lg font-medium text-slate-700 dark:text-slate-300">No partners found</p>
            <p class="text-sm mt-1 text-slate-500">Get started by adding your first business partner</p>
        </td>
    </tr>
<?php
else:
while ($p = $partners->fetch_assoc()):

    // Calculate total and pending profits
    $stmt = $conn->prepare("
        SELECT 
            SUM(profit) AS total,
            SUM(CASE WHEN status='pending' THEN profit ELSE 0 END) AS pending
        FROM partner_profits
        WHERE partner_id = ?
    ");
    $stmt->bind_param("i", $p['id']);
    $stmt->execute();
    $profitData = $stmt->get_result()->fetch_assoc();
    
    $totalProfit = $profitData['total'] ?? 0;
    $pendingProfit = $profitData['pending'] ?? 0;
?>
<?php
    // Generate color palette for avatars
    $colors = [
        ['bg' => 'bg-primary/10', 'text' => 'text-primary'],
        ['bg' => 'bg-purple-100 dark:bg-purple-900/30', 'text' => 'text-purple-600 dark:text-purple-400'],
        ['bg' => 'bg-slate-200 dark:bg-slate-700', 'text' => 'text-slate-700 dark:text-slate-300'],
        ['bg' => 'bg-orange-100 dark:bg-orange-900/30', 'text' => 'text-orange-600 dark:text-orange-400'],
        ['bg' => 'bg-green-100 dark:bg-green-900/30', 'text' => 'text-green-600 dark:text-green-400'],
    ];
    $colorIndex = $p['id'] % count($colors);
    $avatarColor = $colors[$colorIndex];
    $initials = '';
    $nameParts = explode(' ', $p['name']);
    if (count($nameParts) >= 2) {
        $initials = strtoupper(substr($nameParts[0], 0, 1) . substr($nameParts[1], 0, 1));
    } else {
        $initials = strtoupper(substr($p['name'], 0, 2));
    }
?>
<tr class="hover:bg-slate-50 dark:hover:bg-slate-800/40 transition-colors group cursor-pointer" 
    onclick="viewPartnerAccount(<?= intval($p['id']) ?>, '<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>')">
    <td class="px-6 py-4">
        <div class="flex items-center gap-3">
            <div class="size-9 rounded-lg <?= $avatarColor['bg'] ?> <?= $avatarColor['text'] ?> flex items-center justify-center font-bold text-sm"><?= $initials ?></div>
            <div class="flex flex-col">
                <span class="text-sm font-bold text-slate-900 dark:text-slate-100"><?= htmlspecialchars($p['name']) ?></span>
                <span class="text-xs text-slate-500">ID: P-<?= str_pad($p['id'], 5, '0', STR_PAD_LEFT) ?></span>
            </div>
        </div>
    </td>
    <td class="px-6 py-4">
        <div class="flex flex-col text-xs space-y-0.5">
            <span class="font-medium text-slate-700 dark:text-slate-300"><?= htmlspecialchars($p['phone'] ?: 'N/A') ?></span>
            <?php if ($p['address']): ?>
            <span class="text-slate-500"><?= htmlspecialchars(substr($p['address'], 0, 30)) ?><?= strlen($p['address']) > 30 ? '...' : '' ?></span>
            <?php endif; ?>
        </div>
    </td>
    <td class="px-6 py-4">
        <div class="w-20">
            <div class="flex justify-between items-center mb-1">
                <span class="text-xs font-bold text-slate-700 dark:text-slate-300"><?= number_format($p['share_percentage'] ?? 0, 0) ?>%</span>
            </div>
            <div class="w-full bg-slate-100 dark:bg-slate-800 rounded-full h-1.5">
                <div class="bg-primary h-1.5 rounded-full" style="width: <?= min($p['share_percentage'] ?? 0, 100) ?>%"></div>
            </div>
        </div>
    </td>
    <td class="px-6 py-4">
        <span class="px-2.5 py-1 bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 dark:text-emerald-400 rounded-lg text-xs font-bold border border-emerald-100 dark:border-emerald-800">
            Rs. <?= number_format($p['total'] ?? 0, 0) ?>
        </span>
    </td>
    <td class="px-6 py-4">
        <span class="text-sm font-medium <?= ($p['remaining'] ?? 0) > 0 ? 'text-slate-600 dark:text-slate-400' : 'text-slate-400 dark:text-slate-500' ?>">
            Rs. <?= number_format($p['remaining'] ?? 0, 0) ?>
        </span>
    </td>
    <?php if ($isAdmin): ?>
    <td class="px-6 py-4 text-right" onclick="event.stopPropagation()">
        <div class="flex items-center justify-end gap-1">
            <button onclick="openEditModal(<?= intval($p['id']) ?>)" 
                    class="p-2 text-amber-600 hover:bg-amber-50 dark:hover:bg-amber-900/20 rounded-lg transition-colors"
                    title="Edit partner">
                <span class="material-symbols-outlined text-lg">edit</span>
            </button>
            <button onclick="deletePartner(<?= intval($p['id']) ?>, '<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>')" 
                    class="p-2 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition-colors"
                    title="Delete partner">
                <span class="material-symbols-outlined text-lg">delete</span>
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
</section>

</div> <!-- End page body -->
</div>

<!-- Distribute Profit Modal - Responsive -->
<div id="distributeProfitModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden items-center justify-center z-50 p-3 md:p-4 animate-fadeIn" style="min-height: 100vh;">
    <div class="bg-white dark:bg-slate-900 rounded-xl md:rounded-2xl shadow-2xl w-full max-w-[85vw] md:max-w-4xl mx-auto transform transition-all animate-slideIn max-h-[92vh] md:max-h-[90vh] flex flex-col overflow-hidden">
        <!-- Header -->
        <div class="bg-gradient-to-r from-emerald-600 via-emerald-600 to-emerald-700 px-4 md:px-6 py-3 md:py-4 flex items-center justify-between">
            <div class="flex items-center gap-2 md:gap-3">
                <div class="bg-white/20 backdrop-blur-sm rounded-lg md:rounded-xl p-1.5 md:p-2">
                    <span class="material-symbols-outlined text-white text-xl md:text-2xl">payments</span>
                </div>
                <div>
                    <h3 class="text-base md:text-xl font-bold text-white">Distribute Profit</h3>
                    <p class="text-emerald-100 text-xs hidden md:block">Allocate container profits to partners</p>
                </div>
            </div>
            <button type="button" onclick="closeDistributeProfitModal()" class="text-white/80 hover:text-white hover:bg-white/20 rounded-lg p-1.5 md:p-2 transition-all">
                <span class="material-symbols-outlined text-lg md:text-xl">close</span>
            </button>
        </div>

        <!-- Form -->
        <form id="distributeProfitForm" class="p-4 md:p-6 overflow-y-auto flex-1">
            <div class="space-y-5">
                <!-- Container Selection -->
                <div>
                    <label class="block text-sm font-bold text-slate-700 dark:text-slate-300 mb-2">
                        <span class="flex items-center gap-2">
                            <span class="material-symbols-outlined text-lg">inventory_2</span>
                            Select Container *
                        </span>
                    </label>
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">search</span>
                        <select id="distributeContainerSelect" name="container_id" required 
                                class="w-full pl-10 pr-4 py-3 border-2 border-slate-200 dark:border-slate-700 dark:bg-slate-800 rounded-xl focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 transition-all">
                            <option value="">Loading containers...</option>
                        </select>
                    </div>
                </div>

                <!-- Financial Summary - Responsive to value size -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 md:gap-4">
                    <div class="bg-gradient-to-br from-blue-50 to-blue-100 dark:from-blue-900/20 dark:to-blue-800/20 p-4 md:p-5 rounded-lg md:rounded-xl border-2 border-blue-200 dark:border-blue-800 min-h-[100px] flex flex-col justify-between">
                        <div class="flex items-center gap-2 md:gap-3 mb-2">
                            <div class="bg-blue-500 rounded-lg p-1.5 md:p-2">
                                <span class="material-symbols-outlined text-white text-lg md:text-xl">receipt_long</span>
                            </div>
                            <p class="text-xs md:text-sm font-bold text-blue-700 dark:text-blue-300">Invoice Total</p>
                        </div>
                        <p class="font-bold text-blue-900 dark:text-blue-100 break-words" id="invoiceTotalDisplay" style="word-break: break-word; line-height: 1.2;">Rs 0.00</p>
                    </div>
                    <div class="bg-gradient-to-br from-orange-50 to-orange-100 dark:from-orange-900/20 dark:to-orange-800/20 p-4 md:p-5 rounded-lg md:rounded-xl border-2 border-orange-200 dark:border-orange-800 min-h-[100px] flex flex-col justify-between">
                        <div class="flex items-center gap-2 md:gap-3 mb-2">
                            <div class="bg-orange-500 rounded-lg p-1.5 md:p-2">
                                <span class="material-symbols-outlined text-white text-lg md:text-xl">trending_down</span>
                            </div>
                            <p class="text-xs md:text-sm font-bold text-orange-700 dark:text-orange-300">Total Expenses</p>
                        </div>
                        <p class="font-bold text-orange-900 dark:text-orange-100 break-words" id="expenseTotalDisplay" style="word-break: break-word; line-height: 1.2;">Rs 0.00</p>
                    </div>
                    <div class="bg-gradient-to-br from-emerald-50 to-emerald-100 dark:from-emerald-900/20 dark:to-emerald-800/20 p-4 md:p-5 rounded-lg md:rounded-xl border-2 border-emerald-200 dark:border-emerald-800 min-h-[100px] flex flex-col justify-between">
                        <div class="flex items-center gap-2 md:gap-3 mb-2">
                            <div class="bg-emerald-500 rounded-lg p-1.5 md:p-2">
                                <span class="material-symbols-outlined text-white text-lg md:text-xl">trending_up</span>
                            </div>
                            <p class="text-xs md:text-sm font-bold text-emerald-700 dark:text-emerald-300">Net Profit</p>
                        </div>
                        <p class="font-bold text-emerald-900 dark:text-emerald-100 break-words" id="profitTotalDisplay" style="word-break: break-word; line-height: 1.2;">Rs 0.00</p>
                    </div>
                </div>

                <!-- Info Notice -->
                <div class="bg-blue-50 dark:bg-blue-900/20 border-l-4 border-blue-500 p-4 rounded-r-xl">
                    <div class="flex items-start gap-3">
                        <span class="material-symbols-outlined text-blue-600 dark:text-blue-400 text-xl">info</span>
                        <div class="text-sm text-blue-800 dark:text-blue-200">
                            <p class="font-semibold mb-1">Distribution Information</p>
                            <p>Profits will be distributed to all partners based on their share percentages.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="flex flex-col-reverse sm:flex-row justify-end gap-2 md:gap-3 mt-4 md:mt-6 pt-4 md:pt-5 border-t border-slate-200 dark:border-slate-700">
                <button type="button" onclick="closeDistributeProfitModal()" 
                        class="w-full sm:w-auto px-4 md:px-6 py-2.5 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded-lg font-semibold text-sm hover:bg-slate-200 dark:hover:bg-slate-700 transition-all">
                    Cancel
                </button>
                <button type="submit" 
                        class="w-full sm:w-auto px-4 md:px-6 py-2.5 bg-gradient-to-r from-emerald-600 to-emerald-700 text-white rounded-lg hover:from-emerald-700 hover:to-emerald-800 transition-all shadow-lg shadow-emerald-500/30 font-semibold text-sm flex items-center justify-center gap-2">
                    <span class="material-symbols-outlined text-lg">check_circle</span>
                    <span class="hidden sm:inline">Distribute to Partners</span>
                    <span class="sm:hidden">Distribute</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Add Partner Modal - Responsive -->
<div id="addPartnerModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden items-center justify-center z-50 p-2 sm:p-4 animate-fadeIn" style="min-height: 100vh;">
    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-xs sm:max-w-sm mx-auto overflow-hidden transform transition-all animate-slideIn max-h-[90vh] flex flex-col">
        <!-- Header -->
        <div class="bg-gradient-to-r from-indigo-600 via-blue-600 to-purple-600 text-white px-5 py-3">
            <div class="flex justify-between items-center">
                <h3 class="text-lg font-bold flex items-center gap-2">
                    <span class="material-symbols-outlined">person_add</span>
                    Add Partner
                </h3>
                <button type="button" onclick="closeAddModal()" class="text-white/90 hover:text-white hover:bg-white/20 rounded-lg p-1.5 transition-all">
                    <span class="material-symbols-outlined text-xl">close</span>
                </button>
            </div>
        </div>

        <!-- Form -->
        <form id="addPartnerForm" class="p-5 space-y-4 overflow-y-auto flex-1">
            <!-- Partner Name -->
            <div class="space-y-1.5">
                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300">
                    <span class="flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-base">person</span>
                        Partner Name *
                    </span>
                </label>
                <input type="text" name="name" required
                       class="w-full px-3 py-2 border-2 border-slate-200 dark:border-slate-700 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent dark:bg-slate-800 dark:text-white transition-all text-sm"
                       placeholder="Enter full partner name">
            </div>

            <!-- Phone Number -->
            <div class="space-y-1.5">
                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300">
                    <span class="flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-base">phone</span>
                        Phone Number
                    </span>
                </label>
                <input type="text" name="phone"
                       class="w-full px-3 py-2 border-2 border-slate-200 dark:border-slate-700 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent dark:bg-slate-800 dark:text-white transition-all text-sm"
                       placeholder="e.g., 03001234567">
            </div>

            <!-- Share Percentage -->
            <div class="space-y-1.5">
                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300">
                    <span class="flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-base">percent</span>
                        Share Percentage (%) *
                    </span>
                </label>
                <input type="number" name="share_percentage" step="0.01" min="0" max="100" required
                       class="w-full px-3 py-2 border-2 border-slate-200 dark:border-slate-700 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent dark:bg-slate-800 dark:text-white transition-all text-sm"
                       placeholder="e.g., 25.5">
            </div>

            <!-- Address -->
            <div class="space-y-1.5">
                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300">
                    <span class="flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-base">location_on</span>
                        Address
                    </span>
                </label>
                <textarea name="address" rows="2"
                          class="w-full px-3 py-2 border-2 border-slate-200 dark:border-slate-700 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent dark:bg-slate-800 dark:text-white transition-all text-sm resize-none"
                          placeholder="Complete business address"></textarea>
            </div>

            <!-- Additional Contact -->
            <div class="space-y-1.5">
                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300">
                    <span class="flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-base">contact_mail</span>
                        Additional Contact Info
                    </span>
                </label>
                <textarea name="contact" rows="2"
                          class="w-full px-3 py-2 border-2 border-slate-200 dark:border-slate-700 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent dark:bg-slate-800 dark:text-white transition-all text-sm resize-none"
                          placeholder="Email, secondary phone, etc."></textarea>
            </div>

            <!-- Action Buttons -->
            <div class="flex gap-2.5 pt-3 border-t border-slate-200 dark:border-slate-700">
                <button type="button" onclick="closeAddModal()"
                        class="flex-1 px-3 py-2.5 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded-lg font-semibold text-sm hover:bg-slate-200 dark:hover:bg-slate-700 transition-all">
                    Cancel
                </button>
                <button type="submit"
                        class="flex-1 px-3 py-2.5 bg-gradient-to-r from-blue-600 to-indigo-600 text-white rounded-lg font-semibold text-sm hover:from-blue-700 hover:to-indigo-700 shadow-lg shadow-blue-500/30 transition-all flex items-center justify-center gap-1.5">
                    <span class="material-symbols-outlined text-base">save</span>
                    Save
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Partner Modal - Responsive -->
<div id="editPartnerModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden items-center justify-center z-50 p-2 sm:p-4 animate-fadeIn" style="min-height: 100vh;">
    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-xs sm:max-w-sm mx-auto overflow-hidden transform transition-all animate-slideIn max-h-[90vh] flex flex-col">
        <!-- Header -->
        <div class="bg-gradient-to-r from-amber-600 via-orange-600 to-red-600 text-white px-5 py-3">
            <div class="flex justify-between items-center">
                <h3 class="text-lg font-bold flex items-center gap-2">
                    <span class="material-symbols-outlined">edit</span>
                    Edit Partner
                </h3>
                <button type="button" onclick="closeEditModal()" class="text-white/90 hover:text-white hover:bg-white/20 rounded-lg p-1.5 transition-all">
                    <span class="material-symbols-outlined text-xl">close</span>
                </button>
            </div>
        </div>

        <!-- Form -->
        <form id="editPartnerForm" class="p-5 space-y-4 overflow-y-auto flex-1">
            <input type="hidden" name="id" id="editPartnerId">
            
            <!-- Partner Name -->
            <div class="space-y-1.5">
                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300">
                    <span class="flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-base">person</span>
                        Partner Name *
                    </span>
                </label>
                <input type="text" name="name" id="editPartnerName" required
                       class="w-full px-3 py-2 border-2 border-slate-200 dark:border-slate-700 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent dark:bg-slate-800 dark:text-white transition-all text-sm"
                       placeholder="Enter full partner name">
            </div>

            <!-- Phone Number -->
            <div class="space-y-1.5">
                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300">
                    <span class="flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-base">phone</span>
                        Phone Number
                    </span>
                </label>
                <input type="text" name="phone" id="editPartnerPhone"
                       class="w-full px-3 py-2 border-2 border-slate-200 dark:border-slate-700 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent dark:bg-slate-800 dark:text-white transition-all text-sm"
                       placeholder="e.g., 03001234567">
            </div>

            <!-- Share Percentage -->
            <div class="space-y-1.5">
                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300">
                    <span class="flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-base">percent</span>
                        Share Percentage (%) *
                    </span>
                </label>
                <input type="number" name="share_percentage" id="editPartnerShare" step="0.01" min="0" max="100" required
                       class="w-full px-3 py-2 border-2 border-slate-200 dark:border-slate-700 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent dark:bg-slate-800 dark:text-white transition-all text-sm"
                       placeholder="e.g., 25.5">
            </div>

            <!-- Address -->
            <div class="space-y-1.5">
                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300">
                    <span class="flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-base">location_on</span>
                        Address
                    </span>
                </label>
                <textarea name="address" id="editPartnerAddress" rows="2"
                          class="w-full px-3 py-2 border-2 border-slate-200 dark:border-slate-700 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent dark:bg-slate-800 dark:text-white transition-all text-sm resize-none"
                          placeholder="Complete business address"></textarea>
            </div>

            <!-- Additional Contact -->
            <div class="space-y-1.5">
                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300">
                    <span class="flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-base">contact_mail</span>
                        Additional Contact Info
                    </span>
                </label>
                <textarea name="contact" id="editPartnerContact" rows="2"
                          class="w-full px-3 py-2 border-2 border-slate-200 dark:border-slate-700 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent dark:bg-slate-800 dark:text-white transition-all text-sm resize-none"
                          placeholder="Email, secondary phone, etc."></textarea>
            </div>

            <!-- Action Buttons -->
            <div class="flex gap-2.5 pt-3 border-t border-slate-200 dark:border-slate-700">
                <button type="button" onclick="closeEditModal()"
                        class="flex-1 px-3 py-2.5 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded-lg font-semibold text-sm hover:bg-slate-200 dark:hover:bg-slate-700 transition-all">
                    Cancel
                </button>
                <button type="submit"
                        class="flex-1 px-3 py-2.5 bg-gradient-to-r from-orange-600 to-red-600 text-white rounded-lg font-semibold text-sm hover:from-orange-700 hover:to-red-700 shadow-lg shadow-orange-500/30 transition-all flex items-center justify-center gap-1.5">
                    <span class="material-symbols-outlined text-base">save</span>
                    Update
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Custom Delete Confirmation Modal - Responsive -->
<div id="deleteConfirmModal" class="fixed inset-0 bg-black/65 backdrop-blur-sm hidden items-center justify-center z-50 p-4 animate-fadeIn" style="min-height: 100vh;">
    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl border border-red-100 dark:border-red-900/40 max-w-md w-full transform transition-all animate-slideIn overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-200 dark:border-slate-800 flex items-start justify-between gap-3">
            <div class="flex items-center gap-3">
                <div class="h-11 w-11 rounded-xl bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400 flex items-center justify-center">
                    <span class="material-symbols-outlined text-[24px]">delete_forever</span>
                </div>
                <div>
                    <h3 class="text-lg font-bold text-slate-900 dark:text-slate-100">Delete Partner</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400">This action is permanent.</p>
                </div>
            </div>
            <button type="button" onclick="closeDeleteModal()" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 transition" aria-label="Close">
                <span class="material-symbols-outlined text-[20px]">close</span>
            </button>
        </div>

        <div class="px-5 py-4 space-y-3">
            <p class="text-sm text-slate-700 dark:text-slate-300">You are about to delete:</p>
            <div class="rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/70 px-4 py-3">
                <p class="text-base font-bold text-slate-900 dark:text-slate-100" id="deletePartnerName"></p>
            </div>
            <div class="rounded-xl border border-red-200 dark:border-red-900/50 bg-red-50 dark:bg-red-900/20 px-4 py-3">
                <p class="text-sm font-semibold text-red-700 dark:text-red-300">Associated transactions and profit history will also be deleted.</p>
            </div>
        </div>

        <div class="px-5 pb-5 pt-2 flex flex-col-reverse sm:flex-row sm:justify-end gap-2.5">
            <button type="button" onclick="closeDeleteModal()"
                    class="w-full sm:w-auto px-5 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 text-slate-700 dark:text-slate-300 font-semibold hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                Cancel
            </button>
            <button type="button" onclick="confirmDelete()"
                    class="w-full sm:w-auto px-5 py-2.5 rounded-xl bg-red-600 text-white font-semibold hover:bg-red-700 transition-colors shadow-lg shadow-red-500/25 flex items-center justify-center gap-2">
                <span class="material-symbols-outlined text-[20px]">delete</span>
                Delete Partner
            </button>
        </div>
    </div>
</div>

<!-- Partner Account Modal - Responsive -->
<div id="partnerAccountModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden items-center justify-center z-50 p-3 md:p-4 animate-fadeIn" style="min-height: 100vh;">
    <div class="bg-white dark:bg-slate-900 rounded-xl md:rounded-2xl shadow-2xl w-full max-w-[90vw] md:max-w-5xl mx-auto transform transition-all animate-slideIn max-h-[92vh] md:max-h-[90vh] flex flex-col overflow-hidden">
        <!-- Header -->
        <div class="bg-gradient-to-r from-purple-600 via-purple-600 to-purple-700 px-4 md:px-6 py-3 md:py-4 flex items-center justify-between">
            <div class="flex items-center gap-2 md:gap-3">
                <div class="bg-white/20 backdrop-blur-sm rounded-lg md:rounded-xl p-1.5 md:p-2">
                    <span class="material-symbols-outlined text-white text-xl md:text-2xl">account_balance_wallet</span>
                </div>
                <div>
                    <h3 class="text-base md:text-xl font-bold text-white" id="accountPartnerName">Partner Account</h3>
                    <p class="text-purple-100 text-xs hidden md:block">Complete transaction history and account details</p>
                </div>
            </div>
            <button type="button" onclick="closeAccountModal()" class="text-white/80 hover:text-white hover:bg-white/20 rounded-lg p-1.5 md:p-2 transition-all">
                <span class="material-symbols-outlined text-lg md:text-xl">close</span>
            </button>
        </div>
        
        <!-- Content -->
        <div class="p-4 md:p-6 overflow-y-auto flex-1">
            <!-- Account Summary Cards - Responsive to value size -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 md:gap-4 mb-4 md:mb-6">
                <div class="bg-gradient-to-br from-emerald-50 to-emerald-100 dark:from-emerald-900/20 dark:to-emerald-800/20 p-4 md:p-5 rounded-lg md:rounded-xl border-2 border-emerald-200 dark:border-emerald-800 min-h-[100px] flex flex-col justify-between">
                    <div class="flex items-center justify-between mb-2">
                        <p class="text-xs md:text-sm font-bold text-emerald-700 dark:text-emerald-300">Total Earned</p>
                        <div class="bg-emerald-500 rounded-lg p-1.5 md:p-2">
                            <span class="material-symbols-outlined text-white text-lg md:text-xl">trending_up</span>
                        </div>
                    </div>
                    <p class="font-bold text-emerald-900 dark:text-emerald-100 break-words" id="accountTotalEarned" style="word-break: break-word; line-height: 1.2;">Rs 0.00</p>
                </div>
                <div class="bg-gradient-to-br from-blue-50 to-blue-100 dark:from-blue-900/20 dark:to-blue-800/20 p-4 md:p-5 rounded-lg md:rounded-xl border-2 border-blue-200 dark:border-blue-800 min-h-[100px] flex flex-col justify-between">
                    <div class="flex items-center justify-between mb-2">
                        <p class="text-xs md:text-sm font-bold text-blue-700 dark:text-blue-300">Total Paid</p>
                        <div class="bg-blue-500 rounded-lg p-1.5 md:p-2">
                            <span class="material-symbols-outlined text-white text-lg md:text-xl">paid</span>
                        </div>
                    </div>
                    <p class="font-bold text-blue-900 dark:text-blue-100 break-words" id="accountPaid" style="word-break: break-word; line-height: 1.2;">Rs 0.00</p>
                </div>
                <div class="bg-gradient-to-br from-orange-50 to-orange-100 dark:from-orange-900/20 dark:to-orange-800/20 p-4 md:p-5 rounded-lg md:rounded-xl border-2 border-orange-200 dark:border-orange-800 min-h-[100px] flex flex-col justify-between">
                    <div class="flex items-center justify-between mb-2">
                        <p class="text-xs md:text-sm font-bold text-orange-700 dark:text-orange-300">Remaining Balance</p>
                        <div class="bg-orange-500 rounded-lg p-1.5 md:p-2">
                            <span class="material-symbols-outlined text-white text-lg md:text-xl">account_balance</span>
                        </div>
                    </div>
                    <p class="font-bold text-orange-900 dark:text-orange-100 break-words" id="accountRemaining" style="word-break: break-word; line-height: 1.2;">Rs 0.00</p>
                </div>
            </div>
            
            <?php if ($isAdmin): ?>
            <!-- Add Transaction Form -->
            <div class="bg-gradient-to-r from-blue-50 to-indigo-50 dark:from-blue-900/20 dark:to-indigo-900/20 border-2 border-blue-200 dark:border-blue-800 rounded-xl p-5 mb-6">
                <div class="flex items-center gap-2 mb-4">
                    <span class="material-symbols-outlined text-blue-600 dark:text-blue-400">add_card</span>
                    <h4 class="font-bold text-slate-800 dark:text-slate-200">Add Partner Transaction</h4>
                </div>
                <form id="partnerTransactionForm" class="grid grid-cols-1 md:grid-cols-6 gap-3" enctype="multipart/form-data">
                    <input type="hidden" name="partner_id" id="partnerTransactionPartnerId" value="">
                    <div class="md:col-span-2">
                        <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 mb-1">Transaction Type *</label>
                        <select name="transaction_type" required class="w-full border-2 border-slate-200 dark:border-slate-700 dark:bg-slate-800 px-3 py-2 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 transition-all">
                            <option value="credit">💰 Payment to Partner</option>
                            <option value="debit">📉 Deduction / Charge</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 mb-1">Amount *</label>
                        <input type="number" name="amount" step="0.01" min="0.01" required placeholder="0.00" class="w-full border-2 border-slate-200 dark:border-slate-700 dark:bg-slate-800 px-3 py-2 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 transition-all">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 mb-1">Date *</label>
                        <input type="date" name="transaction_date" required value="<?= date('Y-m-d') ?>" class="w-full border-2 border-slate-200 dark:border-slate-700 dark:bg-slate-800 px-3 py-2 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 transition-all">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 mb-1">Reference</label>
                        <input type="text" name="reference_number" placeholder="Ref #" class="w-full border-2 border-slate-200 dark:border-slate-700 dark:bg-slate-800 px-3 py-2 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 transition-all">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 mb-1">Description</label>
                        <input type="text" name="description" placeholder="Note" class="w-full border-2 border-slate-200 dark:border-slate-700 dark:bg-slate-800 px-3 py-2 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 transition-all">
                    </div>
                    <div class="md:col-span-4">
                        <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 mb-1">Upload Proof (Image/PDF)</label>
                        <input type="file" name="proof" accept="image/*,.pdf" class="w-full border-2 border-slate-200 dark:border-slate-700 dark:bg-slate-800 px-3 py-2 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 transition-all">
                    </div>
                    <div class="md:col-span-2 flex items-end">
                        <button type="submit" class="w-full px-4 py-2.5 bg-gradient-to-r from-blue-600 to-blue-700 text-white rounded-lg hover:from-blue-700 hover:to-blue-800 transition-all shadow-lg shadow-blue-500/30 font-semibold flex items-center justify-center gap-2">
                            <span class="material-symbols-outlined text-lg">add</span>
                            Add Transaction
                        </button>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <!-- Manual Transactions Table -->
            <div class="border-2 border-slate-200 dark:border-slate-800 rounded-xl overflow-hidden mb-6">
                <div class="bg-gradient-to-r from-slate-50 to-slate-100 dark:from-slate-800 dark:to-slate-700 px-5 py-3 border-b-2 border-slate-200 dark:border-slate-700">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-slate-600 dark:text-slate-400">receipt</span>
                        <h4 class="font-bold text-slate-800 dark:text-slate-200">Manual Transactions</h4>
                    </div>
                </div>
                <div class="overflow-x-auto max-h-64">
                    <table class="w-full">
                        <thead class="bg-slate-100 dark:bg-slate-800 sticky top-0">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-bold text-slate-600 dark:text-slate-400 uppercase">Date</th>
                                <th class="px-4 py-3 text-left text-xs font-bold text-slate-600 dark:text-slate-400 uppercase">Type</th>
                                <th class="px-4 py-3 text-left text-xs font-bold text-slate-600 dark:text-slate-400 uppercase">Reference</th>
                                <th class="px-4 py-3 text-left text-xs font-bold text-slate-600 dark:text-slate-400 uppercase">Note</th>
                                <th class="px-4 py-3 text-left text-xs font-bold text-slate-600 dark:text-slate-400 uppercase">Proof</th>
                                <th class="px-4 py-3 text-right text-xs font-bold text-slate-600 dark:text-slate-400 uppercase">Amount</th>
                                <?php if ($isAdmin): ?><th class="px-4 py-3 text-center text-xs font-bold text-slate-600 dark:text-slate-400 uppercase">Action</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody id="partnerTransactions" class="divide-y divide-slate-200 dark:divide-slate-700">
                            <tr>
                                <td colspan="<?= $isAdmin ? '7' : '6' ?>" class="px-4 py-8 text-center text-slate-500">Loading...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Profit History Table -->
            <div class="border-2 border-slate-200 dark:border-slate-800 rounded-xl overflow-hidden">
                <div class="bg-gradient-to-r from-slate-50 to-slate-100 dark:from-slate-800 dark:to-slate-700 px-5 py-3 border-b-2 border-slate-200 dark:border-slate-700">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-slate-600 dark:text-slate-400">history</span>
                        <h4 class="font-bold text-slate-800 dark:text-slate-200">Profit Distribution History</h4>
                    </div>
                </div>
                <div class="overflow-x-auto max-h-96">
                    <table class="w-full">
                        <thead class="bg-slate-100 dark:bg-slate-800 sticky top-0">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-bold text-slate-600 dark:text-slate-400 uppercase">Date</th>
                                <th class="px-4 py-3 text-left text-xs font-bold text-slate-600 dark:text-slate-400 uppercase">Container</th>
                                <th class="px-4 py-3 text-left text-xs font-bold text-slate-600 dark:text-slate-400 uppercase">Invoice</th>
                                <th class="px-4 py-3 text-right text-xs font-bold text-slate-600 dark:text-slate-400 uppercase">Amount</th>
                                <th class="px-4 py-3 text-center text-xs font-bold text-slate-600 dark:text-slate-400 uppercase">Status</th>
                                <?php if ($isAdmin): ?><th class="px-4 py-3 text-center text-xs font-bold text-slate-600 dark:text-slate-400 uppercase">Action</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody id="accountTransactions" class="divide-y divide-slate-200 dark:divide-slate-700">
                            <tr>
                                <td colspan="<?= $isAdmin ? '6' : '5' ?>" class="px-4 py-8 text-center text-slate-500">Loading...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Footer Actions -->
        <div class="px-4 md:px-6 py-3 md:py-4 bg-slate-50 dark:bg-slate-800/50 flex flex-col-reverse sm:flex-row gap-2 md:gap-3 justify-end border-t border-slate-200 dark:border-slate-700">
            <button type="button" onclick="closeAccountModal()" class="w-full sm:w-auto px-4 md:px-6 py-2.5 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded-lg font-semibold text-sm hover:bg-slate-200 dark:hover:bg-slate-700 transition-all">
                Close
            </button>
            <button type="button" id="printPartnerTransactions" class="w-full sm:w-auto px-4 md:px-6 py-2.5 bg-gradient-to-r from-emerald-600 to-emerald-700 text-white rounded-lg hover:from-emerald-700 hover:to-emerald-800 transition-all shadow-lg shadow-emerald-500/30 font-semibold text-sm flex items-center justify-center gap-2">
                <span class="material-symbols-outlined text-lg">print</span>
                Print History
            </button>
        </div>
    </div>
</div>

<!-- Proof Preview Modal - Responsive -->
<div id="proofPreviewModal" class="fixed inset-0 bg-black/70 backdrop-blur-sm hidden items-center justify-center z-[60] p-4 animate-fadeIn" style="min-height: 100vh;">
    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-4xl transform transition-all animate-slideIn overflow-hidden max-h-[90vh] flex flex-col">
        <!-- Header -->
        <div class="bg-gradient-to-r from-slate-700 via-slate-700 to-slate-800 px-5 py-4 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="bg-white/20 backdrop-blur-sm rounded-lg p-2">
                    <span class="material-symbols-outlined text-white text-xl">description</span>
                </div>
                <h3 class="text-white font-bold text-lg" id="proofPreviewTitle">Proof Preview</h3>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" id="downloadProofBtn" onclick="downloadProof()" class="text-white/90 hover:text-white hover:bg-white/20 rounded-lg px-3 py-2 transition-all flex items-center gap-2" title="Download proof">
                    <span class="material-symbols-outlined text-xl">download</span>
                    <span class="text-sm font-medium hidden sm:inline">Download</span>
                </button>
                <button type="button" onclick="closeProofPreview()" class="text-white/80 hover:text-white hover:bg-white/20 rounded-lg p-2 transition-all">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
        </div>
        <!-- Content -->
        <div id="proofPreviewContent" class="p-4 bg-slate-50 dark:bg-slate-800 overflow-auto flex-1"></div>
    </div>
</div>

<!-- Confirm Modal - Responsive -->
<div id="confirmModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden items-center justify-center z-50 p-4 animate-fadeIn" style="min-height: 100vh;">
    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl max-w-md w-full transform transition-all animate-slideIn">
        <!-- Header -->
        <div class="bg-gradient-to-r from-blue-600 via-blue-600 to-blue-700 px-6 py-4 rounded-t-2xl flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="bg-white/20 backdrop-blur-sm rounded-lg p-2">
                    <span class="material-symbols-outlined text-white text-xl">help</span>
                </div>
                <h3 class="text-xl font-bold text-white" id="confirmModalTitle">Please Confirm</h3>
            </div>
        </div>
        <!-- Content -->
        <div class="p-6">
            <p class="text-slate-700 dark:text-slate-300 text-base" id="confirmModalMessage"></p>
        </div>
        <!-- Actions -->
        <div class="px-6 py-4 bg-slate-50 dark:bg-slate-800/50 rounded-b-2xl flex justify-end gap-3 border-t-2 border-slate-200 dark:border-slate-700">
            <button type="button" onclick="closeConfirmModal(false)" 
                    class="px-6 py-2.5 border-2 border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 rounded-xl hover:bg-white dark:hover:bg-slate-800 transition-colors font-semibold">
                Cancel
            </button>
            <button type="button" onclick="closeConfirmModal(true)" 
                    class="px-6 py-2.5 bg-gradient-to-r from-blue-600 to-blue-700 text-white rounded-xl hover:from-blue-700 hover:to-blue-800 transition-all shadow-lg shadow-blue-500/30 font-semibold flex items-center gap-2">
                <span class="material-symbols-outlined text-xl">check_circle</span>
                Confirm
            </button>
        </div>
    </div>
</div>

<!-- Toast Container -->
<div id="toastContainer" class="fixed top-4 right-4 z-50 space-y-3"></div>

</div>
</div>

<?php
if (!$isAjax) {
    include 'include/footer.php';
?>

<style>
/* Base Layout */
html,
body {
    min-height: 100%;
    margin: 0 !important;
    padding: 0 !important;
    overflow-x: hidden;
}

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

/* Ensure overlay modals always cover full viewport */
.fixed[style*="min-height: 100vh"] {
    top: 0 !important;
    left: 0 !important;
    right: 0 !important;
    bottom: 0 !important;
    width: 100% !important;
    height: 100% !important;
    margin: 0 !important;
    padding: 0 !important;
}

/* Modal Animations */
@keyframes fadeIn {
    from {
        opacity: 0;
    }
    to {
        opacity: 1;
    }
}

@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateY(-20px) scale(0.95);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

.animate-fadeIn {
    animation: fadeIn 0.2s ease-out;
}

.animate-slideIn {
    animation: slideIn 0.3s ease-out;
}

/* Smooth scrollbars */
::-webkit-scrollbar {
    width: 8px;
    height: 8px;
}

::-webkit-scrollbar-track {
    background: transparent;
}

::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 4px;
}

::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}

.dark ::-webkit-scrollbar-thumb {
    background: #475569;
}

.dark ::-webkit-scrollbar-thumb:hover {
    background: #64748b;
}
</style>

<script>
(function() {
    'use strict';
    
    console.log('Partners management JavaScript loaded successfully');

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

    // Page scroll lock for modals
    var setPageScrollLock = function(locked) {
        document.body.classList.toggle('overflow-hidden', locked);
        document.documentElement.classList.toggle('overflow-hidden', locked);
        document.body.style.overflow = locked ? 'hidden' : '';
        document.documentElement.style.overflow = locked ? 'hidden' : '';
    };

    var partnerProofBaseUrl = '<?php echo BASE_URL; ?>uploads/partner_transaction_proofs/';
    var printCompanyDetails = <?php echo json_encode($companyDetails); ?>;
    var isAdminPage = <?php echo $isAdmin ? 'true' : 'false'; ?>;
    var confirmCallback = null;

    function showToast(type, message) {
        var container = document.getElementById('toastContainer');
        if (!container) return;

        var toast = document.createElement('div');
        var baseClass = 'px-5 py-3 rounded-lg shadow-lg text-white flex items-center gap-2';
        var typeClass = 'bg-blue-600';
        if (type === 'success') {
            typeClass = 'bg-green-600';
        } else if (type === 'error') {
            typeClass = 'bg-red-600';
        } else if (type === 'warning') {
            typeClass = 'bg-yellow-600';
        }
        toast.className = baseClass + ' ' + typeClass;
        toast.textContent = message;

        container.appendChild(toast);
        setTimeout(function() {
            toast.classList.add('opacity-0');
            toast.style.transition = 'opacity 0.3s ease';
            setTimeout(function() {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            }, 300);
        }, 2800);
    }

    function showConfirm(message, onConfirm) {
        confirmCallback = onConfirm || null;
        document.getElementById('confirmModalMessage').textContent = message;
        document.getElementById('confirmModal').classList.remove('hidden');
        document.getElementById('confirmModal').classList.add('flex');
        setPageScrollLock(true);
    }

    window.closeConfirmModal = function(confirmed) {
        document.getElementById('confirmModal').classList.add('hidden');
        document.getElementById('confirmModal').classList.remove('flex');
        setPageScrollLock(false);
        if (confirmed && typeof confirmCallback === 'function') {
            confirmCallback();
        }
        confirmCallback = null;
    };

    // Modal Functions
    window.openAddModal = function() {
        document.getElementById('addPartnerModal').classList.remove('hidden');
        document.getElementById('addPartnerModal').classList.add('flex');
        document.getElementById('addPartnerForm').reset();
        setPageScrollLock(true);
    };

    window.closeAddModal = function() {
        document.getElementById('addPartnerModal').classList.add('hidden');
        document.getElementById('addPartnerModal').classList.remove('flex');
        setPageScrollLock(false);
    };

    window.openEditModal = function(id) {
        console.log('openEditModal called with id:', id, 'type:', typeof id); // Debug log
        
        fetch('partners.php?action=get&id=' + id + '&ajax=1', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(response) {
            console.log('Response received:', response.status, response.statusText); // Debug log
            if (!response.ok) {
                throw new Error('HTTP Error: ' + response.status);
            }
            // Check if response is JSON
            var contentType = response.headers.get('content-type');
            console.log('Content-Type:', contentType); // Debug log
            if (!contentType || !contentType.includes('application/json')) {
                return response.text().then(function(text) {
                    console.error('Expected JSON but got:', text.substring(0, 200));
                    throw new Error('Server returned HTML instead of JSON. Check console for details.');
                });
            }
            return response.json();
        })
        .then(function(data) {
            console.log('Data received:', data); // Debug log
            
            // Check for session expiry or redirect
            if (data.redirect) {
                showToast('warning', data.message || 'Session expired. Please login again.');
                setTimeout(function() {
                    window.location.href = data.redirect;
                }, 800);
                return;
            }
            
            if (data.success && data.data) {
                document.getElementById('editPartnerId').value = data.data.id;
                document.getElementById('editPartnerName').value = data.data.name;
                document.getElementById('editPartnerPhone').value = data.data.phone || '';
                document.getElementById('editPartnerAddress').value = data.data.address || '';
                document.getElementById('editPartnerContact').value = data.data.contact || '';
                document.getElementById('editPartnerShare').value = data.data.share_percentage || 0;
                
                document.getElementById('editPartnerModal').classList.remove('hidden');
                document.getElementById('editPartnerModal').classList.add('flex');
                setPageScrollLock(true);
            } else {
                showToast('error', 'Error: ' + (data.message || 'Failed to load partner data'));
            }
        })
        .catch(function(error) {
            console.error('Error:', error);
            showToast('error', 'Error fetching partner data: ' + error.message);
        });
    };

    window.closeEditModal = function() {
        document.getElementById('editPartnerModal').classList.add('hidden');
        document.getElementById('editPartnerModal').classList.remove('flex');
        setPageScrollLock(false);
    };

    window.openDistributeProfitModal = function() {
        document.getElementById('distributeProfitModal').classList.remove('hidden');
        document.getElementById('distributeProfitModal').classList.add('flex');
        setPageScrollLock(true);
        loadUndistributedContainers();
        resetProfitSummary();
    };

    window.closeDistributeProfitModal = function() {
        document.getElementById('distributeProfitModal').classList.add('hidden');
        document.getElementById('distributeProfitModal').classList.remove('flex');
        setPageScrollLock(false);
    };

    function resetProfitSummary() {
        setProfitStatValue('invoiceTotalDisplay', 0);
        setProfitStatValue('expenseTotalDisplay', 0);
        setProfitStatValue('profitTotalDisplay', 0);
    }
    
    // Helper function for profit distribution stat blocks with dynamic sizing
    function setProfitStatValue(elementId, value) {
        var elem = document.getElementById(elementId);
        if (!elem) return;
        
        var formattedValue = 'Rs ' + parseFloat(value).toFixed(2);
        elem.textContent = formattedValue;
        
        // Dynamically adjust font size based on content length
        var textLength = formattedValue.length;
        if (textLength <= 15) {
            elem.style.fontSize = 'clamp(1.5rem, 3vw, 1.875rem)'; // text-2xl to text-3xl
        } else if (textLength <= 20) {
            elem.style.fontSize = 'clamp(1.25rem, 2.5vw, 1.5rem)'; // text-xl to text-2xl
        } else if (textLength <= 25) {
            elem.style.fontSize = 'clamp(1.125rem, 2vw, 1.25rem)'; // text-lg to text-xl
        } else {
            elem.style.fontSize = 'clamp(0.875rem, 1.5vw, 1rem)'; // text-sm to text-base
        }
    }

    function loadUndistributedContainers() {
        var select = document.getElementById('distributeContainerSelect');
        if (!select) return;

        select.innerHTML = '<option value="">Loading containers...</option>';

        fetch('partners.php?action=undistributed_containers&ajax=1', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(response) {
            if (!response.ok) {
                throw new Error('HTTP Error: ' + response.status);
            }
            return response.json();
        })
        .then(function(data) {
            if (data.success && data.containers) {
                if (data.containers.length === 0) {
                    select.innerHTML = '<option value="">No available containers</option>';
                    return;
                }

                var options = '<option value="">Select Container...</option>';
                data.containers.forEach(function(container) {
                    var containerNum = container.container_number || ('Container #' + container.id);
                    var customerName = container.customer_name || 'Unknown Customer';
                    var label = containerNum + ' - ' + customerName;
                    options += '<option value="' + container.id + '">' + label + '</option>';
                });
                select.innerHTML = options;
            } else {
                select.innerHTML = '<option value="">Failed to load containers</option>';
            }
        })
        .catch(function(error) {
            console.error('Error:', error);
            select.innerHTML = '<option value="">Error loading containers</option>';
        });
    }

    function loadContainerProfit(containerId) {
        fetch('partners.php?action=container_profit&id=' + containerId + '&ajax=1', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(response) {
            if (!response.ok) {
                throw new Error('HTTP Error: ' + response.status);
            }
            return response.json();
        })
        .then(function(data) {
            if (!data.success || !data.container) {
                resetProfitSummary();
                showToast('error', data.message || 'Failed to load container details');
                return;
            }

            var invoiceTotal = parseFloat(data.container.invoice_total || 0);
            var expenseTotal = parseFloat(data.container.expense_total || 0);
            var profitTotal = parseFloat(data.container.profit || 0);

            setProfitStatValue('invoiceTotalDisplay', invoiceTotal);
            setProfitStatValue('expenseTotalDisplay', expenseTotal);
            setProfitStatValue('profitTotalDisplay', profitTotal);
        })
        .catch(function(error) {
            console.error('Error:', error);
            resetProfitSummary();
            showToast('error', 'Error loading container data: ' + error.message);
        });
    }

    window.viewPartnerAccount = function(id, name) {
        document.getElementById('accountPartnerName').textContent = name + ' - Account Statement';
        document.getElementById('partnerAccountModal').classList.remove('hidden');
        document.getElementById('partnerAccountModal').classList.add('flex');
        setPageScrollLock(true);
        
        var partnerIdInput = document.getElementById('partnerTransactionPartnerId');
        if (partnerIdInput) {
            partnerIdInput.value = id;
        }
        
        // Load account data
        fetch('partners.php?action=account&id=' + id + '&ajax=1', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(response) {
            if (!response.ok) {
                throw new Error('HTTP Error: ' + response.status);
            }
            return response.json();
        })
        .then(function(data) {
            // Check for session expiry or redirect
            if (data.redirect) {
                showToast('warning', data.message || 'Session expired. Please login again.');
                setTimeout(function() {
                    window.location.href = data.redirect;
                }, 800);
                return;
            }
            
            if (data.success) {
                window.currentPartnerAccount = {
                    partnerId: id,
                    partnerName: name,
                    partner: data.partner || {},
                    summary: data.summary || { total: 0, paid: 0, remaining: 0 },
                    transactions: data.transactions || [],
                    profits: data.profits || []
                };

                // Helper function to set text with dynamic sizing
                var setStatValue = function(elementId, value) {
                    var elem = document.getElementById(elementId);
                    if (!elem) return;
                    
                    var formattedValue = 'Rs ' + parseFloat(value).toFixed(2);
                    elem.textContent = formattedValue;
                    
                    // Dynamically adjust font size based on content length
                    var textLength = formattedValue.length;
                    if (textLength <= 15) {
                        elem.style.fontSize = 'clamp(1.5rem, 3vw, 1.875rem)'; // text-2xl to text-3xl
                    } else if (textLength <= 20) {
                        elem.style.fontSize = 'clamp(1.25rem, 2.5vw, 1.5rem)'; // text-xl to text-2xl
                    } else if (textLength <= 25) {
                        elem.style.fontSize = 'clamp(1.125rem, 2vw, 1.25rem)'; // text-lg to text-xl
                    } else {
                        elem.style.fontSize = 'clamp(0.875rem, 1.5vw, 1rem)'; // text-sm to text-base
                    }
                };

                // Update summary with dynamic sizing
                setStatValue('accountTotalEarned', data.summary.total);
                setStatValue('accountPaid', data.summary.paid);
                setStatValue('accountRemaining', data.summary.remaining);

                var manualBody = document.getElementById('partnerTransactions');
                if (manualBody) {
                    if (!data.transactions || data.transactions.length === 0) {
                        manualBody.innerHTML = '<tr><td colspan="' + (isAdminPage ? 7 : 6) + '" class="px-4 py-8 text-center text-gray-500">No manual transactions</td></tr>';
                    } else {
                        var manualHtml = '';
                        data.transactions.forEach(function(tx) {
                            // Check if this is a dashboard fund transaction
                            var isDashboardFund = (tx.description && tx.description.indexOf('Fund added to dashboard') !== -1) || 
                                                  (tx.reference_number && tx.reference_number.indexOf('DASH-FUND-') === 0);
                            
                            var typeLabel = tx.transaction_type === 'credit' ? 'Payment to Partner' : 
                                           (isDashboardFund ? 'Dashboard Fund Contribution' : 'Deduction / Charge');
                            var typeClass = tx.transaction_type === 'credit' ? 'text-green-700 bg-green-50' : 
                                           (isDashboardFund ? 'text-blue-700 bg-blue-50' : 'text-red-700 bg-red-50');
                            var proofCell;
                            if (tx.proof) {
                                var proofUrl = partnerProofBaseUrl + encodeURIComponent(tx.proof);
                                var proofUrlEncoded = encodeURIComponent(proofUrl);
                                var proofNameEncoded = encodeURIComponent(tx.proof);
                                var proofIsImg = /\.(jpg|jpeg|png|gif|webp)$/i.test(tx.proof);
                                if (proofIsImg) {
                                    proofCell = '<button type="button" onclick="openProofPreview(decodeURIComponent(\'' + proofUrlEncoded + '\'), true, decodeURIComponent(\'' + proofNameEncoded + '\'))" class="rounded border hover:opacity-90 transition" title="Preview proof">' +
                                        '<img src="' + proofUrl + '" class="h-10 w-10 object-cover rounded border" title="View proof">' +
                                        '</button>';
                                } else {
                                    proofCell = '<button type="button" onclick="openProofPreview(decodeURIComponent(\'' + proofUrlEncoded + '\'), false, decodeURIComponent(\'' + proofNameEncoded + '\'))" class="text-blue-600 hover:underline flex items-center gap-1">' +
                                        '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>' +
                                        'View PDF</button>';
                                }
                            } else {
                                proofCell = '<span class="text-gray-400 text-xs">No proof</span>';
                            }
                            // Dashboard fund transactions cannot be deleted from partner account - only from Settings
                            var deleteBtn = (isAdminPage && !isDashboardFund)
                                ? '<button onclick="deletePartnerTx(' + tx.id + ',' + id + ')" class="text-red-500 hover:text-red-700 p-1 rounded hover:bg-red-50" title="Delete transaction">' +
                                    '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>' +
                                    '</button>'
                                : (isAdminPage && isDashboardFund ? '<span class="text-xs text-gray-400" title="Dashboard funds can only be deleted from Settings page">Settings only</span>' : '');
                            manualHtml += '<tr class="hover:bg-gray-50">' +
                                '<td class="px-4 py-3 text-sm text-gray-700">' + tx.transaction_date + '</td>' +
                                '<td class="px-4 py-3"><span class="inline-block px-2 py-0.5 rounded text-xs font-semibold ' + typeClass + '">' + typeLabel + '</span></td>' +
                                '<td class="px-4 py-3 text-sm text-gray-700">' + (tx.reference_number || '-') + '</td>' +
                                '<td class="px-4 py-3 text-sm text-gray-700">' + (tx.description || '-') + '</td>' +
                                '<td class="px-4 py-3 text-sm">' + proofCell + '</td>' +
                                '<td class="px-4 py-3 text-sm text-right font-bold ' + (tx.transaction_type === 'credit' ? 'text-green-700' : 'text-red-700') + '">PKR ' + parseFloat(tx.amount).toFixed(2) + '</td>' +
                                (isAdminPage ? '<td class="px-4 py-3 text-center">' + deleteBtn + '</td>' : '') +
                                '</tr>';
                        });
                        manualBody.innerHTML = manualHtml;
                    }
                }
                
                // Update transactions
                var tbody = document.getElementById('accountTransactions');
                if (data.profits.length === 0) {
                    tbody.innerHTML = '<tr><td colspan=\"' + (isAdminPage ? 6 : 5) + '\" class=\"px-4 py-8 text-center text-gray-500\">No transactions found</td></tr>';
                } else {
                    var html = '';
                    data.profits.forEach(function(profit) {
                        var statusClass = profit.status === 'completed' ? 'bg-green-100 text-green-800' : 'bg-orange-100 text-orange-800';
                        var statusIcon = profit.status === 'completed' 
                            ? '<svg class=\"w-4 h-4 mr-1\" fill=\"currentColor\" viewBox=\"0 0 20 20\"><path fill-rule=\"evenodd\" d=\"M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z\" clip-rule=\"evenodd\"/></svg>'
                            : '<svg class=\"w-4 h-4 mr-1\" fill=\"currentColor\" viewBox=\"0 0 20 20\"><path fill-rule=\"evenodd\" d=\"M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z\" clip-rule=\"evenodd\"/></svg>';
                        
                        var deleteBtn = isAdminPage
                            ? '<button onclick="deleteProfit(' + profit.id + ',' + id + ')" class="text-red-500 hover:text-red-700 p-1 rounded hover:bg-red-50" title="Delete profit record">' +
                                '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>' +
                                '</button>'
                            : '';
                        
                        html += '<tr class=\"hover:bg-gray-50\">' +
                            '<td class=\"px-4 py-3 text-sm text-gray-700\">' + profit.created_at + '</td>' +
                            '<td class=\"px-4 py-3 text-sm font-medium text-gray-900\">' + profit.container_number + '</td>' +
                            '<td class=\"px-4 py-3 text-sm text-blue-600\">' + profit.invoice_number + '</td>' +
                            '<td class=\"px-4 py-3 text-sm text-right font-bold text-green-600\">PKR ' + parseFloat(profit.profit).toFixed(2) + '</td>' +
                            '<td class=\"px-4 py-3 text-center\"><span class=\"inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold ' + statusClass + '\">' +
                            statusIcon + profit.status.charAt(0).toUpperCase() + profit.status.slice(1) + '</span></td>' +
                            (isAdminPage ? '<td class=\"px-4 py-3 text-center\">' + deleteBtn + '</td>' : '') +
                            '</tr>';
                    });
                    tbody.innerHTML = html;
                }
            } else {
                showToast('error', 'Error: ' + (data.message || 'Failed to load account data'));
            }
        })
        .catch(function(error) {
            console.error('Error:', error);
            showToast('error', 'Error loading account data: ' + error.message);
        });

        var txForm = document.getElementById('partnerTransactionForm');
        if (txForm && !txForm.dataset.bound) {
            txForm.dataset.bound = 'true';
            txForm.addEventListener('submit', function(e) {
                e.preventDefault();

                var amountInput = txForm.querySelector('input[name="amount"]');
                var amountValue = parseFloat(amountInput ? amountInput.value : '0');
                if (!(amountValue > 0)) {
                    showToast('error', 'Amount must be greater than 0');
                    if (amountInput) {
                        amountInput.focus();
                    }
                    return;
                }

                var formData = new FormData(txForm);
                fetch('partners.php?action=add_partner_transaction&ajax=1', {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(function(response) {
                    if (!response.ok) {
                        throw new Error('HTTP Error: ' + response.status);
                    }
                    return response.json();
                })
                .then(function(data) {
                    if (data.success) {
                        showToast('success', data.message || 'Transaction added successfully');
                        txForm.reset();
                        viewPartnerAccount(id, name);
                        // Update the partner row in main table
                        updatePartnerRow(id);
                    } else {
                        showToast('error', 'Error: ' + (data.message || 'Failed to add transaction'));
                    }
                })
                .catch(function(error) {
                    console.error('Error:', error);
                    showToast('error', 'Error adding transaction: ' + error.message);
                });
            });
        }
    };

    function downloadPartnerTransactionHistory() {
        if (!window.currentPartnerAccount) {
            showToast('info', 'No account data available to download.');
            return;
        }

        var lines = [];
        var safeValue = function(value) {
            var text = String(value == null ? '' : value);
            if (/[",\n]/.test(text)) {
                return '"' + text.replace(/"/g, '""') + '"';
            }
            return text;
        };

        lines.push('Manual Transactions');
        lines.push('Date,Type,Reference,Note,Proof,Amount');
        if (window.currentPartnerAccount.transactions.length === 0) {
            lines.push('No manual transactions');
        } else {
            window.currentPartnerAccount.transactions.forEach(function(tx) {
                var isDashboardFund = (tx.description && tx.description.indexOf('Fund added to dashboard') !== -1) || 
                                      (tx.reference_number && tx.reference_number.indexOf('DASH-FUND-') === 0);
                var typeLabel = tx.transaction_type === 'credit' ? 'Payment to Partner' : 
                               (isDashboardFund ? 'Dashboard Fund Contribution' : 'Deduction / Charge');
                lines.push([
                    safeValue(tx.transaction_date),
                    safeValue(typeLabel),
                    safeValue(tx.reference_number || '-'),
                    safeValue(tx.description || '-'),
                    safeValue(tx.proof ? 'Yes' : 'No'),
                    safeValue(parseFloat(tx.amount || 0).toFixed(2))
                ].join(','));
            });
        }

        lines.push('');
        lines.push('Profit History');
        lines.push('Date,Container,Invoice,Amount,Status');
        if (window.currentPartnerAccount.profits.length === 0) {
            lines.push('No profit history');
        } else {
            window.currentPartnerAccount.profits.forEach(function(pf) {
                lines.push([
                    safeValue(pf.created_at),
                    safeValue(pf.container_number || '-'),
                    safeValue(pf.invoice_number || '-'),
                    safeValue(parseFloat(pf.profit || 0).toFixed(2)),
                    safeValue(pf.status || '-')
                ].join(','));
            });
        }

        var csv = lines.join('\n');
        var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        var url = URL.createObjectURL(blob);
        var link = document.createElement('a');
        var safeName = (window.currentPartnerAccount.partnerName || 'partner').replace(/[^a-z0-9_-]+/gi, '_');
        link.href = url;
        link.download = 'partner_transactions_' + safeName + '.csv';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    }

    function printPartnerTransactionHistory() {
        if (!window.currentPartnerAccount) {
            showToast('info', 'No account data available to print.');
            return;
        }

        var account = window.currentPartnerAccount;
        var partner = account.partner || {};
        var summary = account.summary || { total: 0, paid: 0, remaining: 0 };
        var transactions = Array.isArray(account.transactions) ? account.transactions.slice() : [];

        var esc = function(value) {
            return String(value == null ? '' : value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        };

        transactions.sort(function(a, b) {
            var aKey = String(a.transaction_date || '') + ' ' + String(a.created_at || '');
            var bKey = String(b.transaction_date || '') + ' ' + String(b.created_at || '');
            if (aKey < bKey) return -1;
            if (aKey > bKey) return 1;
            return 0;
        });

        var runningBalance = 0;
        var rowsHtml = '';
        if (transactions.length === 0) {
            rowsHtml = '<tr><td colspan="7" style="text-align:center;padding:12px;color:#666;">No transactions found</td></tr>';
        } else {
            transactions.forEach(function(tx, idx) {
                var amount = parseFloat(tx.amount || 0);
                var debit = tx.transaction_type === 'debit' ? amount : 0;
                var credit = tx.transaction_type === 'credit' ? amount : 0;
                runningBalance += debit - credit;
                var isLastRow = idx === (transactions.length - 1);
                var balanceCellStyle = isLastRow
                    ? 'text-align:right;color:#b91c1c;font-weight:700;'
                    : 'text-align:right;';

                rowsHtml += '<tr>' +
                    '<td>' + esc(tx.transaction_date || '-') + '</td>' +
                    '<td>' + esc(tx.reference_number || '-') + '</td>' +
                    '<td>' + esc(tx.description || '-') + '</td>' +
                    '<td style="text-align:right;">' + (debit > 0 ? debit.toFixed(2) : '-') + '</td>' +
                    '<td style="text-align:right;">' + (credit > 0 ? credit.toFixed(2) : '-') + '</td>' +
                    '<td style="text-align:center;">' + esc(tx.transaction_type === 'credit' ? 'Credit' : 'Debit') + '</td>' +
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
            showToast('error', 'Please allow popups to print the statement.');
            return;
        }

        var html = '<!DOCTYPE html><html><head><title>Partner Transaction History</title>' +
            '<style>' +
            'body{font-family:Arial,sans-serif;color:#111;margin:0;padding:0;font-size:12px;}' +
            '.page{padding:0 28px 28px;}' +
            '.header{display:flex;align-items:center;justify-content:center;gap:20px;margin-bottom:10px;}' +
            '.logo-wrap{flex-shrink:0;}' +
            '.company-details{flex-shrink:0;}' +
            '.company-name{font-size:24px;font-weight:700;letter-spacing:0.4px;}' +
            '.company-meta{font-size:12px;color:#333;line-height:1.5;}' +
            '.divider{border-top:1.5px solid #111;margin:14px 0 10px;}' +
            '.partner-info{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px 24px;margin-bottom:10px;}' +
            '.partner-info div{font-size:12px;}' +
            '.partner-summary{margin:6px 0 12px;color:#b91c1c;font-weight:700;font-size:12px;text-align:center;}' +
            '.report-title{text-align:center;font-weight:700;font-size:14px;margin:10px 0 12px;text-transform:uppercase;}' +
            'table{width:100%;border-collapse:collapse;}' +
            'th,td{border:1px solid #222;padding:7px 8px;font-size:11px;vertical-align:middle;text-align:center;}' +
            'th{background:#f2f2f2;text-transform:uppercase;}' +
            '.text-right{text-align:center;}' +
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
            '<div class="partner-info">' +
                '<div><strong>Partner Name:</strong> ' + esc(partner.name || account.partnerName || '-') + '</div>' +
                '<div style="text-align:right;"><strong>Phone:</strong> ' + esc(partner.phone || '-') + '</div>' +
                '<div><strong>Address:</strong> ' + esc(partner.address || '-') + '</div>' +
                '<div style="text-align:right;"><strong>Share %:</strong> ' + esc((partner.share_percentage != null ? parseFloat(partner.share_percentage).toFixed(2) + '%' : '-')) + '</div>' +
            '</div>' +
            '<div class="partner-summary">' +
                'Total: PKR ' + parseFloat(summary.total || 0).toFixed(2) +
                ' &nbsp;|&nbsp; Paid: PKR ' + parseFloat(summary.paid || 0).toFixed(2) +
                ' &nbsp;|&nbsp; Remaining: PKR ' + parseFloat(summary.remaining || 0).toFixed(2) +
            '</div>' +
            '<table>' +
                '<thead><tr>' +
                    '<th style="width:12%;">Date</th>' +
                    '<th style="width:14%;">Voucher / Ref</th>' +
                    '<th>Description</th>' +
                    '<th style="width:10%;" class="text-right">Debit</th>' +
                    '<th style="width:10%;" class="text-right">Credit</th>' +
                    '<th style="width:10%;">Type</th>' +
                    '<th style="width:12%;" class="text-right">Balance</th>' +
                '</tr></thead>' +
                '<tbody>' + rowsHtml + '</tbody>' +
            '</table>' +
            '</div>' +
            '<script>window.onload=function(){window.print();setTimeout(function(){window.close();},300);};<\/script>' +
            '</body></html>';

        printWindow.document.open();
        printWindow.document.write(html);
        printWindow.document.close();
    }

    window.closeAccountModal = function() {
        document.getElementById('partnerAccountModal').classList.add('hidden');
        document.getElementById('partnerAccountModal').classList.remove('flex');
        setPageScrollLock(false);
    };

    // Store current proof URL and filename for download
    var currentProofUrl = '';
    var currentProofFileName = '';

    window.openProofPreview = function(url, isImage, fileName) {
        var modal = document.getElementById('proofPreviewModal');
        var title = document.getElementById('proofPreviewTitle');
        var content = document.getElementById('proofPreviewContent');
        if (!modal || !content) return;

        // Store for download
        currentProofUrl = url;
        currentProofFileName = fileName || 'proof';

        title.textContent = fileName ? ('Proof Preview - ' + fileName) : 'Proof Preview';

        if (isImage) {
            content.innerHTML = '<div class="flex justify-center"><img src="' + url + '" alt="Proof Preview" class="max-w-full max-h-[70vh] object-contain rounded border"></div>';
        } else {
            content.innerHTML = '<iframe src="' + url + '" style="width:100%;height:70vh;border:1px solid #d1d5db;border-radius:0.5rem;background:#fff;" title="Proof Preview"></iframe>';
        }

        modal.classList.remove('hidden');
        modal.classList.add('flex');
        setPageScrollLock(true);
    };

    window.downloadProof = function() {
        if (!currentProofUrl) {
            showToast('error', 'No proof file to download');
            return;
        }

        var ext = currentProofFileName.split('.').pop();
        var partnerName = (window.currentPartnerAccount && window.currentPartnerAccount.partnerName)
            ? window.currentPartnerAccount.partnerName.replace(/[^a-zA-Z0-9]/g, '_')
            : 'partner';
        var downloadName = partnerName + '_partner_transaction_proof.' + ext;

        // Create a temporary link and trigger download
        var link = document.createElement('a');
        link.href = currentProofUrl;
        link.download = downloadName;
        link.target = '_blank';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        
        showToast('success', 'Downloading proof file...');
    };

    window.closeProofPreview = function() {
        var modal = document.getElementById('proofPreviewModal');
        var content = document.getElementById('proofPreviewContent');
        if (modal) {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }
        if (content) {
            content.innerHTML = '';
        }
        // Clear stored proof data
        currentProofUrl = '';
        currentProofFileName = '';
        setPageScrollLock(false);
    };

    // Function to update partner row in main table after transactions
    function updatePartnerRow(partnerId) {
        fetch('partners.php?action=get&id=' + partnerId + '&ajax=1', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(response) {
            if (!response.ok) throw new Error('Failed to fetch partner data');
            return response.json();
        })
        .then(function(data) {
            if (data.success && data.data) {
                var partner = data.data;
                
                // Update desktop table row
                var desktopRow = document.querySelector('tr[onclick*="viewPartnerAccount(' + partnerId + ',"]');
                if (desktopRow) {
                    var totalEarnedCell = desktopRow.querySelector('td:nth-child(4) span');
                    var remainingCell = desktopRow.querySelector('td:nth-child(5) span');
                    
                    if (totalEarnedCell) {
                        totalEarnedCell.textContent = 'Rs. ' + parseFloat(partner.total || 0).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
                    }
                    if (remainingCell) {
                        remainingCell.textContent = 'Rs. ' + parseFloat(partner.remaining || 0).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
                        // Update color based on remaining value
                        remainingCell.className = 'text-sm font-medium ' + ((partner.remaining > 0) ? 'text-slate-600 dark:text-slate-400' : 'text-slate-400 dark:text-slate-500');
                    }
                }
                
                // Update mobile card
                var mobileCard = document.querySelector('.partner-card[onclick*="viewPartnerAccount(' + partnerId + ',"]');
                if (mobileCard) {
                    var totalEarnedMobile = mobileCard.querySelector('div:nth-of-type(3) > div:nth-of-type(3) span.px-2');
                    var remainingMobile = mobileCard.querySelector('div:nth-of-type(3) > div:nth-of-type(4) span.text-xs.font-medium');
                    
                    if (totalEarnedMobile) {
                        totalEarnedMobile.textContent = 'Rs. ' + parseFloat(partner.total || 0).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
                    }
                    if (remainingMobile) {
                        remainingMobile.textContent = 'Rs. ' + parseFloat(partner.remaining || 0).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
                        // Update color based on remaining value
                        remainingMobile.className = 'text-xs font-medium ' + ((partner.remaining > 0) ? 'text-slate-600 dark:text-slate-400' : 'text-slate-400 dark:text-slate-500');
                    }
                }
            }
        })
        .catch(function(error) {
            console.error('Error updating partner row:', error);
        });
    }

    window.deletePartnerTx = function(txId, partnerId) {
        showConfirm('Delete this transaction? The balance will be automatically reversed.', function() {
            var fd = new FormData();
            fd.append('tx_id', txId);
            fd.append('partner_id', partnerId);
            fetch('partners.php?action=delete_partner_transaction&ajax=1', {
                method: 'POST',
                body: fd,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    showToast('success', data.message || 'Transaction deleted');
                    // Reload the account view
                    var nameEl = document.getElementById('accountPartnerName');
                    var partnerName = nameEl ? nameEl.textContent.replace(' - Account Statement', '') : '';
                    viewPartnerAccount(partnerId, partnerName);
                    // Update the partner row in main table
                    updatePartnerRow(partnerId);
                } else {
                    showToast('error', data.message || 'Failed to delete transaction');
                }
            })
            .catch(function(err) {
                showToast('error', 'Error: ' + err.message);
            });
        });
    };

    window.deleteProfit = function(profitId, partnerId) {
        showConfirm('Delete this profit record? The balance will be automatically reversed.', function() {
            var fd = new FormData();
            fd.append('profit_id', profitId);
            fd.append('partner_id', partnerId);
            fetch('partners.php?action=delete_profit&ajax=1', {
                method: 'POST',
                body: fd,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    showToast('success', data.message || 'Profit record deleted');
                    // Reload the account view
                    var nameEl = document.getElementById('accountPartnerName');
                    var partnerName = nameEl ? nameEl.textContent.replace(' - Account Statement', '') : '';
                    viewPartnerAccount(partnerId, partnerName);
                    // Update the partner row in main table
                    updatePartnerRow(partnerId);
                } else {
                    showToast('error', data.message || 'Failed to delete profit record');
                }
            })
            .catch(function(err) {
                showToast('error', 'Error: ' + err.message);
            });
        });
    };

    // Delete confirmation modal functions
    var deletePartnerId = null;
    var deletePartnerName = '';
    
    window.deletePartner = function(id, name) {
        console.log('deletePartner called with id:', id, 'name:', name); // Debug log
        deletePartnerId = id;
        deletePartnerName = name;
        document.getElementById('deletePartnerName').textContent = '"' + name + '"';
        document.getElementById('deleteConfirmModal').classList.remove('hidden');
        document.getElementById('deleteConfirmModal').classList.add('flex');
        setPageScrollLock(true);
    };

    window.closeDeleteModal = function() {
        document.getElementById('deleteConfirmModal').classList.add('hidden');
        document.getElementById('deleteConfirmModal').classList.remove('flex');
        setPageScrollLock(false);
        deletePartnerId = null;
        deletePartnerName = '';
    };
    
    window.confirmDelete = function() {
        if (!deletePartnerId) return;
        
        var formData = new FormData();
        fetch('partners.php?action=delete&id=' + deletePartnerId + '&ajax=1', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(response) {
            if (!response.ok) {
                throw new Error('HTTP Error: ' + response.status);
            }
            // Check if response is JSON
            var contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                return response.text().then(function(text) {
                    console.error('Expected JSON but got:', text.substring(0, 200));
                    throw new Error('Server returned HTML instead of JSON. Check console for details.');
                });
            }
            return response.json();
        })
        .then(function(data) {
            closeDeleteModal();
            
            // Check for session expiry or redirect
            if (data.redirect) {
                showToast('warning', data.message || 'Session expired. Please login again.');
                setTimeout(function() {
                    window.location.href = data.redirect;
                }, 800);
                return;
            }
            
            if (data.success) {
                showToast('success', 'Partner deleted successfully!');
                setTimeout(function() {
                    location.reload();
                }, 1500);
            } else {
                showToast('error', 'Error: ' + (data.message || 'Failed to delete partner'));
            }
        })
        .catch(function(error) {
            closeDeleteModal();
            console.error('Error:', error);
            showToast('error', 'Error while deleting: ' + error.message);
        });
    };


    window.markPaid = function(containerId) {
        showConfirm('Mark all profits for this container as paid?', function() {
            fetch('partners.php?action=complete&id=' + containerId + '&ajax=1', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(response) {
                if (!response.ok) {
                    throw new Error('HTTP Error: ' + response.status);
                }
                return response.json();
            })
            .then(function(data) {
                if (data.success) {
                    showToast('success', data.message || 'Profits marked as paid successfully');
                    location.reload();
                } else {
                    showToast('error', 'Error: ' + (data.message || 'Failed to mark profits as paid'));
                }
            })
            .catch(function(error) {
                console.error('Error:', error);
                showToast('error', 'Error marking profits as paid: ' + error.message);
            });
        });
    };

    // Form Handlers
    document.getElementById('addPartnerForm').addEventListener('submit', function(e) {
        e.preventDefault();
        var formData = new FormData(this);
        
        fetch('partners.php?action=add&ajax=1', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(response) {
            if (!response.ok) {
                throw new Error('HTTP Error: ' + response.status);
            }
            return response.json();
        })
        .then(function(data) {
            // Check for session expiry or redirect
            if (data.redirect) {
                showToast('warning', data.message || 'Session expired. Please login again.');
                setTimeout(function() {
                    window.location.href = data.redirect;
                }, 800);
                return;
            }
            
            if (data.success) {
                showToast('success', data.message || 'Partner added successfully');
                closeAddModal();
                location.reload();
            } else {
                showToast('error', 'Error: ' + (data.message || 'Failed to add partner'));
            }
        })
        .catch(function(error) {
            console.error('Error:', error);
            showToast('error', 'Error adding partner: ' + error.message);
        });
    });

    document.getElementById('editPartnerForm').addEventListener('submit', function(e) {
        e.preventDefault();
        var formData = new FormData(this);
        var id = document.getElementById('editPartnerId').value;
        
        fetch('partners.php?action=edit&id=' + id + '&ajax=1', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(response) {
            if (!response.ok) {
                throw new Error('HTTP Error: ' + response.status);
            }
            return response.json();
        })
        .then(function(data) {
            // Check for session expiry or redirect
            if (data.redirect) {
                showToast('warning', data.message || 'Session expired. Please login again.');
                setTimeout(function() {
                    window.location.href = data.redirect;
                }, 800);
                return;
            }
            
            if (data.success) {
                showToast('success', data.message || 'Partner updated successfully');
                closeEditModal();
                location.reload();
            } else {
                showToast('error', 'Error: ' + (data.message || 'Failed to update partner'));
            }
        })
        .catch(function(error) {
            console.error('Error:', error);
            showToast('error', 'Error updating partner: ' + error.message);
        });
    });

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


    // AJAX navigation handler using event delegation
    document.addEventListener('click', function(e) {
        var link = e.target.closest('.ajax-link');
        if (!link) return;
        
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
        var distributeSelect = document.getElementById('distributeContainerSelect');
        if (distributeSelect && !distributeSelect.dataset.bound) {
            distributeSelect.dataset.bound = 'true';
            distributeSelect.addEventListener('change', function() {
                var containerId = distributeSelect.value;
                if (!containerId) {
                    resetProfitSummary();
                    return;
                }
                loadContainerProfit(containerId);
            });
        }

        var distributeForm = document.getElementById('distributeProfitForm');
        if (distributeForm && !distributeForm.dataset.bound) {
            distributeForm.dataset.bound = 'true';
            distributeForm.addEventListener('submit', function(e) {
                e.preventDefault();

                var containerId = document.getElementById('distributeContainerSelect').value;
                if (!containerId) {
                    showToast('warning', 'Please select a container');
                    return;
                }

                showConfirm('Add profit to all partners for this container?', function() {
                    var formData = new FormData();
                    formData.append('container_id', containerId);

                    fetch('partners.php?action=distribute_profit&ajax=1', {
                        method: 'POST',
                        body: formData,
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    })
                    .then(function(response) {
                        if (!response.ok) {
                            throw new Error('HTTP Error: ' + response.status);
                        }
                        return response.json();
                    })
                    .then(function(data) {
                        if (data.redirect) {
                            showToast('warning', data.message || 'Session expired. Please login again.');
                            setTimeout(function() {
                                window.location.href = data.redirect;
                            }, 800);
                            return;
                        }

                        if (data.success) {
                            showToast('success', data.message || 'Profit distributed successfully');
                            closeDistributeProfitModal();
                            location.reload();
                        } else {
                            showToast('error', 'Error: ' + (data.message || 'Failed to distribute profit'));
                        }
                    })
                    .catch(function(error) {
                        console.error('Error:', error);
                        showToast('error', 'Error distributing profit: ' + error.message);
                    });
                });
            });
        }

        // All ajax-link handling is done via event delegation above
    }

    var downloadBtn = document.getElementById('downloadPartnerTransactions');
    if (downloadBtn) {
        downloadBtn.addEventListener('click', downloadPartnerTransactionHistory);
    }

    var printBtn = document.getElementById('printPartnerTransactions');
    if (printBtn) {
        printBtn.addEventListener('click', printPartnerTransactionHistory);
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

    // Close modals on outside click
    window.addEventListener('click', function(e) {
        if (e.target.id === 'addPartnerModal') {
            closeAddModal();
        }
        if (e.target.id === 'editPartnerModal') {
            closeEditModal();
        }
        if (e.target.id === 'deleteConfirmModal') {
            closeDeleteModal();
        }
        if (e.target.id === 'partnerAccountModal') {
            closeAccountModal();
        }
        if (e.target.id === 'proofPreviewModal') {
            closeProofPreview();
        }
        if (e.target.id === 'distributeProfitModal') {
            closeDistributeProfitModal();
        }
        if (e.target.id === 'confirmModal') {
            closeConfirmModal(false);
        }
    });

    // Close modals on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeAddModal();
            closeEditModal();
            closeDeleteModal();
            closeAccountModal();
            closeProofPreview();
            closeDistributeProfitModal();
            closeConfirmModal(false);
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
        var phoneFilter = document.getElementById('filterPhone').value.toLowerCase().trim();
        var addressFilter = document.getElementById('filterAddress').value.toLowerCase().trim();
        
        // Filter desktop table rows
        var tbody = document.getElementById('partnersTableBody');
        if (!tbody) {
            console.error('Partners table body not found');
            return;
        }
        
        var rows = tbody.querySelectorAll('tr');
        var visibleCount = 0;
        var totalCount = 0;
        
        rows.forEach(function(row) {
            // Skip the "no partners found" row
            if (row.cells.length < 5) {
                return;
            }
            
            totalCount++;
            
            // Get text content from cells
            var nameCell = row.cells[0].textContent.toLowerCase();
            var phoneAddressCell = row.cells[1].textContent.toLowerCase();
            
            // Check if row matches all active filters
            var nameMatch = !nameFilter || nameCell.includes(nameFilter);
            var phoneMatch = !phoneFilter || phoneAddressCell.includes(phoneFilter);
            var addressMatch = !addressFilter || phoneAddressCell.includes(addressFilter);
            
            if (nameMatch && phoneMatch && addressMatch) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });
        
        // Filter mobile cards
        var mobileCards = document.querySelectorAll('.partner-card');
        var mobileVisibleCount = 0;
        var mobileTotalCount = mobileCards.length;
        
        mobileCards.forEach(function(card) {
            var cardName = card.getAttribute('data-partner-name') || '';
            var cardPhone = card.getAttribute('data-partner-phone') || '';
            var cardAddress = card.getAttribute('data-partner-address') || '';
            
            // Check if card matches all active filters
            var nameMatch = !nameFilter || cardName.includes(nameFilter);
            var phoneMatch = !phoneFilter || cardPhone.includes(phoneFilter);
            var addressMatch = !addressFilter || cardAddress.includes(addressFilter);
            
            if (nameMatch && phoneMatch && addressMatch) {
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
        
        if (nameFilter || phoneFilter || addressFilter) {
            statsDiv.textContent = 'Showing ' + displayCount + ' of ' + displayTotal + ' partners';
        } else {
            statsDiv.textContent = '';
        }
    };
    
    window.clearFilters = function() {
        document.getElementById('filterName').value = '';
        document.getElementById('filterPhone').value = '';
        document.getElementById('filterAddress').value = '';
        applyFilters();
    };
})();
</script>

<?php
}
ob_end_flush(); // Flush output buffer
