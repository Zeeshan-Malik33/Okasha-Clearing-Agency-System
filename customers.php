<?php
require 'config/auth.php';
require 'config/database.php';
require 'config/constants.php';

/* ============================
   AUTO MIGRATION - Add rate column to containers if it doesn't exist
============================ */
$result = $conn->query("SHOW COLUMNS FROM containers LIKE 'rate'");
if ($result && $result->num_rows == 0) {
    $conn->query("ALTER TABLE containers ADD COLUMN rate decimal(10,2) DEFAULT 0.00 COMMENT 'Container rate' AFTER gross_weight");
}

/* ============================
   AUTO MIGRATION - Add rate column to customers if it doesn't exist
============================ */
$result = $conn->query("SHOW COLUMNS FROM customers LIKE 'rate'");
if ($result && $result->num_rows == 0) {
    $conn->query("ALTER TABLE customers ADD COLUMN rate decimal(10,2) DEFAULT 0.00 COMMENT 'Default container rate' AFTER remaining_amount");
}

/* ============================
   AUTO MIGRATION - Create container_transactions table if it doesn't exist
============================ */
$result = $conn->query("SHOW TABLES LIKE 'container_transactions'");
if ($result && $result->num_rows == 0) {
    $conn->query("CREATE TABLE container_transactions (
        id int(11) NOT NULL AUTO_INCREMENT,
        container_id int(11) NOT NULL,
        customer_id int(11) DEFAULT NULL,
        transaction_type enum('credit','debit') NOT NULL DEFAULT 'credit',
        amount decimal(10,2) NOT NULL DEFAULT 0.00,
        description varchar(255) DEFAULT NULL,
        reference_number varchar(100) DEFAULT NULL,
        payment_method varchar(50) DEFAULT NULL,
        transaction_date date NOT NULL,
        proof varchar(255) DEFAULT NULL COMMENT 'File name of uploaded proof document',
        created_at timestamp NOT NULL DEFAULT current_timestamp(),
        created_by int(11) DEFAULT NULL,
        PRIMARY KEY (id),
        KEY idx_container_id (container_id),
        KEY idx_customer_id (customer_id),
        KEY idx_transaction_date (transaction_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

/* ============================
   AUTO MIGRATION - Add proof column to container_transactions if it doesn't exist
============================ */
$tableCheck = $conn->query("SHOW TABLES LIKE 'container_transactions'");
if ($tableCheck && $tableCheck->num_rows > 0) {
    $result = $conn->query("SHOW COLUMNS FROM container_transactions LIKE 'proof'");
    if ($result && $result->num_rows == 0) {
        $conn->query("ALTER TABLE container_transactions ADD COLUMN proof varchar(255) DEFAULT NULL COMMENT 'File name of uploaded proof document' AFTER transaction_date");
    }
}

// Create upload directory if it doesn't exist
$upload_dir = __DIR__ . '/uploads/transaction_proofs/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

/* ============================
   AUTO FIX - Correct container status mismatches
   (containers marked 'completed' but invoice amount > total paid)
============================ */
$conn->query("
    UPDATE containers
    SET status = 'pending'
    WHERE status = 'completed'
      AND total_amount > 0
      AND total_paid < total_amount
");

/* ============================
   HELPER FUNCTION - Update Customer Account Totals
============================ */
function updateCustomerAccountTotals($customerId, $conn) {
    // Calculate total invoiced amount from all containers of this customer
    $invoiceQuery = $conn->prepare("
        SELECT COALESCE(SUM(i.invoice_amount), 0) as total_invoiced
        FROM invoices i
        JOIN containers c ON c.id = i.container_id
        WHERE c.customer_id = ?
    ");
    $invoiceQuery->bind_param("i", $customerId);
    $invoiceQuery->execute();
    $invoiceResult = $invoiceQuery->get_result()->fetch_assoc();
    $totalInvoiced = $invoiceResult ? (float)$invoiceResult['total_invoiced'] : 0;

    // Calculate total paid from all containers of this customer
    $paidQuery = $conn->prepare("
        SELECT COALESCE(SUM(ct.amount), 0) as total_paid
        FROM container_transactions ct
        JOIN containers c ON c.id = ct.container_id
        WHERE c.customer_id = ? AND ct.transaction_type = 'credit'
    ");
    $paidQuery->bind_param("i", $customerId);
    $paidQuery->execute();
    $paidResult = $paidQuery->get_result()->fetch_assoc();
    $totalPaid = $paidResult ? (float)$paidResult['total_paid'] : 0;

    // Calculate remaining amount
    $remainingAmount = $totalInvoiced - $totalPaid;

    // Update customer account with calculated totals
    $updateQuery = $conn->prepare("
        UPDATE customers
        SET total_invoiced = ?, total_paid = ?, remaining_amount = ?
        WHERE id = ?
    ");
    $updateQuery->bind_param("dddi", $totalInvoiced, $totalPaid, $remainingAmount, $customerId);
    $updateQuery->execute();

    return [
        'total_invoiced' => $totalInvoiced,
        'total_paid' => $totalPaid,
        'remaining_amount' => $remainingAmount
    ];
}

$action = $_GET['action'] ?? 'list';
$id     = $_GET['id'] ?? null;
$isAdmin = ($_SESSION['role'] === 'admin');
$isAjax  = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

$systemSettings = getSystemSettings();
$companyDetails = [
    'name' => $systemSettings['system_name'] ?: APP_NAME,
    'location' => $systemSettings['system_location'] ?: '',
    'contact' => $systemSettings['system_contact'] ?: '',
    'email' => $systemSettings['system_email'] ?: '',
    'logo' => !empty($systemSettings['system_logo']) ? BASE_URL . 'uploads/system/' . $systemSettings['system_logo'] : ''
];

/* ============================
   HANDLE DELETE CUSTOMER
============================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin && $action === 'delete') {
    $customer_id = (int)$_POST['id'];

    try {
        $conn->begin_transaction();

        $runDelete = function($sql) use ($conn) {
            $result = $conn->query($sql);
            if ($result === false) {
                throw new Exception('Delete query failed: ' . $conn->error);
            }
        };

        // Collect related uploaded files before deleting DB records.
        $invoiceFiles = [];
        $expenseProofFiles = [];
        $transactionProofFiles = [];

        $invoiceFileQuery = $conn->query("SELECT invoice_file FROM containers WHERE customer_id = $customer_id AND invoice_file IS NOT NULL AND invoice_file != ''");
        if ($invoiceFileQuery) {
            while ($row = $invoiceFileQuery->fetch_assoc()) {
                $invoiceFiles[] = $row['invoice_file'];
            }
        }

        $expenseProofQuery = $conn->query("SELECT ce.proof FROM container_expenses ce JOIN containers c ON c.id = ce.container_id WHERE c.customer_id = $customer_id AND ce.proof IS NOT NULL AND ce.proof != ''");
        if ($expenseProofQuery) {
            while ($row = $expenseProofQuery->fetch_assoc()) {
                $expenseProofFiles[] = $row['proof'];
            }
        }

        $transactionProofQuery = $conn->query("SELECT ct.proof FROM container_transactions ct JOIN containers c ON c.id = ct.container_id WHERE c.customer_id = $customer_id AND ct.proof IS NOT NULL AND ct.proof != ''");
        if ($transactionProofQuery) {
            while ($row = $transactionProofQuery->fetch_assoc()) {
                $transactionProofFiles[] = $row['proof'];
            }
        }

        // Delete all container-linked records first.
        $runDelete("DELETE FROM agent_transactions WHERE container_id IN (SELECT id FROM containers WHERE customer_id = $customer_id)");
        $runDelete("DELETE FROM partner_profits WHERE container_id IN (SELECT id FROM containers WHERE customer_id = $customer_id)");
        $runDelete("DELETE FROM container_transactions WHERE customer_id = $customer_id OR container_id IN (SELECT id FROM containers WHERE customer_id = $customer_id)");
        $runDelete("DELETE FROM container_expenses WHERE container_id IN (SELECT id FROM containers WHERE customer_id = $customer_id)");
        $runDelete("DELETE FROM invoices WHERE customer_id = $customer_id OR container_id IN (SELECT id FROM containers WHERE customer_id = $customer_id)");
        $runDelete("DELETE FROM containers WHERE customer_id = $customer_id");

        // Finally delete customer record.
        $customerDeleteResult = $conn->query("DELETE FROM customers WHERE id = $customer_id");
        if (!$customerDeleteResult) {
            throw new Exception('Failed to delete customer: ' . $conn->error);
        }

        $conn->commit();

        // Cleanup uploaded files after successful DB commit.
        foreach (array_unique($invoiceFiles) as $file) {
            $path = __DIR__ . '/uploads/invoices/' . basename($file);
            if (is_file($path)) {
                @unlink($path);
            }
        }
        foreach (array_unique($expenseProofFiles) as $file) {
            $path = __DIR__ . '/uploads/container_expenses/' . basename($file);
            if (is_file($path)) {
                @unlink($path);
            }
        }
        foreach (array_unique($transactionProofFiles) as $file) {
            $path = __DIR__ . '/uploads/transaction_proofs/' . basename($file);
            if (is_file($path)) {
                @unlink($path);
            }
        }

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Customer and all related container records deleted successfully']);
            exit;
        }
        header("Location: customers.php");
        exit;
    } catch (Exception $e) {
        $conn->rollback();

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Failed to delete customer and related records: ' . $e->getMessage()]);
            exit;
        }

        header("Location: customers.php?error=" . urlencode($e->getMessage()));
        exit;
    }
}

/* ============================
   HANDLE GET CUSTOMER DATA
============================ */
if ($action === 'get' && $id && $isAjax) {
    $stmt = $conn->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $customer = $stmt->get_result()->fetch_assoc();
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'customer' => $customer]);
    exit;
}

/* ============================
   HANDLE GET CUSTOMER ACCOUNT
============================ */
if ($action === 'account' && $id && $isAjax) {
    // Get customer details with account totals
    $stmt = $conn->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $customer = $stmt->get_result()->fetch_assoc();
    
    // Get account summary from customers table
    $account = array(
        'total_amount' => $customer['total_invoiced'],
        'total_paid' => $customer['total_paid'],
        'remaining_balance' => $customer['remaining_amount']
    );
    
    // Get transactions
    $transactions = [];
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true, 
        'customer' => $customer,
        'account' => $account,
        'transactions' => []
    ]);
    exit;
}

/* ============================
   HANDLE GET CUSTOMER CONTAINERS
============================ */
if ($action === 'get_containers' && $id && $isAjax) {
    $stmt = $conn->prepare("
        SELECT 
            c.id, 
            c.container_number, 
            c.bl_number, 
            c.invoice_file,
            c.HS_code, 
            c.net_weight, 
            c.gross_weight, 
            c.status, 
            c.created_at,
            COALESCE(c.rate, 0) as rate,
            COALESCE(i.gross_weight, c.gross_weight, c.net_weight, 0) as selected_weight,
            COALESCE(i.invoice_amount, 0) as total_amount,
            COALESCE((SELECT SUM(ct.amount) 
                      FROM container_transactions ct 
                      WHERE ct.container_id = c.id 
                      AND ct.transaction_type = 'credit'), 0) as total_paid,
            COALESCE(i.invoice_amount, 0) - COALESCE((SELECT SUM(ct.amount) 
                                                       FROM container_transactions ct 
                                                       WHERE ct.container_id = c.id 
                                                       AND ct.transaction_type = 'credit'), 0) as remaining_balance
        FROM containers c
        LEFT JOIN invoices i ON i.container_id = c.id
        WHERE c.customer_id = ?
        ORDER BY c.created_at DESC, c.id DESC
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $containers = [];
    while ($row = $result->fetch_assoc()) {
        $containers[] = $row;
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'containers' => $containers]);
    exit;
}

/* ============================
   HANDLE GET CONTAINER ACCOUNT
============================ */
if ($action === 'container_account' && $id && $isAjax) {
    $containerId = (int)$id;

    $stmt = $conn->prepare("SELECT c.*, cu.name as customer_name FROM containers c LEFT JOIN customers cu ON cu.id = c.customer_id WHERE c.id = ?");
    $stmt->bind_param("i", $containerId);
    $stmt->execute();
    $container = $stmt->get_result()->fetch_assoc();

    if (!$container) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Container not found']);
        exit;
    }

    // Read totals directly from container table columns
    $totalAmount = (float)($container['total_amount'] ?? 0);
    $paidAmount = (float)($container['total_paid'] ?? 0);
    $remainingAmount = (float)($container['remaining_amount'] ?? 0);

    $transactions = [];
    $txStmt = $conn->prepare("SELECT id, container_id, transaction_type, amount, description, reference_number, payment_method, transaction_date, proof FROM container_transactions WHERE container_id = ? ORDER BY transaction_date DESC, id DESC");
    $txStmt->bind_param("i", $containerId);
    $txStmt->execute();
    $txResult = $txStmt->get_result();
    while ($tx = $txResult->fetch_assoc()) {
        $transactions[] = $tx;
    }

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'container' => $container,
        'account' => [
            'total_amount' => $totalAmount,
            'total_paid' => $paidAmount,
            'remaining_balance' => $remainingAmount
        ],
        'transactions' => $transactions
    ]);
    exit;
}

/* ============================
   HANDLE ADD CONTAINER TRANSACTION
============================ */
if ($action === 'add_container_transaction' && $_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax) {
    if (!$isAdmin) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Unauthorized: Admin access required']);
        exit;
    }

    $containerId = (int)($_POST['container_id'] ?? 0);
    $transactionId = (int)($_POST['transaction_id'] ?? 0);
    $customerId = (int)($_POST['customer_id'] ?? 0);
    $transactionType = $_POST['transaction_type'] ?? 'credit';
    $amount = (float)($_POST['amount'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $referenceNumber = trim($_POST['reference_number'] ?? '');
    $paymentMethod = trim($_POST['payment_method'] ?? '');
    $transactionDate = $_POST['transaction_date'] ?? date('Y-m-d');
    $createdBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

    if (!$containerId || $amount <= 0) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Container and valid amount are required']);
        exit;
    }

    if (!in_array($transactionType, ['credit', 'debit'], true)) {
        $transactionType = 'credit';
    }

    // Handle proof file upload
    $proofFile = null;
    if ($transactionId > 0) {
        // Get existing proof file if editing
        $existingQuery = $conn->query("SELECT proof FROM container_transactions WHERE id = $transactionId");
        if ($existingQuery && $row = $existingQuery->fetch_assoc()) {
            $proofFile = $row['proof'];
        }
    }

    if (!empty($_FILES['proof']['name'])) {
        $ext = strtolower(pathinfo($_FILES['proof']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'])) {
            $proofFile = 'container_tx_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
            move_uploaded_file($_FILES['proof']['tmp_name'], __DIR__ . '/uploads/transaction_proofs/' . $proofFile);
        }
    }

    if ($transactionId > 0) {
        $stmt = $conn->prepare("UPDATE container_transactions SET transaction_type = ?, amount = ?, description = ?, reference_number = ?, payment_method = ?, transaction_date = ?, proof = ? WHERE id = ? AND container_id = ?");
        $stmt->bind_param("sdsssssii", $transactionType, $amount, $description, $referenceNumber, $paymentMethod, $transactionDate, $proofFile, $transactionId, $containerId);
    } else {
        $stmt = $conn->prepare("INSERT INTO container_transactions (container_id, customer_id, transaction_type, amount, description, reference_number, payment_method, transaction_date, proof, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iisdsssssi", $containerId, $customerId, $transactionType, $amount, $description, $referenceNumber, $paymentMethod, $transactionDate, $proofFile, $createdBy);
    }

    if ($stmt->execute()) {
        // Check if container is fully paid and update status to completed
        $invoiceStmt = $conn->prepare("SELECT COALESCE(invoice_amount, 0) as invoice_amount FROM invoices WHERE container_id = ? ORDER BY id DESC LIMIT 1");
        $invoiceStmt->bind_param("i", $containerId);
        $invoiceStmt->execute();
        $invoiceResult = $invoiceStmt->get_result()->fetch_assoc();
        $totalAmount = $invoiceResult ? (float)$invoiceResult['invoice_amount'] : 0;

        $paidStmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as total_paid FROM container_transactions WHERE container_id = ? AND transaction_type = 'credit'");
        $paidStmt->bind_param("i", $containerId);
        $paidStmt->execute();
        $paidResult = $paidStmt->get_result()->fetch_assoc();
        $totalPaid = $paidResult ? (float)$paidResult['total_paid'] : 0;

        // Update container totals (total_amount, total_paid, remaining_amount)
        $remainingAmount = $totalAmount - $totalPaid;
        $updateContainerTotals = $conn->prepare("
            UPDATE containers 
            SET total_amount = ?, 
                total_paid = ?, 
                remaining_amount = ? 
            WHERE id = ?
        ");
        $updateContainerTotals->bind_param("dddi", $totalAmount, $totalPaid, $remainingAmount, $containerId);
        $updateContainerTotals->execute();

        // If fully paid, update container status to completed
        if ($totalAmount > 0 && $totalPaid >= $totalAmount) {
            $updateStatus = $conn->prepare("UPDATE containers SET status = 'completed' WHERE id = ? AND status != 'completed'");
            $updateStatus->bind_param("i", $containerId);
            $updateStatus->execute();
        }

        // Update customer account totals with all their container transactions
        if ($customerId > 0) {
            updateCustomerAccountTotals($customerId, $conn);
        }

        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => ($transactionId > 0 ? 'Container transaction updated successfully' : 'Container transaction added successfully')]);
        exit;
    }

    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Failed to add container transaction']);
    exit;
}

/* ============================
   HANDLE DELETE CONTAINER TRANSACTION
============================ */
if ($action === 'delete_container_transaction' && $_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax) {
    if (!$isAdmin) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Unauthorized: Admin access required']);
        exit;
    }

    $transactionId = (int)($_POST['transaction_id'] ?? 0);
    $containerId = (int)($_POST['container_id'] ?? 0);

    if (!$transactionId || !$containerId) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid transaction request']);
        exit;
    }

    $stmt = $conn->prepare("DELETE FROM container_transactions WHERE id = ? AND container_id = ?");
    $stmt->bind_param("ii", $transactionId, $containerId);

    if ($stmt->execute()) {
        // Get customer ID for this container
        $customerQuery = $conn->prepare("SELECT customer_id FROM containers WHERE id = ?");
        $customerQuery->bind_param("i", $containerId);
        $customerQuery->execute();
        $containerResult = $customerQuery->get_result()->fetch_assoc();
        $customerId = $containerResult ? (int)$containerResult['customer_id'] : 0;

        // Recalculate and update container totals
        $totalsQuery = $conn->prepare("
            SELECT 
                COALESCE((SELECT SUM(invoice_amount) FROM invoices WHERE container_id = ?), 0) as invoice_total,
                COALESCE((SELECT SUM(amount) FROM container_transactions WHERE container_id = ? AND transaction_type = 'credit'), 0) as paid_total
        ");
        $totalsQuery->bind_param("ii", $containerId, $containerId);
        $totalsQuery->execute();
        $totalsResult = $totalsQuery->get_result()->fetch_assoc();
        $totalAmount = (float)$totalsResult['invoice_total'];
        $totalPaid = (float)$totalsResult['paid_total'];
        $remainingAmount = $totalAmount - $totalPaid;
        
        $updateContainerTotals = $conn->prepare("
            UPDATE containers 
            SET total_amount = ?, 
                total_paid = ?, 
                remaining_amount = ? 
            WHERE id = ?
        ");
        $updateContainerTotals->bind_param("dddi", $totalAmount, $totalPaid, $remainingAmount, $containerId);
        $updateContainerTotals->execute();

        // Update customer account totals after deletion
        if ($customerId > 0) {
            updateCustomerAccountTotals($customerId, $conn);
        }

        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Container transaction deleted successfully']);
        exit;
    }

    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Failed to delete container transaction']);
    exit;
}

/* ============================
   HANDLE GET DAILY EXPENSES
============================ */
if ($action === 'get_daily_expenses' && $isAjax) {
    $expenses = [];
    $result = $conn->query("SELECT id, name, amount, description, location, reason, expense_date FROM daily_expenses ORDER BY expense_date ASC, id ASC");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $expenses[] = $row;
        }
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'expenses' => $expenses]);
    exit;
}

/* ============================
   HANDLE GET AGENTS LIST
============================ */
if ($action === 'get_agents' && $isAjax) {
    $agents = [];
    $result = $conn->query("SELECT id, name FROM agents ORDER BY name ASC");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $agents[] = $row;
        }
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'agents' => $agents]);
    exit;
}

/* ============================
   HANDLE ADD CONTAINER FROM CUSTOMER PROFILE
============================ */
if ($action === 'add_container' && $_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax) {
    if (!$isAdmin) {
        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Unauthorized: Admin access required']);
        exit;
    }
    
    // Debug logging
    error_log("Add container request received");
    error_log("POST data: " . print_r($_POST, true));
    
    $customer_id  = (int)($_POST['customer_id'] ?? 0);
    $bl_number    = trim($_POST['bl_number'] ?? '');
    $container_no = trim($_POST['container_number'] ?? '');
    $hs_code      = trim($_POST['hs_code'] ?? '');
    $tp_no        = trim($_POST['tp_no'] ?? '');
    $packages     = (int)($_POST['packages'] ?? 0);
    $gd_no        = trim($_POST['gd_no'] ?? '');
    $destination  = trim($_POST['destination'] ?? '');
    $port         = trim($_POST['port'] ?? '');
    $net_weight   = (float)($_POST['net_weight'] ?? 0);
    $gross_weight = (float)($_POST['gross_weight'] ?? 0);
    $rate         = (float)($_POST['rate'] ?? 0);
    $status       = 'pending'; // Always set to pending for new containers
    $created_date = date('Y-m-d');
    
    error_log("Parsed data - customer_id: $customer_id, container_no: $container_no, rate: $rate");
    
    if (!$customer_id || !$container_no) {
        error_log("Validation failed - missing customer_id or container_no");
        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Customer and container number are required']);
        exit;
    }
    
    // If no rate provided, get customer's default rate
    if ($rate == 0) {
        $rateQuery = $conn->query("SELECT rate FROM customers WHERE id = $customer_id");
        if ($rateQuery && $rateQuery->num_rows > 0) {
            $rateRow = $rateQuery->fetch_assoc();
            $rate = (float)$rateRow['rate'];
        }
    }

    // Handle invoice file upload
    $invoiceFile = null;
    if (!empty($_FILES['invoice']['name'])) {
        $ext = strtolower(pathinfo($_FILES['invoice']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['pdf','jpg','jpeg','png'])) {
            $targetDir = "uploads/invoices/";
            if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
            $invoiceFile = time() . "_$container_no.$ext";
            move_uploaded_file(
                $_FILES['invoice']['tmp_name'],
                $targetDir . $invoiceFile
            );
        }
    }

    $newContainerId = getNextReusableId($conn, 'containers');
    $stmt = $conn->prepare("
        INSERT INTO containers
        (id, customer_id, bl_number, container_number, HS_code, tp_no, packages, gd_no, destination, port, net_weight, gross_weight, rate, invoice_file, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    if (!$stmt) {
        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Database prepare error: ' . $conn->error]);
        exit;
    }
    
    $stmt->bind_param(
        "iissssisssdddsss",
        $newContainerId,
        $customer_id,
        $bl_number,
        $container_no,
        $hs_code,
        $tp_no,
        $packages,
        $gd_no,
        $destination,
        $port,
        $net_weight,
        $gross_weight,
        $rate,
        $invoiceFile,
        $status,
        $created_date
    );
    
    if ($stmt->execute()) {
        error_log("Container inserted successfully with ID: $newContainerId");
        
        // Auto-create invoice for the container
        $invoiceNumber = 'INV-' . str_pad($newContainerId, 5, '0', STR_PAD_LEFT);
        $invoiceDate = date('Y-m-d');
        $invoiceAmount = $gross_weight * $rate;
        
        // Get total expenses for this container (should be 0 for new container)
        $totalExpensesResult = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM container_expenses WHERE container_id = $newContainerId");
        $totalExpensesRow = $totalExpensesResult ? $totalExpensesResult->fetch_assoc() : ['total' => 0];
        $totalExpenses = (float)$totalExpensesRow['total'];
        $netPayable = $invoiceAmount - $totalExpenses;
        
        // Create invoice
        $newInvoiceId = getNextReusableId($conn, 'invoices');
        $invoiceStmt = $conn->prepare("
            INSERT INTO invoices 
            (id, container_id, customer_id, container_number, invoice_number, gross_weight, rate, invoice_amount, total_expenses, net_payable, invoice_date, added_to_account)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
        ");
        $invoiceStmt->bind_param(
            "iiisssdddds",
            $newInvoiceId,
            $newContainerId,
            $customer_id,
            $container_no,
            $invoiceNumber,
            $gross_weight,
            $rate,
            $invoiceAmount,
            $totalExpenses,
            $netPayable,
            $invoiceDate
        );
        
        if ($invoiceStmt->execute()) {
            error_log("Invoice created automatically for container ID: $newContainerId");
            
            // Update container totals (total_amount, total_paid, remaining_amount)
            $updateContainerStmt = $conn->prepare("
                UPDATE containers 
                SET total_amount = ?, 
                    total_paid = 0, 
                    remaining_amount = ? 
                WHERE id = ?
            ");
            $updateContainerStmt->bind_param("ddi", $invoiceAmount, $invoiceAmount, $newContainerId);
            $updateContainerStmt->execute();
            error_log("Container totals updated for container ID: $newContainerId");
            
            // Update customer account totals with the new invoice
            updateCustomerAccountTotals($customer_id, $conn);
            error_log("Customer account updated for customer ID: $customer_id");
        } else {
            error_log("Failed to create invoice: " . $invoiceStmt->error);
        }
        
        // Clean any output buffer to ensure pure JSON response
        if (ob_get_length()) ob_clean();
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Container added successfully with invoice', 'container_id' => $newContainerId]);
        exit;
    } else {
        error_log("SQL execution failed: " . $stmt->error);
        
        // Clean any output buffer to ensure pure JSON response
        if (ob_get_length()) ob_clean();
        
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Failed to add container: ' . $stmt->error]);
        exit;
    }
}

/* ============================
   HANDLE GET CUSTOMER INVOICES
============================ */
if ($action === 'get_invoices' && $id && $isAjax) {
    $stmt = $conn->prepare("
        SELECT i.id, i.invoice_number, i.invoice_amount, i.invoice_date,
               i.added_to_account, c.container_number
        FROM invoices i
        JOIN containers c ON c.id = i.container_id
        WHERE i.customer_id = ?
        ORDER BY i.invoice_date DESC, i.id DESC
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $invoices = [];
    while ($row = $result->fetch_assoc()) {
        $invoices[] = $row;
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'invoices' => $invoices]);
    exit;
}

/* ============================
   HANDLE ADD TRANSACTION
============================ */
// Handled in containers.php for container transactions

/* ============================
   HANDLE EDIT TRANSACTION
============================ */
// Handled in containers.php for container transactions

/* ============================
   HANDLE DELETE TRANSACTION
============================ */
// Handled in containers.php for container transactions

/* ============================
   HANDLE CREATE CUSTOMER
============================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin && $action === 'add') {
    $name  = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    $newCustomerId = getNextReusableId($conn, 'customers');
    $stmt = $conn->prepare("
        INSERT INTO customers (id, name, email, phone, notes)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("issss", $newCustomerId, $name, $email, $phone, $notes);
    $stmt->execute();


    
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    }
    header("Location: customers.php");
    exit;
}

/* ============================
   HANDLE EDIT CUSTOMER
============================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin && $action === 'edit') {
    $customer_id = (int)($_POST['customer_id'] ?? 0);
    $name  = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    $stmt = $conn->prepare("
        UPDATE customers 
        SET name=?, email=?, phone=?, notes=?
        WHERE id=?
    ");
    $stmt->bind_param("ssssi", $name, $email, $phone, $notes, $customer_id);
    $stmt->execute();
    
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    }
    header("Location: customers.php");
    exit;
}

if (!$isAjax) {
    include 'include/header.php';
    include 'include/sidebar.php';
}

if ($isAjax) {
    ob_start();
}
?>
<div id="mobileSidebarOverlay" class="md:hidden" onclick="closeMobileSidebar()"></div>

<!-- Top Bar - Responsive Header -->
<header class="sticky top-0 z-40 w-full bg-white/80 dark:bg-slate-900/80 backdrop-blur-md border-b border-slate-200 dark:border-slate-800">
    <div class="flex items-center justify-between px-4 md:px-8 h-16">
        <!-- Mobile Menu Button -->
        <button type="button" onclick="toggleMobileSidebar()" class="flex md:hidden items-center justify-center h-11 w-11 rounded-lg hover:bg-slate-200/50 dark:hover:bg-slate-800/50 transition-colors" aria-label="Open navigation menu">
            <span class="material-symbols-outlined text-slate-700 dark:text-slate-300">menu</span>
        </button>
        
        <!-- Title Section -->
        <div class="flex items-center gap-3 flex-1 md:flex-initial">
            <h2 class="text-lg md:text-xl font-bold tracking-tight">Customers</h2>
            <div class="hidden lg:flex items-center px-3 py-1 bg-slate-100 dark:bg-slate-800 rounded-full text-[10px] font-bold text-slate-500 uppercase tracking-wider">
                <span class="material-symbols-outlined text-xs mr-1">badge</span> MANAGEMENT
            </div>
        </div>
        
        <!-- Desktop Actions -->
        <div class="hidden lg:flex items-center gap-4 flex-1 justify-end">
            <?php if ($isAdmin): ?>
            <button onclick="openCustomerModal()" class="bg-primary text-white px-4 py-2 rounded-lg text-sm font-bold flex items-center gap-2 shadow-lg shadow-primary/20 hover:scale-[1.02] active:scale-[0.98] transition-all">
                <span class="material-symbols-outlined text-lg">person_add</span>
                <span>Add Customer</span>
            </button>
            <?php endif; ?>
        </div>
        
        <!-- Mobile/Tablet Actions -->
        <div class="flex items-center gap-2">
            <?php if ($isAdmin): ?>
            <button onclick="openCustomerModal()" class="lg:hidden bg-primary text-white h-11 px-4 rounded-lg font-bold flex items-center gap-2 shadow-lg shadow-primary/20 active:scale-[0.95] transition-all">
                <span class="material-symbols-outlined">person_add</span>
                <span class="hidden sm:inline text-sm">Add</span>
            </button>
            <?php endif; ?>
        </div>
    </div>
</header>

<!-- Page Body - Responsive Container -->
<div class="p-4 md:p-6 lg:p-8 pb-20 md:pb-8">

<!-- CUSTOMER MODAL - Responsive -->
<div id="customerModal" class="hidden fixed top-0 left-0 right-0 bottom-0 bg-black/60 backdrop-blur-sm z-[120] flex items-center justify-center p-4 animate-fadeIn" style="min-height: 100vh;">
    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-md mx-auto overflow-hidden transform transition-all animate-slideIn max-h-[90vh] flex flex-col">
        <!-- Modal Header -->
        <div class="bg-gradient-to-r from-indigo-600 via-blue-600 to-purple-600 text-white px-5 py-3">
            <div class="flex justify-between items-center">
                <h3 id="modalTitle" class="text-lg font-bold flex items-center gap-2">
                    <span class="material-symbols-outlined">person_add</span>
                    Add Customer
                </h3>
                <button type="button" onclick="closeCustomerModal()" class="bg-blue-500 hover:bg-blue-600 text-white rounded-lg p-1.5 transition-all shadow-md">
                    <span class="material-symbols-outlined text-xl">close</span>
                </button>
            </div>
        </div>
        
        <!-- Modal Body -->
        <form id="customerForm" method="POST" action="customers.php" class="p-5 space-y-4">
            <input type="hidden" name="customer_id" id="customer_id" value="">
            <input type="hidden" name="action" id="formAction" value="add">
            
            <!-- Customer Name -->
            <div class="space-y-1.5">
                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300">
                    <span class="flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-base">person</span>
                        Customer Name
                    </span>
                </label>
                <input type="text" name="name" required 
                    class="w-full px-3 py-2 border-2 border-slate-200 dark:border-slate-700 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent dark:bg-slate-800 dark:text-white transition-all text-sm"
                    placeholder="Enter customer name">
            </div>
            
            <!-- Email -->
            <div class="space-y-1.5">
                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300">
                    <span class="flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-base">email</span>
                        Email Address
                    </span>
                </label>
                <input type="email" name="email" 
                    class="w-full px-3 py-2 border-2 border-slate-200 dark:border-slate-700 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent dark:bg-slate-800 dark:text-white transition-all text-sm"
                    placeholder="customer@example.com">
            </div>
            
            <!-- Phone -->
            <div class="space-y-1.5">
                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300">
                    <span class="flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-base">phone</span>
                        Phone Number
                    </span>
                </label>
                <input type="tel" name="phone" 
                    class="w-full px-3 py-2 border-2 border-slate-200 dark:border-slate-700 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent dark:bg-slate-800 dark:text-white transition-all text-sm"
                    placeholder="+1 (555) 000-0000">
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
                <button type="button" onclick="closeCustomerModal()" 
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

<!-- Container Details Modal (Persistent) -->
<div id="customerDetailsModal" class="hidden fixed top-0 left-0 right-0 bottom-0 bg-black/60 backdrop-blur-sm z-[100] flex items-center justify-center p-4 animate-fadeIn" style="min-height: 100vh;">
    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-4xl mx-4 max-h-[90vh] overflow-hidden flex flex-col transform transition-all animate-slideIn">
        <!-- Modal Header -->
        <div class="bg-gradient-to-r from-violet-600 via-purple-600 to-indigo-600 text-white px-6 py-4">
            <div class="flex justify-between items-center">
                <h3 class="text-xl font-bold flex items-center gap-2">
                    <span class="material-symbols-outlined">inventory_2</span>
                    Container Details
                </h3>
                <button onclick="closeDetailsModal()" class="bg-blue-500 hover:bg-blue-600 text-white rounded-lg p-2 transition-all shadow-md">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
        </div>
        
        <!-- Modal Body -->
        <div class="p-6 overflow-y-auto space-y-6">
            <!-- Container Info Card -->
            <div class="bg-gradient-to-br from-blue-50 to-indigo-50 dark:from-slate-800 dark:to-slate-700 rounded-xl p-6 border border-blue-100 dark:border-slate-600">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="space-y-1">
                        <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Container Number</p>
                        <p id="customerDetailContainer" class="text-2xl font-bold text-slate-900 dark:text-white">-</p>
                    </div>
                    <div class="space-y-1">
                        <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">BL Number</p>
                        <p id="customerDetailBL" class="text-lg font-semibold text-slate-700 dark:text-slate-300">-</p>
                    </div>
                </div>
            </div>
            
            <!-- Details Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <div class="bg-white dark:bg-slate-800 rounded-xl p-4 border border-slate-200 dark:border-slate-700 shadow-sm">
                    <div class="flex items-start gap-3">
                        <div class="p-2 bg-green-100 dark:bg-green-900/30 rounded-lg">
                            <span class="material-symbols-outlined text-green-600 dark:text-green-400">shopping_bag</span>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Net Weight</p>
                            <p id="customerDetailNetWeight" class="text-lg font-bold text-slate-900 dark:text-white">0</p>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white dark:bg-slate-800 rounded-xl p-4 border border-slate-200 dark:border-slate-700 shadow-sm">
                    <div class="flex items-start gap-3">
                        <div class="p-2 bg-blue-100 dark:bg-blue-900/30 rounded-lg">
                            <span class="material-symbols-outlined text-blue-600 dark:text-blue-400">scale</span>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Gross Weight</p>
                            <p id="customerDetailGrossWeight" class="text-lg font-bold text-slate-900 dark:text-white">0</p>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white dark:bg-slate-800 rounded-xl p-4 border border-slate-200 dark:border-slate-700 shadow-sm">
                    <div class="flex items-start gap-3">
                        <div class="p-2 bg-purple-100 dark:bg-purple-900/30 rounded-lg">
                            <span class="material-symbols-outlined text-purple-600 dark:text-purple-400">receipt_long</span>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Status</p>
                            <p id="customerDetailStatus" class="text-sm font-semibold">-</p>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white dark:bg-slate-800 rounded-xl p-4 border border-slate-200 dark:border-slate-700 shadow-sm">
                    <div class="flex items-start gap-3">
                        <div class="p-2 bg-orange-100 dark:bg-orange-900/30 rounded-lg">
                            <span class="material-symbols-outlined text-orange-600 dark:text-orange-400">attach_money</span>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Total Expenses</p>
                            <p id="customerDetailTotalExpenses" class="text-lg font-bold text-slate-900 dark:text-white">0</p>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white dark:bg-slate-800 rounded-xl p-4 border border-slate-200 dark:border-slate-700 shadow-sm md:col-span-2">
                    <div class="flex items-start gap-3">
                        <div class="p-2 bg-slate-100 dark:bg-slate-700 rounded-lg">
                            <span class="material-symbols-outlined text-slate-600 dark:text-slate-400">event</span>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Created Date</p>
                            <p id="customerDetailCreatedAt" class="text-lg font-bold text-slate-900 dark:text-white">-</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Expenses Section -->
            <div class="space-y-3">
                <div class="flex items-center justify-between">
                    <h4 class="text-lg font-bold text-slate-900 dark:text-white flex items-center gap-2">
                        <span class="material-symbols-outlined text-blue-600">receipt</span>
                        Expense Details
                    </h4>
                    <button type="button" class="bg-gradient-to-r from-green-600 to-emerald-600 hover:from-green-700 hover:to-emerald-700 text-white px-4 py-2 rounded-lg text-sm transition-all shadow-lg shadow-green-500/20 flex items-center gap-2" data-action="add-expense">
                        <span class="material-symbols-outlined text-lg">add</span>
                        Add Expense
                    </button>
                </div>
                <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
                    <div class="overflow-x-auto">
                        <table id="customerDetailsExpensesTable" class="w-full text-sm">
                            <thead class="bg-slate-50 dark:bg-slate-700/50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider">Date</th>
                                    <th class="px-4 py-3 text-left text-xs font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider">Type</th>
                                    <th class="px-4 py-3 text-right text-xs font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider">Amount</th>
                                    <th class="px-4 py-3 text-center text-xs font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                                <tr>
                                    <td colspan="4" class="px-4 py-8 text-center text-slate-500 dark:text-slate-400">No expenses recorded</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Expense Modal (Persistent) -->
<div id="customerExpenseModalNew" class="hidden fixed top-0 left-0 right-0 bottom-0 bg-black/60 backdrop-blur-sm z-[110] flex items-center justify-center p-4 animate-fadeIn" style="min-height: 100vh;">
    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-[85%] sm:max-w-2xl mx-auto max-h-[90vh] overflow-hidden flex flex-col transform transition-all animate-slideIn">
        <!-- Modal Header -->
        <div class="bg-gradient-to-r from-emerald-600 via-green-600 to-teal-600 text-white px-6 py-4 flex-shrink-0">
            <div class="flex justify-between items-center">
                <h3 class="text-xl font-bold flex items-center gap-2">
                    <span class="material-symbols-outlined">add_circle</span>
                    <span id="customerExpenseModalTitle">Add Expense</span>
                </h3>
                <button type="button" onclick="closeExpenseModalNew()" class="bg-blue-500 hover:bg-blue-600 text-white rounded-lg p-2 transition-all shadow-md">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
        </div>
        
        <!-- Modal Body -->
        <div class="overflow-y-auto flex-1">
            <form id="customerExpenseFormNew" enctype="multipart/form-data" class="p-6 space-y-5">
                <input type="hidden" name="container_id" id="customerExpenseContainerIdNew">
                <input type="hidden" name="expense_id" id="customerExpenseIdNew">
                <input type="hidden" name="customer_id" id="customerExpenseCustomerIdNew">
                <input type="hidden" name="from_list" value="1">
                
                <!-- Expense Type -->
                <div class="space-y-2">
                    <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300">
                        <span class="flex items-center gap-2">
                            <span class="material-symbols-outlined text-lg text-blue-600">category</span>
                            Expense Type
                        </span>
                    </label>
                    <input type="text" name="expense_type" id="customerExpenseTypeNew" required
                        class="w-full px-4 py-3 border-2 border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent dark:bg-slate-800 dark:text-white transition-all text-sm"
                        placeholder="e.g. Transport, Customs, Loading, etc.">
                </div>
                
                <!-- Amount and Date Row -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <!-- Amount -->
                    <div class="space-y-2">
                        <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300">
                            <span class="flex items-center gap-2">
                                <span class="material-symbols-outlined text-lg text-green-600">payments</span>
                                Amount
                            </span>
                        </label>
                        <input type="number" name="amount" id="customerExpenseAmountNew" step="0.01" required
                            class="w-full px-4 py-3 border-2 border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent dark:bg-slate-800 dark:text-white transition-all text-sm"
                            placeholder="0.00">
                    </div>
                    
                    <!-- Date -->
                    <div class="space-y-2">
                        <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300">
                            <span class="flex items-center gap-2">
                                <span class="material-symbols-outlined text-lg text-orange-600">event</span>
                                Date
                            </span>
                        </label>
                        <input type="date" name="expense_date" id="customerExpenseDateNew" required
                            class="w-full px-4 py-3 border-2 border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent dark:bg-slate-800 dark:text-white transition-all text-sm">
                    </div>
                </div>
                
                <!-- Agent -->
                <div class="space-y-2">
                    <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300">
                        <span class="flex items-center gap-2">
                            <span class="material-symbols-outlined text-lg text-purple-600">badge</span>
                            Assign to Agent <span class="text-slate-400 text-xs ml-1">(Optional)</span>
                        </span>
                    </label>
                    <select name="agent_id" id="customerExpenseAgentNew"
                        class="w-full px-4 py-3 border-2 border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent dark:bg-slate-800 dark:text-white transition-all text-sm">
                        <option value="">-- No Agent --</option>
                    </select>
                </div>
                
                <!-- Proof Upload -->
                <div class="space-y-2">
                    <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300">
                        <span class="flex items-center gap-2">
                            <span class="material-symbols-outlined text-lg text-indigo-600">upload_file</span>
                            Upload Proof <span class="text-slate-400 text-xs ml-1">(Optional)</span>
                        </span>
                    </label>
                    <div class="relative">
                        <input type="file" name="proof" accept=".pdf,.jpg,.jpeg,.png"
                            class="w-full px-4 py-3 border-2 border-dashed border-slate-300 dark:border-slate-600 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent dark:bg-slate-800 dark:text-white transition-all text-sm file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-green-50 file:text-green-700 hover:file:bg-green-100 dark:file:bg-green-900/30 dark:file:text-green-400">
                        <p id="customerExpenseProofHelpNew" class="mt-2 text-xs text-slate-500 dark:text-slate-400">Accepted formats: PDF, JPG, JPEG, PNG</p>
                    </div>
                </div>
                
                <!-- Form Actions -->
                <div class="flex gap-3 pt-5 border-t border-slate-200 dark:border-slate-700">
                    <button type="button" onclick="closeExpenseModalNew()" 
                        class="flex-1 px-4 py-3 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded-xl font-semibold hover:bg-slate-200 dark:hover:bg-slate-700 transition-all">
                        Cancel
                    </button>
                    <button type="submit" 
                        class="flex-1 px-4 py-3 bg-gradient-to-r from-green-600 to-emerald-600 text-white rounded-xl font-semibold hover:from-green-700 hover:to-emerald-700 shadow-lg shadow-green-500/30 transition-all flex items-center justify-center gap-2">
                        <span class="material-symbols-outlined text-lg">save</span>
                        Save Expense
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- LIST CUSTOMERS - Responsive -->
<?php
$totalCustomers = $conn->query("SELECT COUNT(*) as total FROM customers")->fetch_assoc()['total'];
?>
<div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 shadow-sm overflow-hidden">
    <div class="px-4 md:px-6 py-4 md:py-5 border-b border-slate-200 dark:border-slate-800 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 sm:gap-0 bg-slate-50/50 dark:bg-slate-800/20">
        <h4 class="text-base md:text-lg font-bold text-slate-900 dark:text-slate-100">Customer List</h4>
        <div class="flex items-center gap-2 relative w-full sm:w-auto">
            <button id="filterToggleBtn" onclick="toggleFilterPanel()" class="flex-1 sm:flex-none px-3 py-2 md:py-1.5 text-xs font-bold text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg flex items-center justify-center gap-1 border border-slate-200 dark:border-slate-700 transition-colors">
                <span class="material-symbols-outlined text-[16px]">filter_list</span>
                <span class="hidden sm:inline">Filter</span>
            </button>
        </div>
    </div>
    
    <!-- Filter Panel - Responsive -->
    <div id="filterPanel" class="hidden px-4 md:px-6 py-4 border-b border-slate-200 dark:border-slate-800 bg-slate-50/30 dark:bg-slate-800/10">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
            <div>
                <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 mb-1">Customer Name</label>
                <input type="text" id="filterName" oninput="applyFilters()" placeholder="Search by name..." class="w-full px-3 py-1.5 text-sm border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 focus:ring-2 focus:ring-primary/50 focus:border-primary">
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 mb-1">Phone Number</label>
                <input type="text" id="filterPhone" oninput="applyFilters()" placeholder="Search by phone..." class="w-full px-3 py-1.5 text-sm border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 focus:ring-2 focus:ring-primary/50 focus:border-primary">
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 mb-1">Email Address</label>
                <input type="text" id="filterEmail" oninput="applyFilters()" placeholder="Search by email..." class="w-full px-3 py-1.5 text-sm border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 focus:ring-2 focus:ring-primary/50 focus:border-primary">
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
    <div class="lg:hidden flex flex-col gap-3">
        <?php
        $stmt_mobile = $conn->query("SELECT * FROM customers ORDER BY id DESC");
        while ($customer = $stmt_mobile->fetch_assoc()):
        ?>
        <div class="customer-card bg-white dark:bg-slate-900 p-4 rounded-xl border border-slate-200 dark:border-slate-800 active:scale-[0.98] transition-transform cursor-pointer"
             data-customer-name="<?= htmlspecialchars(strtolower($customer['name'])) ?>"
             data-customer-phone="<?= htmlspecialchars(strtolower($customer['phone'] ?? '')) ?>"
             data-customer-email="<?= htmlspecialchars(strtolower($customer['email'] ?? '')) ?>"
             onclick="viewCustomerProfile(<?= $customer['id'] ?>)">
            <div class="flex items-start gap-3 mb-3">
                <div class="h-12 w-12 rounded-full bg-primary/10 flex items-center justify-center flex-shrink-0">
                    <span class="material-symbols-outlined text-primary">person</span>
                </div>
                <div class="flex-1 min-w-0">
                    <h4 class="font-bold text-slate-900 dark:text-slate-100 truncate"><?= htmlspecialchars($customer['name']) ?></h4>
                    <p class="text-xs text-slate-500 truncate"><?= htmlspecialchars($customer['email'] ?? 'No email') ?></p>
                </div>
            </div>
            <?php if (!empty($customer['phone'])): ?>
            <div class="flex items-center gap-2 text-xs text-slate-500 mb-3">
                <span class="material-symbols-outlined text-sm">phone</span>
                <?= htmlspecialchars($customer['phone']) ?>
            </div>
            <?php endif; ?>
            <div class="flex gap-2 pt-3 border-t border-slate-100 dark:border-slate-800" onclick="event.stopPropagation()">
                <button onclick="viewCustomerProfile(<?= $customer['id'] ?>)" class="flex-1 h-10 rounded-lg bg-blue-50 dark:bg-blue-900/20 text-blue-600 font-bold text-sm flex items-center justify-center gap-2 active:scale-95 transition-transform">
                    <span class="material-symbols-outlined text-lg">visibility</span>
                    View
                </button>
                <?php if ($isAdmin): ?>
                <button onclick="editCustomer(<?= $customer['id'] ?>)" class="h-10 w-10 rounded-lg bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-600 dark:text-slate-400 active:scale-95 transition-transform">
                    <span class="material-symbols-outlined">edit</span>
                </button>
                <button onclick="deleteCustomer(<?= $customer['id'] ?>, '<?= htmlspecialchars($customer['name']) ?>')" class="h-10 w-10 rounded-lg bg-red-50 dark:bg-red-900/20 flex items-center justify-center text-red-600 active:scale-95 transition-transform">
                    <span class="material-symbols-outlined">delete</span>
                </button>
                <?php endif; ?>
            </div>
        </div>
        <?php endwhile; ?>
    </div>
    
    <!-- Desktop Table View -->
    <div class="hidden lg:block overflow-hidden">
        <div class="overflow-x-auto">
            <table id="customersListTable" class="w-full text-left border-collapse">
                <thead class="sticky top-0 bg-slate-50 dark:bg-slate-800 z-10">
                    <tr>
                        <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider border-b border-slate-200 dark:border-slate-700">
                            ID
                        </th>
                        <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider border-b border-slate-200 dark:border-slate-700">
                            Name
                        </th>
                        <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider border-b border-slate-200 dark:border-slate-700">
                            Email
                        </th>
                        <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider border-b border-slate-200 dark:border-slate-700">
                            Phone
                        </th>
                        <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider border-b border-slate-200 dark:border-slate-700">
                            Notes
                        </th>
                        <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider border-b border-slate-200 dark:border-slate-700 text-right">
                            Actions
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                <?php
                $res = $conn->query("SELECT * FROM customers ORDER BY id DESC");
                while ($row = $res->fetch_assoc()):
                ?>
                <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/50 transition-colors cursor-pointer group" onclick="viewCustomerProfile(<?= $row['id'] ?>)">
                    <td class="px-6 py-4 text-sm font-medium text-slate-400">#C-<?= $row['id'] ?></td>
                    <td class="px-6 py-4">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center text-primary font-bold text-xs">
                                <?= strtoupper(substr(htmlspecialchars($row['name']), 0, 2)) ?>
                            </div>
                            <span class="text-sm font-semibold text-slate-900 dark:text-white"><?= htmlspecialchars($row['name']) ?></span>
                        </div>
                    </td>
                    <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">
                        <?= htmlspecialchars($row['email'] ?? '-') ?>
                    </td>
                    <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">
                        <?= htmlspecialchars($row['phone'] ?? '-') ?>
                    </td>
                    <td class="px-6 py-4">
                        <?php if (!empty($row['notes'])): ?>
                        <span class="px-2 py-1 bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 text-xs font-medium rounded-md">
                            <?= htmlspecialchars(substr($row['notes'], 0, 30)) ?><?= strlen($row['notes']) > 30 ? '...' : '' ?>
                        </span>
                        <?php else: ?>
                        <span class="text-slate-400">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 text-right">
                        <div class="flex justify-end gap-2" onclick="event.stopPropagation()">
                            <?php if ($isAdmin): ?>
                            <button onclick="editCustomer(<?= $row['id'] ?>)" class="p-1.5 hover:bg-slate-100 dark:hover:bg-slate-700 rounded-lg text-slate-400 hover:text-primary transition-colors" title="Edit">
                                <span class="material-symbols-outlined text-[20px]">edit</span>
                            </button>
                            <button onclick="deleteCustomer(<?= $row['id'] ?>, '<?= htmlspecialchars($row['name']) ?>')" class="p-1.5 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg text-slate-400 hover:text-red-500 transition-colors" title="Delete">
                                <span class="material-symbols-outlined text-[20px]">delete</span>
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

</div> <!-- End page body -->

<?php 
if ($isAjax) {
    $html = ob_get_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'html' => $html]);
    exit;
}
?>

<?php include 'include/footer.php'; ?>

<style>
html,
body {
    min-height: 100%;
    margin: 0 !important;
    padding: 0 !important;
    overflow-x: hidden;
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

/* Specific styling for customer modal overlay */
#customerModal {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    width: 100% !important;
    height: 100% !important;
    margin: 0 !important;
    padding: 16px !important;
}

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
</style>

<script>
const companyDetails = <?php echo json_encode($companyDetails); ?>;
const proofBaseUrl = <?php echo json_encode(BASE_URL . 'uploads/transaction_proofs/'); ?>;
const containerInvoiceProofBaseUrl = <?php echo json_encode(BASE_URL . 'uploads/invoices/'); ?>;
const containerExpenseProofBaseUrl = <?php echo json_encode(BASE_URL . 'uploads/container_expenses/'); ?>;
const isAdmin = <?php echo json_encode($isAdmin); ?>;

const formatCurrency = (amount) => parseFloat(amount || 0).toLocaleString('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
});

const getResponsiveFontClass = (value) => {
    const valueStr = formatCurrency(value);
    const length = valueStr.length;
    
    if (length <= 10) return 'text-xl sm:text-2xl';
    if (length <= 13) return 'text-lg sm:text-xl';
    if (length <= 16) return 'text-base sm:text-lg';
    if (length <= 20) return 'text-sm sm:text-base';
    return 'text-xs sm:text-sm';
};

const escapeHtml = (value) => {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
};

const setPageScrollLock = (locked) => {
    document.body.classList.toggle('overflow-hidden', locked);
    document.documentElement.classList.toggle('overflow-hidden', locked);
    document.body.style.overflow = locked ? 'hidden' : '';
    document.documentElement.style.overflow = locked ? 'hidden' : '';
};

// Mobile sidebar toggle
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

window.openEditTransactionModal = function(transactionId) {
    // Transaction editing has been moved to containers.php
};

window.deleteTransaction = function(transactionId) {
    // Transaction deletion has been moved to containers.php
};

window.viewTransactionProof = function(transactionId) {
    // Transaction proof viewing has been moved to containers.php
};

window.downloadCustomerTransactionsPdf = function(customer, account, transactions) {
    // PDF download functionality has been moved - not available from customer profile
};

// Custom Alert Function
window.showAlert = function(message, type = 'info') {
    // Remove any existing alerts
    const existingAlert = document.querySelector('.custom-alert');
    if (existingAlert) existingAlert.remove();
    
    // Icon and color based on type
    const icons = {
        success: '<span class="material-symbols-outlined text-green-500 text-4xl">check_circle</span>',
        error: '<span class="material-symbols-outlined text-red-500 text-4xl">error</span>',
        warning: '<span class="material-symbols-outlined text-yellow-500 text-4xl">warning</span>',
        info: '<span class="material-symbols-outlined text-blue-500 text-4xl">info</span>'
    };
    
    const colors = {
        success: 'from-green-600 to-green-700',
        error: 'from-red-600 to-red-700',
        warning: 'from-yellow-600 to-yellow-700',
        info: 'from-blue-600 to-blue-700'
    };
    
    const alertHtml = `
        <div class="custom-alert fixed top-0 left-0 right-0 bottom-0 bg-black/60 backdrop-blur-sm z-[130] flex items-center justify-center animate-fadeIn p-4" style="min-height: 100vh;">
            <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-md mx-4 transform animate-slideIn">
                <div class="p-6 text-center">
                    <div class="mb-4 flex justify-center">${icons[type] || icons.info}</div>
                    <p class="text-slate-800 dark:text-slate-200 text-base mb-6">${message}</p>
                    <button onclick="this.closest('.custom-alert').remove()" 
                            class="px-6 py-3 bg-gradient-to-r ${colors[type] || colors.info} text-white rounded-xl hover:shadow-lg transition-all font-semibold flex items-center justify-center gap-2 mx-auto">
                        <span class="material-symbols-outlined text-lg">check</span>
                        OK
                    </button>
                </div>
            </div>
        </div>
    `;
    
    document.body.insertAdjacentHTML('beforeend', alertHtml);
    
    // Auto-close on background click
    const alert = document.querySelector('.custom-alert');
    alert.addEventListener('click', function(e) {
        if (e.target === this) {
            this.remove();
        }
    });
    
    // Auto-close on Escape key
    const escHandler = function(e) {
        if (e.key === 'Escape') {
            alert.remove();
            document.removeEventListener('keydown', escHandler);
        }
    };
    document.addEventListener('keydown', escHandler);
};

// Custom Confirm Function
window.showConfirm = function(message, onConfirm) {
    const confirmHtml = `
        <div class="custom-confirm fixed top-0 left-0 right-0 bottom-0 bg-black/60 backdrop-blur-sm z-[130] flex items-center justify-center animate-fadeIn p-4" style="min-height: 100vh;">
            <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-md mx-4 transform animate-slideIn overflow-hidden">
                <div class="bg-gradient-to-r from-red-600 to-rose-600 text-white px-6 py-4">
                    <h3 class="text-xl font-bold flex items-center gap-2">
                        <span class="material-symbols-outlined">warning</span>
                        Confirm Action
                    </h3>
                </div>
                <div class="p-6">
                    <p class="text-slate-800 dark:text-slate-200 text-base mb-6">${message}</p>
                    <div class="flex gap-3 justify-end">
                        <button onclick="this.closest('.custom-confirm').remove()" 
                                class="px-5 py-2.5 bg-slate-100 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-700 dark:text-slate-300 rounded-xl font-semibold hover:bg-slate-200 dark:hover:bg-slate-700 transition-all flex items-center gap-2">
                            <span class="material-symbols-outlined text-lg">close</span>
                            Cancel
                        </button>
                        <button id="confirmYes" 
                                class="px-5 py-2.5 bg-gradient-to-r from-red-600 to-rose-600 hover:from-red-700 hover:to-rose-700 text-white rounded-xl font-semibold transition-all shadow-lg shadow-red-500/30 flex items-center gap-2">
                            <span class="material-symbols-outlined text-lg">check</span>
                            Yes, Delete
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    document.body.insertAdjacentHTML('beforeend', confirmHtml);
    
    const confirmDialog = document.querySelector('.custom-confirm');
    const yesBtn = confirmDialog.querySelector('#confirmYes');
    
    yesBtn.addEventListener('click', function() {
        confirmDialog.remove();
        onConfirm();
    });
    
    // Close on background click
    confirmDialog.addEventListener('click', function(e) {
        if (e.target === this) {
            this.remove();
        }
    });
    
    // Close on Escape
    const escHandler = function(e) {
        if (e.key === 'Escape') {
            confirmDialog.remove();
            document.removeEventListener('keydown', escHandler);
        }
    };
    document.addEventListener('keydown', escHandler);
};

window.openContainerProofPreview = function(proofFile, containerNumber) {
    if (!proofFile) {
        showAlert('No proof uploaded for this container.', 'warning');
        return;
    }

    const safeFile = String(proofFile).trim();
    const proofUrl = containerInvoiceProofBaseUrl + encodeURIComponent(safeFile);
    const ext = safeFile.split('.').pop().toLowerCase();
    const isImage = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'].includes(ext);
    const safeContainerNum = containerNumber ? String(containerNumber).replace(/[^a-zA-Z0-9]/g, '_') : 'Container';
    const downloadName = 'Container_' + safeContainerNum + '.' + ext;

    const previewBody = isImage
        ? `<div class="h-full w-full flex items-center justify-center p-3 bg-slate-100 dark:bg-slate-800"><img src="${proofUrl}" alt="Container proof" class="max-w-full max-h-[72vh] object-contain rounded border border-slate-200 dark:border-slate-700"></div>`
        : `<iframe src="${proofUrl}" class="w-full h-[72vh] bg-white" title="Container proof preview"></iframe>`;

    const modalHtml = `
        <div id="containerProofPreviewModal" class="fixed top-0 left-0 right-0 bottom-0 bg-black/60 backdrop-blur-sm z-[140] flex items-center justify-center animate-fadeIn p-4" style="min-height: 100vh;" onclick="if(event.target===this) closeDynamicModal('containerProofPreviewModal')">
            <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-4xl max-h-[90vh] overflow-hidden animate-slideIn flex flex-col">
                <div class="bg-gradient-to-r from-slate-800 to-slate-900 text-white px-5 py-3 flex items-center justify-between">
                    <h3 class="text-base sm:text-lg font-bold flex items-center gap-2"><i class="fas fa-paperclip"></i>Container Proof Preview</h3>
                    <div class="flex items-center gap-2">
                        <a href="${proofUrl}" download="${escapeHtml(downloadName)}" class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-sm rounded-lg font-semibold transition-colors flex items-center gap-2">
                            <i class="fas fa-download"></i>
                            <span>Download</span>
                        </a>
                        <button type="button" onclick="closeDynamicModal('containerProofPreviewModal')" class="text-white hover:text-slate-300 text-xl" aria-label="Close">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                <div class="flex-1 overflow-auto">${previewBody}</div>
            </div>
        </div>
    `;

    closeDynamicModal('containerProofPreviewModal');
    document.body.insertAdjacentHTML('beforeend', modalHtml);
};

window.openTransactionProofPreview = function(proofEncoded, containerNumber) {
    const decodedProof = decodeURIComponent(String(proofEncoded || '')).trim();
    if (!decodedProof) {
        showAlert('No proof uploaded for this transaction.', 'warning');
        return;
    }

    const proofUrl = proofBaseUrl + encodeURIComponent(decodedProof);
    const fileExt = decodedProof.split('.').pop().toLowerCase();
    const isImage = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'].includes(fileExt);
    const safeContainerNum = containerNumber ? String(containerNumber).replace(/[^a-zA-Z0-9]/g, '_') : 'Container';
    const downloadName = 'Container_' + safeContainerNum + '_transaction.' + fileExt;

    const previewBody = isImage
        ? `<div class="h-full w-full flex items-center justify-center p-3 bg-slate-100 dark:bg-slate-800"><img src="${proofUrl}" alt="Transaction proof" class="max-w-full max-h-[72vh] object-contain rounded border border-slate-200 dark:border-slate-700"></div>`
        : `<iframe src="${proofUrl}" class="w-full h-[72vh] bg-white" title="Transaction proof preview"></iframe>`;

    const modalHtml = `
        <div id="transactionProofPreviewModal" class="fixed top-0 left-0 right-0 bottom-0 bg-black/60 backdrop-blur-sm z-[141] flex items-center justify-center animate-fadeIn p-4" style="min-height: 100vh;" onclick="if(event.target===this) closeDynamicModal('transactionProofPreviewModal')">
            <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-4xl max-h-[90vh] overflow-hidden animate-slideIn flex flex-col">
                <div class="bg-gradient-to-r from-slate-800 to-slate-900 text-white px-5 py-3 flex items-center justify-between">
                    <h4 class="text-base md:text-lg font-bold flex items-center gap-2"><i class="fas fa-file-alt"></i>Transaction Proof</h4>
                    <div class="flex items-center gap-2">
                        <a href="${proofUrl}" download="${escapeHtml(downloadName)}" class="px-3 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold inline-flex items-center gap-2">
                            <i class="fas fa-download"></i><span>Download</span>
                        </a>
                        <button type="button" onclick="closeDynamicModal('transactionProofPreviewModal')" class="w-9 h-9 rounded-lg bg-white/10 hover:bg-white/20 text-white inline-flex items-center justify-center">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                <div class="flex-1 overflow-auto">${previewBody}</div>
            </div>
        </div>
    `;

    closeDynamicModal('transactionProofPreviewModal');
    document.body.insertAdjacentHTML('beforeend', modalHtml);
};

window.openExpenseProofPreview = function(proofEncoded, containerNumber) {
    const decodedProof = decodeURIComponent(String(proofEncoded || '')).trim();
    if (!decodedProof) {
        showAlert('No proof uploaded for this expense.', 'warning');
        return;
    }

    const proofUrl = containerExpenseProofBaseUrl + encodeURIComponent(decodedProof);
    const fileExt = decodedProof.split('.').pop().toLowerCase();
    const isImage = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'].includes(fileExt);
    const safeContainerNum = containerNumber ? String(containerNumber).replace(/[^a-zA-Z0-9]/g, '_') : 'Container';
    const downloadName = 'Container_' + safeContainerNum + '_Expense.' + fileExt;

    const previewBody = isImage
        ? `<div class="h-full w-full flex items-center justify-center p-3 bg-slate-100 dark:bg-slate-800"><img src="${proofUrl}" alt="Expense proof" class="max-w-full max-h-[72vh] object-contain rounded border border-slate-200 dark:border-slate-700"></div>`
        : `<iframe src="${proofUrl}" class="w-full h-[72vh] bg-white" title="Expense proof preview"></iframe>`;

    const modalHtml = `
        <div id="expenseProofPreviewModal" class="fixed top-0 left-0 right-0 bottom-0 bg-black/60 backdrop-blur-sm z-[141] flex items-center justify-center animate-fadeIn p-4" style="min-height: 100vh;" onclick="if(event.target===this) closeDynamicModal('expenseProofPreviewModal')">
            <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-4xl max-h-[90vh] overflow-hidden animate-slideIn flex flex-col">
                <div class="bg-gradient-to-r from-slate-800 to-slate-900 text-white px-5 py-3 flex items-center justify-between">
                    <h4 class="text-base md:text-lg font-bold flex items-center gap-2"><i class="fas fa-receipt"></i>Expense Proof</h4>
                    <div class="flex items-center gap-2">
                        <a href="${proofUrl}" download="${escapeHtml(downloadName)}" class="px-3 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold inline-flex items-center gap-2">
                            <i class="fas fa-download"></i><span>Download</span>
                        </a>
                        <button type="button" onclick="closeDynamicModal('expenseProofPreviewModal')" class="w-9 h-9 rounded-lg bg-white/10 hover:bg-white/20 text-white inline-flex items-center justify-center">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                <div class="flex-1 overflow-auto">${previewBody}</div>
            </div>
        </div>
    `;

    closeDynamicModal('expenseProofPreviewModal');
    document.body.insertAdjacentHTML('beforeend', modalHtml);
};

// Customer modal functions - defined globally
window.openCustomerModal = function(customerId = null) {
    const modal = document.getElementById('customerModal');
    const form = document.getElementById('customerForm');
    const title = document.getElementById('modalTitle');
    
    if (customerId) {
        title.innerHTML = '<span class="material-symbols-outlined">edit</span> Edit Customer';
        form.elements['customer_id'].value = customerId;
        
        // Fetch customer data
        fetch(`customers.php?action=get&id=${customerId}`, {
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        })
        .then(res => res.json())
        .then(data => {
            if (data.success && data.customer) {
                const c = data.customer;
                form.elements['name'].value = c.name || '';
                form.elements['email'].value = c.email || '';
                form.elements['phone'].value = c.phone || '';
                form.elements['notes'].value = c.notes || '';
            } else {
                showAlert('Failed to load customer data.', 'error');
                modal.classList.add('hidden');
                modal.classList.remove('flex');
                setPageScrollLock(false);
            }
        })
        .catch(err => {
            console.error('Error:', err);
            showAlert('An error occurred while loading customer data.', 'error');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            setPageScrollLock(false);
        });
    } else {
        title.innerHTML = '<span class="material-symbols-outlined">person_add</span> Add Customer';
        form.reset();
        form.elements['customer_id'].value = '';
    }
    
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    setPageScrollLock(true);
};

window.closeCustomerModal = function() {
    const modal = document.getElementById('customerModal');
    const form = document.getElementById('customerForm');
    
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    setPageScrollLock(false);
    form.reset();
};

window.viewCustomerProfile = function(customerId) {
    Promise.all([
        fetch(`customers.php?action=account&id=${customerId}`, {headers: {'X-Requested-With': 'XMLHttpRequest'}}).then(r => r.json()),
        fetch(`customers.php?action=get_invoices&id=${customerId}`, {headers: {'X-Requested-With': 'XMLHttpRequest'}}).then(r => r.json())
    ])
    .then(([data, invData]) => {
        if (data.success && data.customer) {
            const c = data.customer;
            const acc = data.account || {total_amount: 0, total_charges: 0, total_paid: 0, remaining_balance: 0, initial_advance: 0};
            const transactions = data.transactions || [];
            const customerInvoices = (invData.success && invData.invoices) ? invData.invoices : [];

            window.currentCustomerTransactions = transactions;
            window.currentCustomerId = customerId;
            
            // Format currency
            // Build transactions HTML
            let transactionsHtml = '';
            if (transactions.length > 0) {
                const rows = transactions.map((t) => {
                    const isCredit = t.transaction_type === 'credit';
                    const typeLabel = isCredit ? 'Payment' : 'Charge';
                    const typeClass = isCredit ? 'text-green-600' : 'text-red-600';
                    const proofCell = t.proof
                        ? `<button onclick="viewTransactionProof(${t.id})" class="inline-flex items-center gap-1 text-xs text-indigo-700 bg-indigo-100 hover:bg-indigo-200 px-2 py-1 rounded">
                                <i class="fas fa-file"></i> Proof
                           </button>`
                        : '<span class="text-xs text-gray-400">No proof</span>';

                    return `
                        <tr class="border-b last:border-b-0">
                            <td class="px-3 py-2 text-xs text-gray-600">${t.transaction_date}</td>
                            <td class="px-3 py-2 text-xs font-semibold ${typeClass}">${typeLabel}</td>
                            <td class="px-3 py-2 text-xs text-right font-semibold ${typeClass}">Rs ${formatCurrency(t.amount)}</td>
                            <td class="px-3 py-2 text-xs text-gray-600 break-words">${t.description || '-'}</td>
                            <td class="px-3 py-2 text-xs text-gray-600 break-words">${t.reference_number || '-'}</td>
                            <td class="px-3 py-2 text-xs text-gray-600 break-words">${t.payment_method || '-'}</td>
                            <td class="px-3 py-2 text-xs text-gray-600 break-words">${t.container_reference || '-'}</td>
                            <td class="px-3 py-2 text-xs break-words">${proofCell}</td>
                            ${isAdmin ? `<td class="px-3 py-2 text-xs">
                                <div class="flex items-center gap-2">
                                    <button onclick="openEditTransactionModal(${t.id})" class="inline-flex items-center gap-1 text-xs text-emerald-700 bg-emerald-100 hover:bg-emerald-200 px-2 py-1 rounded">
                                        <i class="fas fa-pen"></i> Edit
                                    </button>
                                    <button onclick="deleteTransaction(${t.id})" class="inline-flex items-center gap-1 text-xs text-red-700 bg-red-100 hover:bg-red-200 px-2 py-1 rounded">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </div>
                            </td>` : ''}
                        </tr>
                    `;
                }).join('');

                transactionsHtml = `
                    <div class="overflow-x-hidden border border-gray-200 rounded-lg">
                        <table class="w-full table-fixed text-left">
                            <colgroup>
                                <col class="w-[10%]">
                                <col class="w-[9%]">
                                <col class="w-[11%]">
                                <col class="w-[18%]">
                                <col class="w-[12%]">
                                <col class="w-[10%]">
                                <col class="w-[11%]">
                                <col class="w-[7%]">
                                ${isAdmin ? '<col class="w-[12%]">' : ''}
                            </colgroup>
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-3 py-2 text-[11px] font-semibold text-gray-600 uppercase">Date</th>
                                    <th class="px-3 py-2 text-[11px] font-semibold text-gray-600 uppercase">Type</th>
                                    <th class="px-3 py-2 text-[11px] font-semibold text-gray-600 uppercase text-right">Amount</th>
                                    <th class="px-3 py-2 text-[11px] font-semibold text-gray-600 uppercase">Description</th>
                                    <th class="px-3 py-2 text-[11px] font-semibold text-gray-600 uppercase">Reference</th>
                                    <th class="px-3 py-2 text-[11px] font-semibold text-gray-600 uppercase">Method</th>
                                    <th class="px-3 py-2 text-[11px] font-semibold text-gray-600 uppercase">Container</th>
                                    <th class="px-3 py-2 text-[11px] font-semibold text-gray-600 uppercase">Proof</th>
                                    ${isAdmin ? '<th class="px-3 py-2 text-[11px] font-semibold text-gray-600 uppercase">Actions</th>' : ''}
                                </tr>
                            </thead>
                            <tbody>
                                ${rows}
                            </tbody>
                        </table>
                    </div>
                `;
            } else {
                transactionsHtml = '<p class="text-gray-500 text-center py-4">No transactions yet</p>';
            }
            
            const html = `
                <div id="profileModal" class="fixed top-0 left-0 right-0 bottom-0 bg-black/60 backdrop-blur-sm flex items-center justify-center z-50 animate-fadeIn p-4" style="min-height: 100vh;">
                    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-[95%] sm:max-w-[90%] md:max-w-4xl lg:max-w-6xl max-h-[95vh] overflow-hidden my-4 transform animate-slideIn flex flex-col">
                        <!-- Header with Gradient -->
                        <div class="bg-gradient-to-r from-blue-600 via-indigo-600 to-purple-600 text-white">
                            <!-- Top Section with Name and Close Button -->
                            <div class="px-6 py-4 flex items-center justify-between">
                                <div class="flex items-center gap-3">
                                    <div class="p-3 bg-white/20 backdrop-blur-sm rounded-xl shadow-lg">
                                        <span class="material-symbols-outlined text-2xl">person</span>
                                    </div>
                                    <div>
                                        <h2 class="text-2xl font-bold tracking-tight">${escapeHtml(c.name)}</h2>
                                        <p class="text-blue-100 text-sm flex items-center gap-2 font-medium mt-1">
                                            <span class="material-symbols-outlined text-base">badge</span>
                                            Customer ID: #${c.id}
                                        </p>
                                    </div>
                                </div>
                                <button onclick="this.closest('#profileModal').remove()" class="bg-slate-200/95 text-slate-700 hover:bg-slate-300 hover:text-slate-900 transition-all duration-200 rounded-lg p-2 shadow-sm">
                                    <span class="material-symbols-outlined text-xl">close</span>
                                </button>
                            </div>
                        </div>

                        <!-- Content Area -->
                        <div class="p-6 overflow-y-auto flex-1" style="scrollbar-width: thin;">
                            
                            <!-- Account Summary Section -->
                            <div class="mb-6">
                                <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-4 flex items-center gap-2">
                                    <span class="material-symbols-outlined text-blue-600">account_balance_wallet</span>
                                    Account Summary
                                </h3>
                                
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                    <!-- Total Amount Card -->
                                    <div class="bg-gradient-to-br from-blue-50 to-blue-100 dark:from-blue-900/20 dark:to-blue-800/20 border-2 border-blue-200 dark:border-blue-700 p-5 rounded-xl">
                                        <div class="flex items-center justify-between mb-3">
                                            <p class="text-sm font-semibold text-blue-700 dark:text-blue-400 uppercase tracking-wide">Total Amount</p>
                                            <div class="p-2 bg-blue-200 dark:bg-blue-700 rounded-lg">
                                                <span class="material-symbols-outlined text-blue-700 dark:text-blue-300">receipt_long</span>
                                            </div>
                                        </div>
                                        <p class="${getResponsiveFontClass(acc.total_amount)} font-bold text-blue-900 dark:text-blue-200" id="accountTotalAmount">Rs ${formatCurrency(acc.total_amount)}</p>
                                        <p class="text-xs text-blue-600 dark:text-blue-400 mt-2">Total invoiced amount</p>
                                    </div>

                                    <!-- Total Paid Card -->
                                    <div class="bg-gradient-to-br from-green-50 to-green-100 dark:from-green-900/20 dark:to-green-800/20 border-2 border-green-200 dark:border-green-700 p-5 rounded-xl">
                                        <div class="flex items-center justify-between mb-3">
                                            <p class="text-sm font-semibold text-green-700 dark:text-green-400 uppercase tracking-wide">Total Paid</p>
                                            <div class="p-2 bg-green-200 dark:bg-green-700 rounded-lg">
                                                <span class="material-symbols-outlined text-green-700 dark:text-green-300">check_circle</span>
                                            </div>
                                        </div>
                                        <p class="${getResponsiveFontClass(acc.total_paid)} font-bold text-green-900 dark:text-green-200" id="accountTotalPaid">Rs ${formatCurrency(acc.total_paid)}</p>
                                        <p class="text-xs text-green-600 dark:text-green-400 mt-2">Amount received</p>
                                    </div>

                                    <!-- Remaining Balance Card -->
                                    <div class="bg-gradient-to-br from-purple-50 to-purple-100 dark:from-purple-900/20 dark:to-purple-800/20 border-2 border-purple-200 dark:border-purple-700 p-5 rounded-xl">
                                        <div class="flex items-center justify-between mb-3">
                                            <p class="text-sm font-semibold text-purple-700 dark:text-purple-400 uppercase tracking-wide">Balance</p>
                                            <div class="p-2 bg-purple-200 dark:bg-purple-700 rounded-lg">
                                                <span class="material-symbols-outlined text-purple-700 dark:text-purple-300">account_balance</span>
                                            </div>
                                        </div>
                                        <p class="${getResponsiveFontClass(acc.remaining_balance)} font-bold text-purple-900 dark:text-purple-200" id="accountRemainingBalance">Rs ${formatCurrency(acc.remaining_balance)}</p>
                                        <p class="text-xs text-purple-600 dark:text-purple-400 mt-2">Outstanding balance</p>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Containers Section -->
                            <div class="mb-6">
                                <div class="flex items-center justify-between mb-4">
                                    <h3 class="text-lg font-bold text-slate-900 dark:text-white flex items-center gap-2">
                                        <span class="material-symbols-outlined text-blue-600">inventory_2</span>
                                        Containers
                                    </h3>
                                    ${isAdmin ? `<button onclick="openAddContainerForm(${c.id}, event)" class="px-3 sm:px-4 py-2 sm:py-2.5 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white text-xs sm:text-sm font-semibold rounded-lg transition-all shadow-lg shadow-blue-500/30 flex items-center gap-1.5 sm:gap-2 whitespace-nowrap">
                                        <span class="material-symbols-outlined text-base sm:text-lg">add</span>
                                        <span class="hidden sm:inline">Add Container</span>
                                        <span class="sm:hidden">Add</span>
                                    </button>` : ''}
                                </div>
                                <div id="customersContainersList" class="overflow-x-auto">
                                    <p class="text-slate-500 dark:text-slate-400 text-center py-4">Loading containers...</p>
                                </div>
                            </div>

                            <!-- Add Container Form (Hidden by default) -->
                            <div id="addContainerForm" class="hidden mb-6 p-6 border-2 border-blue-300 dark:border-blue-700 bg-gradient-to-br from-blue-50 to-indigo-50 dark:from-blue-900/20 dark:to-indigo-900/20 rounded-xl">
                                <div class="flex items-center justify-between mb-4">
                                    <h4 id="containerFormTitle" class="text-lg font-bold text-slate-900 dark:text-white flex items-center gap-2">
                                        <span id="containerFormTitleIcon" class="material-symbols-outlined text-blue-600">add_box</span>
                                        <span id="containerFormTitleText">New Container</span>
                                    </h4>
                                    <button onclick="closeAddContainerForm()" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-200 dark:hover:bg-slate-700 rounded-lg p-2 transition-all">
                                        <span class="material-symbols-outlined">close</span>
                                    </button>
                                </div>
                                <form id="customerProfileContainerForm" enctype="multipart/form-data" class="space-y-4">
                                    <input type="hidden" name="customer_id" value="${c.id}">
                                    <input type="hidden" name="container_id" value="">
                                    <input type="hidden" name="existing_invoice" value="">
                                    <input type="hidden" name="status" value="pending">
                                    
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1.5">Container Number *</label>
                                            <input type="text" name="container_number" required placeholder="Container Number" 
                                                class="w-full border-2 border-slate-200 dark:border-slate-700 dark:bg-slate-800 dark:text-white px-3 py-2.5 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all">
                                        </div>
                                        
                                        <div>
                                            <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1.5">BL Number</label>
                                            <input type="text" name="bl_number" placeholder="Bill of Lading Number" 
                                                class="w-full border-2 border-slate-200 dark:border-slate-700 dark:bg-slate-800 dark:text-white px-3 py-2.5 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all">
                                        </div>
                                        
                                        <div>
                                            <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1.5">TP No.</label>
                                            <input type="text" name="tp_no" placeholder="TP Number" 
                                                class="w-full border-2 border-slate-200 dark:border-slate-700 dark:bg-slate-800 dark:text-white px-3 py-2.5 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all">
                                        </div>
                                        
                                        <div>
                                            <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1.5">GD No.</label>
                                            <input type="text" name="gd_no" placeholder="GD Number" 
                                                class="w-full border-2 border-slate-200 dark:border-slate-700 dark:bg-slate-800 dark:text-white px-3 py-2.5 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all">
                                        </div>
                                        
                                        <div>
                                            <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1.5">HS Code</label>
                                            <input type="text" name="hs_code" placeholder="HS Code" 
                                                class="w-full border-2 border-slate-200 dark:border-slate-700 dark:bg-slate-800 dark:text-white px-3 py-2.5 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all">
                                        </div>
                                        
                                        <div>
                                            <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1.5">Packages *</label>
                                            <input type="number" name="packages" placeholder="Number of Packages" min="1" required
                                                class="w-full border-2 border-slate-200 dark:border-slate-700 dark:bg-slate-800 dark:text-white px-3 py-2.5 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all">
                                        </div>
                                        
                                        <div>
                                            <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1.5">Destination</label>
                                            <input type="text" name="destination" placeholder="Destination" 
                                                class="w-full border-2 border-slate-200 dark:border-slate-700 dark:bg-slate-800 dark:text-white px-3 py-2.5 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all">
                                        </div>
                                        
                                        <div>
                                            <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1.5">Port</label>
                                            <input type="text" name="port" placeholder="Port" 
                                                class="w-full border-2 border-slate-200 dark:border-slate-700 dark:bg-slate-800 dark:text-white px-3 py-2.5 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all">
                                        </div>
                                    </div>
                                    
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                        <div>
                                            <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1.5">Net Weight (kg)</label>
                                            <input type="number" name="net_weight" step="0.01" min="0.01" placeholder="0.00" 
                                                class="w-full border-2 border-slate-200 dark:border-slate-700 dark:bg-slate-800 dark:text-white px-3 py-2.5 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all">
                                        </div>
                                        
                                        <div>
                                            <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1.5">Gross Weight (kg)</label>
                                            <input type="number" name="gross_weight" step="0.01" min="0.01" placeholder="0.00" 
                                                class="w-full border-2 border-slate-200 dark:border-slate-700 dark:bg-slate-800 dark:text-white px-3 py-2.5 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all">
                                        </div>
                                        
                                        <div>
                                            <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1.5">Rate (per kg) *</label>
                                            <input type="number" name="rate" step="0.01" min="0.01" placeholder="0.00" required
                                                class="w-full border-2 border-slate-200 dark:border-slate-700 dark:bg-slate-800 dark:text-white px-3 py-2.5 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all">
                                        </div>
                                    </div>
                                    
                                    <div>
                                        <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1.5">Invoice File (Optional)</label>
                                        <input type="file" name="invoice" accept=".pdf,.jpg,.jpeg,.png" 
                                            class="w-full border-2 border-dashed border-slate-300 dark:border-slate-600 dark:bg-slate-800 dark:text-white px-3 py-2 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-blue-100 file:text-blue-700 hover:file:bg-blue-200 dark:file:bg-blue-900/30 dark:file:text-blue-400">
                                    </div>
                                    
                                    <div class="grid grid-cols-2 sm:flex gap-2 sm:gap-3 justify-end pt-4 border-t border-slate-200 dark:border-slate-700">
                                        <button type="button" onclick="closeAddContainerForm()" 
                                            class="w-full sm:w-auto px-3 sm:px-4 py-2 sm:py-2.5 bg-slate-100 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-700 dark:text-slate-300 rounded-lg font-semibold text-xs sm:text-sm hover:bg-slate-200 dark:hover:bg-slate-700 transition-all flex items-center justify-center gap-1.5 sm:gap-2">
                                            <span class="material-symbols-outlined text-base sm:text-lg">close</span>
                                            <span>Cancel</span>
                                        </button>
                                        <button type="submit" 
                                            class="w-full sm:w-auto px-3 sm:px-4 py-2 sm:py-2.5 bg-gradient-to-r from-green-600 to-emerald-600 hover:from-green-700 hover:to-emerald-700 text-white rounded-lg font-semibold text-xs sm:text-sm transition-all shadow-lg shadow-green-500/30 flex items-center justify-center gap-1.5 sm:gap-2">
                                            <span class="material-symbols-outlined text-base sm:text-lg">save</span>
                                            <span id="containerFormSubmitText" class="hidden sm:inline">Save Container</span>
                                            <span id="containerFormSubmitTextMobile" class="sm:hidden">Save</span>
                                        </button>
                                    </div>
                                </form>
                            </div>

                        </div>
                        
                        <!-- Footer Actions -->
                        <div class="px-4 md:px-6 py-3 md:py-4 bg-slate-50 dark:bg-slate-800/50 flex flex-col-reverse sm:flex-row gap-2 md:gap-3 justify-end border-t border-slate-200 dark:border-slate-700">
                            <button type="button" onclick="this.closest('#profileModal').remove()" 
                                class="w-full sm:w-auto px-4 md:px-6 py-2.5 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded-lg font-semibold text-sm hover:bg-slate-200 dark:hover:bg-slate-700 transition-all">
                                Close
                            </button>
                        </div>
                    </div>
                </div>
            `;
            document.body.insertAdjacentHTML('beforeend', html);
            
            // Load containers for this customer
            window.refreshCustomerContainers(customerId);

            // Close on background click
            const profileModal = document.getElementById('profileModal');
            profileModal.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.remove();
                }
            });
            
            // Setup container form submission
            const containerForm = document.getElementById('customerProfileContainerForm');
            if (containerForm) {
                // Remove any existing listeners by cloning
                const newForm = containerForm.cloneNode(true);
                containerForm.parentNode.replaceChild(newForm, containerForm);
                
                newForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    console.log('Container form submitted');
                    window.submitContainerForm(customerId);
                });
            } else {
                console.error('Container form not found in DOM');
            }
            
            // Setup expense form submission (single global handler)
            window.setupExpenseFormSubmission();
        } else {
            showAlert('Failed to load customer profile.', 'error');
        }
    })
    .catch(err => {
        console.error('Error:', err);
        showAlert('An error occurred while loading customer profile.', 'error');
    });
};

window.editCustomer = function(customerId) {
    window.openCustomerModal(customerId);
};

// Refresh account summary totals without closing the modal
window.refreshAccountSummary = function(customerId) {
    fetch(`customers.php?action=account&id=${customerId}`, {
        headers: {'X-Requested-With': 'XMLHttpRequest'}
    })
    .then(r => r.json())
    .then(data => {
        if (data.success && data.account) {
            const acc = data.account;
            const totalAmountEl = document.getElementById('accountTotalAmount');
            const totalPaidEl = document.getElementById('accountTotalPaid');
            const remainingBalanceEl = document.getElementById('accountRemainingBalance');
            
            if (totalAmountEl) {
                totalAmountEl.textContent = 'Rs. ' + formatCurrency(acc.total_amount || acc.total_invoiced || 0);
            }
            if (totalPaidEl) {
                totalPaidEl.textContent = 'Rs. ' + formatCurrency(acc.total_paid || 0);
            }
            if (remainingBalanceEl) {
                remainingBalanceEl.textContent = 'Rs. ' + formatCurrency(acc.remaining_balance || 0);
            }
            console.log('✅ Account summary refreshed:', acc);
        }
    })
    .catch(err => console.error('Error refreshing account summary:', err));
};

window.renderContainersTable = function(containers, customerId) {
    if (!containers || containers.length === 0) {
        return '<p class="text-gray-500 text-center py-4">No containers yet</p>';
    }

    return `
        <table class="w-full border-collapse bg-white" id="containersActionTable">
            <thead>
                <tr class="bg-gray-50 border-b">
                    <th class="px-4 py-3 text-center text-xs font-bold text-gray-700 uppercase">ID</th>
                    <th class="px-4 py-3 text-center text-xs font-bold text-gray-700 uppercase">Container No.</th>
                    <th class="px-4 py-3 text-center text-xs font-bold text-gray-700 uppercase">BL No.</th>
                    <th class="px-4 py-3 text-center text-xs font-bold text-gray-700 uppercase">Weight</th>
                    <th class="px-4 py-3 text-center text-xs font-bold text-gray-700 uppercase">Rate</th>
                    <th class="px-4 py-3 text-center text-xs font-bold text-gray-700 uppercase">Total</th>
                    <th class="px-4 py-3 text-center text-xs font-bold text-gray-700 uppercase">Status</th>
                    <th class="px-4 py-3 text-center text-xs font-bold text-gray-700 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody>
                ${containers.map(cont => {
                    const statusClass = cont.status === 'completed'
                        ? 'bg-green-100 text-green-700'
                        : (cont.status === 'pending' ? 'bg-yellow-100 text-yellow-700' : 'bg-blue-100 text-blue-700');
                    
                    const weight = parseFloat(cont.selected_weight || cont.gross_weight || cont.net_weight || 0);
                    const rate = parseFloat(cont.rate || 0);
                    const totalAmount = parseFloat(cont.total_amount || 0);
                    const hasProof = !!(cont.invoice_file && String(cont.invoice_file).trim() !== '');
                    const proofBtnClass = hasProof
                        ? 'bg-cyan-100 text-cyan-700 hover:bg-cyan-200'
                        : 'bg-slate-100 text-slate-400 cursor-not-allowed';
                    
                    return `
                        <tr class="border-b hover:bg-gray-50 transition-colors cursor-pointer" data-action="open-account" data-container-id="${cont.id}" data-customer-id="${customerId}">
                            <td class="px-4 py-3 text-sm text-center font-medium text-gray-900">#${cont.id}</td>
                            <td class="px-4 py-3 text-sm text-center font-semibold text-gray-900">${escapeHtml(cont.container_number)}</td>
                            <td class="px-4 py-3 text-sm text-center text-gray-600">${escapeHtml(cont.bl_number || 'N/A')}</td>
                            <td class="px-4 py-3 text-sm text-center text-gray-700">${weight.toFixed(2)} kg</td>
                            <td class="px-4 py-3 text-sm text-center text-gray-700">Rs ${formatCurrency(rate)}</td>
                            <td class="px-4 py-3 text-sm text-center font-semibold text-blue-900">Rs ${formatCurrency(totalAmount)}</td>
                            <td class="px-4 py-3 text-center">
                                <span class="px-3 py-1 rounded-full text-xs font-semibold ${statusClass}">${escapeHtml(cont.status || 'pending')}</span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-center gap-2" data-actions-row>
                                    <button class="w-9 h-9 flex items-center justify-center rounded transition-colors ${proofBtnClass}" data-action="view-proof" data-container-id="${cont.id}" data-proof-file="${escapeHtml(cont.invoice_file || '')}" data-container-number="${escapeHtml(cont.container_number || '')}" title="${hasProof ? 'View Registration Proof' : 'No proof uploaded'}" ${hasProof ? '' : 'disabled'}>
                                        <i class="fas fa-paperclip"></i>
                                    </button>
                                    <button class="w-9 h-9 flex items-center justify-center bg-purple-100 text-purple-700 hover:bg-purple-200 rounded transition-colors" data-action="open-invoice" data-container-id="${cont.id}" data-customer-id="${customerId}" title="${cont.status === 'completed' ? 'View/Print Invoice' : 'Manage Invoice'}">
                                        <i class="fas fa-file-invoice"></i>
                                    </button>
                                    ${isAdmin ? `<button class="w-9 h-9 flex items-center justify-center bg-green-100 text-green-700 hover:bg-green-200 rounded transition-colors" data-action="edit-container" data-container-id="${cont.id}" data-customer-id="${customerId}" title="Edit Container">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="w-9 h-9 flex items-center justify-center bg-red-100 text-red-700 hover:bg-red-200 rounded transition-colors" data-action="delete-container" data-container-id="${cont.id}" data-customer-id="${customerId}" data-container-number="${escapeHtml(cont.container_number)}" title="Delete Container">
                                        <i class="fas fa-trash"></i>
                                    </button>` : ''}
                                </div>
                            </td>
                        </tr>
                    `;
                }).join('')}
            </tbody>
        </table>
    `;
};

window.refreshCustomerContainers = function(customerId) {
    return fetch(`customers.php?action=get_containers&id=${customerId}`, {
        headers: {'X-Requested-With': 'XMLHttpRequest'}
    })
    .then(r => r.json())
    .then(containerData => {
        const containersList = document.getElementById('customersContainersList');
        if (!containersList) return;
        if (containerData.success) {
            containersList.innerHTML = window.renderContainersTable(containerData.containers || [], customerId);
            
            // Setup event delegation for action buttons
            setupContainerActionDelegation(containersList, customerId);
        }
    })
    .catch(err => console.error('Error loading containers:', err));
};

// Setup event delegation for container action buttons
window.setupContainerActionDelegation = function(containerElement, customerId) {
    if (!containerElement) return;

    // Prevent multiple delegated handlers from being attached after each list refresh.
    if (containerElement._containerActionDelegationBound) return;
    containerElement._containerActionDelegationBound = true;
    
    containerElement.addEventListener('click', function(e) {
        // First check for action buttons
        const btn = e.target.closest('[data-action]');
        if (btn) {
            e.preventDefault();
            e.stopPropagation();
            
            const action = btn.dataset.action;
            const containerId = parseInt(btn.dataset.containerId);
            const customerIdData = parseInt(btn.dataset.customerId);
            const containerNumber = btn.dataset.containerNumber;
            const proofFile = (btn.dataset.proofFile || '').trim();
            
            switch(action) {
                case 'view-proof':
                    if (!proofFile) {
                        showAlert('No proof uploaded for this container.', 'warning');
                        break;
                    }
                    window.openContainerProofPreview(proofFile, containerNumber);
                    break;
                case 'open-invoice':
                    window.openInvoiceModal(containerId, customerIdData);
                    break;
                case 'open-expense':
                    window.openExpenseModal(containerId, customerIdData);
                    break;
                case 'edit-container':
                    window.editContainerInModal(containerId, customerIdData);
                    break;
                case 'delete-container':
                    window.confirmDeleteContainer(containerId, containerNumber, customerIdData);
                    break;
                case 'open-account':
                    window.openContainerTabbedModal(containerId, customerId);
                    break;
            }
            return; // Don't process further
        }
        
        // If click is on a row (but not in action buttons), open tabbed modal
        const row = e.target.closest('tr[data-action="open-account"]');
        if (row && !e.target.closest('[data-actions-row]')) {
            e.preventDefault();
            const containerId = parseInt(row.dataset.containerId);
            window.openContainerTabbedModal(containerId, customerId);
        }
    }, true);
};

window.closeDynamicModal = function(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) modal.remove();
};

window.refreshOpenContainerPopups = function(containerId, customerId, options = {}) {
    const preferredTab = options.preferredTab || null;
    const tabbedModalOpen = !!document.getElementById('containerTabbedModal');
    const accountModalOpen = !!document.getElementById('containerAccountModal');
    const detailsModal = document.getElementById('customerDetailsModal');
    const detailsModalOpen = !!(detailsModal && !detailsModal.classList.contains('hidden'));
    const detailsMatchesContainer = Number(window.currentCustomerDetailContainerId || 0) === Number(containerId || 0);

    if (customerId) {
        window.refreshCustomerContainers(customerId);
        window.refreshAccountSummary(customerId);
    }

    if (tabbedModalOpen) {
        if (preferredTab === 'expense') {
            window.refreshExpenseTabData(containerId, customerId);
        } else {
            window.refreshAccountTabData(containerId, customerId);
        }
    }

    if (accountModalOpen) {
        window.refreshContainerAccountModalData(containerId, customerId);
    }

    if (detailsModalOpen && detailsMatchesContainer) {
        window.viewContainerDetails(containerId, customerId);
    }
};

// In-place refresh of the account tab inside containerTabbedModal (no flash)
window.refreshAccountTabData = function(containerId, customerId) {
    return fetch(`customers.php?action=container_account&id=${containerId}`, {
        headers: {'X-Requested-With': 'XMLHttpRequest'}
    })
    .then(r => r.json())
    .then(accountData => {
        if (!accountData.success) return;

        const cont = accountData.container;
        const acc  = accountData.account || {total_amount: 0, total_paid: 0, remaining_balance: 0};
        const transactions = accountData.transactions || [];
        const isCompleted  = (cont.status === 'completed');

        // Update summary cards
        const totalBalanceEl = document.getElementById('contTabTotalBalance');
        const totalPaidEl    = document.getElementById('contTabTotalPaid');
        const remainingEl    = document.getElementById('contTabRemaining');
        if (totalBalanceEl) totalBalanceEl.textContent = 'Rs ' + formatCurrency(acc.total_amount);
        if (totalPaidEl)    totalPaidEl.textContent    = 'Rs ' + formatCurrency(acc.total_paid);
        if (remainingEl)    remainingEl.textContent    = 'Rs ' + formatCurrency(acc.remaining_balance);

        // Update header status line
        const statusLine = document.getElementById('contTabModalStatusLine');
        if (statusLine) {
            statusLine.textContent = 'BL: ' + (cont.bl_number || 'N/A') + ' | Status: ' + (cont.status || 'pending');
        }

        // Rebuild transaction rows
        const tbody = document.getElementById('contTabTransactionsTbody');
        if (tbody) {
            const rows = transactions.length > 0
                ? transactions.map(t => {
                    const isCredit = t.transaction_type === 'credit';
                    const typeClass = isCredit ? 'text-green-700 bg-green-100' : 'text-red-700 bg-red-100';
                    const pm  = encodeURIComponent(t.payment_method || '');
                    const ref = encodeURIComponent(t.reference_number || '');
                    const desc = encodeURIComponent(t.description || '');
                    const proof = encodeURIComponent(t.proof || '');
                    const proofDisplay = t.proof
                        ? `<button type="button" onclick="openTransactionProofPreview('${proof}')" class="text-blue-600 hover:text-blue-800" title="View Proof"><i class="fas fa-file-alt"></i></button>`
                        : '<span class="text-gray-400">-</span>';
                    const actionButtons = !isAdmin ? '' : (!isCompleted ? `
                        <div class="flex items-center justify-center gap-1">
                            <button type="button" onclick="editContainerTransaction(${t.id}, '${t.transaction_type}', ${parseFloat(t.amount||0)}, '${escapeHtml(t.transaction_date||'')}', '${pm}', '${ref}', '${desc}', '${proof}')" class="w-7 h-7 inline-flex items-center justify-center rounded bg-blue-100 text-blue-700 hover:bg-blue-200" title="Edit"><i class="fas fa-edit"></i></button>
                            <button type="button" onclick="deleteContainerTransaction(${t.id}, ${cont.id}, ${customerId})" class="w-7 h-7 inline-flex items-center justify-center rounded bg-red-100 text-red-700 hover:bg-red-200" title="Delete"><i class="fas fa-trash"></i></button>
                        </div>` : '<span class="text-gray-400 text-xs">Locked</span>');
                    return `<tr class="border-b last:border-b-0">
                        <td class="px-3 py-2 text-xs text-center text-gray-600">${escapeHtml(t.transaction_date||'-')}</td>
                        <td class="px-3 py-2 text-xs text-center"><span class="px-2 py-1 rounded-full font-semibold ${typeClass}">${isCredit ? 'Paid' : 'Debit'}</span></td>
                        <td class="px-3 py-2 text-xs text-center font-semibold">Rs ${formatCurrency(t.amount)}</td>
                        <td class="px-3 py-2 text-xs text-center text-gray-600">${escapeHtml(t.payment_method||'-')}</td>
                        <td class="px-3 py-2 text-xs text-center text-gray-600">${escapeHtml(t.reference_number||'-')}</td>
                        <td class="px-3 py-2 text-xs text-center text-gray-600">${escapeHtml(t.description||'-')}</td>
                        <td class="px-3 py-2 text-xs text-center">${proofDisplay}</td>
                        <td class="px-3 py-2 text-xs text-center">${actionButtons}</td>
                    </tr>`;
                }).join('')
                : `<tr><td colspan="${isAdmin ? 8 : 7}" class="px-3 py-4 text-center text-sm text-gray-500">No transactions yet</td></tr>`;
            tbody.innerHTML = rows;
        }

        // Handle form area state
        const formArea = document.getElementById('contTabTransactionFormArea');
        if (formArea) {
            if (isCompleted && document.getElementById('containerTransactionForm')) {
                // Container just became fully paid – swap form for completed banner
                formArea.innerHTML = `
                    <div class="border border-green-200 bg-green-50 rounded-xl p-4 text-center mb-4">
                        <i class="fas fa-check-circle text-green-600 text-3xl mb-2"></i>
                        <h4 class="text-sm font-bold text-green-800 mb-1">Container Fully Paid</h4>
                        <p class="text-xs text-green-700">This container is completed. No further transactions allowed.</p>
                    </div>`;
            } else if (!isCompleted) {
                // Reset the form in-place
                const form = document.getElementById('containerTransactionForm');
                if (form) {
                    form.reset();
                    form.elements['transaction_date'].value = new Date().toISOString().split('T')[0];
                    const saveBtn   = document.getElementById('saveContainerTransactionBtn');
                    const cancelBtn = document.getElementById('cancelContainerTransactionEditBtn');
                    const proofDisp = document.getElementById('currentProofDisplay');
                    if (saveBtn)   saveBtn.innerHTML = '<i class="fas fa-save mr-1"></i>Save';
                    if (cancelBtn) cancelBtn.classList.add('hidden');
                    if (proofDisp) proofDisp.classList.add('hidden');
                }
            }
        }
    })
    .catch(err => console.error('Error refreshing account tab:', err));
};

// In-place refresh of containerAccountModal (no flash)
window.refreshContainerAccountModalData = function(containerId, customerId) {
    return fetch(`customers.php?action=container_account&id=${containerId}`, {
        headers: {'X-Requested-With': 'XMLHttpRequest'}
    })
    .then(r => r.json())
    .then(accountData => {
        if (!accountData.success) return;

        const cont = accountData.container;
        const acc  = accountData.account || {total_amount: 0, total_paid: 0, remaining_balance: 0};
        const transactions = accountData.transactions || [];
        const isCompleted  = (cont.status === 'completed');

        // Update summary cards
        const totalBalanceEl = document.getElementById('acctModalTotalBalance');
        const totalPaidEl    = document.getElementById('acctModalTotalPaid');
        const remainingEl    = document.getElementById('acctModalRemaining');
        if (totalBalanceEl) totalBalanceEl.textContent = 'Rs ' + formatCurrency(acc.total_amount);
        if (totalPaidEl)    totalPaidEl.textContent    = 'Rs ' + formatCurrency(acc.total_paid);
        if (remainingEl)    remainingEl.textContent    = 'Rs ' + formatCurrency(acc.remaining_balance);

        // Rebuild transaction rows
        const tbody = document.getElementById('acctModalTransactionsTbody');
        if (tbody) {
            const rows = transactions.length > 0
                ? transactions.map(t => {
                    const isCredit = t.transaction_type === 'credit';
                    const typeClass = isCredit ? 'text-green-700 bg-green-100' : 'text-red-700 bg-red-100';
                    const pm  = encodeURIComponent(t.payment_method || '');
                    const ref = encodeURIComponent(t.reference_number || '');
                    const desc = encodeURIComponent(t.description || '');
                    const proof = encodeURIComponent(t.proof || '');
                    const proofDisplay = t.proof
                        ? `<button type="button" onclick="openTransactionProofPreview('${proof}')" class="text-blue-600 hover:text-blue-800" title="View Proof"><i class="fas fa-file-alt"></i></button>`
                        : '<span class="text-gray-400">-</span>';
                    const actionButtons = !isAdmin ? '' : (!isCompleted ? `
                        <div class="flex items-center justify-center gap-1">
                            <button type="button" onclick="editContainerTransaction(${t.id}, '${t.transaction_type}', ${parseFloat(t.amount||0)}, '${escapeHtml(t.transaction_date||'')}', '${pm}', '${ref}', '${desc}', '${proof}')" class="w-7 h-7 inline-flex items-center justify-center rounded bg-blue-100 text-blue-700 hover:bg-blue-200" title="Edit"><i class="fas fa-edit"></i></button>
                            <button type="button" onclick="deleteContainerTransaction(${t.id}, ${cont.id}, ${customerId})" class="w-7 h-7 inline-flex items-center justify-center rounded bg-red-100 text-red-700 hover:bg-red-200" title="Delete"><i class="fas fa-trash"></i></button>
                        </div>` : '<span class="text-gray-400 text-xs">Locked</span>');
                    return `<tr class="border-b last:border-b-0">
                        <td class="px-3 py-2 text-xs text-center text-gray-600">${escapeHtml(t.transaction_date||'-')}</td>
                        <td class="px-3 py-2 text-xs text-center"><span class="px-2 py-1 rounded-full font-semibold ${typeClass}">${isCredit ? 'Paid' : 'Debit'}</span></td>
                        <td class="px-3 py-2 text-xs text-center font-semibold">Rs ${formatCurrency(t.amount)}</td>
                        <td class="px-3 py-2 text-xs text-center text-gray-600">${escapeHtml(t.payment_method||'-')}</td>
                        <td class="px-3 py-2 text-xs text-center text-gray-600">${escapeHtml(t.reference_number||'-')}</td>
                        <td class="px-3 py-2 text-xs text-center text-gray-600">${escapeHtml(t.description||'-')}</td>
                        <td class="px-3 py-2 text-xs text-center">${proofDisplay}</td>
                        <td class="px-3 py-2 text-xs text-center">${actionButtons}</td>
                    </tr>`;
                }).join('')
                : `<tr><td colspan="${isAdmin ? 8 : 7}" class="px-3 py-4 text-center text-sm text-gray-500">No container transactions yet</td></tr>`;
            tbody.innerHTML = rows;
        }

        // Handle form area state
        const formArea = document.getElementById('acctModalTransactionFormArea');
        if (formArea) {
            if (isCompleted && document.getElementById('containerTransactionForm')) {
                formArea.innerHTML = `
                    <div class="border border-green-200 bg-green-50 rounded-xl p-4 text-center">
                        <i class="fas fa-check-circle text-green-600 text-3xl mb-2"></i>
                        <h4 class="text-sm font-bold text-green-800 mb-1">Container Fully Paid</h4>
                        <p class="text-xs text-green-700">This container has been marked as completed. No further transactions can be added.</p>
                    </div>`;
            } else if (!isCompleted) {
                const form = document.getElementById('containerTransactionForm');
                if (form) {
                    form.reset();
                    form.elements['transaction_date'].value = new Date().toISOString().split('T')[0];
                    const saveBtn   = document.getElementById('saveContainerTransactionBtn');
                    const cancelBtn = document.getElementById('cancelContainerTransactionEditBtn');
                    const proofDisp = document.getElementById('currentProofDisplay');
                    if (saveBtn)   saveBtn.innerHTML = '<i class="fas fa-save mr-1"></i>Save Transaction';
                    if (cancelBtn) cancelBtn.classList.add('hidden');
                    if (proofDisp) proofDisp.classList.add('hidden');
                }
            }
        }
    })
    .catch(err => console.error('Error refreshing account modal:', err));
};

// New tabbed modal for container with Account & Expense tabs
window.openContainerTabbedModal = function(containerId, customerId) {
    // Track latest open request to ignore stale async responses and avoid modal flicker.
    window._containerTabbedModalRequestId = (window._containerTabbedModalRequestId || 0) + 1;
    const requestId = window._containerTabbedModalRequestId;

    Promise.all([
        fetch(`customers.php?action=container_account&id=${containerId}`, {
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        }).then(r => r.json()),
        fetch(`containers.php?action=details&id=${containerId}`, {
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        }).then(r => r.json())
    ])
    .then(([accountData, detailsData]) => {
        if (requestId !== window._containerTabbedModalRequestId) return;

        if (!accountData.success || !accountData.container) {
            showAlert(accountData.error || 'Unable to load container data.', 'error');
            return;
        }
        
        if (!detailsData.success || !detailsData.data) {
            showAlert('Unable to load container details.', 'error');
            return;
        }

        const cont = accountData.container;
        const acc = accountData.account || {total_amount: 0, total_paid: 0, remaining_balance: 0};
        const transactions = accountData.transactions || [];
        const isCompleted = (cont.status === 'completed');
        
        const expenses = detailsData.expenses || [];
        const totalExpenses = parseFloat(detailsData.total_expenses || 0);

        // Build transactions table
        const transactionsRows = transactions.length > 0
            ? transactions.map((t) => {
                const isCredit = t.transaction_type === 'credit';
                const typeClass = isCredit ? 'text-green-700 bg-green-100' : 'text-red-700 bg-red-100';
                const paymentMethod = encodeURIComponent(t.payment_method || '');
                const referenceNumber = encodeURIComponent(t.reference_number || '');
                const description = encodeURIComponent(t.description || '');
                const proof = encodeURIComponent(t.proof || '');
                const proofDisplay = t.proof
                    ? `<button type="button" onclick="openTransactionProofPreview('${proof}', '${escapeHtml(cont.container_number||'')}')" class="text-blue-600 hover:text-blue-800" title="View Proof"><i class="fas fa-file-alt"></i></button>`
                    : '<span class="text-gray-400">-</span>';
                
                const actionButtons = !isAdmin ? '' : (!isCompleted ? `
                    <div class="flex items-center justify-center gap-1">
                        <button type="button" onclick="editContainerTransaction(${t.id}, '${t.transaction_type}', ${parseFloat(t.amount || 0)}, '${escapeHtml(t.transaction_date || '')}', '${paymentMethod}', '${referenceNumber}', '${description}', '${proof}')" class="w-7 h-7 inline-flex items-center justify-center rounded bg-blue-100 text-blue-700 hover:bg-blue-200" title="Edit">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button type="button" onclick="deleteContainerTransaction(${t.id}, ${cont.id}, ${customerId})" class="w-7 h-7 inline-flex items-center justify-center rounded bg-red-100 text-red-700 hover:bg-red-200" title="Delete">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                ` : '<span class="text-gray-400 text-xs">Locked</span>');
                
                return `
                    <tr class="border-b last:border-b-0">
                        <td class="px-3 py-2 text-xs text-center text-gray-600">${escapeHtml(t.transaction_date || '-')}</td>
                        <td class="px-3 py-2 text-xs text-center"><span class="px-2 py-1 rounded-full font-semibold ${typeClass}">${isCredit ? 'Paid' : 'Debit'}</span></td>
                        <td class="px-3 py-2 text-xs text-center font-semibold">Rs ${formatCurrency(t.amount)}</td>
                        <td class="px-3 py-2 text-xs text-center text-gray-600">${escapeHtml(t.payment_method || '-')}</td>
                        <td class="px-3 py-2 text-xs text-center text-gray-600">${escapeHtml(t.reference_number || '-')}</td>
                        <td class="px-3 py-2 text-xs text-center text-gray-600">${escapeHtml(t.description || '-')}</td>
                        <td class="px-3 py-2 text-xs text-center">${proofDisplay}</td>
                        <td class="px-3 py-2 text-xs text-center">${actionButtons}</td>
                    </tr>
                `;
            }).join('')
            : `<tr><td colspan="${isAdmin ? 8 : 7}" class="px-3 py-4 text-center text-sm text-gray-500">No transactions yet</td></tr>`;

        // Build expenses table
        const expensesRows = expenses.length > 0
            ? expenses.map(exp => `
                <tr class="border-b last:border-b-0">
                    <td class="px-3 py-2 text-xs text-center">${escapeHtml(exp.expense_date || '-')}</td>
                    <td class="px-3 py-2 text-xs text-center">${escapeHtml(exp.expense_type || '-')}</td>
                    <td class="px-3 py-2 text-xs text-center font-semibold">Rs ${formatCurrency(exp.amount)}</td>
                    <td class="px-3 py-2 text-xs text-center">${escapeHtml(exp.agent_name || '-')}</td>
                    <td class="px-3 py-2 text-xs text-center">
                        ${exp.proof ? `<button type="button" onclick="openExpenseProofPreview('${encodeURIComponent(exp.proof)}', '${escapeHtml(cont.container_number||'')}')" class="text-blue-600 hover:text-blue-800" title="View Proof"><i class="fas fa-eye"></i></button>` : '<span class="text-gray-400">-</span>'}
                    </td>
                    <td class="px-3 py-2 text-xs text-center">
                        ${isAdmin && !isCompleted ? `
                            <div class="flex gap-1 justify-center">
                                <button type="button" onclick='editExpenseInline(${JSON.stringify(exp)})' class="w-7 h-7 inline-flex items-center justify-center rounded bg-blue-100 text-blue-700 hover:bg-blue-200" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button type="button" onclick="deleteExpenseFromCustomer(${exp.id}, ${containerId}, ${customerId}, true)" class="w-7 h-7 inline-flex items-center justify-center rounded bg-red-100 text-red-700 hover:bg-red-200" title="Delete">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        ` : (isAdmin && isCompleted ? `
                            <span class="text-gray-400 text-xs"><i class="fas fa-lock"></i> Locked</span>
                        ` : '-')}
                    </td>
                </tr>
            `).join('')
            : '<tr><td colspan="6" class="px-3 py-4 text-center text-sm text-gray-500">No expenses yet</td></tr>';

        // Transaction form HTML
        const transactionFormHtml = !isAdmin ? '' : (!isCompleted ? `
            <div class="border border-gray-200 rounded-xl p-4 mb-4">
                <h4 class="text-sm font-bold text-gray-800 mb-3"><i class="fas fa-plus-circle text-blue-600 mr-1"></i>Record Transaction</h4>
                <form id="containerTransactionForm" class="space-y-3">
                    <input type="hidden" name="container_id" value="${cont.id}">
                    <input type="hidden" name="customer_id" value="${cont.customer_id || customerId}">
                    <input type="hidden" name="transaction_id" id="container_transaction_id" value="">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1">Type</label>
                            <select name="transaction_type" class="w-full border border-gray-300 p-2 rounded text-sm bg-gray-100" disabled>
                                <option value="credit" selected>Paid (Credit)</option>
                            </select>
                            <input type="hidden" name="transaction_type" value="credit">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1">Amount</label>
                            <input type="number" step="0.01" min="0.01" name="amount" required class="w-full border border-gray-300 p-2 rounded text-sm" placeholder="0.00">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1">Date</label>
                            <input type="date" name="transaction_date" value="${new Date().toISOString().split('T')[0]}" required class="w-full border border-gray-300 p-2 rounded text-sm">
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1">Payment Method</label>
                            <input type="text" name="payment_method" class="w-full border border-gray-300 p-2 rounded text-sm" placeholder="Cash / Bank / Cheque">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1">Reference No.</label>
                            <input type="text" name="reference_number" class="w-full border border-gray-300 p-2 rounded text-sm" placeholder="Receipt/Ref #">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1">Description</label>
                            <input type="text" name="description" class="w-full border border-gray-300 p-2 rounded text-sm" placeholder="Notes">
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1">Upload Proof</label>
                            <input type="file" name="proof" accept=".pdf,.jpg,.jpeg,.png" class="w-full border border-gray-300 p-2 rounded text-sm">
                        </div>
                        <div id="currentProofDisplay" class="hidden">
                            <label class="block text-xs font-semibold text-gray-700 mb-1">Current Proof</label>
                            <a id="currentProofLink" href="#" target="_blank" class="text-blue-600 hover:underline text-sm"><i class="fas fa-file-alt mr-1"></i>View</a>
                        </div>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button type="button" id="cancelContainerTransactionEditBtn" onclick="cancelContainerTransactionEdit()" class="hidden px-4 py-2 border border-gray-300 text-gray-700 rounded text-sm hover:bg-gray-50"><i class="fas fa-times mr-1"></i>Cancel</button>
                        <button type="submit" id="saveContainerTransactionBtn" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded text-sm"><i class="fas fa-save mr-1"></i>Save</button>
                    </div>
                </form>
            </div>
        ` : `
            <div class="border border-green-200 bg-green-50 rounded-xl p-4 text-center mb-4">
                <i class="fas fa-check-circle text-green-600 text-3xl mb-2"></i>
                <h4 class="text-sm font-bold text-green-800 mb-1">Container Fully Paid</h4>
                <p class="text-xs text-green-700">This container is completed. No further transactions allowed.</p>
            </div>
        `);

        const modalHtml = `
            <div id="containerTabbedModal" class="fixed top-0 left-0 right-0 bottom-0 bg-black bg-opacity-50 flex items-center justify-center z-[120] animate-fadeIn p-4" style="min-height: 100vh;" onclick="if(event.target===this) closeDynamicModal('containerTabbedModal')">
                <div class="bg-white rounded-2xl shadow-2xl w-full max-w-[85%] sm:max-w-[90%] md:max-w-4xl max-h-[92vh] overflow-hidden animate-slideIn flex flex-col">
                    <!-- Header -->
                    <div class="bg-gradient-to-r from-indigo-600 to-blue-700 text-white px-4 sm:px-6 py-3 sm:py-4 flex items-center justify-between flex-shrink-0">
                        <div class="flex-1">
                            <h3 class="text-lg font-bold"><i class="fas fa-box mr-2"></i>Container: ${escapeHtml(cont.container_number || '')}</h3>
                            <p id="contTabModalStatusLine" class="text-xs text-blue-100 mt-1">BL: ${escapeHtml(cont.bl_number || 'N/A')} | Status: ${escapeHtml(cont.status || 'pending')}</p>
                        </div>
                        <button onclick="closeDynamicModal('containerTabbedModal')" class="bg-blue-500 hover:bg-blue-600 text-white rounded-lg p-2 w-10 h-10 flex items-center justify-center transition-all shadow-md flex-shrink-0 ml-4">
                            <span class="material-symbols-outlined text-xl">close</span>
                        </button>
                    </div>

                    <!-- Tabs -->
                    <div class="border-b border-gray-200 bg-gray-50 px-4 sm:px-6 flex-shrink-0">
                        <div class="flex gap-1 overflow-x-auto">
                            <button onclick="switchContainerTab('account')" id="tabBtnAccount" class="px-3 sm:px-6 py-2 sm:py-3 text-xs sm:text-sm font-semibold border-b-2 border-blue-600 text-blue-600 transition-colors whitespace-nowrap">
                                <i class="fas fa-wallet mr-1 sm:mr-2"></i><span class="hidden sm:inline">Account</span><span class="sm:hidden">Account</span>
                            </button>
                            <button onclick="switchContainerTab('expense')" id="tabBtnExpense" class="px-3 sm:px-6 py-2 sm:py-3 text-xs sm:text-sm font-semibold border-b-2 border-transparent text-gray-600 hover:text-gray-900 transition-colors whitespace-nowrap">
                                <i class="fas fa-receipt mr-1 sm:mr-2"></i><span class="hidden sm:inline">Expenses</span><span class="sm:hidden">Exp</span>
                            </button>
                        </div>
                    </div>

                    <!-- Tab Content -->
                    <div class="flex-1 overflow-y-auto p-4 sm:p-6">
                        <!-- Account Tab -->
                        <div id="accountTab" class="space-y-4">
                            <!-- Summary Cards -->
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 text-center">
                                    <p class="text-xs uppercase text-blue-700 font-semibold">Total Balance</p>
                                    <p id="contTabTotalBalance" class="${getResponsiveFontClass(acc.total_amount)} font-bold text-blue-900 break-words">Rs ${formatCurrency(acc.total_amount)}</p>
                                </div>
                                <div class="bg-green-50 border border-green-200 rounded-xl p-4 text-center">
                                    <p class="text-xs uppercase text-green-700 font-semibold">Paid</p>
                                    <p id="contTabTotalPaid" class="${getResponsiveFontClass(acc.total_paid)} font-bold text-green-900 break-words">Rs ${formatCurrency(acc.total_paid)}</p>
                                </div>
                                <div class="bg-purple-50 border border-purple-200 rounded-xl p-4 text-center">
                                    <p class="text-xs uppercase text-purple-700 font-semibold">Remaining</p>
                                    <p id="contTabRemaining" class="${getResponsiveFontClass(acc.remaining_balance)} font-bold text-purple-900 break-words">Rs ${formatCurrency(acc.remaining_balance)}</p>
                                </div>
                            </div>

                            <div id="contTabTransactionFormArea">
                            ${transactionFormHtml}
                            </div>

                            <!-- Transactions Table -->
                            <div class="border border-gray-200 rounded-xl overflow-hidden">
                                <div class="bg-gray-50 px-4 py-3 border-b">
                                    <h4 class="text-sm font-bold text-gray-800"><i class="fas fa-table text-indigo-600 mr-1"></i>Transactions History</h4>
                                </div>
                                <div class="overflow-x-auto">
                                    <table class="w-full">
                                        <thead>
                                            <tr class="bg-gray-50 border-b">
                                                <th class="px-3 py-2 text-xs font-bold text-gray-700 uppercase text-center">Date</th>
                                                <th class="px-3 py-2 text-xs font-bold text-gray-700 uppercase text-center">Type</th>
                                                <th class="px-3 py-2 text-xs font-bold text-gray-700 uppercase text-center">Amount</th>
                                                <th class="px-3 py-2 text-xs font-bold text-gray-700 uppercase text-center">Method</th>
                                                <th class="px-3 py-2 text-xs font-bold text-gray-700 uppercase text-center">Reference</th>
                                                <th class="px-3 py-2 text-xs font-bold text-gray-700 uppercase text-center">Description</th>
                                                <th class="px-3 py-2 text-xs font-bold text-gray-700 uppercase text-center">Proof</th>
                                                ${isAdmin ? '<th class="px-3 py-2 text-xs font-bold text-gray-700 uppercase text-center">Actions</th>' : ''}
                                            </tr>
                                        </thead>
                                        <tbody id="contTabTransactionsTbody">${transactionsRows}</tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- Expense Tab -->
                        <div id="expenseTab" class="hidden space-y-4">
                            <!-- Expense Summary -->
                            <div class="bg-orange-50 border border-orange-200 rounded-xl p-4">
                                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                                    <div>
                                        <p class="text-xs uppercase text-orange-700 font-semibold">Total Expenses</p>
                                        <p id="expenseTabTotalAmount" class="${getResponsiveFontClass(totalExpenses)} font-bold text-orange-900 break-words">Rs ${formatCurrency(totalExpenses)}</p>
                                    </div>
                                    ${isAdmin && !isCompleted ? `
                                        <button onclick="toggleExpenseForm()" id="toggleExpenseBtn" class="px-3 sm:px-4 py-2 sm:py-2.5 bg-orange-600 hover:bg-orange-700 text-white rounded-lg text-xs sm:text-sm font-semibold transition-colors whitespace-nowrap">
                                            <i class="fas fa-plus mr-1 sm:mr-2"></i>Add Expense
                                        </button>
                                    ` : (isAdmin && isCompleted ? `
                                        <button disabled class="px-3 sm:px-4 py-2 sm:py-2.5 bg-gray-400 text-white rounded-lg text-xs sm:text-sm font-semibold opacity-50 cursor-not-allowed" title="Cannot add expenses - Container is fully paid">
                                            <i class="fas fa-lock mr-1 sm:mr-2"></i><span class="hidden sm:inline">Expenses Locked</span><span class="sm:hidden">Locked</span>
                                        </button>
                                    ` : '')}
                                </div>
                            </div>

                            <!-- Inline Expense Form -->
                            <div id="inlineExpenseForm" class="hidden border border-gray-200 rounded-xl p-4 mb-4">
                                <h4 class="text-sm font-bold text-gray-800 mb-3"><i class="fas fa-plus-circle text-orange-600 mr-1"></i>Add New Expense</h4>
                                <form id="containerExpenseForm" class="space-y-3">
                                    <input type="hidden" name="container_id" value="${cont.id}">
                                    <input type="hidden" name="customer_id" value="${customerId}">
                                    <input type="hidden" name="expense_id" value="">
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                        <div>
                                            <label class="block text-xs font-semibold text-gray-700 mb-1">Expense Type *</label>
                                            <input type="text" name="expense_type" required class="w-full border border-gray-300 p-2 rounded text-sm" placeholder="e.g., Transport, Customs">
                                        </div>
                                        <div>
                                            <label class="block text-xs font-semibold text-gray-700 mb-1">Amount *</label>
                                            <input type="number" step="0.01" min="0.01" name="amount" required class="w-full border border-gray-300 p-2 rounded text-sm" placeholder="0.00">
                                        </div>
                                    </div>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                        <div>
                                            <label class="block text-xs font-semibold text-gray-700 mb-1">Date *</label>
                                            <input type="date" name="expense_date" value="${new Date().toISOString().split('T')[0]}" required class="w-full border border-gray-300 p-2 rounded text-sm">
                                        </div>
                                        <div>
                                            <label class="block text-xs font-semibold text-gray-700 mb-1">Agent</label>
                                            <select name="agent_id" id="inlineAgentSelect" class="w-full border border-gray-300 p-2 rounded text-sm">
                                                <option value="">-- Select Agent --</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-700 mb-1">Description</label>
                                        <textarea name="description" rows="2" class="w-full border border-gray-300 p-2 rounded text-sm" placeholder="Additional notes..."></textarea>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-700 mb-1">Upload Proof</label>
                                        <input type="file" name="proof" accept=".pdf,.jpg,.jpeg,.png" class="w-full border border-gray-300 p-2 rounded text-sm">
                                        <p class="text-xs text-gray-500 mt-1">Allowed: PDF, JPG, PNG</p>
                                    </div>
                                    <div class="flex flex-col-reverse sm:flex-row justify-end gap-2 sm:gap-3">
                                        <button type="button" onclick="toggleExpenseForm()" class="px-3 sm:px-4 py-2 border border-gray-300 text-gray-700 rounded text-xs sm:text-sm hover:bg-gray-50 flex items-center justify-center gap-1 sm:gap-2"><i class="fas fa-times"></i><span class="hidden sm:inline">Cancel</span></button>
                                        <button type="submit" class="px-3 sm:px-4 py-2 bg-orange-600 hover:bg-orange-700 text-white rounded text-xs sm:text-sm flex items-center justify-center gap-1 sm:gap-2"><i class="fas fa-save"></i><span class="hidden sm:inline">Save Expense</span><span class="sm:hidden">Save</span></button>
                                    </div>
                                </form>
                            </div>

                            <!-- Expenses Table -->
                            <div class="border border-gray-200 rounded-xl overflow-hidden">
                                <div class="bg-gray-50 px-4 py-3 border-b">
                                    <h4 class="text-sm font-bold text-gray-800"><i class="fas fa-receipt text-orange-600 mr-1"></i>Expenses History</h4>
                                </div>
                                <div class="overflow-x-auto">
                                    <table class="w-full">
                                        <thead>
                                            <tr class="bg-gray-50 border-b">
                                                <th class="px-3 py-2 text-xs font-bold text-gray-700 uppercase text-center">Date</th>
                                                <th class="px-3 py-2 text-xs font-bold text-gray-700 uppercase text-center">Type</th>
                                                <th class="px-3 py-2 text-xs font-bold text-gray-700 uppercase text-center">Amount</th>
                                                <th class="px-3 py-2 text-xs font-bold text-gray-700 uppercase text-center">Agent</th>
                                                <th class="px-3 py-2 text-xs font-bold text-gray-700 uppercase text-center">Proof</th>
                                                <th class="px-3 py-2 text-xs font-bold text-gray-700 uppercase text-center">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>${expensesRows}</tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Footer with Action Buttons -->
                    <div class="border-t border-gray-200 bg-gray-50 px-4 sm:px-6 py-3 sm:py-4 flex flex-col-reverse sm:flex-row items-stretch sm:items-center justify-end gap-2 sm:gap-3 flex-shrink-0">
                        <button id="containerPopupPrintBtn" onclick="printActiveContainerReport(${cont.id}, ${customerId}, '${escapeHtml(cont.container_number || '')}', '${escapeHtml(cont.bl_number || '')}')" class="px-3 sm:px-5 py-2.5 bg-green-600 hover:bg-green-700 text-white rounded-lg text-xs sm:text-sm font-semibold transition-colors shadow-sm flex items-center justify-center sm:justify-start gap-1 sm:gap-2">
                            <i class="fas fa-print"></i><span id="containerPopupPrintBtnDesktopLabel" class="hidden sm:inline">Print Transactions</span><span id="containerPopupPrintBtnMobileLabel" class="sm:hidden">Print</span>
                        </button>
                        <button onclick="closeDynamicModal('containerTabbedModal')" class="px-3 sm:px-5 py-2.5 bg-gray-600 hover:bg-gray-700 text-white rounded-lg text-xs sm:text-sm font-semibold transition-colors shadow-sm flex items-center justify-center gap-1 sm:gap-2">
                            <i class="fas fa-times"></i><span class="hidden sm:inline">Close</span>
                        </button>
                    </div>
                </div>
            </div>
        `;

        closeDynamicModal('containerTabbedModal');
        document.body.insertAdjacentHTML('beforeend', modalHtml);
        window.activeContainerPopupTab = 'account';
        window.updateContainerPopupPrintButton();

        // Setup transaction form submission
        const form = document.getElementById('containerTransactionForm');
        if (form) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                const formData = new FormData(this);
                fetch('customers.php?action=add_container_transaction', {
                    method: 'POST',
                    headers: {'X-Requested-With': 'XMLHttpRequest'},
                    body: formData
                })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        showAlert(res.message || 'Transaction saved!', 'success');
                        window.refreshAccountTabData(containerId, customerId);
                        window.refreshAccountSummary(customerId);
                        if (customerId) window.refreshCustomerContainers(customerId);
                    } else {
                        showAlert(res.error || 'Failed to save transaction.', 'error');
                    }
                })
                .catch(err => {
                    console.error(err);
                    showAlert('Failed to save transaction.', 'error');
                });
            });
        }

        // Load agents into inline expense form
        window.loadAgentsList().then(agents => {
            const agentSelect = document.getElementById('inlineAgentSelect');
            if (agentSelect && agents.length > 0) {
                agentSelect.innerHTML = '<option value="">-- Select Agent --</option>' + 
                    agents.map(a => `<option value="${a.id}">${escapeHtml(a.name)}</option>`).join('');
            }
        });

        // Setup inline expense form submission
        const expenseForm = document.getElementById('containerExpenseForm');
        if (expenseForm) {
            expenseForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const formData = new FormData(this);
                const expenseId = formData.get('expense_id');
                
                fetch('containers.php?action=expense', {
                    method: 'POST',
                    headers: {'X-Requested-With': 'XMLHttpRequest'},
                    body: formData
                })
                .then(response => {
                    if (!response.ok) throw new Error(`HTTP ${response.status}`);
                    return response.text().then(text => {
                        try {
                            return text ? JSON.parse(text) : { success: true };
                        } catch (e) {
                            console.warn('Non-JSON save response, treating as success:', text.substring(0, 200));
                            return { success: true };
                        }
                    });
                })
                .then(data => {
                    if (data && data.success === false) {
                        showAlert(data.error || 'Failed to save expense.', 'error');
                        return;
                    }
                    showAlert(expenseId ? 'Expense updated successfully!' : 'Expense added successfully!', 'success');
                    this.reset();
                    toggleExpenseForm(); // Hide the form
                    // Refresh only the expense tab data without reopening modal
                    window.refreshExpenseTabData(containerId, customerId);
                })
                .catch(err => {
                    console.error('Error saving expense:', err);
                    showAlert('Failed to save expense: ' + err.message, 'error');
                });
            });
        }
    })
    .catch(err => {
        if (requestId !== window._containerTabbedModalRequestId) return;
        console.error(err);
        showAlert('Unable to load container data.', 'error');
    });
};

// Tab switching function
window.switchContainerTab = function(tabName) {
    const accountTab = document.getElementById('accountTab');
    const expenseTab = document.getElementById('expenseTab');
    const accountBtn = document.getElementById('tabBtnAccount');
    const expenseBtn = document.getElementById('tabBtnExpense');

    if (tabName === 'account') {
        accountTab.classList.remove('hidden');
        expenseTab.classList.add('hidden');
        accountBtn.classList.add('border-blue-600', 'text-blue-600');
        accountBtn.classList.remove('border-transparent', 'text-gray-600');
        expenseBtn.classList.remove('border-blue-600', 'text-blue-600');
        expenseBtn.classList.add('border-transparent', 'text-gray-600');
    } else {
        accountTab.classList.add('hidden');
        expenseTab.classList.remove('hidden');
        expenseBtn.classList.add('border-blue-600', 'text-blue-600');
        expenseBtn.classList.remove('border-transparent', 'text-gray-600');
        accountBtn.classList.remove('border-blue-600', 'text-blue-600');
        accountBtn.classList.add('border-transparent', 'text-gray-600');
    }

    window.activeContainerPopupTab = tabName;
    window.updateContainerPopupPrintButton();
};

window.updateContainerPopupPrintButton = function() {
    const desktopLabel = document.getElementById('containerPopupPrintBtnDesktopLabel');
    const mobileLabel = document.getElementById('containerPopupPrintBtnMobileLabel');
    if (!desktopLabel || !mobileLabel) return;

    if (window.activeContainerPopupTab === 'expense') {
        desktopLabel.textContent = 'Print Expense Report';
        mobileLabel.textContent = 'Expense';
    } else {
        desktopLabel.textContent = 'Print Transactions';
        mobileLabel.textContent = 'Print';
    }
};

window.printActiveContainerReport = function(containerId, customerId, containerNumber, blNumber) {
    if (window.activeContainerPopupTab === 'expense') {
        window.printExpenseReportForCustomer(containerId, customerId, containerNumber, blNumber);
        return;
    }
    window.printContainerTransactions(containerId, customerId, containerNumber, blNumber);
};

// Toggle inline expense form
window.toggleExpenseForm = function() {
    const form = document.getElementById('inlineExpenseForm');
    const btn = document.getElementById('toggleExpenseBtn');
    if (!form || !btn) return;
    
    if (form.classList.contains('hidden')) {
        form.classList.remove('hidden');
        btn.innerHTML = '<i class="fas fa-times mr-2"></i>Cancel';
        btn.classList.remove('bg-orange-600', 'hover:bg-orange-700');
        btn.classList.add('bg-gray-600', 'hover:bg-gray-700');
    } else {
        form.classList.add('hidden');
        btn.innerHTML = '<i class="fas fa-plus mr-2"></i>Add Expense';
        btn.classList.remove('bg-gray-600', 'hover:bg-gray-700');
        btn.classList.add('bg-orange-600', 'hover:bg-orange-700');
        // Reset form
        const actualForm = document.getElementById('containerExpenseForm');
        if (actualForm) {
            actualForm.reset();
            actualForm.querySelector('[name="expense_id"]').value = '';
            actualForm.querySelector('[name="expense_date"]').value = new Date().toISOString().split('T')[0];
        }
    }
};

// Edit expense inline
window.editExpenseInline = function(exp) {
    // Show the form
    const form = document.getElementById('inlineExpenseForm');
    const btn = document.getElementById('toggleExpenseBtn');
    if (form && form.classList.contains('hidden')) {
        form.classList.remove('hidden');
        if (btn) {
            btn.innerHTML = '<i class="fas fa-times mr-2"></i>Cancel';
            btn.classList.remove('bg-orange-600', 'hover:bg-orange-700');
            btn.classList.add('bg-gray-600', 'hover:bg-gray-700');
        }
    }
    
    // Populate form fields
    const actualForm = document.getElementById('containerExpenseForm');
    if (actualForm) {
        actualForm.querySelector('[name="expense_id"]').value = exp.id || '';
        actualForm.querySelector('[name="expense_type"]').value = exp.expense_type || '';
        actualForm.querySelector('[name="amount"]').value = exp.amount || '';
        actualForm.querySelector('[name="expense_date"]').value = exp.expense_date || '';
        actualForm.querySelector('[name="agent_id"]').value = exp.agent_id || '';
        actualForm.querySelector('[name="description"]').value = exp.description || '';
        
        // Scroll to form
        form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
};

// Refresh expense tab data without reopening modal
window.refreshExpenseTabData = function(containerId, customerId) {
    return fetch(`containers.php?action=details&id=${containerId}`, {
        headers: {'X-Requested-With': 'XMLHttpRequest'}
    })
    .then(r => r.json())
    .then(detailsData => {
        if (!detailsData.success || !detailsData.data) {
            throw new Error('Failed to load expense data');
        }
        
        const expenses = detailsData.expenses || [];
        const totalExpenses = parseFloat(detailsData.total_expenses || 0);
        const containerStatus = detailsData.data?.status || 'pending';
        const isCompleted = (containerStatus === 'completed');
        
        // Update total expenses card
        const expenseTab = document.getElementById('expenseTab');
        const totalExpensesElement = document.getElementById('expenseTabTotalAmount');
        if (totalExpensesElement) {
            totalExpensesElement.textContent = 'Rs ' + formatCurrency(totalExpenses);
            totalExpensesElement.className = getResponsiveFontClass(totalExpenses) + ' font-bold text-orange-900 break-words';
        }
        
        // Rebuild expenses table
        const expensesRows = expenses.length > 0
            ? expenses.map(exp => `
                <tr class="border-b last:border-b-0">
                    <td class="px-3 py-2 text-xs text-center">${escapeHtml(exp.expense_date || '-')}</td>
                    <td class="px-3 py-2 text-xs text-center">${escapeHtml(exp.expense_type || '-')}</td>
                    <td class="px-3 py-2 text-xs text-center font-semibold">Rs ${formatCurrency(exp.amount)}</td>
                    <td class="px-3 py-2 text-xs text-center">${escapeHtml(exp.agent_name || '-')}</td>
                    <td class="px-3 py-2 text-xs text-center">
                        ${exp.proof ? `<button type="button" onclick="openExpenseProofPreview('${encodeURIComponent(exp.proof)}', '${escapeHtml(cont.container_number||'')}')" class="text-blue-600 hover:text-blue-800" title="View Proof"><i class="fas fa-eye"></i></button>` : '<span class="text-gray-400">-</span>'}
                    </td>
                    <td class="px-3 py-2 text-xs text-center">
                        ${isAdmin && !isCompleted ? `
                            <div class="flex gap-1 justify-center">
                                <button type="button" onclick='editExpenseInline(${JSON.stringify(exp)})' class="w-7 h-7 inline-flex items-center justify-center rounded bg-blue-100 text-blue-700 hover:bg-blue-200" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button type="button" onclick="deleteExpenseFromCustomer(${exp.id}, ${containerId}, ${customerId}, true)" class="w-7 h-7 inline-flex items-center justify-center rounded bg-red-100 text-red-700 hover:bg-red-200" title="Delete">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        ` : (isAdmin && isCompleted ? `
                            <span class="text-gray-400 text-xs"><i class="fas fa-lock"></i> Locked</span>
                        ` : '-')}
                    </td>
                </tr>
            `).join('')
            : '<tr><td colspan="6" class="px-3 py-4 text-center text-sm text-gray-500">No expenses yet</td></tr>';
        
        // Update expenses table
        const expensesTableBody = expenseTab ? expenseTab.querySelector('table tbody') : null;
        if (expensesTableBody) {
            expensesTableBody.innerHTML = expensesRows;
        }

        // Ensure the expense tab is visible (stays on expense tab after any operation)
        if (document.getElementById('containerTabbedModal')) {
            window.switchContainerTab('expense');
        }

        // Refresh containers list and account summary
        if (customerId) {
            window.refreshCustomerContainers(customerId);
            window.refreshAccountSummary(customerId);
        }
        
        return true;
    })
    .catch(err => {
        console.error('Error refreshing expense data:', err);
        throw err;
    });
};

window.openContainerAccountModal = function(containerId, customerId) {
    fetch(`customers.php?action=container_account&id=${containerId}`, {
        headers: {'X-Requested-With': 'XMLHttpRequest'}
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success || !data.container) {
            showAlert(data.error || 'Unable to load container account.', 'error');
            return;
        }

        const cont = data.container;
        const acc = data.account || {total_amount: 0, total_paid: 0, remaining_balance: 0};
        const transactions = data.transactions || [];
        const isCompleted = (cont.status === 'completed');

        const transactionsRows = transactions.length > 0
            ? transactions.map((t) => {
                const isCredit = t.transaction_type === 'credit';
                const typeClass = isCredit ? 'text-green-700 bg-green-100' : 'text-red-700 bg-red-100';
                const paymentMethod = encodeURIComponent(t.payment_method || '');
                const referenceNumber = encodeURIComponent(t.reference_number || '');
                const description = encodeURIComponent(t.description || '');
                const proof = encodeURIComponent(t.proof || '');
                const proofDisplay = t.proof
                    ? `<button type="button" onclick="openTransactionProofPreview('${proof}', '${escapeHtml(cont.container_number||'')}')" class="text-blue-600 hover:text-blue-800" title="View Proof"><i class="fas fa-file-alt"></i></button>`
                    : '<span class="text-gray-400">-</span>';
                
                // Show edit/delete buttons only if container is not completed and user is admin
                const actionButtons = !isAdmin ? '' : (!isCompleted ? `
                    <div class="flex items-center justify-center gap-1">
                        <button type="button" onclick="editContainerTransaction(${t.id}, '${t.transaction_type}', ${parseFloat(t.amount || 0)}, '${escapeHtml(t.transaction_date || '')}', '${paymentMethod}', '${referenceNumber}', '${description}', '${proof}')" class="w-7 h-7 inline-flex items-center justify-center rounded bg-blue-100 text-blue-700 hover:bg-blue-200" title="Edit">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button type="button" onclick="deleteContainerTransaction(${t.id}, ${cont.id}, ${customerId})" class="w-7 h-7 inline-flex items-center justify-center rounded bg-red-100 text-red-700 hover:bg-red-200" title="Delete">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                ` : '<span class="text-gray-400 text-xs">Locked</span>');
                
                return `
                    <tr class="border-b last:border-b-0">
                        <td class="px-3 py-2 text-xs text-center text-gray-600">${escapeHtml(t.transaction_date || '-')}</td>
                        <td class="px-3 py-2 text-xs text-center"><span class="px-2 py-1 rounded-full font-semibold ${typeClass}">${isCredit ? 'Paid' : 'Debit'}</span></td>
                        <td class="px-3 py-2 text-xs text-center font-semibold">Rs ${formatCurrency(t.amount)}</td>
                        <td class="px-3 py-2 text-xs text-center text-gray-600">${escapeHtml(t.payment_method || '-')}</td>
                        <td class="px-3 py-2 text-xs text-center text-gray-600">${escapeHtml(t.reference_number || '-')}</td>
                        <td class="px-3 py-2 text-xs text-center text-gray-600">${escapeHtml(t.description || '-')}</td>
                        <td class="px-3 py-2 text-xs text-center">${proofDisplay}</td>
                        <td class="px-3 py-2 text-xs text-center">
                            ${actionButtons}
                        </td>
                    </tr>
                `;
            }).join('')
            : `<tr><td colspan="${isAdmin ? 8 : 7}" class="px-3 py-4 text-center text-sm text-gray-500">No container transactions yet</td></tr>`;

        // Transaction form HTML - only visible to admins when not completed
        const transactionFormHtml = !isAdmin ? '' : (!isCompleted ? `
            <div class="border border-gray-200 rounded-xl p-4">
                <h4 class="text-sm font-bold text-gray-800 mb-3"><i class="fas fa-plus-circle text-blue-600 mr-1"></i>Record Container Transaction</h4>
                <form id="containerTransactionForm" class="space-y-3">
                    <input type="hidden" name="container_id" value="${cont.id}">
                    <input type="hidden" name="customer_id" value="${cont.customer_id || customerId}">
                    <input type="hidden" name="transaction_id" id="container_transaction_id" value="">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1">Type</label>
                            <select name="transaction_type" class="w-full border border-gray-300 p-2 rounded text-sm bg-gray-100" disabled>
                                <option value="credit" selected>Paid (Credit)</option>
                            </select>
                            <input type="hidden" name="transaction_type" value="credit">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1">Amount</label>
                            <input type="number" step="0.01" min="0.01" name="amount" required class="w-full border border-gray-300 p-2 rounded text-sm" placeholder="0.00">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1">Date</label>
                            <input type="date" name="transaction_date" value="${new Date().toISOString().split('T')[0]}" required class="w-full border border-gray-300 p-2 rounded text-sm">
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1">Payment Method</label>
                            <input type="text" name="payment_method" class="w-full border border-gray-300 p-2 rounded text-sm" placeholder="Cash / Bank / Cheque">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1">Reference No.</label>
                            <input type="text" name="reference_number" class="w-full border border-gray-300 p-2 rounded text-sm" placeholder="Receipt/Ref #">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1">Description</label>
                            <input type="text" name="description" class="w-full border border-gray-300 p-2 rounded text-sm" placeholder="Notes">
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1">Upload Proof <span class="text-gray-500 font-normal">(Receipt/Bank Slip)</span></label>
                            <input type="file" name="proof" accept=".pdf,.jpg,.jpeg,.png" class="w-full border border-gray-300 p-2 rounded text-sm">
                            <p class="text-xs text-gray-500 mt-1">Allowed: PDF, JPG, PNG (Max 5MB)</p>
                        </div>
                        <div id="currentProofDisplay" class="hidden">
                            <label class="block text-xs font-semibold text-gray-700 mb-1">Current Proof</label>
                            <div class="flex items-center gap-2">
                                <a id="currentProofLink" href="#" target="_blank" class="text-blue-600 hover:underline text-sm"><i class="fas fa-file-alt mr-1"></i>View Current</a>
                            </div>
                        </div>
                    </div>
                    <div class="flex justify-end">
                        <button type="button" id="cancelContainerTransactionEditBtn" onclick="cancelContainerTransactionEdit()" class="hidden mr-2 px-4 py-2 border border-gray-300 text-gray-700 rounded text-sm hover:bg-gray-50"><i class="fas fa-times mr-1"></i>Cancel Edit</button>
                        <button type="submit" id="saveContainerTransactionBtn" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded text-sm"><i class="fas fa-save mr-1"></i>Save Transaction</button>
                    </div>
                </form>
            </div>
        ` : `
            <div class="border border-green-200 bg-green-50 rounded-xl p-4 text-center">
                <i class="fas fa-check-circle text-green-600 text-3xl mb-2"></i>
                <h4 class="text-sm font-bold text-green-800 mb-1">Container Fully Paid</h4>
                <p class="text-xs text-green-700">This container has been marked as completed. No further transactions can be added.</p>
            </div>
        `);

        const modalHtml = `
            <div id="containerAccountModal" class="fixed top-0 left-0 right-0 bottom-0 bg-black bg-opacity-50 flex items-center justify-center z-[120] animate-fadeIn p-4" style="min-height: 100vh;" onclick="if(event.target===this) closeDynamicModal('containerAccountModal')">
                <div class="bg-white rounded-2xl shadow-2xl w-full max-w-5xl max-h-[92vh] overflow-hidden animate-slideIn">
                    <div class="bg-gradient-to-r from-indigo-600 to-blue-700 text-white px-6 py-3 flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-bold"><i class="fas fa-wallet mr-2"></i>Container Account - ${escapeHtml(cont.container_number || '')}</h3>
                            <p class="text-xs text-blue-100 mt-1">BL: ${escapeHtml(cont.bl_number || 'N/A')}</p>
                        </div>
                        <button onclick="closeDynamicModal('containerAccountModal')" class="text-white hover:text-gray-200 text-xl"><i class="fas fa-times"></i></button>
                    </div>

                    <div class="p-6 max-h-[calc(92vh-70px)] overflow-y-auto space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 text-center">
                                <p class="text-xs uppercase text-blue-700 font-semibold">Total Balance</p>
                                <p id="acctModalTotalBalance" class="${getResponsiveFontClass(acc.total_amount)} font-bold text-blue-900 break-words">Rs ${formatCurrency(acc.total_amount)}</p>
                            </div>
                            <div class="bg-green-50 border border-green-200 rounded-xl p-4 text-center">
                                <p class="text-xs uppercase text-green-700 font-semibold">Paid</p>
                                <p id="acctModalTotalPaid" class="${getResponsiveFontClass(acc.total_paid)} font-bold text-green-900 break-words">Rs ${formatCurrency(acc.total_paid)}</p>
                            </div>
                            <div class="bg-purple-50 border border-purple-200 rounded-xl p-4 text-center">
                                <p class="text-xs uppercase text-purple-700 font-semibold">Remaining</p>
                                <p id="acctModalRemaining" class="${getResponsiveFontClass(acc.remaining_balance)} font-bold text-purple-900 break-words">Rs ${formatCurrency(acc.remaining_balance)}</p>
                            </div>
                        </div>

                        <div id="acctModalTransactionFormArea">
                        ${transactionFormHtml}
                        </div>

                        <div class="border border-gray-200 rounded-xl overflow-hidden">
                            <div class="bg-gray-50 px-4 py-3 border-b">
                                <h4 class="text-sm font-bold text-gray-800"><i class="fas fa-table text-indigo-600 mr-1"></i>Container Transactions</h4>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="w-full">
                                    <thead>
                                        <tr class="bg-gray-50 border-b">
                                            <th class="px-3 py-2 text-xs font-bold text-gray-700 uppercase text-center">Date</th>
                                            <th class="px-3 py-2 text-xs font-bold text-gray-700 uppercase text-center">Type</th>
                                            <th class="px-3 py-2 text-xs font-bold text-gray-700 uppercase text-center">Amount</th>
                                            <th class="px-3 py-2 text-xs font-bold text-gray-700 uppercase text-center">Method</th>
                                            <th class="px-3 py-2 text-xs font-bold text-gray-700 uppercase text-center">Reference</th>
                                            <th class="px-3 py-2 text-xs font-bold text-gray-700 uppercase text-center">Description</th>
                                            <th class="px-3 py-2 text-xs font-bold text-gray-700 uppercase text-center">Proof</th>
                                            ${isAdmin ? '<th class="px-3 py-2 text-xs font-bold text-gray-700 uppercase text-center">Actions</th>' : ''}
                                        </tr>
                                    </thead>
                                    <tbody id="acctModalTransactionsTbody">${transactionsRows}</tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;

        closeDynamicModal('containerAccountModal');
        document.body.insertAdjacentHTML('beforeend', modalHtml);

        const form = document.getElementById('containerTransactionForm');
        if (form) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                const formData = new FormData(this);
                fetch('customers.php?action=add_container_transaction', {
                    method: 'POST',
                    headers: {'X-Requested-With': 'XMLHttpRequest'},
                    body: formData
                })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        showAlert(res.message || 'Container transaction recorded successfully!', 'success');
                        window.refreshContainerAccountModalData(containerId, customerId);
                        window.refreshAccountSummary(customerId);
                        if (customerId) window.refreshCustomerContainers(customerId);
                    } else {
                        showAlert(res.error || 'Failed to save container transaction.', 'error');
                    }
                })
                .catch(err => {
                    console.error(err);
                    showAlert('Failed to save container transaction.', 'error');
                });
            });
        }
    })
    .catch(err => {
        console.error(err);
        showAlert('Unable to load container account.', 'error');
    });
};

window.editContainerTransaction = function(id, type, amount, transactionDate, paymentMethodEncoded, referenceNumberEncoded, descriptionEncoded, proofEncoded) {
    const form = document.getElementById('containerTransactionForm');
    if (!form) return;

    form.elements['transaction_id'].value = id;
    form.elements['transaction_type'].value = type || 'credit';
    form.elements['amount'].value = amount || '';
    form.elements['transaction_date'].value = transactionDate || new Date().toISOString().split('T')[0];
    form.elements['payment_method'].value = decodeURIComponent(paymentMethodEncoded || '');
    form.elements['reference_number'].value = decodeURIComponent(referenceNumberEncoded || '');
    form.elements['description'].value = decodeURIComponent(descriptionEncoded || '');

    // Show current proof if exists
    const proof = decodeURIComponent(proofEncoded || '');
    const currentProofDisplay = document.getElementById('currentProofDisplay');
    const currentProofLink = document.getElementById('currentProofLink');
    if (proof && currentProofDisplay && currentProofLink) {
        currentProofDisplay.classList.remove('hidden');
        currentProofLink.href = 'uploads/transaction_proofs/' + proof;
    } else if (currentProofDisplay) {
        currentProofDisplay.classList.add('hidden');
    }

    const saveBtn = document.getElementById('saveContainerTransactionBtn');
    if (saveBtn) saveBtn.innerHTML = '<i class="fas fa-save mr-1"></i>Update Transaction';

    const cancelBtn = document.getElementById('cancelContainerTransactionEditBtn');
    if (cancelBtn) cancelBtn.classList.remove('hidden');

    form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
};

window.cancelContainerTransactionEdit = function() {
    const form = document.getElementById('containerTransactionForm');
    if (!form) return;

    form.reset();
    form.elements['transaction_id'].value = '';
    form.elements['transaction_date'].value = new Date().toISOString().split('T')[0];

    // Hide current proof display
    const currentProofDisplay = document.getElementById('currentProofDisplay');
    if (currentProofDisplay) currentProofDisplay.classList.add('hidden');

    const saveBtn = document.getElementById('saveContainerTransactionBtn');
    if (saveBtn) saveBtn.innerHTML = '<i class="fas fa-save mr-1"></i>Save Transaction';

    const cancelBtn = document.getElementById('cancelContainerTransactionEditBtn');
    if (cancelBtn) cancelBtn.classList.add('hidden');
};

window.deleteContainerTransaction = function(transactionId, containerId, customerId) {
    showConfirm('Delete this container transaction?', function() {
        const formData = new FormData();
        formData.append('transaction_id', transactionId);
        formData.append('container_id', containerId);

        fetch('customers.php?action=delete_container_transaction', {
            method: 'POST',
            headers: {'X-Requested-With': 'XMLHttpRequest'},
            body: formData
        })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                showAlert(res.message || 'Container transaction deleted successfully!', 'success');
                window.refreshOpenContainerPopups(containerId, customerId, { preferredTab: 'account' });
                if (customerId) window.refreshCustomerContainers(customerId);
            } else {
                showAlert(res.error || 'Failed to delete container transaction.', 'error');
            }
        })
        .catch(err => {
            console.error(err);
            showAlert('Failed to delete container transaction.', 'error');
        });
    });
};

window.openAddContainerForm = function(customerId, event) {
    if (event) event.stopPropagation();
    const formDiv = document.getElementById('addContainerForm');
    const form = document.getElementById('customerProfileContainerForm');
    const titleText = document.getElementById('containerFormTitleText');
    const titleIcon = document.getElementById('containerFormTitleIcon');
    const submitText = document.getElementById('containerFormSubmitText');
    const submitTextMobile = document.getElementById('containerFormSubmitTextMobile');
    
    if (formDiv && form) {
        // Reset the form to clear any old values
        form.reset();
        // Update the customer_id value
        form.elements['customer_id'].value = customerId;
        form.elements['container_id'].value = '';
        form.elements['existing_invoice'].value = '';
        form.elements['status'].value = 'pending';
        if (titleText) titleText.textContent = 'New Container';
        if (titleIcon) titleIcon.textContent = 'add_box';
        if (submitText) submitText.textContent = 'Save Container';
        if (submitTextMobile) submitTextMobile.textContent = 'Save';
        // Show the form
        formDiv.classList.remove('hidden');
        // Scroll to form
        formDiv.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
};

window.closeAddContainerForm = function() {
    const formDiv = document.getElementById('addContainerForm');
    const titleText = document.getElementById('containerFormTitleText');
    const titleIcon = document.getElementById('containerFormTitleIcon');
    const submitText = document.getElementById('containerFormSubmitText');
    const submitTextMobile = document.getElementById('containerFormSubmitTextMobile');
    if (formDiv) {
        formDiv.classList.add('hidden');
        const form = document.getElementById('customerProfileContainerForm');
        if (form) {
            form.reset();
            form.elements['container_id'].value = '';
            form.elements['existing_invoice'].value = '';
            form.elements['status'].value = 'pending';
        }
        if (titleText) titleText.textContent = 'New Container';
        if (titleIcon) titleIcon.textContent = 'add_box';
        if (submitText) submitText.textContent = 'Save Container';
        if (submitTextMobile) submitTextMobile.textContent = 'Save';
    }
};

window.submitContainerForm = function(customerId) {
    const form = document.getElementById('customerProfileContainerForm');
    if (!form) {
        console.error('Container form not found');
        showAlert('Form not found. Please try again.', 'error');
        return;
    }
    
    // Get form values for validation
    const netWeight = parseFloat(form.elements['net_weight'].value) || 0;
    const grossWeight = parseFloat(form.elements['gross_weight'].value) || 0;
    const rate = parseFloat(form.elements['rate'].value) || 0;
    const packages = parseInt(form.elements['packages'].value) || 0;
    
    // Validation checks
    if (netWeight <= 0 && grossWeight <= 0) {
        showAlert('Please enter either Net Weight or Gross Weight greater than 0', 'error');
        return;
    }
    
    if (rate <= 0) {
        showAlert('Rate must be greater than 0', 'error');
        return;
    }
    
    if (packages <= 0) {
        showAlert('Number of packages must be greater than 0', 'error');
        return;
    }
    
    const formData = new FormData(form);
    const editContainerId = parseInt(form.elements['container_id'].value || '0', 10);
    const isEditMode = editContainerId > 0;
    const endpoint = isEditMode ? 'containers.php?action=edit' : 'customers.php?action=add_container';
    console.log(isEditMode ? '✏️ Submitting container edit form' : '➕ Submitting container form');
    
    fetch(endpoint, {
        method: 'POST',
        headers: {'X-Requested-With': 'XMLHttpRequest'},
        body: formData
    })
    .then(r => {
        if (!r.ok) throw new Error(`HTTP ${r.status}`);
        return r.json();
    })
    .then(data => {
        console.log(isEditMode ? '✅ Container updated:' : '✅ Container added:', data);
        if (data.success) {
            showAlert(isEditMode ? 'Container updated successfully!' : 'Container added successfully!', 'success');
            window.closeAddContainerForm();
            window.refreshCustomerContainers(customerId);
            window.refreshAccountSummary(customerId);
        } else {
            console.error('❌ Server error:', data.error);
            showAlert(data.error || 'Failed to add container. Please try again.', 'error');
        }
    })
    .catch(err => {
        console.error('❌ Error submitting container:', err.message);
        showAlert('Error: ' + err.message, 'error');
    });
};

window.openContainerFormForCustomer = function(customerId, customerName, event) {
    // This function is now deprecated and replaced with openAddContainerForm
    if (event) event.stopPropagation();
    openAddContainerForm(customerId, event);
};

window.confirmDeleteContainer = function(containerId, containerNumber, customerId) {
    showConfirm(
        `Are you sure you want to delete Container #${containerNumber}?<br><br>This will also delete all associated expenses and invoices.`,
        function() {
            const formData = new FormData();
            formData.append('id', containerId);
            
            fetch(`containers.php?action=delete`, {
                method: 'POST',
                headers: {'X-Requested-With': 'XMLHttpRequest'},
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showAlert('Container deleted successfully!', 'success');

                    if (Number(window.currentCustomerDetailContainerId || 0) === Number(containerId || 0)) {
                        window.closeDetailsModal();
                        window.currentCustomerDetailContainerId = null;
                        window.currentDetailsCustomerId = null;
                    }

                    if (document.getElementById('containerTabbedModal')) {
                        closeDynamicModal('containerTabbedModal');
                    }

                    if (document.getElementById('containerAccountModal')) {
                        closeDynamicModal('containerAccountModal');
                    }

                    window.refreshCustomerContainers(customerId);
                    window.refreshAccountSummary(customerId);
                } else {
                    showAlert(data.error || 'Failed to delete container.', 'error');
                }
            })
            .catch(err => {
                console.error('Error:', err);
                showAlert('An error occurred while deleting the container.', 'error');
            });
        }
    );
};

window.currentCustomerDetailContainerId = null;

// View container details in modal
window.viewContainerDetails = function(containerId, customerId) {
    window.currentCustomerDetailContainerId = containerId;
    window.currentDetailsCustomerId = customerId;
    
    console.log('🔹 viewContainerDetails called:', {containerId, customerId});
    
    // First, test the AJAX connection
    fetch('containers.php?action=test', {
        method: 'GET',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
        }
    })
    .then(r => {
        console.log('📝 Test endpoint status:', r.status);
        return r.json();
    })
    .then(testData => {
        console.log('✅ Test endpoint response:', testData);
        
        // Now fetch the actual container details
        return fetch(`containers.php?action=details&id=${containerId}`, {
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        });
    })
    .then(r => {
        console.log('✅ Container details fetch status:', r.status, r.statusText);
        console.log('📝 Response type:', r.headers.get('Content-Type'));
        
        if (!r.ok) {
            throw new Error(`HTTP ${r.status}: ${r.statusText}`);
        }
        
        // Parse as text first to see actual content
        return r.text().then(text => {
            console.log('📝 Raw response (first 300 chars):', text.substring(0, 300));
            try {
                return JSON.parse(text);
            } catch (parseErr) {
                console.error('❌ JSON parse failed:', parseErr.message);
                console.error('❌ Full response:', text);
                throw new Error('Invalid JSON response: ' + text.substring(0, 150));
            }
        });
    })
    .then(data => {
        console.log('✅ Container details data:', data);
        if (!data || !data.success || !data.data) {
            const errorMsg = data?.message || 'Unable to load container profile.';
            console.error('❌ Data validation failed:', errorMsg);
            showAlert(errorMsg, 'error');
            return;
        }

        const cont = data.data;
        const totalExpenses = parseFloat(data.total_expenses || 0);
        const expenses = data.expenses || [];
        
        // Get or create the modal
        let modal = document.getElementById('customerDetailsModal');
        if (!modal) {
            showAlert('Detail modal not found. Please refresh the page.', 'error');
            return;
        }
        
        // Helper function to safely set element content
        const setElementContent = (id, content, isHTML = false) => {
            const elem = document.getElementById(id);
            if (!elem) {
                console.warn(`Element with ID "${id}" not found in modal`);
                return;
            }
            if (isHTML) {
                elem.innerHTML = content;
            } else {
                elem.textContent = content;
            }
        };
        
        // Populate the modal with container details
        setElementContent('customerDetailContainer', escapeHtml(cont.container_number || '-'));
        setElementContent('customerDetailBL', 'BL: ' + escapeHtml(cont.bl_number || 'N/A'));
        
        const statusElem = document.getElementById('customerDetailStatus');
        if (statusElem) {
            statusElem.textContent = escapeHtml(cont.status || 'pending');
            statusElem.className = 'px-3 py-1 text-xs font-semibold rounded-full ' + 
                (cont.status === 'completed' ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700');
        }
        
        setElementContent('customerDetailNetWeight', (cont.net_weight || 0).toFixed(2));
        setElementContent('customerDetailGrossWeight', (cont.gross_weight || 0).toFixed(2));
        setElementContent('customerDetailCreatedAt', cont.created_at || '-');
        setElementContent('customerDetailTotalExpenses', totalExpenses.toFixed(2));
        
        // Populate expenses table
        const expensesTable = document.getElementById('customerDetailsExpensesTable');
        if (expensesTable) {
            expensesTable.innerHTML = '';
            
            if (expenses.length > 0) {
                expenses.forEach(exp => {
                    const row = document.createElement('tr');
                    row.className = 'border-b';
                    row.innerHTML = `
                        <td class="px-4 py-2 text-sm">${escapeHtml(exp.expense_type || '-')}</td>
                        <td class="px-4 py-2 text-sm font-semibold">${formatCurrency(exp.amount)}</td>
                        <td class="px-4 py-2 text-sm">${escapeHtml(exp.expense_date || '-')}</td>
                        <td class="px-4 py-2 text-sm">${escapeHtml(exp.agent_name || '-')}</td>
                        <td class="px-4 py-2 text-sm">
                            ${exp.proof ? `<button type="button" onclick="openExpenseProofPreview('${encodeURIComponent(exp.proof)}', '${escapeHtml(cont.container_number||'')}')" class="text-blue-600 hover:text-blue-800"><i class="fas fa-eye mr-1"></i>View</button>` : '<span class="text-gray-400">No proof</span>'}
                        </td>
                        <td class="px-4 py-2 text-right">
                            <div class="flex gap-1 justify-end">
                                ${isAdmin ? `<button type="button" data-action="edit-expense" data-container-id="${containerId}" data-customer-id="${customerId}" data-expense-payload="${encodeURIComponent(JSON.stringify(exp))}" class="w-8 h-8 inline-flex items-center justify-center rounded-full border border-blue-200 text-blue-600 hover:bg-blue-50 transition"><i class="fas fa-edit"></i></button>` : ''}
                                ${isAdmin ? `<button type="button" data-action="delete-expense" data-expense-id="${exp.id}" data-container-id="${containerId}" data-customer-id="${customerId}" class="w-8 h-8 inline-flex items-center justify-center rounded-full border border-red-200 text-red-600 hover:bg-red-50 transition"><i class="fas fa-trash"></i></button>` : ''}
                            </div>
                        </td>
                    `;
                    expensesTable.appendChild(row);
                });
            } else {
                const row = document.createElement('tr');
                row.innerHTML = '<td colspan="6" class="px-4 py-3 text-center text-gray-500">No expenses recorded</td>';
                expensesTable.appendChild(row);
            }
        } else {
            console.warn('Expenses table element not found in modal');
        }
        
        // Show modal
        modal.classList.remove('hidden');
    })
    .catch(err => {
        console.error('❌ Error loading container details:', {
            message: err.message,
            stack: err.stack,
            containerId: containerId,
            customerId: customerId
        });
        showAlert('Unable to load container profile: ' + err.message + '. Please check browser console (F12) for details.', 'error');
    });
};

window.closeDetailsModal = function() {
    const modal = document.getElementById('customerDetailsModal');
    if (modal) {
        modal.classList.add('hidden');
    }
};

window.openExpenseModalEdit = function(containerId, customerId, expensePayload = null) {
    let expenseData = null;

    if (expensePayload && typeof expensePayload === 'string') {
        try {
            expenseData = JSON.parse(decodeURIComponent(expensePayload));
        } catch (err) {
            console.error('❌ Failed to decode expense payload:', err);
            showAlert('Unable to open expense editor. Invalid expense data.', 'error');
            return;
        }
    } else if (expensePayload && typeof expensePayload === 'object') {
        expenseData = expensePayload;
    }

    openExpenseModal(containerId, customerId, expenseData);
};

window.openExpenseModal = function(containerId, customerId, expenseData = null) {
    console.log('🔹 openExpenseModal called:', {containerId, customerId, hasExpenseData: !!expenseData});
    
    // Get modal and form elements first
    const modal = document.getElementById('customerExpenseModalNew');
    const form = document.getElementById('customerExpenseFormNew');
    
    if (!modal) {
        showAlert('Expense modal not found. Please refresh the page.', 'error');
        console.error('❌ Modal not found');
        return;
    }
    
    if (!form) {
        showAlert('Expense form not found. Please refresh the page.', 'error');
        console.error('❌ Form not found');
        return;
    }
    
    // Fetch container details and agents in parallel
    console.log('🔹 Fetching container details and agents...');
    
    Promise.all([
        // Fetch container details
        fetch(`containers.php?action=details&id=${containerId}`, {
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        })
        .then(r => {
            console.log('✅ Container details response:', r.status);
            if (!r.ok) throw new Error(`HTTP ${r.status}`);
            return r.json();
        })
        .catch(err => {
            console.error('❌ Container fetch error:', err.message);
            throw err;
        }),
        
        // Fetch agents
        fetch('customers.php?action=get_agents', {
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        })
        .then(r => {
            console.log('✅ Agents response:', r.status);
            if (!r.ok) throw new Error(`HTTP ${r.status}`);
            return r.json();
        })
        .then(data => data.agents || [])
        .catch(err => {
            console.error('❌ Agents fetch error:', err.message);
            return [];
        })
    ])
    .then(([containerData, agents]) => {
        console.log('✅ Both responses received:', {
            containerDataSuccess: containerData?.success,
            agentsCount: agents?.length || 0
        });
        
        // Validate container data
        if (!containerData || !containerData.success || !containerData.data) {
            const msg = containerData?.message || 'Unable to load container details';
            showAlert(msg, 'error');
            console.error('❌ Invalid container data:', containerData);
            return;
        }
        
        const cont = containerData.data;
        console.log('✅ Container:', {id: cont.id, number: cont.container_number});
        
        // Check if container is completed (fully paid) - prevent adding/editing expenses
        if (cont.status === 'completed') {
            showAlert('Cannot add or edit expenses - This container is fully paid and locked.', 'warning');
            console.log('⚠️ Container is completed, expenses locked');
            return;
        }
        
        // Get form elements
        const agentSelect = form.querySelector('#customerExpenseAgentNew');
        const title = modal.querySelector('h3');
        const containerIdField = form.querySelector('#customerExpenseContainerIdNew');
        const expenseIdField = form.querySelector('#customerExpenseIdNew');
        const customerIdField = form.querySelector('#customerExpenseCustomerIdNew');
        const typeField = form.querySelector('#customerExpenseTypeNew');
        const amountField = form.querySelector('#customerExpenseAmountNew');
        const dateField = form.querySelector('#customerExpenseDateNew');
        const proofHelp = form.querySelector('#customerExpenseProofHelpNew');
        
        // Validate all elements exist
        if (!agentSelect || !title || !containerIdField || !expenseIdField || !customerIdField) {
            showAlert('Form elements missing. Please refresh the page.', 'error');
            console.error('❌ Missing form elements:', {
                agentSelect: !!agentSelect,
                title: !!title,
                containerIdField: !!containerIdField,
                expenseIdField: !!expenseIdField,
                customerIdField: !!customerIdField
            });
            return;
        }
        
        // Reset form
        form.reset();
        console.log('✅ Form reset');
        
        // Populate agents dropdown
        agentSelect.innerHTML = '<option value="">-- No Agent --</option>' + 
            (agents || []).map(a => `<option value="${a.id}">${escapeHtml(a.name)}</option>`).join('');
        console.log('✅ Agents dropdown populated:', agents?.length || 0, 'agents');
        
        // Set hidden fields
        containerIdField.value = containerId;
        expenseIdField.value = '';
        customerIdField.value = customerId;
        console.log('✅ Hidden fields set');
        
        // Set mode (add or edit)
        if (expenseData) {
            // Edit mode
            console.log('📝 Edit mode for expense:', expenseData.id);
            title.innerHTML = '<i class="fas fa-edit mr-2"></i>Edit Expense - ' + escapeHtml(cont.container_number || '');
            expenseIdField.value = expenseData.id;
            
            if (typeField) typeField.value = expenseData.expense_type || '';
            if (amountField) amountField.value = expenseData.amount || '';
            if (dateField) dateField.value = expenseData.expense_date || '';
            if (agentSelect && expenseData.agent_id) agentSelect.value = expenseData.agent_id;
            if (proofHelp && expenseData.proof) proofHelp.textContent = 'Current: ' + escapeHtml(expenseData.proof);
        } else {
            // Add mode
            console.log('➕ Add mode for container:', cont.id);
            title.innerHTML = '<i class="fas fa-receipt mr-2"></i>Add Expense - ' + escapeHtml(cont.container_number || '');
            if (dateField) dateField.value = new Date().toISOString().split('T')[0];
            if (proofHelp) proofHelp.textContent = '';
        }
        
        // Setup form submission
        window.setupExpenseFormSubmission();
        console.log('✅ Form submission handler setup');
        
        // Show modal
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        modal.style.display = 'flex';
        document.body.classList.add('overflow-hidden');
        console.log('✅ Modal displayed');
    })
    .catch(err => {
        console.error('❌ Error in Promise.all:', err.message);
        showAlert('Error loading expense form: ' + err.message, 'error');
    });
};

window.closeExpenseModalNew = function() {
    const modal = document.getElementById('customerExpenseModalNew');
    if (modal) {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        modal.style.display = 'none';
        document.body.classList.remove('overflow-hidden');
        // Reset form
        const form = document.getElementById('customerExpenseFormNew');
        if (form) form.reset();
    }
};

// Setup expense form submission handler
window.setupExpenseFormSubmission = function() {
    const form = document.getElementById('customerExpenseFormNew');
    if (!form) return;

    form.onsubmit = function(e) {
        e.preventDefault();

        const formData = new FormData(this);
        const containerId = formData.get('container_id');
        const customerId = formData.get('customer_id');
        const expenseId = formData.get('expense_id');

        fetch('containers.php?action=expense', {
            method: 'POST',
            headers: {'X-Requested-With': 'XMLHttpRequest'},
            body: formData
        })
        .then(response => {
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            return response.text().then(text => {
                try {
                    return text ? JSON.parse(text) : { success: true };
                } catch (e) {
                    console.warn('Non-JSON save response, treating as success:', text.substring(0, 200));
                    return { success: true };
                }
            });
        })
        .then(data => {
            if (data && data.success === false) {
                showAlert(data.error || 'Failed to save expense.', 'error');
                return;
            }
            console.log('✅ Expense saved:', data);
            const expenseModal = document.getElementById('customerExpenseModalNew');
            if (expenseModal) {
                expenseModal.classList.add('hidden');
                expenseModal.classList.remove('flex');
                expenseModal.style.display = 'none';
            }
            document.body.classList.remove('overflow-hidden');
            this.reset();
            showAlert(expenseId ? 'Expense updated successfully!' : 'Expense added successfully!', 'success');
            window.closeExpenseModalNew();
            
            // Reopen the tabbed modal on expense tab
            if (containerId && customerId) {
                window.openContainerTabbedModal(containerId, customerId);
                // Switch to expense tab after reopening
                setTimeout(() => window.switchContainerTab('expense'), 100);
            }
            
            // Refresh containers list and account summary
            if (customerId) {
                window.refreshCustomerContainers(customerId);
                window.refreshAccountSummary(customerId);
            }
        })
        .catch(err => {
            console.error('Error saving expense:', err);
            showAlert('Failed to save expense: ' + err.message, 'error');
        });
    };
};

// Initialize form submission on page load
document.addEventListener('DOMContentLoaded', function() {
    window.setupExpenseFormSubmission();
});

window.loadAgentsList = function() {
    return fetch('customers.php?action=get_agents', {headers: {'X-Requested-With': 'XMLHttpRequest'}})
        .then(r => {
            if (!r.ok) throw new Error(`HTTP ${r.status}`);
            return r.json();
        })
        .then(data => {
            console.log('✅ Agents loaded:', data.agents?.length || 0);
            return (data.success && data.agents) ? data.agents : [];
        })
        .catch(err => {
            console.error('❌ Failed to load agents:', err.message);
            return [];
        });
};

window.deleteExpenseFromCustomer = function(expenseId, containerId, customerId, reopenTabbedModal = false) {
    showConfirm('Delete this expense record?', function() {
        const formData = new FormData();
        formData.append('id', expenseId);
        console.log('🗑️ Deleting expense:', expenseId);
        fetch('containers.php?action=delete_expense', {
            method: 'POST',
            headers: {'X-Requested-With': 'XMLHttpRequest'},
            body: formData
        })
        .then(r => {
            if (!r.ok) throw new Error(`HTTP ${r.status}`);
            return r.json();
        })
        .then(data => {
            console.log('✅ Delete response:', data);
            if (data.success) {
                showAlert('Expense deleted successfully!', 'success');
                window.refreshOpenContainerPopups(containerId, customerId, {
                    preferredTab: reopenTabbedModal ? 'expense' : null
                });
            } else {
                showAlert(data.message || 'Failed to delete expense.', 'error');
            }
        })
        .catch(err => {
            console.error('❌ Error deleting expense:', err.message);
            showAlert('Error deleting expense: ' + err.message, 'error');
        });
    });
};

window.openInvoiceModal = function(containerId, customerId) {
    console.log('🔹 Opening invoice modal for container:', containerId);
    
    // Check container status to determine if fields should be disabled
    fetch(`customers.php?action=container_account&id=${containerId}`, {headers: {'X-Requested-With': 'XMLHttpRequest'}})
    .then(r => r.json())
    .then(statusData => {
        const isCompleted = statusData.success && statusData.container && statusData.container.status === 'completed';
        
        // Proceed with loading invoice config (allow opening even if completed)
        return fetch(`containers.php?action=get_invoice_config&id=${containerId}`, {headers: {'X-Requested-With': 'XMLHttpRequest'}})
            .then(r => ({response: r, isCompleted: isCompleted}));
    })
    .then(({response, isCompleted}) => {
        console.log('Response status:', response.status, response.statusText);
        console.log('Container is completed:', isCompleted);
        if (!response.ok) throw new Error(`HTTP ${response.status} ${response.statusText}`);
        return response.text().then(text => ({text, isCompleted}));
    })
    .then(({text, isCompleted}) => {
        console.log('Response text:', text);
        try {
            const data = JSON.parse(text);
            return {data, isCompleted};
        } catch (e) {
            console.error('JSON Parse error:', e, 'Text was:', text);
            throw new Error('Invalid JSON response: ' + text.substring(0, 200));
        }
    })
    .then(({data, isCompleted}) => {
        console.log('✅ Invoice config loaded:', data);
        if (!data.success || !data.data) {
            showAlert(data.message || 'Unable to load invoice configuration.', 'error');
            return;
        }

        const cfg = data.data;
        const toNumber = (value, fallback = 0) => {
            const parsed = parseFloat(value);
            return Number.isFinite(parsed) ? parsed : fallback;
        };

        const defaultType = cfg.last_used_weight_type || 'gross';
        const containerGrossWeight = toNumber(cfg.gross_weight, 0);
        const containerNetWeight = toNumber(cfg.net_weight, 0);
        const lastWeightValue = toNumber(cfg.last_weight_value, NaN);
        const baseWeight = defaultType === 'net' ? containerNetWeight : containerGrossWeight;
        const fallbackWeight = baseWeight > 0 ? baseWeight : (containerGrossWeight > 0 ? containerGrossWeight : containerNetWeight);
        const defaultWeight = Number.isFinite(lastWeightValue) && lastWeightValue > 0 ? lastWeightValue : fallbackWeight;

        const containerRateValue = toNumber(cfg.rate, NaN);
        const lastRateValue = toNumber(cfg.last_rate_value, NaN);
        const defaultRate = (Number.isFinite(containerRateValue) && containerRateValue > 0)
            ? containerRateValue
            : ((Number.isFinite(lastRateValue) && lastRateValue > 0) ? lastRateValue : 0);
        const totalExpenseOnContainer = toNumber(cfg.total_expenses, 0);
        const disabledAttr = (isCompleted || !isAdmin) ? 'disabled' : '';
        const disabledClass = (isCompleted || !isAdmin) ? 'opacity-50 cursor-not-allowed bg-gray-100' : '';

        const modalHtml = `
            <div class="fixed top-0 left-0 right-0 bottom-0 bg-black/60 backdrop-blur-sm z-[65] flex items-center justify-center overflow-hidden p-2 sm:p-4" id="customerInvoiceConfigModal" style="min-height: 100vh;" onclick="if(event.target===this) window.requestCloseInvoiceConfigModal()">
                <div class="bg-white rounded-2xl shadow-2xl w-full max-w-[95%] sm:max-w-sm md:max-w-md mx-auto animate-slideIn overflow-hidden">
                    <!-- Header -->
                    <div class="bg-gradient-to-br from-blue-600 via-blue-700 to-indigo-700 text-white px-4 py-3.5 relative overflow-hidden">
                        <div class="absolute top-0 right-0 w-48 h-48 bg-white/5 rounded-full -mr-24 -mt-24"></div>
                        <div class="absolute bottom-0 left-0 w-32 h-32 bg-white/5 rounded-full -ml-16 -mb-16"></div>
                        <div class="relative flex items-center justify-between">
                            <div class="flex items-center gap-2.5">
                                <div class="w-10 h-10 bg-white/20 backdrop-blur-sm rounded-xl flex items-center justify-center">
                                    <i class="fas fa-file-invoice-dollar text-lg"></i>
                                </div>
                                <div>
                                    <h3 class="text-lg font-bold tracking-tight">Invoice Configuration</h3>
                                    <p class="text-blue-100 text-xs mt-0.5">Configure and generate invoice</p>
                                </div>
                            </div>
                            <button onclick="window.requestCloseInvoiceConfigModal()" class="w-9 h-9 flex items-center justify-center rounded-lg hover:bg-white/10 transition-colors">
                                <i class="fas fa-times text-lg"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Body -->
                    <div class="p-4">
                        <form id="customerInvoiceConfigForm" class="space-y-4">
                            <input type="hidden" name="container_id" value="${cfg.container_id}">
                            <input type="hidden" name="customer_id" value="${cfg.customer_id}">
                            
                            <!-- Info Cards -->
                            <div class="grid grid-cols-2 gap-2.5">
                                <div class="bg-gradient-to-br from-slate-50 to-slate-100 border border-slate-200 px-3 py-2 rounded-xl">
                                    <div class="flex items-center gap-1.5 mb-0.5">
                                        <i class="fas fa-box text-slate-400 text-xs"></i>
                                        <div class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Container</div>
                                    </div>
                                    <div class="text-sm font-bold text-slate-900 truncate">${escapeHtml(cfg.container_number)}</div>
                                </div>
                                <div class="bg-gradient-to-br from-slate-50 to-slate-100 border border-slate-200 px-3 py-2 rounded-xl">
                                    <div class="flex items-center gap-1.5 mb-0.5">
                                        <i class="fas fa-user text-slate-400 text-xs"></i>
                                        <div class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Customer</div>
                                    </div>
                                    <div class="text-sm font-bold text-slate-900 truncate">${escapeHtml(cfg.customer_name)}</div>
                                </div>
                            </div>

                            <!-- Form Grid -->
                            <div class="space-y-3">
                                <div class="grid grid-cols-2 gap-3">
                                    <!-- Weight Type -->
                                    <div>
                                        <label class="flex items-center gap-1.5 text-xs font-semibold text-slate-700 mb-1.5">
                                            <i class="fas fa-balance-scale text-blue-500 text-xs"></i>
                                            Weight Type
                                        </label>
                                        <select name="weight_type" id="invoice_weight_type" ${disabledAttr} class="w-full px-3 py-2 bg-white border-2 border-slate-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm font-medium text-slate-900 transition-all hover:border-slate-300 ${disabledClass}">
                                            <option value="gross" ${defaultType === 'gross' ? 'selected' : ''}>Gross Weight</option>
                                            <option value="net" ${defaultType === 'net' ? 'selected' : ''}>Net Weight</option>
                                        </select>
                                    </div>

                                    <!-- Weight Value -->
                                    <div>
                                        <label class="flex items-center gap-1.5 text-xs font-semibold text-slate-700 mb-1.5">
                                            <i class="fas fa-weight text-blue-500 text-xs"></i>
                                            Weight (kg)
                                        </label>
                                        <input type="number" step="0.01" name="weight_value" id="invoice_weight_value" value="${defaultWeight}" ${disabledAttr} class="w-full px-3 py-2 bg-white border-2 border-slate-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm font-semibold text-slate-900 transition-all hover:border-slate-300 ${disabledClass}">
                                    </div>
                                </div>

                                <!-- Rate -->
                                <div>
                                    <label class="flex items-center gap-1.5 text-xs font-semibold text-slate-700 mb-1.5">
                                        <i class="fas fa-tag text-blue-500 text-xs"></i>
                                        Rate per kg (Rs)
                                    </label>
                                    <input type="number" step="0.01" name="rate" id="invoice_rate" value="${defaultRate}" ${disabledAttr} class="w-full px-3 py-2 bg-white border-2 border-slate-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm font-semibold text-slate-900 transition-all hover:border-slate-300 ${disabledClass}">
                                </div>

                                <!-- Summary Blocks -->
                                <div class="grid grid-cols-3 gap-2.5">
                                    <div class="bg-gradient-to-br from-green-50 to-emerald-50 border border-green-200 rounded-xl p-2.5">
                                        <div class="text-[10px] font-bold text-green-700 uppercase tracking-wide">Total Invoice</div>
                                        <div class="text-base font-black text-green-700 leading-tight mt-1" id="invoice_total_amount_display">0.00</div>
                                    </div>
                                    <div class="bg-gradient-to-br from-orange-50 to-amber-50 border border-orange-200 rounded-xl p-2.5">
                                        <div class="text-[10px] font-bold text-orange-700 uppercase tracking-wide">Total Expense</div>
                                        <div class="text-base font-black text-orange-700 leading-tight mt-1" id="invoice_total_expense_display">${formatCurrency(totalExpenseOnContainer)}</div>
                                    </div>
                                    <div class="bg-gradient-to-br from-blue-50 to-indigo-50 border border-blue-200 rounded-xl p-2.5">
                                        <div class="text-[10px] font-bold text-blue-700 uppercase tracking-wide">Profit</div>
                                        <div class="text-base font-black text-blue-700 leading-tight mt-1" id="invoice_profit_display">0.00</div>
                                    </div>
                                </div>
                            </div>

                            <!-- Warning Message (if completed or non-admin) -->
                            ${(isCompleted || !isAdmin) ? `
                                <div class="bg-gradient-to-r from-amber-50 to-yellow-50 border-2 border-amber-200 rounded-lg p-3 shadow-sm">
                                    <div class="flex items-center gap-2.5">
                                        <div class="w-9 h-9 bg-amber-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                            <i class="fas fa-lock text-amber-600"></i>
                                        </div>
                                        <div>
                                            <div class="text-sm font-bold text-amber-900">${isCompleted ? 'Container Fully Paid' : 'View Only'}</div>
                                            <div class="text-xs text-amber-700 mt-0.5">${isCompleted ? 'Only invoice printing is available.' : 'You do not have permission to edit.'}</div>
                                        </div>
                                    </div>
                                </div>
                            ` : ''}

                            <!-- Action Buttons -->
                            <div class="flex gap-2.5 pt-1">
                                <button type="submit" class="flex-1 px-4 py-2.5 bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 text-white rounded-lg text-sm font-semibold transition-all shadow-lg shadow-blue-500/30 hover:shadow-xl hover:shadow-blue-500/40 transform hover:-translate-y-0.5">
                                    <i class="fas fa-print mr-1.5"></i>Generate
                                </button>
                                <button type="button" id="customerInvoiceSaveBtn" ${disabledAttr} class="flex-1 px-4 py-2.5 ${(isCompleted || !isAdmin) ? 'bg-slate-300 cursor-not-allowed text-slate-500' : 'bg-gradient-to-r from-green-600 to-emerald-600 hover:from-green-700 hover:to-emerald-700 shadow-lg shadow-green-500/30 hover:shadow-xl hover:shadow-green-500/40 transform hover:-translate-y-0.5'} text-white rounded-lg text-sm font-semibold transition-all" ${isCompleted ? 'title="Cannot save - Container is fully paid"' : (!isAdmin ? 'title="View only - Admin access required"' : '')}>
                                    <i class="fas fa-save mr-1.5"></i>Save
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        `;

        closeDynamicModal('customerInvoiceConfigModal');
        document.body.insertAdjacentHTML('beforeend', modalHtml);

        const weightType = document.getElementById('invoice_weight_type');
        const weightInput = document.getElementById('invoice_weight_value');
        const rateInput = document.getElementById('invoice_rate');
        const totalInvoiceDisplay = document.getElementById('invoice_total_amount_display');
        const totalExpenseDisplay = document.getElementById('invoice_total_expense_display');
        const profitDisplay = document.getElementById('invoice_profit_display');
        const invoiceConfigModal = document.getElementById('customerInvoiceConfigModal');
        let isInvoiceConfigSaving = false;

        const numberString = (value) => {
            const num = parseFloat(value || 0);
            return Number.isFinite(num) ? num.toFixed(2) : '0.00';
        };

        const getSnapshot = () => ({
            weightType: String(weightType?.value || ''),
            weightValue: numberString(weightInput?.value),
            rate: numberString(rateInput?.value)
        });

        let lastSavedSnapshot = getSnapshot();

        const isInvoiceConfigDirty = () => {
            const current = getSnapshot();
            return current.weightType !== lastSavedSnapshot.weightType ||
                current.weightValue !== lastSavedSnapshot.weightValue ||
                current.rate !== lastSavedSnapshot.rate;
        };

        window.requestCloseInvoiceConfigModal = function() {
            if (isCompleted || isInvoiceConfigSaving || !isInvoiceConfigDirty()) {
                closeDynamicModal('customerInvoiceConfigModal');
                return;
            }

            showAlert('Please save invoice changes with the Save button before leaving this popup.', 'warning');
        };

        const recalc = () => {
            const amount = (parseFloat(weightInput.value || 0) * parseFloat(rateInput.value || 0));
            const profit = amount - totalExpenseOnContainer;
            if (totalInvoiceDisplay) totalInvoiceDisplay.textContent = formatCurrency(amount);
            if (totalExpenseDisplay) totalExpenseDisplay.textContent = formatCurrency(totalExpenseOnContainer);
            if (profitDisplay) profitDisplay.textContent = formatCurrency(profit);
        };

        weightType.addEventListener('change', function() {
            weightInput.value = this.value === 'net' ? containerNetWeight : containerGrossWeight;
            recalc();
        });
        weightInput.addEventListener('input', recalc);
        rateInput.addEventListener('input', recalc);
        recalc();

        const saveInvoiceConfig = function(openPreview, onSaved = null) {
            if (isInvoiceConfigSaving) {
                return;
            }
            const form = document.getElementById('customerInvoiceConfigForm');
            const formData = new FormData(form);
            const selectedWeightType = formData.get('weight_type');
            isInvoiceConfigSaving = true;
            fetch('containers.php?action=save_invoice_config', {
                method: 'POST',
                headers: {'X-Requested-With': 'XMLHttpRequest'},
                body: formData
            })
            .then(r => r.json())
            .then(saveRes => {
                if (!saveRes.success) {
                    showAlert(saveRes.message || 'Unable to save invoice config.', 'error');
                    return;
                }

                lastSavedSnapshot = getSnapshot();

                if (openPreview) {
                    closeDynamicModal('customerInvoiceConfigModal');
                    window.printInvoiceDirectFromCustomer(containerId, selectedWeightType);
                    // Refresh after modal is gone so totals update silently in the background
                    setTimeout(function() {
                        window.refreshCustomerContainers(customerId);
                        window.refreshAccountSummary(customerId);
                    }, 800);
                } else {
                    window.refreshCustomerContainers(customerId);
                    window.refreshAccountSummary(customerId);
                    closeDynamicModal('customerInvoiceConfigModal');
                    showAlert('Invoice configuration saved successfully!', 'success');
                }

                if (typeof onSaved === 'function') {
                    onSaved(saveRes);
                }
            })
            .catch(err => {
                console.error(err);
                showAlert('Unable to save invoice config.', 'error');
            })
            .finally(() => {
                isInvoiceConfigSaving = false;
            });
        };

        document.getElementById('customerInvoiceConfigForm').addEventListener('submit', function(e) {
            e.preventDefault();
            if (isCompleted || !isAdmin) {
                // Non-admin or completed containers — skip saving and print directly
                const selectedWeightType = weightType.value || defaultType;
                closeDynamicModal('customerInvoiceConfigModal');
                window.printInvoiceDirectFromCustomer(containerId, selectedWeightType);
            } else {
                saveInvoiceConfig(true);
            }
        });

        document.getElementById('customerInvoiceSaveBtn').addEventListener('click', function() {
            if (!isCompleted) {
                saveInvoiceConfig(false);
            }
        });
    })
    .catch(err => {
        console.error('Error opening invoice modal:', err);
        showAlert('Unable to open invoice modal: ' + err.message, 'error');
    });
};

window.showInvoicePreviewModal = function(containerId, customerId, weightType = 'gross') {
    fetch(`containers.php?action=invoice_view&id=${containerId}&weight_type=${encodeURIComponent(weightType)}`, {
        headers: {'X-Requested-With': 'XMLHttpRequest'}
    })
    .then(r => r.text())
    .then(html => {
        const modalHtml = `
            <div class="fixed top-0 left-0 right-0 bottom-0 bg-black bg-opacity-50 z-50 flex items-center justify-center overflow-hidden" id="customerInvoicePreviewModal" style="min-height: 100vh;" onclick="if(event.target===this) closeDynamicModal('customerInvoicePreviewModal')">
                <div class="bg-white rounded-lg shadow-2xl w-full max-w-2xl mx-4 max-h-[70vh] flex flex-col overflow-hidden animate-slideIn">
                    <div class="bg-gradient-to-r from-gray-800 to-gray-900 text-white px-4 py-2.5 rounded-t-lg flex justify-between items-center print-hide flex-shrink-0">
                        <h3 class="text-lg font-bold"><i class="fas fa-file-invoice mr-2"></i>Invoice Preview</h3>
                        <div class="flex items-center gap-3">
                            <button onclick="printInvoiceFromCustomer()" class="text-white hover:text-gray-300 text-2xl" title="Print Invoice"><i class="fas fa-print"></i></button>
                            <button onclick="closeDynamicModal('customerInvoicePreviewModal')" class="text-white hover:text-gray-300 text-2xl"><i class="fas fa-times"></i></button>
                        </div>
                    </div>
                    <div id="customerInvoicePreviewContent" class="flex-1 overflow-y-auto bg-gray-100 p-2 flex justify-center"></div>
                </div>
            </div>
        `;

        closeDynamicModal('customerInvoicePreviewModal');
        document.body.insertAdjacentHTML('beforeend', modalHtml);
        const previewContent = document.getElementById('customerInvoicePreviewContent');
        previewContent.innerHTML = html;
        window.currentInvoiceContainerId = containerId;

        fetch(`containers.php?action=check_invoice_status&id=${containerId}`, {headers: {'X-Requested-With': 'XMLHttpRequest'}})
            .then(r => r.json())
            .then(statusData => {
                const addBtn = document.getElementById('customerAddInvoiceToAccountBtn');
                const addedMsg = document.getElementById('customerAddedToAccountMsg');
                if (statusData.success && statusData.added_to_account) {
                    addBtn.classList.add('hidden');
                    addedMsg.classList.remove('hidden');
                } else {
                    addBtn.classList.remove('hidden');
                    addedMsg.classList.add('hidden');
                }
            });

        document.getElementById('customerAddInvoiceToAccountBtn').onclick = function() {
            const btn = this;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>Adding...';
            fetch('containers.php?action=add_invoice_to_account', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: `container_id=${encodeURIComponent(containerId)}`
            })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    showAlert('Invoice added to customer account successfully!', 'success');
                    refreshCustomerContainers(customerId);
                    const addedMsg = document.getElementById('customerAddedToAccountMsg');
                    btn.classList.add('hidden');
                    addedMsg.classList.remove('hidden');
                } else {
                    showAlert(res.message || 'Failed to add invoice to account.', 'error');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-plus-circle mr-1"></i>Add Invoice to Customer Account';
                }
            })
            .catch(err => {
                console.error(err);
                showAlert('Failed to add invoice to account.', 'error');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-plus-circle mr-1"></i>Add Invoice to Customer Account';
            });
        };
    })
    .catch(err => {
        console.error(err);
        showAlert('Unable to generate invoice preview.', 'error');
    });
};

window.printInvoiceFromCustomer = function() {
    const content = document.getElementById('printableInvoiceContent');
    if (!content) {
        showAlert('Invoice content not found.', 'warning');
        return;
    }

    const iframe = document.createElement('iframe');
    iframe.style.position = 'absolute';
    iframe.style.width = '0px';
    iframe.style.height = '0px';
    iframe.style.border = 'none';
    document.body.appendChild(iframe);

    const doc = iframe.contentWindow.document;
    doc.open();
    doc.write('<html><head><title>Print Invoice</title>');
    doc.write('<script src="https://cdn.tailwindcss.com"><\/script>');
    doc.write('<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">');
    doc.write('</head><body class="bg-white p-4">');
    doc.write(content.outerHTML);
    doc.write('</body></html>');
    doc.close();

    iframe.contentWindow.focus();
    setTimeout(function() {
        iframe.contentWindow.print();
        document.body.removeChild(iframe);
    }, 500);
};

window.printInvoiceDirectFromCustomer = function(containerId, weightType = 'gross') {
    fetch(`containers.php?action=invoice_view&id=${containerId}&weight_type=${encodeURIComponent(weightType)}`, {
        headers: {'X-Requested-With': 'XMLHttpRequest'}
    })
    .then(r => {
        if (!r.ok) throw new Error(`HTTP ${r.status}`);
        return r.text();
    })
    .then(html => {
        const parser = new DOMParser();
        const parsed = parser.parseFromString(html, 'text/html');
        const printableRoot = parsed.querySelector('#printableInvoiceContent');
        const printableHtml = printableRoot ? printableRoot.outerHTML : html;

        const iframe = document.createElement('iframe');
        iframe.style.position = 'absolute';
        iframe.style.width = '0px';
        iframe.style.height = '0px';
        iframe.style.border = 'none';
        document.body.appendChild(iframe);

        const frameDoc = iframe.contentWindow.document;
        frameDoc.open();
        frameDoc.write('<html><head><title></title>');
        frameDoc.write('<style>@page { margin: 12mm; }</style>');
        frameDoc.write('<script src="https://cdn.tailwindcss.com"><\/script>');
        frameDoc.write('<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">');
        frameDoc.write('</head><body class="bg-white p-4">');
        frameDoc.write(printableHtml);
        frameDoc.write('</body></html>');
        frameDoc.close();

        iframe.contentWindow.focus();
        setTimeout(function() {
            iframe.contentWindow.print();
            document.body.removeChild(iframe);
        }, 500);
    })
    .catch(err => {
        console.error(err);
        showAlert('Unable to print invoice directly.', 'error');
    });
};

window.openUnifiedPrintPreview = function(title, bodyHtml) {
    const iframe = document.createElement('iframe');
    iframe.style.position = 'absolute';
    iframe.style.width = '0px';
    iframe.style.height = '0px';
    iframe.style.border = 'none';
    document.body.appendChild(iframe);

    const doc = iframe.contentWindow.document;
    doc.open();
    doc.write(`
        <html>
        <head>
            <title></title>
            <style>
                * { box-sizing: border-box; }
                body { font-family: Arial, sans-serif; font-size: 12px; color: #111827; margin: 16px; }
                .meta-top { display: flex; justify-content: space-between; font-size: 11px; margin-bottom: 6px; }
                .report-title { text-align: center; font-size: 18px; font-weight: 700; margin: 10px 0 14px; letter-spacing: 0.3px; }
                .company-wrap { display: flex; align-items: center; justify-content: center; gap: 12px; margin-bottom: 6px; }
                .company-logo { width: 64px; height: 64px; object-fit: contain; }
                .company-name { font-size: 32px; font-weight: 700; line-height: 1.1; }
                .company-meta { text-align: center; font-size: 12px; line-height: 1.3; }
                .divider { border-top: 2px solid #111827; margin: 8px 0; }
                .entity-row { display: flex; justify-content: space-between; gap: 12px; margin: 3px 0; }
                .totals { text-align: center; font-weight: 700; color: #b91c1c; margin: 8px 0; }
                table { width: 100%; border-collapse: collapse; }
                th, td { border: 1px solid #111827; padding: 6px 5px; font-size: 11px; }
                th { font-weight: 700; text-align: center; }
                td.right { text-align: right; }
                td.center { text-align: center; }
                .empty { text-align: center; color: #6b7280; padding: 10px 0; }
                .info-table { width: 100%; border-collapse: collapse; margin: 6px 0; }
                .info-table th.section-header { background: #1e293b; color: #ffffff; text-align: left; padding: 5px 8px; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.07em; }
                .info-table td { border: 1px solid #cbd5e1; padding: 4px 8px; font-size: 11px; vertical-align: middle; }
                .info-table td.info-label { font-weight: 700; color: #374151; background: #f1f5f9; width: 15%; white-space: nowrap; }
                .info-table td.info-value { color: #111827; width: 35%; }
                @media print {
                    @page { margin: 0; size: auto; }
                    body { margin: 0; padding: 12mm; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                }
            </style>
        </head>
        <body>${bodyHtml}</body>
        </html>
    `);
    doc.close();

    iframe.contentWindow.focus();
    setTimeout(function() {
        iframe.contentWindow.print();
        document.body.removeChild(iframe);
    }, 400);
};

window.buildContainerLedgerPrintHtml = function(payload) {
    const {
        title,
        container,
        customerObj,
        containerNumber,
        blNumber,
        totals,
        rows
    } = payload;

    const c = container || {};
    const cu = customerObj || {};
    const dispContainerNumber = c.container_number || containerNumber || '-';
    const dispBlNumber       = c.bl_number || blNumber || '-';
    const dispCustomerName   = cu.name || c.customer_name || '-';
    const dispCustomerPhone  = cu.phone || '-';
    const dispCustomerEmail  = cu.email || '-';
    const dispCustomerNotes  = cu.notes || '';
    const dispHsCode         = c.HS_code || c.hs_code || '-';
    const dispGdNo           = c.gd_no || '-';
    const dispTpNo           = c.tp_no || '-';
    const dispDestination    = c.destination || '-';
    const dispPort           = c.port || '-';
    const dispPackages       = c.packages || '-';
    const dispNetWeight      = c.net_weight ? parseFloat(c.net_weight).toLocaleString() + ' kg' : '-';
    const dispGrossWeight    = c.gross_weight ? parseFloat(c.gross_weight).toLocaleString() + ' kg' : '-';
    const dispStatus         = c.status ? c.status.charAt(0).toUpperCase() + c.status.slice(1) : '-';

    const logoHtml = companyDetails.logo
        ? `<img src="${escapeHtml(companyDetails.logo)}" alt="Logo" class="company-logo">`
        : '';

    const rowHtml = rows.length > 0
        ? rows.map((row, idx) => `
            <tr>
                <td class="center">${escapeHtml(row.date || '-')}</td>
                <td class="center">${escapeHtml(row.voucher || '-')}</td>
                <td class="center">${escapeHtml(row.description || '-')}</td>
                <td class="center">${row.debit > 0 ? formatCurrency(row.debit) : '-'}</td>
                <td class="center">${row.credit > 0 ? formatCurrency(row.credit) : '-'}</td>
                <td class="center">${escapeHtml(row.type || '-')}</td>
                <td class="center"${idx === rows.length - 1 ? ' style="color:#b91c1c;font-weight:700;"' : ''}>${formatCurrency(row.balance || 0)}</td>
            </tr>
        `).join('')
        : '<tr><td class="empty" colspan="7">No transactions found</td></tr>';

    return `
        <div class="company-wrap">
            ${logoHtml}
            <div>
                <div class="company-name">${escapeHtml(companyDetails.name || '')}</div>
                <div class="company-meta">${escapeHtml(companyDetails.location || '')}</div>
                <div class="company-meta">${escapeHtml(companyDetails.contact || '')} | ${escapeHtml(companyDetails.email || '')}</div>
            </div>
        </div>
        <div class="divider"></div>
        <div class="report-title">Container Transaction History</div>
        <table class="info-table">
            <thead><tr><th colspan="4" class="section-header">Customer Details</th></tr></thead>
            <tbody>
                <tr>
                    <td class="info-label">Name</td><td class="info-value">${escapeHtml(dispCustomerName)}</td>
                    <td class="info-label">Phone</td><td class="info-value">${escapeHtml(dispCustomerPhone)}</td>
                </tr>
                <tr>
                    <td class="info-label">Email</td><td class="info-value" colspan="3">${escapeHtml(dispCustomerEmail)}</td>
                </tr>
                ${dispCustomerNotes ? `<tr><td class="info-label">Notes</td><td class="info-value" colspan="3">${escapeHtml(dispCustomerNotes)}</td></tr>` : ''}
            </tbody>
        </table>
        <table class="info-table" style="margin-top:8px;">
            <thead><tr><th colspan="4" class="section-header">Container Details</th></tr></thead>
            <tbody>
                <tr>
                    <td class="info-label">Container #</td><td class="info-value">${escapeHtml(dispContainerNumber)}</td>
                    <td class="info-label">BL #</td><td class="info-value">${escapeHtml(dispBlNumber)}</td>
                </tr>
                <tr>
                    <td class="info-label">HS Code</td><td class="info-value">${escapeHtml(dispHsCode)}</td>
                    <td class="info-label">GD #</td><td class="info-value">${escapeHtml(dispGdNo)}</td>
                </tr>
                <tr>
                    <td class="info-label">TP #</td><td class="info-value">${escapeHtml(dispTpNo)}</td>
                    <td class="info-label">Destination</td><td class="info-value">${escapeHtml(dispDestination)}</td>
                </tr>
                <tr>
                    <td class="info-label">Port</td><td class="info-value">${escapeHtml(dispPort)}</td>
                    <td class="info-label">Packages</td><td class="info-value">${escapeHtml(String(dispPackages))}</td>
                </tr>
                <tr>
                    <td class="info-label">Net Weight</td><td class="info-value">${escapeHtml(dispNetWeight)}</td>
                    <td class="info-label">Gross Weight</td><td class="info-value">${escapeHtml(dispGrossWeight)}</td>
                </tr>
                <tr>
                    <td class="info-label">Status</td><td class="info-value" colspan="3">${escapeHtml(dispStatus)}</td>
                </tr>
            </tbody>
        </table>
        <div class="totals">Total: Rs. ${formatCurrency(totals.total || 0)} &nbsp; | &nbsp; Paid: Rs. ${formatCurrency(totals.paid || 0)} &nbsp; | &nbsp; Remaining: Rs. ${formatCurrency(totals.remaining || 0)}</div>
        <div class="divider"></div>
        <table>
            <thead>
                <tr>
                    <th>DATE</th>
                    <th>VOUCHER/REF</th>
                    <th>DESCRIPTION</th>
                    <th>DEBIT</th>
                    <th>CREDIT</th>
                    <th>TYPE</th>
                    <th>BALANCE</th>
                </tr>
            </thead>
            <tbody>${rowHtml}</tbody>
        </table>
    `;
};

window.buildExpenseReportPrintHtml = function(payload) {
    const {
        container,
        customerObj,
        containerNumber,
        blNumber,
        totals,
        rows
    } = payload;

    const c = container || {};
    const cu = customerObj || {};
    const dispContainerNumber = c.container_number || containerNumber || '-';
    const dispBlNumber       = c.bl_number || blNumber || '-';
    const dispCustomerName   = cu.name || c.customer_name || '-';
    const dispCustomerPhone  = cu.phone || '-';
    const dispCustomerEmail  = cu.email || '-';
    const dispCustomerNotes  = cu.notes || '';
    const dispHsCode         = c.HS_code || c.hs_code || '-';
    const dispGdNo           = c.gd_no || '-';
    const dispTpNo           = c.tp_no || '-';
    const dispDestination    = c.destination || '-';
    const dispPort           = c.port || '-';
    const dispPackages       = c.packages || '-';
    const dispNetWeight      = c.net_weight ? parseFloat(c.net_weight).toLocaleString() + ' kg' : '-';
    const dispGrossWeight    = c.gross_weight ? parseFloat(c.gross_weight).toLocaleString() + ' kg' : '-';
    const dispStatus         = c.status ? c.status.charAt(0).toUpperCase() + c.status.slice(1) : '-';

    const logoHtml = companyDetails.logo
        ? `<img src="${escapeHtml(companyDetails.logo)}" alt="Logo" class="company-logo">`
        : '';

    const rowHtml = rows.length > 0
        ? rows.map((row) => `
            <tr>
                <td class="center">${escapeHtml(row.date || '-')}</td>
                <td class="center">${escapeHtml(row.voucher || '-')}</td>
                <td class="center">${escapeHtml(row.description || '-')}</td>
                <td class="center">${formatCurrency(row.amount || 0)}</td>
                <td class="center">${formatCurrency(row.balance || 0)}</td>
            </tr>
        `).join('')
        : '<tr><td class="empty" colspan="5">No expenses found</td></tr>';

    return `
        <div class="company-wrap">
            ${logoHtml}
            <div>
                <div class="company-name">${escapeHtml(companyDetails.name || '')}</div>
                <div class="company-meta">${escapeHtml(companyDetails.location || '')}</div>
                <div class="company-meta">${escapeHtml(companyDetails.contact || '')} | ${escapeHtml(companyDetails.email || '')}</div>
            </div>
        </div>
        <div class="divider"></div>
        <div class="report-title">Container Expenses Report</div>
        <table class="info-table">
            <thead><tr><th colspan="4" class="section-header">Customer Details</th></tr></thead>
            <tbody>
                <tr>
                    <td class="info-label">Name</td><td class="info-value">${escapeHtml(dispCustomerName)}</td>
                    <td class="info-label">Phone</td><td class="info-value">${escapeHtml(dispCustomerPhone)}</td>
                </tr>
                <tr>
                    <td class="info-label">Email</td><td class="info-value" colspan="3">${escapeHtml(dispCustomerEmail)}</td>
                </tr>
                ${dispCustomerNotes ? `<tr><td class="info-label">Notes</td><td class="info-value" colspan="3">${escapeHtml(dispCustomerNotes)}</td></tr>` : ''}
            </tbody>
        </table>
        <table class="info-table" style="margin-top:8px;">
            <thead><tr><th colspan="4" class="section-header">Container Details</th></tr></thead>
            <tbody>
                <tr>
                    <td class="info-label">Container #</td><td class="info-value">${escapeHtml(dispContainerNumber)}</td>
                    <td class="info-label">BL #</td><td class="info-value">${escapeHtml(dispBlNumber)}</td>
                </tr>
                <tr>
                    <td class="info-label">HS Code</td><td class="info-value">${escapeHtml(dispHsCode)}</td>
                    <td class="info-label">GD #</td><td class="info-value">${escapeHtml(dispGdNo)}</td>
                </tr>
                <tr>
                    <td class="info-label">TP #</td><td class="info-value">${escapeHtml(dispTpNo)}</td>
                    <td class="info-label">Destination</td><td class="info-value">${escapeHtml(dispDestination)}</td>
                </tr>
                <tr>
                    <td class="info-label">Port</td><td class="info-value">${escapeHtml(dispPort)}</td>
                    <td class="info-label">Packages</td><td class="info-value">${escapeHtml(String(dispPackages))}</td>
                </tr>
                <tr>
                    <td class="info-label">Net Weight</td><td class="info-value">${escapeHtml(dispNetWeight)}</td>
                    <td class="info-label">Gross Weight</td><td class="info-value">${escapeHtml(dispGrossWeight)}</td>
                </tr>
                <tr>
                    <td class="info-label">Status</td><td class="info-value" colspan="3">${escapeHtml(dispStatus)}</td>
                </tr>
            </tbody>
        </table>
        <div class="divider"></div>
        <table>
            <thead>
                <tr>
                    <th>DATE</th>
                    <th>VOUCHER/REF</th>
                    <th>DESCRIPTION</th>
                    <th>AMOUNT</th>
                    <th>BALANCE</th>
                </tr>
            </thead>
            <tbody>${rowHtml}</tbody>
        </table>
        <div class="totals" style="margin-top:10px; font-size:14px;">Total Expenses: Rs. ${formatCurrency(totals.paid || 0)}</div>
    `;
};

window.printExpenseReportForCustomer = function(containerId, customerId, containerNumber, blNumber) {
    Promise.all([
        fetch(`customers.php?action=container_account&id=${containerId}`, { headers: {'X-Requested-With': 'XMLHttpRequest'} }).then(r => r.json()),
        customerId ? fetch(`customers.php?action=get&id=${customerId}`, { headers: {'X-Requested-With': 'XMLHttpRequest'} }).then(r => r.json()) : Promise.resolve(null),
        fetch(`containers.php?action=details&id=${containerId}`, { headers: {'X-Requested-With': 'XMLHttpRequest'} }).then(r => r.json())
    ])
    .then(([accountData, customerData, detailsData]) => {
        const rawExpenses = Array.isArray(detailsData?.expenses) ? detailsData.expenses.slice() : [];
        const container = accountData?.container || {};
        const invoiceTotal = parseFloat(accountData?.account?.total_amount || 0);
        const totalExpense = parseFloat(detailsData?.total_expenses || 0);

        // Sort by date ascending, then by id ascending (oldest/lowest id first)
        rawExpenses.sort((a, b) => {
            const dateDiff = String(a.expense_date || '').localeCompare(String(b.expense_date || ''));
            if (dateDiff !== 0) return dateDiff;
            return (parseInt(a.id) || 0) - (parseInt(b.id) || 0);
        });

        let runningExpense = 0;
        const rows = rawExpenses.map((exp) => {
            const amount = parseFloat(exp.amount || 0);
            runningExpense += amount;
            const descParts = [exp.expense_type || 'Expense'];
            if (exp.agent_name && exp.agent_name !== '-') descParts.push('by ' + exp.agent_name);
            return {
                date: exp.expense_date || '-',
                voucher: exp.id ? `CE-${exp.id}` : '-',
                description: descParts.join(' — '),
                amount: amount,
                balance: runningExpense
            };
        });

        const html = window.buildExpenseReportPrintHtml({
            container: container,
            customerObj: customerData?.customer || null,
            containerNumber: containerNumber,
            blNumber: blNumber,
            totals: {
                total: invoiceTotal,
                paid: totalExpense,
                remaining: invoiceTotal - totalExpense
            },
            rows
        });

        window.openUnifiedPrintPreview('Container Expense Report', html);
    })
    .catch(err => {
        console.error(err);
        showAlert('Unable to print expense report.', 'error');
    });
};

window.printContainerTransactions = function(containerId, customerId, containerNumber, blNumber) {
    Promise.all([
        fetch(`customers.php?action=container_account&id=${containerId}`, { headers: {'X-Requested-With': 'XMLHttpRequest'} }).then(r => r.json()),
        customerId ? fetch(`customers.php?action=get&id=${customerId}`, { headers: {'X-Requested-With': 'XMLHttpRequest'} }).then(r => r.json()) : Promise.resolve(null)
    ])
    .then(([data, customerData]) => {
        if (!data.success) {
            showAlert(data.message || 'Unable to load transaction data.', 'error');
            return;
        }

        const account = data.account || { total_amount: 0, total_paid: 0, remaining_balance: 0 };
        const container = data.container || {};
        const transactions = Array.isArray(data.transactions) ? data.transactions.slice() : [];

        transactions.sort((a, b) => {
            const dateDiff = String(a.transaction_date || '').localeCompare(String(b.transaction_date || ''));
            if (dateDiff !== 0) return dateDiff;
            return (parseInt(a.id) || 0) - (parseInt(b.id) || 0);
        });

        const totalAmount = parseFloat(account.total_amount || 0);
        let runningCredit = 0;
        let runningDebit = 0;

        const rows = transactions.map((txn) => {
            const amount = parseFloat(txn.amount || 0);
            const isCredit = txn.transaction_type === 'credit';
            if (isCredit) {
                runningCredit += amount;
            } else {
                runningDebit += amount;
            }

            const runningRemaining = totalAmount + runningDebit - runningCredit;

            return {
                date: txn.transaction_date || '-',
                voucher: txn.reference_number || '-',
                description: txn.description || txn.payment_method || '-',
                debit: isCredit ? 0 : amount,
                credit: isCredit ? amount : 0,
                balance: runningRemaining,
                type: isCredit ? 'Paid' : 'Debit'
            };
        });

        const html = window.buildContainerLedgerPrintHtml({
            title: 'Container Transaction History',
            container: container,
            customerObj: customerData?.customer || null,
            containerNumber: containerNumber,
            blNumber: blNumber,
            totals: {
                total: totalAmount,
                paid: parseFloat(account.total_paid || 0),
                remaining: parseFloat(account.remaining_balance || 0)
            },
            rows
        });

        window.openUnifiedPrintPreview('Container Transaction History', html);
    })
    .catch(err => {
        console.error(err);
        showAlert('Unable to print transaction report.', 'error');
    });
};

window.editContainerInModal = function(containerId, customerId) {
    fetch(`containers.php?action=get&id=${containerId}`, {
        headers: {'X-Requested-With': 'XMLHttpRequest'}
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success || !data.data) {
            showAlert('Unable to load container data.', 'error');
            return;
        }

        const cont = data.data;
        const formDiv = document.getElementById('addContainerForm');
        const form = document.getElementById('customerProfileContainerForm');
        const titleText = document.getElementById('containerFormTitleText');
        const titleIcon = document.getElementById('containerFormTitleIcon');
        const submitText = document.getElementById('containerFormSubmitText');
        const submitTextMobile = document.getElementById('containerFormSubmitTextMobile');

        if (!formDiv || !form) {
            showAlert('Container form not found. Please reopen customer profile.', 'error');
            return;
        }

        form.reset();
        form.elements['container_id'].value = cont.id || '';
        form.elements['customer_id'].value = customerId || cont.customer_id || '';
        form.elements['existing_invoice'].value = cont.invoice_file || '';
        form.elements['status'].value = cont.status || 'pending';

        form.elements['container_number'].value = cont.container_number || '';
        form.elements['bl_number'].value = cont.bl_number || '';
        form.elements['tp_no'].value = cont.tp_no || '';
        form.elements['gd_no'].value = cont.gd_no || '';
        form.elements['hs_code'].value = cont.HS_code || '';
        form.elements['packages'].value = cont.packages || '';
        form.elements['destination'].value = cont.destination || '';
        form.elements['port'].value = cont.port || '';
        form.elements['net_weight'].value = cont.net_weight || '';
        form.elements['gross_weight'].value = cont.gross_weight || '';
        form.elements['rate'].value = cont.rate || '';

        if (titleText) titleText.textContent = 'Edit Container';
        if (titleIcon) titleIcon.textContent = 'edit';
        if (submitText) submitText.textContent = 'Update Container';
        if (submitTextMobile) submitTextMobile.textContent = 'Update';

        formDiv.classList.remove('hidden');
        formDiv.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    })
    .catch(err => {
        console.error(err);
        showAlert('Unable to open edit container modal.', 'error');
    });
};

window.deleteCustomer = function(customerId, name) {
    showConfirm(
        `Are you sure you want to delete customer "${name}"?<br><br>This will also delete all related containers and expenses.`,
        function() {
            const formData = new FormData();
            formData.append('id', customerId);
            
            fetch(`customers.php?action=delete`, {
                method: 'POST',
                headers: {'X-Requested-With': 'XMLHttpRequest'},
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showAlert('Customer deleted successfully!', 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showAlert('Failed to delete customer.', 'error');
                }
            })
            .catch(err => {
                console.error('Error:', err);
                showAlert('An error occurred while deleting.', 'error');
            });
        }
    );
};

// Initialize customer page functionality
(function() {
    'use strict';
    
    // Form submission handler
    const form = document.getElementById('customerForm');
    if (form) {
        // Clone to remove any existing listeners
        const newForm = form.cloneNode(true);
        form.parentNode.replaceChild(newForm, form);
        
        newForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const customerId = newForm.elements['customer_id'].value;
            const action = customerId ? 'edit' : 'add';
            const formData = new FormData(this);
            
            fetch(`customers.php?action=${action}`, {
                method: 'POST',
                headers: {'X-Requested-With': 'XMLHttpRequest'},
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    window.closeCustomerModal();
                    const actionText = customerId ? 'updated' : 'added';
                    showAlert(`Customer ${actionText} successfully!`, 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showAlert('Operation failed. Please try again.', 'error');
                }
            })
            .catch(err => {
                console.error('Error:', err);
                showAlert('An error occurred while processing your request.', 'error');
            });
        });
    }
    
    // Modal background click to close
    const modal = document.getElementById('customerModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                window.closeCustomerModal();
            }
        });
    }
    
    // Escape key to close modal
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const invoiceModal = document.getElementById('customerInvoiceConfigModal');
            if (invoiceModal && typeof window.requestCloseInvoiceConfigModal === 'function') {
                window.requestCloseInvoiceConfigModal();
                return;
            }

            const modal = document.getElementById('customerModal');
            if (modal && !modal.classList.contains('hidden')) {
                window.closeCustomerModal();
            }
            const detailsModal = document.getElementById('customerDetailsModal');
            if (detailsModal && !detailsModal.classList.contains('hidden')) {
                window.closeDetailsModal();
            }
            const expenseModal = document.getElementById('customerExpenseModalNew');
            if (expenseModal && !expenseModal.classList.contains('hidden')) {
                window.closeExpenseModalNew();
            }
        }
    });
    
    // Expense actions listener (add/edit/delete)
    document.addEventListener('click', function(e) {
        const addExpenseBtn = e.target.closest('[data-action="add-expense"]');
        if (addExpenseBtn) {
            e.preventDefault();
            e.stopPropagation();
            console.log('Add Expense button clicked');
            console.log('Current container ID:', window.currentCustomerDetailContainerId);
            console.log('Current customer ID:', window.currentDetailsCustomerId);
            
            if (!window.currentCustomerDetailContainerId || !window.currentDetailsCustomerId) {
                showAlert('Please view container details first before adding an expense.', 'error');
                return;
            }
            
            window.openExpenseModal(window.currentCustomerDetailContainerId, window.currentDetailsCustomerId);
            return;
        }

        const editExpenseBtn = e.target.closest('[data-action="edit-expense"]');
        if (editExpenseBtn) {
            e.preventDefault();
            e.stopPropagation();

            const containerId = parseInt(editExpenseBtn.dataset.containerId || '0', 10);
            const customerId = parseInt(editExpenseBtn.dataset.customerId || '0', 10);
            const expensePayload = editExpenseBtn.dataset.expensePayload || null;

            if (!containerId || !customerId || !expensePayload) {
                showAlert('Unable to open expense editor. Missing expense data.', 'error');
                return;
            }

            window.openExpenseModalEdit(containerId, customerId, expensePayload);
            return;
        }

        const deleteExpenseBtn = e.target.closest('[data-action="delete-expense"]');
        if (deleteExpenseBtn) {
            e.preventDefault();
            e.stopPropagation();

            const expenseId = parseInt(deleteExpenseBtn.dataset.expenseId || '0', 10);
            const containerId = parseInt(deleteExpenseBtn.dataset.containerId || '0', 10);
            const customerId = parseInt(deleteExpenseBtn.dataset.customerId || '0', 10);

            if (!expenseId || !containerId || !customerId) {
                showAlert('Unable to delete expense. Invalid expense data.', 'error');
                return;
            }

            window.deleteExpenseFromCustomer(expenseId, containerId, customerId);
            return;
        }
    }, true);
    
    // Setup expense form submission
    window.setupExpenseFormSubmission();
})();

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
    var emailFilter = document.getElementById('filterEmail').value.toLowerCase().trim();
    
    // Filter desktop table rows
    var tbody = document.querySelector('#customersListTable tbody');
    if (tbody) {
        var rows = tbody.querySelectorAll('tr');
        var visibleCount = 0;
        var totalCount = 0;
        
        rows.forEach(function(row) {
            // Skip rows with less than expected columns (like "no data" messages)
            if (row.cells.length < 4) {
                return;
            }
            
            totalCount++;
            
            // Get text content from cells (ID=0, Name=1, Email=2, Phone=3, Notes=4, Actions=5)
            var nameCell = row.cells[1].textContent.toLowerCase();
            var emailCell = row.cells[2].textContent.toLowerCase();
            var phoneCell = row.cells[3].textContent.toLowerCase();
            
            // Check if row matches all active filters
            var nameMatch = !nameFilter || nameCell.includes(nameFilter);
            var phoneMatch = !phoneFilter || phoneCell.includes(phoneFilter);
            var emailMatch = !emailFilter || emailCell.includes(emailFilter);
            
            if (nameMatch && phoneMatch && emailMatch) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });
    }
    
    // Filter mobile cards
    var mobileCards = document.querySelectorAll('.customer-card');
    var mobileVisibleCount = 0;
    var mobileTotalCount = mobileCards.length;
    
    mobileCards.forEach(function(card) {
        var cardName = card.getAttribute('data-customer-name') || '';
        var cardPhone = card.getAttribute('data-customer-phone') || '';
        var cardEmail = card.getAttribute('data-customer-email') || '';
        
        // Check if card matches all active filters
        var nameMatch = !nameFilter || cardName.includes(nameFilter);
        var phoneMatch = !phoneFilter || cardPhone.includes(phoneFilter);
        var emailMatch = !emailFilter || cardEmail.includes(emailFilter);
        
        if (nameMatch && phoneMatch && emailMatch) {
            card.style.display = '';
            mobileVisibleCount++;
        } else {
            card.style.display = 'none';
        }
    });
    
    // Update filter stats
    var statsDiv = document.getElementById('filterStats');
    var tbody = document.querySelector('#customersListTable tbody');
    var displayCount = tbody ? visibleCount : mobileVisibleCount;
    var displayTotal = tbody ? totalCount : mobileTotalCount;
    
    if (nameFilter || phoneFilter || emailFilter) {
        statsDiv.textContent = 'Showing ' + displayCount + ' of ' + displayTotal + ' customers';
    } else {
        statsDiv.textContent = '';
    }
};

window.clearFilters = function() {
    document.getElementById('filterName').value = '';
    document.getElementById('filterPhone').value = '';
    document.getElementById('filterEmail').value = '';
    applyFilters();
};
</script>
