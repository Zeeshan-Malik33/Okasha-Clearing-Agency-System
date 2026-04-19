<?php
// Remove BOM and start clean output buffering
if (function_exists('ob_get_clean') && ob_get_level()) {
    ob_end_clean();
}
ob_start();

require 'config/auth.php';
require 'config/database.php';
require 'config/constants.php';

$action  = $_GET['action'] ?? 'list';
$id      = $_GET['id'] ?? null;
$isAdmin = ($_SESSION['role'] === 'admin');
$isAjax  = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// Keep container operations inside customer profile popup only
if (!$isAjax) {
    header('Location: customePKRphp');
    exit;
}

// Debug: Log AJAX and action for troubleshooting
if ($isAjax && in_array($action, ['details', 'get', 'get_invoice_config', 'expense_report', 'invoice_view', 'add_invoice_to_account', 'check_invoice_status'])) {
    error_log("AJAX Request - Action: $action, ID: $id, IsAjax: " . ($isAjax ? 'true' : 'false'));
}

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

/* ============================
   TEST ENDPOINT - Verify JSON response capability
============================ */
if ($action === 'test' && $isAjax) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'AJAX JSON endpoint is working correctly',
        'timestamp' => date('Y-m-d H:i:s'),
        'connection' => $conn ? 'OK' : 'FAILED'
    ]);
    exit(0);
}

/* ============================
   HANDLE DELETE CONTAINER
============================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin && $action === 'delete') {
    $container_id = (int)$_POST['id'];
    
    try {
        // Start transaction for data integrity
        $conn->begin_transaction();
        
        // Initialize customer_id
        $customer_id = null;
        
        // Get container and invoice details before deletion
        $containerQuery = $conn->query("
            SELECT c.container_number, c.customer_id, cu.name as customer_name
            FROM containers c
            LEFT JOIN customers cu ON cu.id = c.customer_id
            WHERE c.id = $container_id
        ");
        
        if ($containerQuery && $containerQuery->num_rows > 0) {
            $containerData = $containerQuery->fetch_assoc();
            $customer_id = $containerData['customer_id'];
            $container_number = $containerData['container_number'];
            $customer_name = $containerData['customer_name'];
        }
        
        // Delete related records in the correct order to avoid foreign key constraint errors
        
        // 1. Delete agent transactions for this container (if any)
        $conn->query("DELETE FROM agent_transactions WHERE container_id = $container_id");
        
        // 2. Delete container transactions (payments/credits)
        $conn->query("DELETE FROM container_transactions WHERE container_id = $container_id");
        
        // 3. Delete invoices for this container
        $conn->query("DELETE FROM invoices WHERE container_id = $container_id");
        
        // 4. Delete expenses for this container
        $conn->query("DELETE FROM container_expenses WHERE container_id = $container_id");
        
        // 5. Finally, delete the container itself
        $result = $conn->query("DELETE FROM containers WHERE id = $container_id");
        
        if (!$result) {
            throw new Exception($conn->error);
        }
        
        // Recalculate customer account totals after all deletions
        if ($customer_id) {
            updateCustomerAccountTotals($customer_id, $conn);
        }
        
        // Commit transaction
        $conn->commit();
        
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Container deleted successfully. Customer account updated.']);
            exit;
        }
        header("Location: containePKRphp");
        exit;
    } catch (Exception $e) {
        // Rollback on error
        $conn->rollback();
        
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Error deleting container: ' . $e->getMessage()]);
            exit;
        }
        header("Location: containePKRphp?error=" . urlencode($e->getMessage()));
        exit;
    }
}

/* ============================
   HANDLE GET CONTAINER DATA
============================ */
if ($action === 'get' && $id && $isAjax) {
    $stmt = $conn->prepare("SELECT * FROM containers WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $container = $stmt->get_result()->fetch_assoc();
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $container]);
    exit;
}

/* ============================
   HANDLE CONTAINER DETAILS (AJAX VIEW)
============================ */
if ($action === 'details' && $id && $isAjax) {
    // Clear any previous output
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Set JSON response headers
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    try {
        // Validate ID
        $containerId = intval($id);
        if ($containerId <= 0) {
            http_response_code(400);
            die(json_encode(['success' => false, 'message' => 'Invalid container ID'], JSON_UNESCAPED_SLASHES));
        }
        
        // Check database connection
        if (!$conn) {
            http_response_code(500);
            die(json_encode(['success' => false, 'message' => 'Database connection failed'], JSON_UNESCAPED_SLASHES));
        }
        
        // Fetch container details
        $query = "
            SELECT c.*, cu.name AS customer_name, cu.email AS customer_email, cu.phone AS customer_phone
            FROM containers c
            LEFT JOIN customers cu ON cu.id = c.customer_id
            WHERE c.id = ?
        ";
        
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            http_response_code(500);
            die(json_encode(['success' => false, 'message' => 'Failed to prepare statement: ' . $conn->error], JSON_UNESCAPED_SLASHES));
        }
        
        $stmt->bind_param("i", $containerId);
        if (!$stmt->execute()) {
            http_response_code(500);
            die(json_encode(['success' => false, 'message' => 'Failed to execute query: ' . $stmt->error], JSON_UNESCAPED_SLASHES));
        }
        
        $result = $stmt->get_result();
        $container = $result->fetch_assoc();
        
        if (!$container) {
            http_response_code(404);
            die(json_encode(['success' => false, 'message' => 'Container not found'], JSON_UNESCAPED_SLASHES));
        }
        
        // Fetch expenses for this container
        $expenses = [];
        $totalExpenses = 0;
        
        $expenseQuery = "
            SELECT ce.id, ce.expense_type, ce.amount, ce.expense_date, ce.proof, ce.agent_id, 
                   COALESCE(a.name, '-') AS agent_name
            FROM container_expenses ce
            LEFT JOIN agents a ON a.id = ce.agent_id
            WHERE ce.container_id = ?
            ORDER BY ce.expense_date DESC, ce.id DESC
        ";
        
        $expStmt = $conn->prepare($expenseQuery);
        if ($expStmt) {
            $expStmt->bind_param("i", $containerId);
            if ($expStmt->execute()) {
                $expResult = $expStmt->get_result();
                while ($row = $expResult->fetch_assoc()) {
                    $expenses[] = $row;
                    $totalExpenses += (float)$row['amount'];
                }
                $expStmt->close();
            }
        }
        
        // Return success response
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'data' => $container,
            'expenses' => $expenses,
            'total_expenses' => $totalExpenses,
            'is_admin' => $isAdmin
        ], JSON_UNESCAPED_SLASHES | JSON_NUMERIC_CHECK);
        exit(0);
        
    } catch (Exception $e) {
        http_response_code(500);
        die(json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()], JSON_UNESCAPED_SLASHES));
    }
}

/* ============================
   HANDLE EDIT CONTAINER
============================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin && $action === 'edit') {
    $container_id = (int)($_POST['container_id'] ?? 0);
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
    $status       = trim($_POST['status'] ?? 'pending');

    if ($container_id <= 0 || $customer_id <= 0 || $container_no === '') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid container edit request']);
        exit;
    }

    try {
        $conn->begin_transaction();

        // Keep old customer id so we can recalculate both accounts if moved.
        $oldCustomerId = 0;
        $oldCustomerStmt = $conn->prepare("SELECT customer_id FROM containers WHERE id = ?");
        $oldCustomerStmt->bind_param("i", $container_id);
        $oldCustomerStmt->execute();
        $oldCustomerRow = $oldCustomerStmt->get_result()->fetch_assoc();
        if ($oldCustomerRow) {
            $oldCustomerId = (int)($oldCustomerRow['customer_id'] ?? 0);
        }

        $invoiceFile = $_POST['existing_invoice'] ?? null;
        if (!empty($_FILES['invoice']['name'])) {
            $ext = strtolower(pathinfo($_FILES['invoice']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['pdf','jpg','jpeg','png'], true)) {
                $targetDir = "uploads/invoices/";
                if (!is_dir($targetDir)) {
                    mkdir($targetDir, 0777, true);
                }
                $invoiceFile = time() . "_" . $container_no . "." . $ext;
                move_uploaded_file($_FILES['invoice']['tmp_name'], $targetDir . $invoiceFile);
            }
        }

        // Persist all editable container fields.
        $stmt = $conn->prepare(" 
            UPDATE containers SET
                customer_id = ?,
                bl_number = ?,
                container_number = ?,
                HS_code = ?,
                tp_no = ?,
                packages = ?,
                gd_no = ?,
                destination = ?,
                port = ?,
                net_weight = ?,
                gross_weight = ?,
                rate = ?,
                invoice_file = ?,
                status = ?
            WHERE id = ?
        ");
        $stmt->bind_param(
            "issssisssdddssi",
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
            $container_id
        );
        $stmt->execute();

        // Recalculate invoice totals from edited values.
        $invoiceAmount = $gross_weight * $rate;
        $expenseTotal = 0.0;
        $expenseStmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) AS total FROM container_expenses WHERE container_id = ?");
        $expenseStmt->bind_param("i", $container_id);
        $expenseStmt->execute();
        $expenseRow = $expenseStmt->get_result()->fetch_assoc();
        if ($expenseRow) {
            $expenseTotal = (float)($expenseRow['total'] ?? 0);
        }
        $netPayable = $invoiceAmount - $expenseTotal;

        // Update existing invoice row(s), or create one if missing.
        $invoiceExistsStmt = $conn->prepare("SELECT id FROM invoices WHERE container_id = ? LIMIT 1");
        $invoiceExistsStmt->bind_param("i", $container_id);
        $invoiceExistsStmt->execute();
        $invoiceExists = $invoiceExistsStmt->get_result()->fetch_assoc();

        if ($invoiceExists) {
            $updateInvoiceStmt = $conn->prepare(" 
                UPDATE invoices
                SET customer_id = ?, container_number = ?, gross_weight = ?, rate = ?, invoice_amount = ?, total_expenses = ?, net_payable = ?
                WHERE container_id = ?
            ");
            $updateInvoiceStmt->bind_param(
                "isdddddi",
                $customer_id,
                $container_no,
                $gross_weight,
                $rate,
                $invoiceAmount,
                $expenseTotal,
                $netPayable,
                $container_id
            );
            $updateInvoiceStmt->execute();
        } else {
            $invoiceNumber = 'INV-' . str_pad($container_id, 5, '0', STR_PAD_LEFT);
            $invoiceDate = date('Y-m-d');
            $newInvoiceId = getNextReusableId($conn, 'invoices');
            $insertInvoiceStmt = $conn->prepare(" 
                INSERT INTO invoices (id, container_id, customer_id, container_number, invoice_number, gross_weight, rate, invoice_amount, total_expenses, net_payable, invoice_date, added_to_account)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
            ");
            $insertInvoiceStmt->bind_param(
                "iiisssdddds",
                $newInvoiceId,
                $container_id,
                $customer_id,
                $container_no,
                $invoiceNumber,
                $gross_weight,
                $rate,
                $invoiceAmount,
                $expenseTotal,
                $netPayable,
                $invoiceDate
            );
            $insertInvoiceStmt->execute();
        }

        // Keep container account columns aligned with invoice and transaction totals.
        $paidTotal = 0.0;
        $paidStmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) AS total_paid FROM container_transactions WHERE container_id = ? AND transaction_type = 'credit'");
        $paidStmt->bind_param("i", $container_id);
        $paidStmt->execute();
        $paidRow = $paidStmt->get_result()->fetch_assoc();
        if ($paidRow) {
            $paidTotal = (float)($paidRow['total_paid'] ?? 0);
        }
        $remainingAmount = $invoiceAmount - $paidTotal;

        $updateContainerTotalsStmt = $conn->prepare(" 
            UPDATE containers
            SET total_amount = ?, total_paid = ?, remaining_amount = ?
            WHERE id = ?
        ");
        $updateContainerTotalsStmt->bind_param("dddi", $invoiceAmount, $paidTotal, $remainingAmount, $container_id);
        $updateContainerTotalsStmt->execute();

        // Auto-correct container status based on actual payment state after recalculation.
        $correctStatus = ($invoiceAmount > 0 && $paidTotal >= $invoiceAmount) ? 'completed' : 'pending';
        $statusFixStmt = $conn->prepare("UPDATE containers SET status = ? WHERE id = ?");
        $statusFixStmt->bind_param("si", $correctStatus, $container_id);
        $statusFixStmt->execute();

        // Recalculate customer account summaries.
        updateCustomerAccountTotals($customer_id, $conn);
        if ($oldCustomerId > 0 && $oldCustomerId !== $customer_id) {
            updateCustomerAccountTotals($oldCustomerId, $conn);
        }

        $conn->commit();

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'message' => 'Container updated successfully',
                'container_id' => $container_id
            ]);
            exit;
        }

        header("Location: containePKRphp");
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Failed to update container: ' . $e->getMessage()
        ]);
        exit;
    }
}

/* ============================
   HANDLE DELETE EXPENSE
============================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin && $action === 'delete_expense') {
    $expense_id = (int)$_POST['id'];
    
    // Get info before delete to potentially revert agent transaction? 
    // For now, simple delete.
    
    $conn->query("DELETE FROM container_expenses WHERE id = $expense_id");
    
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    }
}

/* ============================
   HANDLE ADD/EDIT EXPENSE
============================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin && $action === 'expense') {

    $id           = !empty($_POST['expense_id']) ? (int)$_POST['expense_id'] : null;
    $container_id = (int)$_POST['container_id'];
    $type         = trim($_POST['expense_type']);
    $amount       = (float)$_POST['amount'];
    $date         = $_POST['expense_date'];
    $agent_id     = !empty($_POST['agent_id']) ? (int)$_POST['agent_id'] : null;
    $addToAgent   = $agent_id ? 1 : 0;
    $fromList     = isset($_POST['from_list']) ? 1 : 0;

    // Upload proof
    $proofFile = null;
    if ($id) {
        // If editing, get existing proof
        $res = $conn->query("SELECT proof FROM container_expenses WHERE id = $id");
        if ($r = $res->fetch_assoc()) $proofFile = $r['proof'];
    }

    if (!empty($_FILES['proof']['name'])) {
        $ext = strtolower(pathinfo($_FILES['proof']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['pdf','jpg','jpeg','png'])) {
            $targetDir = "uploads/container_expenses/";
            if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
            $proofFile = time() . "_{$container_id}_" . rand(100,999) . ".$ext";
            move_uploaded_file(
                $_FILES['proof']['tmp_name'],
                $targetDir . $proofFile
            );
        }
    }

    if ($id) {
        // Update
        $stmt = $conn->prepare("
            UPDATE container_expenses 
            SET container_id=?, expense_type=?, amount=?, expense_date=?, agent_id=?, proof=?
            WHERE id=?
        ");
        $stmt->bind_param("isssisi", $container_id, $type, $amount, $date, $agent_id, $proofFile, $id);
        $stmt->execute();
    } else {
        // Insert
        $stmt = $conn->prepare("
            INSERT INTO container_expenses
            (container_id, expense_type, amount, expense_date, agent_id, proof, add_to_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("isdsssi", $container_id, $type, $amount, $date, $agent_id, $proofFile, $addToAgent);
        $stmt->execute();

        // If agent IS selected, add to agent transactions
        if ($agent_id && $addToAgent) {
            // Fetch current agent stats first
            $agentQ = $conn->query("SELECT total_amount, total_paid, remaining_amount FROM agents WHERE id = $agent_id");
            if ($ag = $agentQ->fetch_assoc()) {
                $new_total = $ag['total_amount'] + $amount;
                $new_rem = $ag['remaining_amount'] + $amount;
                $current_paid = $ag['total_paid'];

                // Insert Transaction with snapshot
                $stmt2 = $conn->prepare("
                    INSERT INTO agent_transactions
                    (agent_id, container_id, description, credit, created_at, total_amount, total_paid, remaining_amount)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $desc = "Expense: $type (Container #$container_id)";
                $stmt2->bind_param("iisdsddd", $agent_id, $container_id, $desc, $amount, $date, $new_total, $current_paid, $new_rem);
                $stmt2->execute();
                
                // Update Agent Totals
                $updateAgent = $conn->prepare("
                    UPDATE agents 
                    SET total_amount = ?, 
                        remaining_amount = ? 
                    WHERE id = ?
                ");
                $updateAgent->bind_param("ddi", $new_total, $new_rem, $agent_id);
                $updateAgent->execute();
            }
        }
    }

    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'message' => $id ? 'Expense updated successfully' : 'Expense added successfully',
            'container_id' => (int)$container_id,
            'expense_id' => $id ? (int)$id : (int)$conn->insert_id
        ], JSON_UNESCAPED_SLASHES | JSON_NUMERIC_CHECK);
        exit;
    }

    if ($fromList) {
        header("Location: containePKRphp");
    } else {
        header("Location: containePKRphp?action=view&id=$container_id");
    }
    exit;
}

/* ============================
   HANDLE CREATE CONTAINER
============================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin && $action === 'add') {

    $customer_id  = (int)$_POST['customer_id'];
    $bl_number    = trim($_POST['bl_number']);
    $container_no = trim($_POST['container_number']);
    $hs_code      = trim($_POST['hs_code']);
    $net_weight   = (float)$_POST['net_weight'];
    $gross_weight = (float)$_POST['gross_weight'];
    $status       = $_POST['status'];
    $created_date = date('Y-m-d');
    
    // Get customer's rate to initialize container's rate
    $customerRate = 0.00;
    $rateQuery = $conn->query("SELECT rate FROM customers WHERE id = $customer_id");
    if ($rateQuery && $rateQuery->num_rows > 0) {
        $rateRow = $rateQuery->fetch_assoc();
        $customerRate = (float)$rateRow['rate'];
    }

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

    $stmt = $conn->prepare("
        INSERT INTO containers
        (customer_id, bl_number, container_number, HS_code, net_weight, gross_weight, rate, invoice_file, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param(
        "isssdddsss",
        $customer_id,
        $bl_number,
        $container_no,
        $hs_code,
        $net_weight,
        $gross_weight,
        $customerRate,
        $invoiceFile,
        $status,
        $created_date
    );
    $stmt->execute();

    if (!$isAjax) {
        header("Location: containePKRphp");
        exit;
    }
    
    // For AJAX, set action to list and let page render below
    $action = 'list';
}

/* ============================
   HANDLE GET INVOICE CONFIG DATA
============================ */
if ($action === 'get_invoice_config' && $id && $isAjax) {
    $stmt = $conn->prepare("
        SELECT c.*, cu.name AS customer_name
        FROM containers c
        JOIN customers cu ON cu.id = c.customer_id
        WHERE c.id = ?
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $container = $stmt->get_result()->fetch_assoc();
    
    if (!$container) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Container not found']);
        exit;
    }
    
    // Detect the last used weight type and provide reliable fallback values from latest invoice.
    $lastUsedWeightType = 'gross';
    $lastWeightValue = null;
    $lastRateValue = null;
    $invoiceQuery = $conn->query("SELECT gross_weight, rate FROM invoices WHERE container_id = $id ORDER BY id DESC LIMIT 1");
    if ($invoiceQuery && $invoiceQuery->num_rows > 0) {
        $invoice = $invoiceQuery->fetch_assoc();
        $invoiceWeight = isset($invoice['gross_weight']) ? (float)$invoice['gross_weight'] : 0.0;
        $lastWeightValue = $invoiceWeight;
        $lastRateValue = isset($invoice['rate']) ? (float)$invoice['rate'] : null;

        // Compare with container weights to determine which was used.
        $containerGross = isset($container['gross_weight']) ? (float)$container['gross_weight'] : 0.0;
        $containerNet = isset($container['net_weight']) ? (float)$container['net_weight'] : 0.0;

        if (abs($invoiceWeight - $containerNet) < abs($invoiceWeight - $containerGross)) {
            $lastUsedWeightType = 'net';
        }
    }

    $effectiveRate = isset($container['rate']) ? (float)$container['rate'] : 0.0;
    if ($effectiveRate <= 0 && $lastRateValue !== null && (float)$lastRateValue > 0) {
        $effectiveRate = (float)$lastRateValue;
    }

    // Ensure weight/rate fallbacks are non-zero for read-only completed containers.
    if (($lastWeightValue === null || (float)$lastWeightValue <= 0)) {
        $containerGross = isset($container['gross_weight']) ? (float)$container['gross_weight'] : 0.0;
        $containerNet = isset($container['net_weight']) ? (float)$container['net_weight'] : 0.0;
        if ($lastUsedWeightType === 'net' && $containerNet > 0) {
            $lastWeightValue = $containerNet;
        } elseif ($containerGross > 0) {
            $lastWeightValue = $containerGross;
        } elseif ($containerNet > 0) {
            $lastWeightValue = $containerNet;
        }
    }

    if (($lastRateValue === null || (float)$lastRateValue <= 0) && $effectiveRate > 0) {
        $lastRateValue = $effectiveRate;
    }

    $totalExpenses = 0.0;
    $expenseQuery = $conn->query("SELECT COALESCE(SUM(amount), 0) AS total_expenses FROM container_expenses WHERE container_id = $id");
    if ($expenseQuery && $expenseQuery->num_rows > 0) {
        $expenseRow = $expenseQuery->fetch_assoc();
        $totalExpenses = isset($expenseRow['total_expenses']) ? (float)$expenseRow['total_expenses'] : 0.0;
    }
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'data' => [
            'container_id' => $container['id'],
            'customer_id' => $container['customer_id'],
            'container_number' => $container['container_number'],
            'customer_name' => $container['customer_name'],
            'gross_weight' => (float)$container['gross_weight'],
            'net_weight' => (float)$container['net_weight'],
            'rate' => $effectiveRate,
            'total_expenses' => $totalExpenses,
            'last_used_weight_type' => $lastUsedWeightType,
            'last_weight_value' => $lastWeightValue,
            'last_rate_value' => $lastRateValue
        ]
    ]);
    exit;
}

/* ============================
   HANDLE SAVE INVOICE CONFIG & GENERATE
============================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save_invoice_config' && $isAjax && $isAdmin) {
    $container_id = (int)$_POST['container_id'];
    $customer_id = (int)$_POST['customer_id'];
    $weight_type = $_POST['weight_type'] ?? 'gross'; // 'gross' or 'net'
    $weight_value = (float)$_POST['weight_value'];
    $rate = (float)$_POST['rate'];

    if ($container_id <= 0 || $weight_value <= 0 || $rate <= 0 || !in_array($weight_type, ['gross', 'net'], true)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Please provide valid invoice values before saving.']);
        exit;
    }

    try {
        $conn->begin_transaction();

        $containerStmt = $conn->prepare("SELECT id, customer_id, container_number FROM containers WHERE id = ? LIMIT 1");
        $containerStmt->bind_param("i", $container_id);
        $containerStmt->execute();
        $containerData = $containerStmt->get_result()->fetch_assoc();

        if (!$containerData) {
            throw new Exception('Container not found');
        }

        $customer_id = (int)$containerData['customer_id'];
        $container_number = (string)$containerData['container_number'];
        $newInvoiceAmount = $weight_value * $rate;

        if ($weight_type === 'net') {
            $updateContainer = $conn->prepare("UPDATE containers SET net_weight = ?, rate = ? WHERE id = ?");
        } else {
            $updateContainer = $conn->prepare("UPDATE containers SET gross_weight = ?, rate = ? WHERE id = ?");
        }
        $updateContainer->bind_param("ddi", $weight_value, $rate, $container_id);
        $updateContainer->execute();

        $expenseStmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) AS total_expenses FROM container_expenses WHERE container_id = ?");
        $expenseStmt->bind_param("i", $container_id);
        $expenseStmt->execute();
        $expenseRow = $expenseStmt->get_result()->fetch_assoc();
        $total_expenses = $expenseRow ? (float)$expenseRow['total_expenses'] : 0.0;
        $net_payable = $newInvoiceAmount - $total_expenses;

        $invoiceStmt = $conn->prepare("SELECT id FROM invoices WHERE container_id = ? ORDER BY id DESC LIMIT 1");
        $invoiceStmt->bind_param("i", $container_id);
        $invoiceStmt->execute();
        $invoiceData = $invoiceStmt->get_result()->fetch_assoc();

        if ($invoiceData) {
            $invoiceId = (int)$invoiceData['id'];
            $updateInvoice = $conn->prepare("\n                UPDATE invoices\n                SET customer_id = ?, container_number = ?, gross_weight = ?, rate = ?, invoice_amount = ?, total_expenses = ?, net_payable = ?\n                WHERE id = ?\n            ");
            $updateInvoice->bind_param(
                "isdddddi",
                $customer_id,
                $container_number,
                $weight_value,
                $rate,
                $newInvoiceAmount,
                $total_expenses,
                $net_payable,
                $invoiceId
            );
            $updateInvoice->execute();
        } else {
            $invoiceNumber = 'INV-' . str_pad($container_id, 5, '0', STR_PAD_LEFT);
            $invoiceDateDB = date('Y-m-d');
            $added_to_account = 0;
            $newInvoiceId = getNextReusableId($conn, 'invoices');
            $insertInvoice = $conn->prepare("INSERT INTO invoices (id, container_id, customer_id, container_number, invoice_number, gross_weight, rate, invoice_amount, total_expenses, net_payable, invoice_date, added_to_account) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $insertInvoice->bind_param(
                "iiisssddddsi",
                $newInvoiceId,
                $container_id,
                $customer_id,
                $container_number,
                $invoiceNumber,
                $weight_value,
                $rate,
                $newInvoiceAmount,
                $total_expenses,
                $net_payable,
                $invoiceDateDB,
                $added_to_account
            );
            $insertInvoice->execute();
        }

        $paidStmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) AS total_paid FROM container_transactions WHERE container_id = ? AND transaction_type = 'credit'");
        $paidStmt->bind_param("i", $container_id);
        $paidStmt->execute();
        $paidRow = $paidStmt->get_result()->fetch_assoc();
        $total_paid = $paidRow ? (float)$paidRow['total_paid'] : 0.0;
        $remaining_amount = $newInvoiceAmount - $total_paid;

        $containerTotalsStmt = $conn->prepare("UPDATE containers SET total_amount = ?, total_paid = ?, remaining_amount = ? WHERE id = ?");
        $containerTotalsStmt->bind_param("dddi", $newInvoiceAmount, $total_paid, $remaining_amount, $container_id);
        $containerTotalsStmt->execute();

        $customerTotals = updateCustomerAccountTotals($customer_id, $conn);

        $conn->commit();

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => 'Invoice configuration saved successfully',
            'container_id' => $container_id,
            'new_amount' => $newInvoiceAmount,
            'container' => [
                'id' => $container_id,
                'rate' => $rate,
                'gross_weight' => $weight_type === 'gross' ? $weight_value : null,
                'net_weight' => $weight_type === 'net' ? $weight_value : null,
                'total_amount' => $newInvoiceAmount,
                'total_paid' => $total_paid,
                'remaining_amount' => $remaining_amount
            ],
            'customer' => $customerTotals
        ]);
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Failed to save invoice configuration: ' . $e->getMessage()
        ]);
        exit;
    }
    
    // Calculate new invoice amount for response
    $newInvoiceAmount = $weight_value * $rate;
    
    // Update container's weight (based on selected weight type) and rate
    if ($weight_type === 'net') {
        $updateContainer = $conn->prepare("UPDATE containers SET net_weight = ?, rate = ? WHERE id = ?");
    } else {
        $updateContainer = $conn->prepare("UPDATE containers SET gross_weight = ?, rate = ? WHERE id = ?");
    }
    $updateContainer->bind_param("ddi", $weight_value, $rate, $container_id);
    $updateContainer->execute();
    
    // Update or create invoice record to maintain consistency for weight type detection
    $checkInvoice = $conn->query("SELECT id, invoice_amount, added_to_account FROM invoices WHERE container_id = $container_id");
    if ($checkInvoice && $checkInvoice->num_rows > 0) {
        // Get existing invoice details
        $existingInvoice = $checkInvoice->fetch_assoc();
        $previousInvoiceAmount = (float)$existingInvoice['invoice_amount'];
        $invoiceAdded = (bool)($existingInvoice['added_to_account'] ?? 0);
        
        // Calculate the difference in invoice amount
        $amountDifference = $newInvoiceAmount - $previousInvoiceAmount;
        
        // Update existing invoice with new weight value
        $updateInvoice = $conn->prepare("UPDATE invoices SET gross_weight = ?, invoice_amount = ? WHERE container_id = ?");
        $updateInvoice->bind_param("ddi", $weight_value, $newInvoiceAmount, $container_id);
        $updateInvoice->execute();
        
        // If invoice was already added to customer account, update customer totals with the difference
        if ($invoiceAdded && $amountDifference != 0) {
            // Start transaction for data integrity
            $conn->begin_transaction();
            
            try {
                // Update customer totals
                $updateCustomer = $conn->prepare("
                    UPDATE customers 
                    SET total_invoiced = total_invoiced + ?, 
                        remaining_amount = remaining_amount + ?
                    WHERE id = ?
                ");
                $updateCustomer->bind_param("ddi", $amountDifference, $amountDifference, $customer_id);
                $updateCustomer->execute();
                
                // Get container number for description
                $containerInfo = $conn->query("SELECT container_number FROM containers WHERE id = $container_id");
                $containerNumber = 'Unknown';
                if ($containerInfo && $row = $containerInfo->fetch_assoc()) {
                    $containerNumber = $row['container_number'];
                }
                
                $conn->commit();
            } catch (Exception $e) {
                $conn->rollback();
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'message' => 'Error updating customer account: ' . $e->getMessage()
                ]);
                exit;
            }
        }
    } else {
        // Create invoice record with all required fields
        // Fetch container_number for this container
        $container_number = '';
        $containerQuery = $conn->prepare("SELECT container_number FROM containers WHERE id = ?");
        $containerQuery->bind_param("i", $container_id);
        $containerQuery->execute();
        $containerResult = $containerQuery->get_result();
        if ($containerRow = $containerResult->fetch_assoc()) {
            $container_number = $containerRow['container_number'];
        }
        $invoiceNumber = 'INV-' . str_pad($container_id, 5, '0', STR_PAD_LEFT);
        $invoiceDateDB = date('Y-m-d');
        $total_expenses = 0.00;
        $net_payable = $newInvoiceAmount;
        $added_to_account = 0;
        $newInvoiceId = getNextReusableId($conn, 'invoices');
        $insertInvoice = $conn->prepare("INSERT INTO invoices (id, container_id, customer_id, container_number, invoice_number, gross_weight, rate, invoice_amount, total_expenses, net_payable, invoice_date, added_to_account) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $insertInvoice->bind_param(
            "iiisssddddsi",
            $newInvoiceId,
            $container_id,
            $customer_id,
            $container_number,
            $invoiceNumber,
            $weight_value,
            $rate,
            $newInvoiceAmount,
            $total_expenses,
            $net_payable,
            $invoiceDateDB,
            $added_to_account
        );
        $insertInvoice->execute();
    }
    
    // Note: Customer totals will be updated by invoice_view action when invoice is generated/viewed
    // This ensures consistency and avoids double-updating
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => 'Invoice configuration saved successfully',
        'container_id' => $container_id,
        'new_amount' => $newInvoiceAmount
    ]);
    exit;
}

/* ============================
   HANDLE EXPENSE REPORT VIEW (PRINTABLE)
   Professional A4 Design
============================ */
if ($action === 'expense_report' && $id && $isAjax) {
    // Fetch container + customer details
    $stmt = $conn->prepare("
        SELECT c.*, cu.name AS customer_name, cu.phone AS customer_phone, cu.email AS customer_email, cu.notes AS customer_notes
        FROM containers c
        JOIN customers cu ON cu.id = c.customer_id
        WHERE c.id = ?
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $container = $stmt->get_result()->fetch_assoc();

    if (!$container) {
        echo '<div class="p-6 text-center text-red-500 font-bold">Error: Container not found.</div>';
        exit;
    }
    
    // Fetch expenses
    $expenses = [];
    $totalExpense = 0;
    $eStmt = $conn->prepare("
        SELECT ce.*, COALESCE(a.name, '-') AS agent_name 
        FROM container_expenses ce
        LEFT JOIN agents a ON a.id = ce.agent_id
        WHERE ce.container_id = ? 
        ORDER BY ce.expense_date ASC, ce.id ASC
    ");
    $eStmt->bind_param("i", $id);
    $eStmt->execute();
    $eResult = $eStmt->get_result();
    while ($e = $eResult->fetch_assoc()) {
        $expenses[] = $e;
        $totalExpense += (float)$e['amount'];
    }
    
    $reportDate = date('d M Y');
    
    // Get system settings
    $systemSettings = getSystemSettings();
    $systemName = $systemSettings['system_name'] ?: 'Container Management';
    $systemLocation = $systemSettings['system_location'] ?: '';
    $systemContact = $systemSettings['system_contact'] ?: '';
    $systemEmail = $systemSettings['system_email'] ?: '';
    $systemLogo = $systemSettings['system_logo'] ?: '';
    ?>
    
    <div id="printableExpenseReport" class="expense-report-page shadow-xl">
    <style>
        /* A4 Print Styles */
        @media print {
            @page { size: A4; margin: 0; }
            body { margin: 0; padding: 0; background: white; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
            .expense-report-page { 
                width: 100%; margin: 0; padding: 15mm; box-shadow: none !important; border: none !important; 
            }
            .no-print { display: none !important; }
        }
        
        .expense-report-page {
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto;
            padding: 15mm;
            background: white;
            position: relative;
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            color: #1f2937;
        }

        .report-header { border-bottom: 3px solid #059669; padding-bottom: 20px; margin-bottom: 30px; }
        .company-name-exp { font-size: 32px; font-weight: 800; color: #065f46; letter-spacing: -0.5px; }
        .company-sub-exp { font-size: 14px; color: #6b7280; font-weight: 500; margin-top: 4px; }
        
        .report-title { font-size: 36px; font-weight: 900; color: #10b981; text-transform: uppercase; letter-spacing: 2px; margin-bottom: 10px; }
        
        .info-section { background: #f0fdf4; border-radius: 8px; padding: 20px; margin-bottom: 25px; border: 2px solid #bbf7d0; }
        .info-row { display: flex; gap: 40px; margin-bottom: 10px; }
        .info-label { font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; color: #065f46; font-weight: 700; }
        .info-value { font-size: 15px; font-weight: 600; color: #111827; margin-top: 2px; }

        .expense-table { width: 100%; border-collapse: separate; border-spacing: 0; margin-bottom: 30px; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; }
        .expense-table th { background: #065f46; color: white; padding: 14px 16px; text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 0.8px; font-weight: 700; }
        .expense-table th:first-child { border-top-left-radius: 6px; }
        .expense-table th:last-child { border-top-right-radius: 6px; text-align: right; }
        .expense-table td { padding: 12px 16px; border-bottom: 1px solid #e5e7eb; vertical-align: middle; background: white; font-size: 14px; }
        .expense-table tbody tr:last-child td { border-bottom: none; }
        .expense-table tbody tr:hover { background-color: #f9fafb; }
        .expense-table .amount-col { text-align: right; font-weight: 700; }
        .expense-table .text-center { text-align: center; }

        .total-section { background: linear-gradient(to right, #d1fae5, #a7f3d0); border: 3px solid #059669; border-radius: 10px; padding: 20px; margin-top: 30px; }
        .total-row { display: flex; justify-content: space-between; align-items: center; }
        .total-label { font-size: 20px; font-weight: 800; color: #065f46; text-transform: uppercase; letter-spacing: 1px; }
        .total-amount { font-size: 28px; font-weight: 900; color: #047857; }
        
        .report-footer { position: absolute; bottom: 15mm; left: 15mm; right: 15mm; text-align: center; font-size: 11px; color: #9ca3af; border-top: 1px solid #e5e7eb; padding-top: 15px; }
    </style>

    <!-- Report Header -->
    <div class="report-header">
        <div class="flex justify-between items-start">
            <div>
                <?php if (!empty($systemLogo)): ?>
                <div class="flex items-center gap-4 mb-3">
                    <img src="uploads/system/<?= htmlspecialchars($systemLogo) ?>" 
                         alt="<?= htmlspecialchars($systemName) ?>" 
                         class="h-16 object-contain">
                    <div>
                        <div class="company-name-exp"><?= htmlspecialchars($systemName) ?></div>
                        <div class="company-sub-exp">Container Management System</div>
                    </div>
                </div>
                <?php else: ?>
                <div class="company-name-exp"><?= htmlspecialchars($systemName) ?></div>
                <div class="company-sub-exp">Container Management System</div>
                <?php endif; ?>
                <div class="mt-3 text-sm text-gray-500">
                    <?php if (!empty($systemLocation)): ?>
                    <p><?= nl2br(htmlspecialchars($systemLocation)) ?></p>
                    <?php endif; ?>
                    <?php 
                    $contactParts = [];
                    if (!empty($systemEmail)) $contactParts[] = htmlspecialchars($systemEmail);
                    if (!empty($systemContact)) $contactParts[] = htmlspecialchars($systemContact);
                    if (!empty($contactParts)): 
                    ?>
                    <p><?= implode(' | ', $contactParts) ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="text-right">
                <div class="text-sm text-gray-600 mb-1">Report Date</div>
                <div class="text-lg font-bold text-gray-900"><?= $reportDate ?></div>
            </div>
        </div>
    </div>

    <div class="report-title text-center">
        <i class="fas fa-receipt mr-3"></i>EXPENSE REPORT
    </div>

    <!-- Container & Customer Info -->
    <div class="info-section">
        <div class="info-row">
            <div class="flex-1">
                <div class="info-label">Container Number</div>
                <div class="info-value"><?= htmlspecialchars($container['container_number']) ?></div>
            </div>
            <div class="flex-1">
                <div class="info-label">BL Number</div>
                <div class="info-value"><?= htmlspecialchars($container['bl_number'] ?: 'N/A') ?></div>
            </div>
        </div>
        <div class="info-row" style="margin-bottom: 0;">
            <div class="flex-1">
                <div class="info-label">Customer Name</div>
                <div class="info-value"><?= htmlspecialchars($container['customer_name']) ?></div>
            </div>
            <div class="flex-1">
                <div class="info-label">Total Expenses</div>
                <div class="info-value" style="color: #059669; font-size: 18px;"><?= count($expenses) ?> Record(s)</div>
            </div>
        </div>
    </div>

    <!-- Expenses Table -->
    <table class="expense-table">
        <thead>
            <tr>
                <th style="width: 15%;">Date</th>
                <th style="width: 35%;">Expense Type</th>
                <th style="width: 20%;">Agent</th>
                <th style="width: 20%;">Amount</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($expenses)): ?>
                <?php foreach ($expenses as $exp): ?>
                <tr>
                    <td class="text-gray-700"><?= date('d M Y', strtotime($exp['expense_date'])) ?></td>
                    <td class="font-semibold text-gray-900"><?= htmlspecialchars($exp['expense_type']) ?></td>
                    <td class="text-gray-700"><?= htmlspecialchars($exp['agent_name']) ?></td>
                    <td class="amount-col text-gray-900">PKR <?= number_format((float)$exp['amount'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="4" class="text-center text-gray-500 py-8">No expenses recorded for this container</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- Total Section -->
    <?php if (!empty($expenses)): ?>
    <div class="total-section">
        <div class="total-row">
            <div class="total-label">
                <i class="fas fa-calculator mr-2"></i>TOTAL EXPENSES
            </div>
            <div class="total-amount">
                PKR <?= number_format($totalExpense, 2) ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Footer -->
    <div class="report-footer">
        <p class="font-bold mb-1">Container Management System - Expense Report</p>
        <p>This is a computer-generated report. Generated on <?= date('d M Y, h:i A') ?></p>
    </div>
    </div>
    <?php
    exit;
}

/* ============================
   HANDLE INVOICE VIEW (PRINTABLE)
   Professional A4 Design
============================ */
if ($action === 'invoice_view' && $id && $isAjax) {
    // Fetch container + customer details - rate comes from container now
    $stmt = $conn->prepare("
        SELECT c.*, cu.name AS customer_name, cu.phone AS customer_phone, cu.email AS customer_email, cu.notes AS customer_notes
        FROM containers c
        JOIN customers cu ON cu.id = c.customer_id
        WHERE c.id = ?
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $container = $stmt->get_result()->fetch_assoc();

    if (!$container) {
        echo '<div class="p-6 text-center text-red-500 font-bold">Error: Container not found.</div>';
        exit;
    }

    // Get rate from container's own rate column
    $rate = isset($container['rate']) ? (float)$container['rate'] : 0.00;
    
    // Determine which weight to use based on weight_type parameter
    $weight_type = $_GET['weight_type'] ?? 'gross';
    $invoiceWeight = ($weight_type === 'net') ? (float)$container['net_weight'] : (float)$container['gross_weight'];
    $weightLabel = ($weight_type === 'net') ? 'Net Weight' : 'Gross Weight';
    
    // Fetch expenses
    $expenses = [];
    $totalExpense = 0;
    $eStmt = $conn->prepare("SELECT * FROM container_expenses WHERE container_id = ? ORDER BY expense_date ASC");
    $eStmt->bind_param("i", $id);
    $eStmt->execute();
    $eResult = $eStmt->get_result();
    while ($e = $eResult->fetch_assoc()) {
        $expenses[] = $e;
        $totalExpense += $e['amount'];
    }

    // Fetch container payments (credit transactions) for this container
    $totalPayments = 0;
    $payments = [];
    $pStmt = $conn->prepare("
        SELECT amount, description, transaction_date, payment_method, reference_number
        FROM container_transactions 
        WHERE container_id = ? AND transaction_type = 'credit'
        ORDER BY transaction_date ASC
    ");
    $pStmt->bind_param("i", $id);
    $pStmt->execute();
    $pResult = $pStmt->get_result();
    while ($p = $pResult->fetch_assoc()) {
        $payments[] = $p;
        $totalPayments += $p['amount'];
    }

    // Calculations
    // Invoice Amount = Selected Weight * Rate
    $grossAmount = $invoiceWeight * $rate;
    
    // Subtotal after expenses
    $subtotalAfterExpenses = $grossAmount - $totalExpense;
    
    // Net Payable = Invoice Amount - Expenses - Payments
    $netPayable = $subtotalAfterExpenses - $totalPayments;
    
    $invoiceNumber = 'INV-' . str_pad($id, 5, '0', STR_PAD_LEFT);
    $invoiceDate = date('d M Y');
    $invoiceDateDB = date('Y-m-d');
    
    // Get system settings for invoice header
    $systemSettings = getSystemSettings();
    $systemName = $systemSettings['system_name'] ?: 'Container';
    $systemLocation = $systemSettings['system_location'] ?: '';
    $systemContact = $systemSettings['system_contact'] ?: '';
    $systemEmail = $systemSettings['system_email'] ?: '';
    $systemLogo = $systemSettings['system_logo'] ?: '';
    
    // Save or update invoice in database
    // Check if invoice already exists for this container
    $checkInvoice = $conn->query("SELECT id, invoice_amount, added_to_account FROM invoices WHERE container_id = $id");
    $invoiceExists = $checkInvoice->num_rows > 0;
    $previousInvoiceAmount = 0;
    $invoiceAdded = false;
    
    if ($invoiceExists) {
        $existingInvoice = $checkInvoice->fetch_assoc();
        $previousInvoiceAmount = (float)$existingInvoice['invoice_amount'];
        $invoiceAdded = (bool)($existingInvoice['added_to_account'] ?? 0);
        
        // Calculate the difference in invoice amount
        $amountDifference = $grossAmount - $previousInvoiceAmount;
        
        // Update existing invoice
        $updateInvoice = $conn->prepare("
            UPDATE invoices 
            SET customer_id = ?, 
                container_number = ?, 
                gross_weight = ?, 
                rate = ?, 
                invoice_amount = ?, 
                total_expenses = ?, 
                net_payable = ?,
                invoice_date = ?
            WHERE container_id = ?
        ");
        $updateInvoice->bind_param(
            "isdddddsi",
            $container['customer_id'],
            $container['container_number'],
            $invoiceWeight,
            $rate,
            $grossAmount,
            $totalExpense,
            $netPayable,
            $invoiceDateDB,
            $id
        );
        $updateInvoice->execute();
        
        // If invoice was already added to customer account, update customer totals with the difference
        if ($invoiceAdded && $amountDifference != 0) {
            $updateCustomerTotals = $conn->prepare("
                UPDATE customers 
                SET total_invoiced = total_invoiced + ?, 
                    remaining_amount = remaining_amount + ?
                WHERE id = ?
            ");
            $updateCustomerTotals->bind_param("ddi", $amountDifference, $amountDifference, $container['customer_id']);
            $updateCustomerTotals->execute();
        }
    } else {
        // Insert new invoice
        $insertInvoice = $conn->prepare("
            INSERT INTO invoices 
            (container_id, customer_id, container_number, invoice_number, gross_weight, rate, invoice_amount, total_expenses, net_payable, invoice_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $insertInvoice->bind_param(
            "iissddddds",
            $id,
            $container['customer_id'],
            $container['container_number'],
            $invoiceNumber,
            $invoiceWeight,
            $rate,
            $grossAmount,
            $totalExpense,
            $netPayable,
            $invoiceDateDB
        );
        $insertInvoice->execute();
        $invoiceAdded = false;
    }
    ?>
    
    <div id="printableInvoiceContent" class="invoice-page">
    <style>
        * { box-sizing: border-box; }
        @media print {
            @page { size: A4; margin: 12mm; margin-top: 8mm; margin-bottom: 0; }
            body { margin: 0; padding: 0; background: white; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
            .invoice-page { box-shadow: none !important; transform: none !important; padding: 0 !important; }
            .inv-stamp, .inv-main-title { display: none !important; }
        }
        .invoice-page {
            width: 100%;
            max-width: 780px;
            margin: 0 auto;
            padding: 28px 32px;
            background: white;
            font-family: Arial, sans-serif;
            font-size: 12px;
            color: #111;
            transform: scale(0.85);
            transform-origin: top center;
        }
        /* Top stamp row */
        .inv-stamp { display: flex; justify-content: space-between; font-size: 11px; margin-bottom: 6px; color: #555; }
        /* Title */
        .inv-main-title { text-align: center; font-size: 16px; font-weight: 700; letter-spacing: 1px; margin-bottom: 10px; }
        /* Company header */
        .inv-company-wrap { display: flex; align-items: center; justify-content: center; gap: 14px; margin-bottom: 6px; }
        .inv-company-logo { width: 72px; height: 72px; object-fit: contain; }
        .inv-company-name { font-size: 30px; font-weight: 700; line-height: 1.1; color: #111; }
        .inv-company-meta { text-align: center; font-size: 11px; color: #333; line-height: 1.5; margin-bottom: 4px; }
        /* Dividers */
        .inv-divider { border: none; border-top: 2px solid #111; margin: 8px 0; }
        /* Two-column info rows */
        .inv-row { display: flex; justify-content: space-between; margin: 4px 0; font-size: 12px; }
        .inv-row .inv-left { flex: 1; }
        .inv-row .inv-right { text-align: right; }
        .inv-label { font-weight: 700; }
        /* Container detail grid */
        .inv-detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 3px 20px; margin: 6px 0; font-size: 12px; }
        .inv-detail-item { display: flex; gap: 4px; }
        .inv-detail-item .inv-label { min-width: 110px; }
        .inv-detail-grid > .inv-detail-item:nth-child(odd) .inv-label { min-width: unset; }
        .inv-detail-grid > .inv-detail-item:nth-child(even) { justify-content: flex-end; text-align: right; }
        /* Table */
        .inv-table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        .inv-table th, .inv-table td { border: 1px solid #111; padding: 7px 8px; font-size: 12px; }
        .inv-table th { font-weight: 700; text-align: center; background: #f5f5f5; }
        .inv-table td.center { text-align: center; }
        .inv-table td.right { text-align: right; }
        .inv-table tr.total-row td { font-weight: 700; background: #f5f5f5; }
        .inv-table tr.due-row td { font-weight: 700; font-size: 13px; }
        .inv-section-title { text-align: center; font-size: 18px; font-weight: 700; margin: 10px 0 14px; letter-spacing: 0.3px; }
    </style>

    <!-- Page title -->
    <div class="inv-main-title">Invoice</div>

    <!-- Company header: logo + name + contact (like screenshot) -->
    <div class="inv-company-wrap">
        <?php if (!empty($systemLogo) && file_exists("uploads/system/$systemLogo")): ?>
            <img src="uploads/system/<?= htmlspecialchars($systemLogo) ?>" alt="Logo" class="inv-company-logo">
        <?php endif; ?>
        <div>
            <div class="inv-company-name"><?= htmlspecialchars($systemName) ?></div>
            <?php if (!empty($systemLocation)): ?>
                <div class="inv-company-meta"><?= htmlspecialchars($systemLocation) ?></div>
            <?php endif; ?>
            <?php if (!empty($systemContact) || !empty($systemEmail)): ?>
                <div class="inv-company-meta">
                    <?= htmlspecialchars($systemContact) ?><?= (!empty($systemContact) && !empty($systemEmail)) ? ' | ' : '' ?><?= htmlspecialchars($systemEmail) ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <hr class="inv-divider">
    <div class="inv-section-title">Invoice</div>

    <!-- Customer details + Invoice meta -->
    <div class="inv-row">
        <div class="inv-left">
            <div><span class="inv-label">Customer Name:</span> <?= htmlspecialchars($container['customer_name']) ?></div>
            <?php if (!empty($container['customer_phone'])): ?>
            <div><span class="inv-label">Phone:</span> <?= htmlspecialchars($container['customer_phone']) ?></div>
            <?php endif; ?>
            <?php if (!empty($container['customer_email'])): ?>
            <div><span class="inv-label">Email:</span> <?= htmlspecialchars($container['customer_email']) ?></div>
            <?php endif; ?>
        </div>
        <div class="inv-right">
            <div><span class="inv-label">Invoice #:</span> <?= htmlspecialchars($invoiceNumber) ?></div>
            <div><span class="inv-label">Date:</span> <?= $invoiceDate ?></div>
        </div>
    </div>

    <hr class="inv-divider">

    <!-- Container details -->
    <div class="inv-detail-grid">
        <?php if (!empty($container['container_number'])): ?>
        <div class="inv-detail-item"><span class="inv-label">Container #:</span> <span><?= htmlspecialchars($container['container_number']) ?></span></div>
        <?php endif; ?>
        <?php if (!empty($container['bl_number'])): ?>
        <div class="inv-detail-item"><span class="inv-label">B/L #:</span> <span><?= htmlspecialchars($container['bl_number']) ?></span></div>
        <?php endif; ?>
        <?php if (!empty($container['hs_code'])): ?>
        <div class="inv-detail-item"><span class="inv-label">HS Code:</span> <span><?= htmlspecialchars($container['hs_code']) ?></span></div>
        <?php endif; ?>
        <?php if (!empty($container['gd_no'])): ?>
        <div class="inv-detail-item"><span class="inv-label">GD #:</span> <span><?= htmlspecialchars($container['gd_no']) ?></span></div>
        <?php endif; ?>
        <?php if (!empty($container['tp_no'])): ?>
        <div class="inv-detail-item"><span class="inv-label">TP #:</span> <span><?= htmlspecialchars($container['tp_no']) ?></span></div>
        <?php endif; ?>
        <?php if (!empty($container['destination'])): ?>
        <div class="inv-detail-item"><span class="inv-label">Destination:</span> <span><?= htmlspecialchars($container['destination']) ?></span></div>
        <?php endif; ?>
        <?php if (!empty($container['port'])): ?>
        <div class="inv-detail-item"><span class="inv-label">Port:</span> <span><?= htmlspecialchars($container['port']) ?></span></div>
        <?php endif; ?>
        <?php if (!empty($container['packages'])): ?>
        <div class="inv-detail-item"><span class="inv-label">Packages:</span> <span><?= htmlspecialchars($container['packages']) ?></span></div>
        <?php endif; ?>
        <?php if (!empty($container['net_weight'])): ?>
        <div class="inv-detail-item"><span class="inv-label">Net Weight:</span> <span><?= number_format((float)$container['net_weight'], 2) ?> kg</span></div>
        <?php endif; ?>
        <?php if (!empty($container['gross_weight'])): ?>
        <div class="inv-detail-item"><span class="inv-label">Gross Weight:</span> <span><?= number_format((float)$container['gross_weight'], 2) ?> kg</span></div>
        <?php endif; ?>
        <?php if (!empty($container['status'])): ?>
        <div class="inv-detail-item"><span class="inv-label">Status:</span> <span><?= htmlspecialchars(ucfirst($container['status'])) ?></span></div>
        <?php endif; ?>
    </div>

    <hr class="inv-divider">

    <!-- Weight / Rate / Amount table -->
    <?php
    $invoiceTotal = (float)($container['total_amount'] ?? $grossAmount);
    $profit = $invoiceTotal - $totalExpense;
    ?>
    <table class="inv-table">
        <thead>
            <tr>
                <th>Description</th>
                <th><?= htmlspecialchars($weightLabel) ?> (kg)</th>
                <th>Rate (PKR/kg)</th>
                <th>Amount (PKR)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Container Charges</td>
                <td class="center"><?= number_format($invoiceWeight, 2) ?></td>
                <td class="center"><?= number_format($rate, 2) ?></td>
                <td class="right"><?= number_format($invoiceTotal, 2) ?></td>
            </tr>
            <tr class="total-row">
                <td colspan="3" class="right">Invoice Total</td>
                <td class="right">PKR <?= number_format($invoiceTotal, 2) ?></td>
            </tr>
        </tbody>
    </table>

    </div>
    <script>
    window.invoiceAddedToAccount = <?= $invoiceAdded ? 'true' : 'false' ?>;
    window.currentInvoiceContainerId = <?= $id ?>;
    </script>
    <?php
    exit;
}

// =============================================
// AJAX: ADD INVOICE TO CUSTOMER ACCOUNT
// =============================================
if ($action === 'add_invoice_to_account' && $isAjax) {
    header('Content-Type: application/json');
    
    $containerId = isset($_POST['container_id']) ? intval($_POST['container_id']) : 0;
    // Defensive validation for container_id
    if (!$containerId || $containerId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid or missing container ID. Please refresh the page and try again.']);
        exit;
    }
    // Optionally, check that container_id is numeric and not a string
    if (!is_numeric($_POST['container_id']) || strval(intval($_POST['container_id'])) !== strval($_POST['container_id'])) {
        echo json_encode(['success' => false, 'message' => 'Container ID must be a valid integer.']);
        exit;
    }
    
    // Check if added_to_account column exists
    $checkColumn = $conn->query("SHOW COLUMNS FROM invoices LIKE 'added_to_account'");
    if (!$checkColumn || $checkColumn->num_rows === 0) {
        echo json_encode([
            'success' => false, 
            'message' => 'Database not configured properly. Please run: ALTER TABLE invoices ADD COLUMN added_to_account TINYINT(1) DEFAULT 0;'
        ]);
        exit;
    }
    
    // Get container details
    $containerQuery = $conn->query("
        SELECT c.*, cu.name AS customer_name 
        FROM containers c 
        LEFT JOIN customers cu ON cu.id = c.customer_id 
        WHERE c.id = $containerId
    ");
    
    if (!$containerQuery || $containerQuery->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Container not found']);
        exit;
    }
    
    $container = $containerQuery->fetch_assoc();
    
    // Validate customer_id
    if (empty($container['customer_id'])) {
        echo json_encode(['success' => false, 'message' => 'Customer ID not found for this container']);
        exit;
    }
    
    // Check if invoice exists
    $invoiceQuery = $conn->query("
        SELECT i.*, c.customer_id, c.container_number 
        FROM invoices i 
        LEFT JOIN containers c ON c.id = i.container_id 
        WHERE i.container_id = $containerId
    ");
    
    $invoice = null;
    if ($invoiceQuery && $invoiceQuery->num_rows > 0) {
        $invoice = $invoiceQuery->fetch_assoc();
        
        // Check if already added to account
        if (!empty($invoice['added_to_account'])) {
            echo json_encode(['success' => false, 'message' => 'Invoice already added to customer account']);
            exit;
        }
    } else {
        // Invoice doesn't exist, create it automatically
        $rate = isset($container['rate']) ? (float)$container['rate'] : 0.00;
        $grossWeight = (float)$container['gross_weight'];
        $invoiceAmount = $grossWeight * $rate;
        // Get expenses
        $totalExpense = 0;
        $expQuery = $conn->query("SELECT SUM(amount) as total FROM container_expenses WHERE container_id = $containerId");
        if ($expQuery && $row = $expQuery->fetch_assoc()) {
            $totalExpense = (float)$row['total'];
        }
        $netPayable = $invoiceAmount - $totalExpense;
        $invoiceNumber = 'INV-' . str_pad($containerId, 5, '0', STR_PAD_LEFT);
        $invoiceDateDB = date('Y-m-d');
        $added_to_account = 0;
        $newInvoiceId = getNextReusableId($conn, 'invoices');
        $insertInvoice = $conn->prepare("INSERT INTO invoices (id, container_id, customer_id, container_number, invoice_number, gross_weight, rate, invoice_amount, total_expenses, net_payable, invoice_date, added_to_account) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $insertInvoice->bind_param(
            "iiisssddddsi",
            $newInvoiceId,
            $containerId,
            $container['customer_id'],
            $container['container_number'],
            $invoiceNumber,
            $grossWeight,
            $rate,
            $invoiceAmount,
            $totalExpense,
            $netPayable,
            $invoiceDateDB,
            $added_to_account
        );
        if (!$insertInvoice->execute()) {
            echo json_encode(['success' => false, 'message' => 'Failed to create invoice: ' . $insertInvoice->error]);
            exit;
        }
        // Get the newly created invoice
        $invoiceQuery = $conn->query("SELECT i.*, c.customer_id, c.container_number FROM invoices i LEFT JOIN containers c ON c.id = i.container_id WHERE i.container_id = $containerId");
        if ($invoiceQuery && $invoiceQuery->num_rows > 0) {
            $invoice = $invoiceQuery->fetch_assoc();
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to retrieve created invoice']);
            exit;
        }
    }
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Mark invoice as added to account
        $markStmt = $conn->prepare("UPDATE invoices SET added_to_account = 1 WHERE container_id = ?");
        $markStmt->bind_param("i", $containerId);
        if (!$markStmt->execute()) {
            throw new Exception('Failed to mark invoice as added: ' . $markStmt->error);
        }

        // Update customer account totals with all their container transactions
        if (!empty($invoice['customer_id'])) {
            updateCustomerAccountTotals((int)$invoice['customer_id'], $conn);
        }
        
        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Invoice successfully added to customer account'
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    
    exit;
}

// =============================================
// AJAX: CHECK INVOICE STATUS
// =============================================
if ($action === 'check_invoice_status' && $id && $isAjax) {
    header('Content-Type: application/json');
    
    // Check if invoice exists and is added to account
    $checkQuery = $conn->query("SELECT added_to_account FROM invoices WHERE container_id = " . intval($id));
    
    if ($checkQuery && $checkQuery->num_rows > 0) {
        $invoice = $checkQuery->fetch_assoc();
        echo json_encode([
            'success' => true, 
            'added_to_account' => (bool)($invoice['added_to_account'] ?? 0)
        ]);
    } else {
        echo json_encode([
            'success' => true, 
            'added_to_account' => false
        ]);
    }
    
    exit;
}

if (!$isAjax) {
    include 'include/header.php';
    include 'include/sidebar.php';
}

// Safety fallback: If AJAX request reached this point without being handled, return error JSON
if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => "Action '$action' not found or invalid request. ID: " . ($id ?? 'not provided') . ". Check browser console for details."
    ]);
    exit;
}

if ($isAjax) {
    ob_start();
}
?>
<div id="page-content">

<div class="max-w-7xl mx-auto">

<div class="flex justify-between items-center mb-6">
    <h1 class="text-3xl font-bold text-gray-800">
        <i class="fas fa-shipping-fast mr-2 text-blue-600"></i>Containers Management
    </h1>
    <?php if ($isAdmin): ?>
        <button id="addContainerBtn" onclick="openContainerModal()" class="bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 text-white px-6 py-2.5 rounded-lg shadow-lg transition-all duration-200 flex items-center gap-2">
            <i class="fas fa-plus"></i> Add Container
        </button>
    <?php endif; ?>
</div>

<!-- CONTAINER MODAL -->
<div id="containerModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center overflow-hidden" style="display: none;">
    <div class="bg-white rounded-lg shadow-2xl w-full max-w-2xl mx-4">
        <div class="bg-gradient-to-r from-blue-600 to-blue-700 text-white px-6 py-3 rounded-t-lg flex justify-between items-center">
            <h2 class="text-lg font-bold" id="modalTitle">
                <i class="fas fa-box mr-2"></i>Add Container
            </h2>
            <button onclick="closeContainerModal()" class="text-white hover:text-gray-200 text-xl">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="POST" id="containerForm" enctype="multipart/form-data" class="p-4">
            <input type="hidden" name="container_id" id="container_id">
            <input type="hidden" name="existing_invoice" id="existing_invoice">
            
            <div class="grid grid-cols-2 gap-3">
                <div class="col-span-2">
                    <label class="block text-xs font-semibold text-gray-700 mb-1">
                        <i class="fas fa-user text-blue-600 mr-1"></i>Customer *
                    </label>
                    <select name="customer_id" id="customer_id" required class="w-full border border-gray-300 p-2 rounded focus:ring-2 focus:ring-blue-500 focus:border-transparent text-sm">
                        <option value="">Select Customer</option>
                        <?php
                        $customers = $conn->query("SELECT id, name FROM customers");
                        while ($c = $customers->fetch_assoc()):
                        ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">
                        <i class="fas fa-file-alt text-blue-600 mr-1"></i>BL Number
                    </label>
                    <input name="bl_number" id="bl_number" placeholder="Bill of Lading Number" class="w-full border border-gray-300 p-2 rounded focus:ring-2 focus:ring-blue-500 focus:border-transparent text-sm">
                </div>
                
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">
                        <i class="fas fa-box text-blue-600 mr-1"></i>Container Number
                    </label>
                    <input name="container_number" id="container_number" placeholder="Container Number" class="w-full border border-gray-300 p-2 rounded focus:ring-2 focus:ring-blue-500 focus:border-transparent text-sm">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">
                        <i class="fas fa-barcode text-blue-600 mr-1"></i>HS Code
                    </label>
                    <input name="hs_code" id="hs_code" placeholder="HS Code" class="w-full border border-gray-300 p-2 rounded focus:ring-2 focus:ring-blue-500 focus:border-transparent text-sm">
                </div>
                
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">
                        <i class="fas fa-weight text-blue-600 mr-1"></i>Net Weight (kg)
                    </label>
                    <input name="net_weight" id="net_weight" type="number" step="0.01" placeholder="0.00" class="w-full border border-gray-300 p-2 rounded focus:ring-2 focus:ring-blue-500 focus:border-transparent text-sm">
                </div>
                
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">
                        <i class="fas fa-weight-hanging text-blue-600 mr-1"></i>Gross Weight (kg)
                    </label>
                    <input name="gross_weight" id="gross_weight" type="number" step="0.01" placeholder="0.00" class="w-full border border-gray-300 p-2 rounded focus:ring-2 focus:ring-blue-500 focus:border-transparent text-sm">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">
                        <i class="fas fa-info-circle text-blue-600 mr-1"></i>Status
                    </label>
                    <select name="status" id="status" class="w-full border border-gray-300 p-2 rounded focus:ring-2 focus:ring-blue-500 focus:border-transparent text-sm">
                        <option value="pending">Pending</option>
                        <option value="completed">Completed</option>
                    </select>
                </div>

                <div class="col-span-2">
                    <label class="block text-xs font-semibold text-gray-700 mb-1">
                        <i class="fas fa-file-invoice text-blue-600 mr-1"></i>Invoice File
                    </label>
                    <input type="file" name="invoice" id="invoice" accept=".pdf,.jpg,.jpeg,.png" class="w-full border border-gray-300 p-1.5 rounded focus:ring-2 focus:ring-blue-500 focus:border-transparent text-sm">
                </div>
            </div>

            <div class="mt-4 flex gap-2 justify-end">
                <button type="button" onclick="closeContainerModal()" class="px-4 py-2 border border-gray-300 text-gray-700 rounded hover:bg-gray-50 transition-colors text-sm">
                    <i class="fas fa-times mr-1"></i>Cancel
                </button>
                <button type="submit" class="px-4 py-2 bg-gradient-to-r from-green-600 to-green-700 hover:from-green-700 hover:to-green-800 text-white rounded transition-all shadow-md text-sm">
                    <i class="fas fa-save mr-1"></i>Save Container
                </button>
            </div>
        </form>
    </div>
</div>

<!-- EXPENSE MODAL -->
<div id="expenseModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-[55] flex items-center justify-center overflow-hidden" style="display: none;">
    <div class="bg-white rounded-lg shadow-2xl w-full max-w-lg mx-4">
        <div class="bg-gradient-to-r from-green-600 to-green-700 text-white px-6 py-3 rounded-t-lg flex justify-between items-center">
            <h2 class="text-lg font-bold">
                <i class="fas fa-receipt mr-2"></i>Add Expense
            </h2>
            <button onclick="closeContainerExpenseModal()" class="text-white hover:text-gray-200 text-xl">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="POST" action="containePKRphp?action=expense" enctype="multipart/form-data" class="p-4" id="expenseForm">
            <input type="hidden" name="container_id" id="expense_container_id">
            <input type="hidden" name="expense_id" id="expense_id">
            <input type="hidden" name="from_list" value="1">
            
            <div class="space-y-3">
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Expense Type</label>
                    <input name="expense_type" id="expense_type" required placeholder="e.g. Transport, Customs, Loading" class="w-full border border-gray-300 p-2 rounded focus:ring-2 focus:ring-green-500 focus:border-transparent text-sm">
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Amount</label>
                        <input name="amount" id="expense_amount" type="number" step="0.01" required placeholder="0.00" class="w-full border border-gray-300 p-2 rounded focus:ring-2 focus:ring-green-500 focus:border-transparent text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Date</label>
                        <input type="date" name="expense_date" id="expense_date" required value="<?= date('Y-m-d') ?>" class="w-full border border-gray-300 p-2 rounded focus:ring-2 focus:ring-green-500 focus:border-transparent text-sm">
                    </div>
                </div>



                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Agent (Optional)</label>
                    <div class="flex items-center gap-2">
                        <select name="agent_id" id="expense_agent_id" class="w-full border border-gray-300 p-2 rounded focus:ring-2 focus:ring-green-500 focus:border-transparent text-sm">
                            <option value="">Select Agent</option>
                            <?php
                            $agents = $conn->query("SELECT id, name FROM agents");
                            while ($a = $agents->fetch_assoc()):
                            ?>
                                <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['name']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Proof Document</label>
                    <input type="file" name="proof" class="w-full border border-gray-300 p-1.5 rounded focus:ring-2 focus:ring-green-500 focus:border-transparent text-sm">
                    <p class="text-xs text-gray-500 mt-1" id="proof_help_text"></p>
                </div>
            </div>

            <div class="mt-4 flex gap-2 justify-end">
                <button type="button" onclick="closeContainerExpenseModal()" class="px-4 py-2 border border-gray-300 text-gray-700 rounded hover:bg-gray-50 transition-colors text-sm">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 bg-gradient-to-r from-green-600 to-green-700 hover:from-green-700 hover:to-green-800 text-white rounded transition-all shadow-md text-sm">
                    <i class="fas fa-save mr-1"></i> Save Expense
                </button>
            </div>
        </form>
    </div>
</div>

<!-- VIEW CONTAINER MODAL -->
<div id="viewContainerModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center overflow-hidden" style="display: none;">
    <div class="bg-white rounded-lg shadow-2xl w-full max-w-3xl mx-4 max-h-[90vh] overflow-hidden flex flex-col">
        <div class="bg-gradient-to-r from-indigo-600 to-blue-600 text-white px-6 py-3 rounded-t-lg flex justify-between items-center">
            <h2 class="text-lg font-bold">
                <i class="fas fa-box-open mr-2"></i>Container Details
            </h2>
            <button type="button" onclick="closeViewModal()" class="print-hide text-white hover:text-gray-200 text-xl">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="p-6 overflow-y-auto">
            <div class="flex flex-wrap gap-4 mb-6">
                <div class="flex-1 min-w-[220px]">
                    <p class="text-xs uppercase text-gray-500 font-semibold">Container Number</p>
                    <p class="text-2xl font-bold text-gray-800" id="view_container_number">-</p>
                    <p class="text-sm text-gray-500" id="view_bl_number">BL: -</p>
                </div>
                <div class="flex items-center gap-2">
                    <span class="px-3 py-1 text-xs font-semibold rounded-full" id="view_status_badge">Status</span>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <div class="bg-gray-50 p-4 rounded-lg">
                    <p class="text-xs uppercase text-gray-500 font-semibold mb-1">Customer</p>
                    <p class="font-semibold text-gray-800" id="view_customer_name">-</p>
                    <p class="text-sm text-gray-500" id="view_customer_phone">-</p>
                </div>
                <div class="bg-gray-50 p-4 rounded-lg">
                    <p class="text-xs uppercase text-gray-500 font-semibold mb-1">Weights (kg)</p>
                    <p class="font-semibold text-gray-800"><span id="view_net_weight">0.00</span> / <span id="view_gross_weight">0.00</span></p>
                </div>
                <div class="bg-gray-50 p-4 rounded-lg">
                    <p class="text-xs uppercase text-gray-500 font-semibold mb-1">Created On</p>
                    <p class="font-semibold text-gray-800" id="view_created_at">-</p>
                </div>
                <div class="bg-blue-50 p-4 rounded-lg border-2 border-blue-200">
                    <p class="text-xs uppercase text-blue-600 font-bold mb-1"><i class="fas fa-receipt mr-1"></i>Total Expenses</p>
                    <p class="font-bold text-blue-700 text-lg">PKR <span id="view_total_expenses">0.00</span></p>
                </div>
            </div>

            <div>
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-lg font-semibold text-gray-800"><i class="fas fa-receipt mr-2 text-green-600"></i>Expenses <span class="text-sm font-normal text-gray-500" id="view_expense_summary">(0 records)</span></h3>
                    <button onclick="printExpenseReport(currentContainerId)" class="bg-gradient-to-r from-green-600 to-green-700 hover:from-green-700 hover:to-green-800 text-white px-4 py-2 rounded-lg text-sm transition-all shadow-md flex items-center gap-2 print-hide">
                        <i class="fas fa-print"></i> Print Expense Report
                    </button>
                </div>
                <div class="border rounded-lg overflow-hidden">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-100 text-gray-600 uppercase text-xs">
                            <tr>
                                <th class="px-4 py-2 text-left">Type</th>
                                <th class="px-4 py-2 text-left">Amount</th>
                                <th class="px-4 py-2 text-left">Date</th>
                                <th class="px-4 py-2 text-left">Agent</th>
                                <th class="px-4 py-2 text-left">Proof</th>
                                <th class="px-4 py-2 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="view_expenses_table">
                            <tr>
                                <td colspan="6" class="px-4 py-3 text-center text-gray-500">No expenses recorded</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- VIEW INVOICE MODAL -->
<div id="invoiceModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center overflow-hidden" style="display: none;">
    <div class="bg-white rounded-lg shadow-2xl w-full max-w-2xl mx-4 max-h-[70vh] flex flex-col overflow-hidden">
        <div class="bg-gradient-to-r from-gray-800 to-gray-900 text-white px-4 py-2.5 rounded-t-lg flex justify-between items-center print-hide flex-shrink-0">
            <h2 class="text-lg font-bold">
                <i class="fas fa-file-invoice mr-2"></i>Invoice Preview
            </h2>
            <div class="flex items-center gap-3">
                <button onclick="printInvoice()" class="text-white hover:text-gray-300 text-2xl" title="Print Invoice">
                    <i class="fas fa-print"></i>
                </button>
                <button onclick="closeInvoiceModal()" class="text-white hover:text-gray-300 text-2xl">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
        <div id="invoiceModalContent" class="flex-1 overflow-y-auto bg-gray-100 p-2 flex justify-center">
            <!-- Invoice content will be loaded here -->
        </div>
        <div class="bg-white border-t border-gray-200 px-4 py-3 rounded-b-lg print-hide flex-shrink-0">
            <button id="addToAccountBtn" onclick="addInvoiceToCustomerAccount()" class="w-full bg-gradient-to-r from-green-600 to-green-700 hover:from-green-700 hover:to-green-800 text-white px-6 py-3 rounded-lg shadow-lg transition-all duration-200 flex items-center justify-center gap-2 font-semibold">
                <i class="fas fa-plus-circle"></i>
                <span>Add Invoice to Customer Account</span>
            </button>
            <div id="addedToAccountMsg" class="hidden w-full bg-green-50 border-2 border-green-500 text-green-700 px-6 py-3 rounded-lg flex items-center justify-center gap-2 font-semibold">
                <i class="fas fa-check-circle text-xl"></i>
                <span>Invoice Already Added to Customer Account</span>
            </div>
        </div>
    </div>
</div>

<!-- PROOF MODAL -->
<div id="proofModal" class="hidden fixed inset-0 bg-black bg-opacity-75 z-[60] flex items-center justify-center overflow-hidden" style="display: none;">
    <div class="bg-white rounded-lg shadow-2xl w-full max-w-5xl mx-4 h-[85vh] flex flex-col">
        <div class="bg-gray-800 text-white px-4 py-3 rounded-t-lg flex justify-between items-center">
            <h2 class="text-lg font-bold">
                <i class="fas fa-file-alt mr-2"></i>Proof Document
            </h2>
            <button onclick="closeProofModal()" class="text-white hover:text-gray-300 text-2xl leading-none">
                &times;
            </button>
        </div>
        <div class="flex-1 bg-gray-200 p-1 relative flex items-center justify-center overflow-hidden">
            <iframe id="proofFrame" class="w-full h-full border-0 bg-white" src="about:blank"></iframe>
        </div>
    </div>
</div>

<!-- INVOICE CONFIG MODAL -->
<div id="invoiceConfigModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-[65] flex items-center justify-center overflow-hidden" style="display: none;">
    <div class="bg-white rounded-lg shadow-2xl w-full max-w-sm mx-4">
        <div class="bg-gradient-to-r from-blue-600 to-blue-700 text-white px-3 py-2 rounded-t-lg flex items-center justify-between">
            <div class="flex items-center gap-2">
                <i class="fas fa-file-invoice-dollar"></i>
                <h2 class="text-sm font-bold">Invoice Configuration</h2>
            </div>
            <button onclick="closeInvoiceConfigModal()" class="text-white hover:text-gray-200">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form id="invoiceConfigForm" class="p-3">
            <input type="hidden" id="invoice_config_container_id" name="container_id">
            <input type="hidden" id="invoice_config_customer_id" name="customer_id">
            <input type="hidden" id="invoice_config_weight_type" name="weight_type" value="gross">
            <input type="hidden" id="invoice_config_weight_value" name="weight_value">

            <div class="space-y-2">
                <!-- Container + Customer compact row -->
                <div class="grid grid-cols-2 gap-2">
                    <div class="bg-gray-50 px-2 py-1.5 rounded border border-gray-200">
                        <div class="text-xs text-gray-500 uppercase tracking-wide">Container</div>
                        <div class="text-sm font-bold text-gray-900 truncate" id="invoice_config_container_number">-</div>
                    </div>
                    <div class="bg-gray-50 px-2 py-1.5 rounded border border-gray-200">
                        <div class="text-xs text-gray-500 uppercase tracking-wide">Customer</div>
                        <div class="text-sm font-bold text-gray-900 truncate" id="invoice_config_customer_name">-</div>
                    </div>
                </div>

                <!-- Weight selector button (opens sub-popup) + weight input row -->
                <div class="grid grid-cols-2 gap-2 items-end">
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">
                            <i class="fas fa-balance-scale mr-1 text-blue-500"></i>Weight Type
                        </label>
                        <!-- Sub-popup trigger -->
                        <div class="relative">
                            <button type="button" id="weightTypePickerBtn" onclick="toggleWeightTypePicker()"
                                    class="w-full flex items-center justify-between px-2 py-1.5 border-2 border-blue-300 bg-blue-50 hover:bg-blue-100 rounded-lg text-sm font-semibold text-blue-800 transition-all">
                                <span id="weightTypePickerLabel"><i class="fas fa-weight-hanging mr-1"></i>Gross</span>
                                <i class="fas fa-chevron-down text-xs text-blue-500" id="weightTypePickerChevron"></i>
                            </button>
                            <!-- Sub-popup dropdown -->
                            <div id="weightTypePickerDropdown" class="hidden absolute left-0 top-full mt-1 w-52 bg-white border-2 border-blue-300 rounded-lg shadow-xl z-[80] overflow-hidden">
                                <div class="bg-blue-600 text-white text-xs font-bold px-3 py-1.5 uppercase tracking-wide">Select Weight Type</div>
                                <button type="button" id="weightPickerGross"
                                        onclick="selectWeightType('gross')"
                                        class="w-full flex items-center justify-between px-3 py-2 hover:bg-blue-50 transition-all border-b border-gray-100">
                                    <div class="flex items-center gap-2">
                                        <i class="fas fa-weight-hanging text-blue-500 w-4"></i>
                                        <span class="text-sm font-semibold text-gray-700">Gross Weight</span>
                                    </div>
                                    <span id="gross_weight_display" class="text-xs text-gray-500 bg-gray-100 px-1.5 py-0.5 rounded">0 kg</span>
                                </button>
                                <button type="button" id="weightPickerNet"
                                        onclick="selectWeightType('net')"
                                        class="w-full flex items-center justify-between px-3 py-2 hover:bg-blue-50 transition-all">
                                    <div class="flex items-center gap-2">
                                        <i class="fas fa-balance-scale text-green-500 w-4"></i>
                                        <span class="text-sm font-semibold text-gray-700">Net Weight</span>
                                    </div>
                                    <span id="net_weight_display" class="text-xs text-gray-500 bg-gray-100 px-1.5 py-0.5 rounded">0 kg</span>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">
                            <i class="fas fa-weight mr-1 text-blue-500"></i><span id="weight_label_text">Gross</span> (kg)
                        </label>
                        <input type="number" step="0.01" id="invoice_config_weight" name="weight"
                               class="w-full px-2 py-1.5 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm font-semibold"
                               required oninput="calculateInvoicePreview()">
                    </div>
                </div>

                <!-- Rate -->
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">
                        <i class="fas fa-tag mr-1 text-blue-500"></i>Rate per kg (Rs)
                    </label>
                    <input type="number" step="0.01" id="invoice_config_rate" name="rate"
                           class="w-full px-2 py-1.5 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm font-semibold"
                           required oninput="calculateInvoicePreview()">
                </div>

                <!-- Invoice Preview -->
                <div class="bg-green-50 border border-green-300 rounded-lg px-3 py-2 flex items-center justify-between">
                    <span class="text-xs font-bold text-green-700 uppercase"><i class="fas fa-receipt mr-1"></i>Amount</span>
                    <span class="text-lg font-black text-green-700" id="invoice_preview_amount">PKR 0.00</span>
                </div>
            </div>

            <div class="mt-3 space-y-1.5">
                <div class="flex gap-1.5">
                    <button type="button" onclick="handleGenerateInvoice()"
                            class="flex-1 px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-xs font-semibold transition-all">
                        <i class="fas fa-print mr-1"></i>Generate
                    </button>
                    <button type="button" onclick="handleUpdateAndSave()"
                            class="flex-1 px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white rounded-lg text-xs font-semibold transition-all">
                        <i class="fas fa-save mr-1"></i>Save
                    </button>
                </div>
                <button type="button" id="addToAccountBtnConfig" onclick="addInvoiceToAccountFromConfig()"
                        class="w-full bg-gradient-to-r from-green-600 to-green-700 hover:from-green-700 hover:to-green-800 text-white px-3 py-1.5 rounded-lg text-xs font-semibold flex items-center justify-center gap-1.5 transition-all">
                    <i class="fas fa-plus-circle"></i><span>Add to Customer Account</span>
                </button>
                <div id="addedToAccountMsgConfig" class="hidden w-full bg-green-50 border border-green-500 text-green-700 px-3 py-1.5 rounded-lg flex items-center justify-center gap-1.5 text-xs font-semibold">
                    <i class="fas fa-check-circle"></i><span>Already Added to Account</span>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- CUSTOM ALERT MODAL -->
<div id="customAlertModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-[70] flex items-center justify-center overflow-hidden" style="display: none;">
    <div class="bg-white rounded-lg shadow-2xl w-full max-w-md mx-4 animate-fade-in">
        <div id="customAlertHeader" class="bg-gradient-to-r from-blue-600 to-blue-700 text-white px-6 py-4 rounded-t-lg flex items-center gap-3">
            <i id="customAlertIcon" class="fas fa-info-circle text-2xl"></i>
            <h3 id="customAlertTitle" class="text-lg font-bold">Alert</h3>
        </div>
        <div class="p-6">
            <p id="customAlertMessage" class="text-gray-700 text-base leading-relaxed"></p>
        </div>
        <div class="px-6 pb-6 flex justify-end">
            <button onclick="closeCustomAlert()" class="bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 text-white px-6 py-2.5 rounded-lg shadow-md transition-all font-semibold">
                <i class="fas fa-check mr-2"></i>OK
            </button>
        </div>
    </div>
</div>

<!-- DELETE CONTAINER CONFIRMATION MODAL -->
<div id="deleteContainerModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-[65] flex items-center justify-center overflow-hidden" style="display: none;">
    <div class="bg-white rounded-lg shadow-2xl w-full max-w-md mx-4">
        <div class="bg-gradient-to-r from-red-600 to-red-700 text-white px-6 py-4 rounded-t-lg flex items-start gap-3">
            <i class="fas fa-exclamation-triangle text-2xl mt-1 flex-shrink-0"></i>
            <div>
                <h2 class="text-lg font-bold">Delete Container</h2>
                <p class="text-sm text-red-100 mt-1">This action cannot be undone</p>
            </div>
        </div>
        <div class="p-6">
            <div class="mb-4">
                <p class="text-gray-700 font-semibold mb-3">Container: <span id="deleteContainerName" class="text-gray-900 font-bold">-</span></p>
                <div class="bg-red-50 border-l-4 border-red-500 p-4 rounded mb-3">
                    <p class="text-sm text-gray-700 font-semibold mb-2">This will permanently delete:</p>
                    <ul class="text-sm text-gray-600 space-y-1 ml-2">
                        <li><i class="fas fa-check text-red-500 mr-2"></i>Container record</li>
                        <li><i class="fas fa-check text-red-500 mr-2"></i>All expenses</li>
                        <li><i class="fas fa-check text-red-500 mr-2"></i>All invoices</li>
                        <li><i class="fas fa-check text-red-500 mr-2"></i>All agent transactions</li>
                    </ul>
                </div>
                <div class="bg-blue-50 border-l-4 border-blue-500 p-4 rounded">
                    <p class="text-sm text-blue-800 font-semibold flex items-center gap-2">
                        <i class="fas fa-info-circle"></i>
                        Important Note
                    </p>
                    <p class="text-sm text-blue-700 mt-1">
                        If this container's invoice was added to the customer account, the invoice amount will be automatically deducted from the customer's total and a transaction record will be created.
                    </p>
                </div>
            </div>
            <div class="flex gap-3 justify-end">
                <button type="button" onclick="closeDeleteContainerModal()" class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors font-medium">
                    <i class="fas fa-times mr-1"></i>Cancel
                </button>
                <button type="button" onclick="confirmDeleteContainer()" class="px-4 py-2 bg-gradient-to-r from-red-600 to-red-700 hover:from-red-700 hover:to-red-800 text-white rounded-lg transition-all shadow-md font-medium">
                    <i class="fas fa-trash mr-1"></i>Delete Container
                </button>
            </div>
        </div>
    </div>
</div>

<!-- LIST CONTAINERS -->
<div class="bg-white rounded-lg shadow-lg overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead>
                <tr class="bg-gradient-to-r from-gray-50 to-gray-100 border-b-2 border-gray-200">
                    <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">
                        ID
                    </th>
                    <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">
                        Container Number
                    </th>
                    <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">
                        BL Number
                    </th>
                    <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">
                        Customer
                    </th>
                    <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">
                        Weight (Net/Gross)
                    </th>
                    <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">
                        Status
                    </th>
                    <th class="px-6 py-4 text-center text-xs font-bold text-gray-700 uppercase tracking-wider">
                        Actions
                    </th>
                </tr>
            </thead>
            <tbody id="containerTableBody" class="divide-y divide-gray-200">
            <?php
            $list = $conn->query("
                SELECT c.id, c.container_number, c.bl_number, c.status, c.net_weight, c.gross_weight, cu.name
                FROM containers c
                JOIN customers cu ON cu.id = c.customer_id
                ORDER BY c.id DESC
            ");
            while ($r = $list->fetch_assoc()):
                $statusColor = $r['status'] === 'completed' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800';
                $statusIcon = $r['status'] === 'completed' ? 'fa-check-circle' : 'fa-clock';
            ?>
            <tr class="hover:bg-blue-50 transition-colors">
                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                    #<?= $r['id'] ?>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900">
                    <?= htmlspecialchars($r['container_number']) ?>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                    <?= htmlspecialchars($r['bl_number'] ?? '-') ?>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                    <?= htmlspecialchars($r['name']) ?>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                    <span class="font-mono"><?= number_format($r['net_weight'], 2) ?> / <?= number_format($r['gross_weight'], 2) ?> kg</span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold <?= $statusColor ?>">
                        <?= ucfirst($r['status']) ?>
                    </span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium">
                    <div class="flex items-center justify-center gap-2">
                        <button type="button" onclick="viewContainer(<?= $r['id'] ?>)" class="w-8 h-8 inline-flex items-center justify-center rounded-full border border-blue-200 text-blue-600 hover:bg-blue-50 transition" title="View Details">
                            <i class="fas fa-eye"></i>
                        </button>
                        <?php if ($isAdmin): ?>
                        <button type="button" onclick="openContainerExpenseModal(<?= $r['id'] ?>)" class="w-8 h-8 inline-flex items-center justify-center rounded-full border border-purple-200 text-purple-600 hover:bg-purple-50 transition" title="Add Expense">
                            <i class="fas fa-receipt"></i>
                        </button>
                        <button type="button" onclick="viewInvoice(<?= $r['id'] ?>)" class="w-8 h-8 inline-flex items-center justify-center rounded-full border border-indigo-200 text-indigo-600 hover:bg-indigo-50 transition" title="Print Invoice">
                            <i class="fas fa-file-invoice"></i>
                        </button>
                        <button type="button" onclick="editContainer(<?= $r['id'] ?>)" class="w-8 h-8 inline-flex items-center justify-center rounded-full border border-green-200 text-green-600 hover:bg-green-50 transition" title="Edit Container">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button type="button" onclick="deleteContainer(<?= $r['id'] ?>, '<?= htmlspecialchars($r['container_number']) ?>')" class="w-8 h-8 inline-flex items-center justify-center rounded-full border border-red-200 text-red-600 hover:bg-red-50 transition" title="Delete Container">
                            <i class="fas fa-trash"></i>
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


<style>
@media print {
    body * {
        visibility: hidden;
    }
    #viewContainerModal, #viewContainerModal * {
        visibility: visible;
    }
    #viewContainerModal {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        margin: 0;
        padding: 0;
        min-height: 100vh;
        background: white;
    }
    #viewContainerModal .bg-white {
        box-shadow: none !important;
        max-width: 100% !important;
        width: 100% !important;
    }
    #viewContainerModal .overflow-y-auto {
        overflow: visible !important;
        max-height: none !important;
    }
    .print-hide {
        display: none !important;
    }
}

.animate-fade-in {
    animation: fadeIn 0.2s ease-in-out;
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
</style>
<script>

// Defensive: Abort if this script is loaded as HTML (e.g., due to PHP error or wrong path)
if (document.currentScript && document.currentScript.textContent.trim().startsWith('<')) {
    // This is not valid JS, abort execution
    console.error('Script loaded as HTML, aborting JS execution.');
    throw new Error('Script loaded as HTML, aborting.');
}

(function() {
    'use strict';

    // Custom Alert Modal Functions
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

    // Container-specific functions

    window.openContainerModal = function(isEdit, customerId = null) {
        var modal = document.getElementById('containerModal');
        if (modal) {
            modal.classList.remove('hidden');
            modal.style.display = 'flex';
            document.body.classList.add('overflow-hidden');
            
            if (!isEdit) {
                document.getElementById('modalTitle').innerHTML = '<i class="fas fa-box mr-2"></i>Add Container';
                document.getElementById('containerForm').reset();
                document.getElementById('containerForm').action = 'containePKRphp?action=add';
                document.getElementById('container_id').value = '';
                document.getElementById('existing_invoice').value = '';
                
                // Pre-select customer if provided
                if (customerId) {
                    document.getElementById('customer_id').value = customerId;
                    document.getElementById('customer_id').readOnly = true;
                }
            }
        }
    };

    window.closeContainerModal = function() {
        var modal = document.getElementById('containerModal');
        if (modal) {
            modal.classList.add('hidden');
            modal.style.display = 'none';
            document.body.classList.remove('overflow-hidden');
            
            // Re-enable customer_id field for next use
            document.getElementById('customer_id').readOnly = false;
        }
    };

    window.openContainerExpenseModal = function(containerId, expenseData) {
        var modal = document.getElementById('expenseModal');
        if (modal) {
            modal.classList.remove('hidden');
            modal.style.display = 'flex';
            document.body.classList.add('overflow-hidden');
            document.getElementById('expense_container_id').value = containerId;
            
            var form = document.getElementById('expenseForm');
            var title = modal.querySelector('h2');
            
            // Reset fields
            document.getElementById('proof_help_text').textContent = '';

            if (expenseData) {
                // Edit Mode
                title.innerHTML = '<i class="fas fa-edit mr-2"></i>Edit Expense';
                document.getElementById('expense_id').value = expenseData.id;
                document.getElementById('expense_type').value = expenseData.expense_type;
                document.getElementById('expense_amount').value = expenseData.amount;
                document.getElementById('expense_date').value = expenseData.expense_date;
                document.getElementById('expense_agent_id').value = expenseData.agent_id || '';
                
                if (expenseData.proof) {
                    document.getElementById('proof_help_text').textContent = 'Current file: ' + expenseData.proof;
                }
            } else {
                // Add Mode
                title.innerHTML = '<i class="fas fa-receipt mr-2"></i>Add Expense';
                form.reset();
                document.getElementById('expense_id').value = '';
                document.getElementById('expense_container_id').value = containerId;
            }
        }
    };

    window.closeContainerExpenseModal = function() {
        var modal = document.getElementById('expenseModal');
        if (modal) {
            modal.classList.add('hidden');
            modal.style.display = 'none';
            document.body.classList.remove('overflow-hidden');
        }
    };

    window.openViewModal = function() {
        var modal = document.getElementById('viewContainerModal');
        if (modal) {
            modal.classList.remove('hidden');
            modal.style.display = 'flex';
            document.body.classList.add('overflow-hidden');
        }
    };

    window.closeViewModal = function() {
        var modal = document.getElementById('viewContainerModal');
        if (modal) {
            modal.classList.add('hidden');
            modal.style.display = 'none';
            document.body.classList.remove('overflow-hidden');
        }
    };

    window.openInvoiceModal = function() {
        var modal = document.getElementById('invoiceModal');
        if (modal) {
            modal.classList.remove('hidden');
            modal.style.display = 'flex';
            document.body.classList.add('overflow-hidden');
            
            // Update button state based on invoice status
            updateInvoiceAccountButton();
        }
    };

    window.closeInvoiceModal = function() {
        var modal = document.getElementById('invoiceModal');
        if (modal) {
            modal.classList.add('hidden');
            modal.style.display = 'none';
            document.body.classList.remove('overflow-hidden');
        }
    };

    window.updateInvoiceAccountButton = function() {
        var addBtn = document.getElementById('addToAccountBtn');
        var addedMsg = document.getElementById('addedToAccountMsg');
        
        if (window.invoiceAddedToAccount) {
            addBtn.classList.add('hidden');
            addedMsg.classList.remove('hidden');
        } else {
            addBtn.classList.remove('hidden');
            addedMsg.classList.add('hidden');
        }
    };

    window.addInvoiceToCustomerAccount = function() {
        // Stricter check: must be a positive integer
        if (!window.currentInvoiceContainerId || isNaN(window.currentInvoiceContainerId) || parseInt(window.currentInvoiceContainerId) <= 0) {
            showCustomAlert('Error: Container ID is missing or invalid. Please refresh the page and try again.', 'error');
            return;
        }
        
        if (window.invoiceAddedToAccount) {
            showCustomAlert('This invoice has already been added to the customer account', 'warning');
            return;
        }
        
        if (!confirm('Are you sure you want to add this invoice to the customer account? This action can only be done once.')) {
            return;
        }
        
        var addBtn = document.getElementById('addToAccountBtn');
        addBtn.disabled = true;
        addBtn.innerHTML = '<i class=\"fas fa-spinner fa-spin mr-2\"></i>Adding...';
        
        console.log('Adding invoice to customer account, container ID:', window.currentInvoiceContainerId);
        
        fetch('containePKRphp?action=add_invoice_to_account', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: 'container_id=' + window.currentInvoiceContainerId
        })
        .then(response => {
            console.log('Response status:', response.status);
            return response.json();
        })
        .then(data => {
            console.log('Server response:', data);
            if (data.success) {
                showCustomAlert('Invoice successfully added to customer account!', 'success');
                window.invoiceAddedToAccount = true;
                updateInvoiceAccountButton();
                
                // Refresh container list if function exists
                setTimeout(function() {
                    location.reload();
                }, 1500);
            } else {
                console.error('Server error:', data.message);
                showCustomAlert(data.message || 'Failed to add invoice to account', 'error');
                addBtn.disabled = false;
                addBtn.innerHTML = '<i class=\"fas fa-plus-circle\"></i><span>Add Invoice to Customer Account</span>';
            }
        })
        .catch(error => {
            console.error('Network error:', error);
            showCustomAlert('Network error: Unable to communicate with server. Check console for details.', 'error');
            addBtn.disabled = false;
            addBtn.innerHTML = '<i class=\"fas fa-plus-circle\"></i><span>Add Invoice to Customer Account</span>';
        });
    };

    window.printInvoice = function() {
        var content = document.getElementById('printableInvoiceContent');
        if (!content) return;
        
        // Create invisible iframe
        var iframe = document.createElement('iframe');
        iframe.style.position = 'absolute';
        iframe.style.width = '0px';
        iframe.style.height = '0px';
        iframe.style.border = 'none';
        document.body.appendChild(iframe);
        
        var doc = iframe.contentWindow.document;
        
        // Write content
        // Copy Tailwind CSS roughly or include a basic print stylesheet
        // Ideally we assume Tailwind CDN is present or styles are inline.
        // My previous PHP generated code uses utility classes. To make them work in iframe, 
        // we need to include the tailwind CDN in the iframe.
        
        doc.open();
        doc.write('<html><head><title>Print Invoice</title>');
        doc.write('<script src="https://cdn.tailwindcss.com"><\/script>'); 
        doc.write('<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">');
        doc.write('</head><body class="bg-white p-4">');
        doc.write(content.innerHTML);
        doc.write('</body></html>');
        doc.close();

        // Wait for styles/images to load then print
        iframe.contentWindow.focus();
        setTimeout(function() {
            iframe.contentWindow.print();
            document.body.removeChild(iframe);
        }, 500);
    };

    window.viewInvoice = function(id) {
        // First, show the invoice configuration modal
        fetch('containePKRphp?action=get_invoice_config&id=' + id, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(function(response) { return response.json(); })
        .then(function(response) {
            if (!response.success) {
                alert('Error: ' + (response.message || 'Unable to load invoice configuration'));
                return;
            }
            
            var data = response.data;
            
            // Fill the form
            document.getElementById('invoice_config_container_id').value = data.container_id;
            document.getElementById('invoice_config_customer_id').value = data.customer_id;
            document.getElementById('invoice_config_container_number').textContent = data.container_number;
            document.getElementById('invoice_config_customer_name').textContent = data.customer_name;
            
            // Display both weight values in the selection area
            document.getElementById('gross_weight_display').textContent = data.gross_weight + ' kg';
            document.getElementById('net_weight_display').textContent = data.net_weight + ' kg';
            
            // Set weight selection based on last used weight type (from server) or default to gross
            var weightType = data.last_used_weight_type || 'gross';
            var selectedWeight = weightType === 'net' ? data.net_weight : data.gross_weight;

            document.getElementById('invoice_config_weight').value = selectedWeight;
            document.getElementById('invoice_config_weight_type').value = weightType;
            document.getElementById('invoice_config_rate').value = data.rate;

            // Store weight values globally for switching
            window.containerWeights = {
                gross: data.gross_weight,
                net: data.net_weight
            };

            // Update picker button label & highlight
            var label = weightType === 'gross' ? 'Gross' : 'Net';
            var icon = weightType === 'gross' ? 'fa-weight-hanging' : 'fa-balance-scale';
            var pickerLabel = document.getElementById('weightTypePickerLabel');
            if (pickerLabel) pickerLabel.innerHTML = '<i class="fas ' + icon + ' mr-1"></i>' + label;
            document.getElementById('weight_label_text').textContent = label;
            var grossBtn = document.getElementById('weightPickerGross');
            var netBtn = document.getElementById('weightPickerNet');
            if (grossBtn) grossBtn.classList.toggle('bg-blue-50', weightType === 'gross');
            if (netBtn) netBtn.classList.toggle('bg-blue-50', weightType === 'net');
            
            // Calculate initial preview
            calculateInvoicePreview();
            
            // Open the config modal
            openInvoiceConfigModal();
        })
        .catch(function(error) {
            console.error('Error:', error);
            alert('Unable to load invoice configuration.');
        });
    };

    // Track if invoice config has unsaved changes
    window.invoiceConfigHasChanges = false;
    window.invoiceConfigOriginalValues = {};

    window.openInvoiceConfigModal = function() {
        var modal = document.getElementById('invoiceConfigModal');
        if (modal) {
            modal.classList.remove('hidden');
            modal.style.display = 'flex';
            document.body.classList.add('overflow-hidden');
            
            // Store original values
            window.invoiceConfigOriginalValues = {
                weight: document.getElementById('invoice_config_weight').value,
                rate: document.getElementById('invoice_config_rate').value
            };
            window.invoiceConfigHasChanges = false;
            
            // Update button state
            updateInvoiceAccountButtonConfig();
        }
    };

    window.closeInvoiceConfigModal = function(force) {
        // Check for unsaved changes
        if (!force && window.invoiceConfigHasChanges) {
            showCustomAlert('You have unsaved changes! Please click "Update & Save" to save your changes before closing.', 'warning');
            return false;
        }
        
        var modal = document.getElementById('invoiceConfigModal');
        if (modal) {
            modal.classList.add('hidden');
            modal.style.display = 'none';
            document.body.classList.remove('overflow-hidden');
            window.invoiceConfigHasChanges = false;
        }
        return true;
    };

    window.toggleWeightTypePicker = function() {
        var dd = document.getElementById('weightTypePickerDropdown');
        var chevron = document.getElementById('weightTypePickerChevron');
        if (!dd) return;
        var isHidden = dd.classList.contains('hidden');
        dd.classList.toggle('hidden', !isHidden);
        if (chevron) chevron.style.transform = isHidden ? 'rotate(180deg)' : '';
    };

    window.selectWeightType = function(type) {
        // Close the dropdown
        var dd = document.getElementById('weightTypePickerDropdown');
        var chevron = document.getElementById('weightTypePickerChevron');
        if (dd) dd.classList.add('hidden');
        if (chevron) chevron.style.transform = '';
        // Delegate to switchWeightType
        switchWeightType(type);
    };

    window.switchWeightType = function(type) {
        // Update hidden field
        document.getElementById('invoice_config_weight_type').value = type;

        // Update weight field with selected weight
        if (window.containerWeights) {
            document.getElementById('invoice_config_weight').value = window.containerWeights[type];
        }

        // Update picker button label
        var label = type === 'gross' ? 'Gross' : 'Net';
        var icon = type === 'gross' ? 'fa-weight-hanging' : 'fa-balance-scale';
        var pickerLabel = document.getElementById('weightTypePickerLabel');
        if (pickerLabel) pickerLabel.innerHTML = '<i class="fas ' + icon + ' mr-1"></i>' + label;

        // Update weight input label
        document.getElementById('weight_label_text').textContent = label;

        // Highlight selected option in dropdown
        var grossBtn = document.getElementById('weightPickerGross');
        var netBtn = document.getElementById('weightPickerNet');
        if (grossBtn) grossBtn.classList.toggle('bg-blue-50', type === 'gross');
        if (netBtn) netBtn.classList.toggle('bg-blue-50', type === 'net');

        // Recalculate invoice preview
        calculateInvoicePreview();

        // Mark as changed
        window.invoiceConfigHasChanges = true;
    };

    window.calculateInvoicePreview = function() {
        var weight = parseFloat(document.getElementById('invoice_config_weight').value) || 0;
        var rate = parseFloat(document.getElementById('invoice_config_rate').value) || 0;
        var invoiceAmount = weight * rate;
        
        // Update hidden field for weight value
        document.getElementById('invoice_config_weight_value').value = weight;
        
        // Check if values changed
        if (window.invoiceConfigOriginalValues) {
            var currentWeight = document.getElementById('invoice_config_weight').value;
            var currentRate = document.getElementById('invoice_config_rate').value;
            
            if (currentWeight !== window.invoiceConfigOriginalValues.weight || 
                currentRate !== window.invoiceConfigOriginalValues.rate) {
                window.invoiceConfigHasChanges = true;
            } else {
                window.invoiceConfigHasChanges = false;
            }
        }
        
        document.getElementById('invoice_preview_amount').textContent = 'PKR ' + invoiceAmount.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    };

    window.updateInvoiceAccountButtonConfig = function() {
        var addBtn = document.getElementById('addToAccountBtnConfig');
        var addedMsg = document.getElementById('addedToAccountMsgConfig');
        var containerId = document.getElementById('invoice_config_container_id').value;
        
        if (!containerId) return;
        
        // Check if invoice exists and is added to account
        fetch('containePKRphp?action=check_invoice_status&id=' + containerId, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.added_to_account) {
                addBtn.classList.add('hidden');
                addBtn.disabled = true;
                addedMsg.classList.remove('hidden');
                // Store the status globally
                window.currentInvoiceAlreadyAdded = true;
            } else {
                addBtn.classList.remove('hidden');
                addBtn.disabled = false;
                addedMsg.classList.add('hidden');
                window.currentInvoiceAlreadyAdded = false;
            }
        })
        .catch(error => {
            console.error('Error checking invoice status:', error);
        });
    };

    window.addInvoiceToAccountFromConfig = function() {
        // Check if already added first
        if (window.currentInvoiceAlreadyAdded === true) {
            showCustomAlert('This invoice has already been added to the customer account.', 'warning');
            return;
        }
        
        var containerId = document.getElementById('invoice_config_container_id').value;
        var addBtn = document.getElementById('addToAccountBtnConfig');
        
        if (!containerId) {
            showCustomAlert('Error: Container ID not found', 'error');
            return;
        }
        
        // Check if button is already disabled
        if (addBtn.disabled) {
            return;
        }
        
        if (!confirm('Are you sure you want to add this invoice to the customer account? This action can only be done once.')) {
            return;
        }
        
        // Disable button immediately
        addBtn.disabled = true;
        addBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>Adding...</span>';
        
        fetch('containePKRphp?action=add_invoice_to_account', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: 'container_id=' + containerId
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showCustomAlert('Invoice successfully added to customer account!', 'success');
                
                // Mark as added
                window.currentInvoiceAlreadyAdded = true;
                
                // Wait a moment before reloading to show the success message
                setTimeout(function() {
                    location.reload();
                }, 1500);
            } else {
                showCustomAlert('Error: ' + (data.message || 'Failed to add invoice to account'), 'error');
                addBtn.disabled = false;
                addBtn.innerHTML = '<i class="fas fa-plus-circle"></i><span>Add Invoice to Customer Account</span>';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showCustomAlert('An error occurred while adding invoice to account', 'error');
            addBtn.disabled = false;
            addBtn.innerHTML = '<i class="fas fa-plus-circle"></i><span>Add Invoice to Customer Account</span>';
        });
    };

    window.printExpenseReport = function(id) {
        if (!id) {
            alert('No container selected');
            return;
        }
        
        fetch('containePKRphp?action=expense_report&id=' + id, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(function(response) { return response.text(); })
        .then(function(html) {
            // Create invisible iframe for printing
            var iframe = document.createElement('iframe');
            iframe.style.position = 'absolute';
            iframe.style.width = '0px';
            iframe.style.height = '0px';
            iframe.style.border = 'none';
            document.body.appendChild(iframe);
            
            var doc = iframe.contentWindow.document;
            
            doc.open();
            doc.write('<html><head><title>Expense Report</title>');
            doc.write('<script src="https://cdn.tailwindcss.com"><\/script>'); 
            doc.write('<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">');
            doc.write('</head><body class="bg-white p-4">');
            doc.write(html);
            doc.write('</body></html>');
            doc.close();

            // Wait for styles/images to load then print
            iframe.contentWindow.focus();
            setTimeout(function() {
                iframe.contentWindow.print();
                setTimeout(function() {
                    document.body.removeChild(iframe);
                }, 100);
            }, 500);
        })
        .catch(function(error) {
            console.error('Error:', error);
            alert('Unable to load expense report.');
        });
    };

    window.viewProof = function(url) {
        var modal = document.getElementById('proofModal');
        var frame = document.getElementById('proofFrame');
        if (modal && frame) {
            frame.src = url;
            modal.classList.remove('hidden');
            modal.style.display = 'flex';
            document.body.classList.add('overflow-hidden');
        }
    };

    window.closeProofModal = function() {
        var modal = document.getElementById('proofModal');
        var frame = document.getElementById('proofFrame');
        if (modal) {
            modal.classList.add('hidden');
            modal.style.display = 'none';
            // Only remove overflow hidden if no other modal is open (checking via z-index or assumption)
            // Ideally we check if viewContainerModal is open.
            // Since proofModal is z-60 and viewContainerModal is z-50, we might still want body overflow-hidden
            // if viewContainerModal is still visible.
            
            var viewModal = document.getElementById('viewContainerModal');
            if (!viewModal || viewModal.classList.contains('hidden')) {
                 document.body.classList.remove('overflow-hidden');
            }
            
            if (frame) frame.src = 'about:blank';
        }
    };

    // Handle Generate Invoice button
    window.handleGenerateInvoice = function() {
        var form = document.getElementById('invoiceConfigForm');
        var formData = new FormData(form);
        var containerId = formData.get('container_id');
        var weightType = formData.get('weight_type');
        
        // Save the configuration first
        fetch('containePKRphp?action=save_invoice_config', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(function(response) { return response.json(); })
        .then(function(response) {
            if (!response.success) {
                showCustomAlert('Error: ' + (response.message || 'Unable to save configuration'), 'error');
                return;
            }
            
            // Mark as saved
            window.invoiceConfigHasChanges = false;
            
            // Close the config modal
            closeInvoiceConfigModal(true);
            
            // Fetch and directly print the invoice with weight_type parameter
            fetch('containePKRphp?action=invoice_view&id=' + containerId + '&weight_type=' + weightType, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(function(response) { return response.text(); })
            .then(function(html) {
                // Create temporary container for invoice content
                var tempDiv = document.createElement('div');
                tempDiv.innerHTML = html;
                var invoiceContent = tempDiv.querySelector('#printableInvoiceContent');
                
                if (!invoiceContent) {
                    showCustomAlert('Error: Unable to generate invoice', 'error');
                    return;
                }
                
                // Create invisible iframe for printing
                var iframe = document.createElement('iframe');
                iframe.style.position = 'absolute';
                iframe.style.width = '0px';
                iframe.style.height = '0px';
                iframe.style.border = 'none';
                document.body.appendChild(iframe);
                
                var doc = iframe.contentWindow.document;
                
                doc.open();
                doc.write('<html><head><title>Print Invoice</title>');
                doc.write('<script src="https://cdn.tailwindcss.com"><\/script>'); 
                doc.write('<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">');
                doc.write('</head><body class="bg-white p-4">');
                doc.write(invoiceContent.outerHTML);
                doc.write('</body></html>');
                doc.close();

                // Wait for styles/images to load then print
                iframe.contentWindow.focus();
                setTimeout(function() {
                    iframe.contentWindow.print();
                    document.body.removeChild(iframe);
                }, 500);
            })
            .catch(function(error) {
                console.error('Error:', error);
                showCustomAlert('Configuration saved, but unable to generate invoice.', 'error');
            });
        })
        .catch(function(error) {
            console.error('Error:', error);
            showCustomAlert('Error saving configuration', 'error');
        });
    };

    // Handle Update & Save button
    window.handleUpdateAndSave = function() {
        var form = document.getElementById('invoiceConfigForm');
        var formData = new FormData(form);
        
        // Save the configuration
        fetch('containePKRphp?action=save_invoice_config', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(function(response) { return response.json(); })
        .then(function(response) {
            if (!response.success) {
                showCustomAlert('Error: ' + (response.message || 'Unable to save configuration'), 'error');
                return;
            }
            
            // Mark as saved and update original values
            window.invoiceConfigHasChanges = false;
            window.invoiceConfigOriginalValues = {
                weight: document.getElementById('invoice_config_weight').value,
                rate: document.getElementById('invoice_config_rate').value
            };
            
            // Show success message
            showCustomAlert('Configuration updated and saved successfully!', 'success');
            
            // Recalculate preview with new amounts
            calculateInvoicePreview();
            
            // Refresh button state
            updateInvoiceAccountButtonConfig();
        })
        .catch(function(error) {
            console.error('Error:', error);
            showCustomAlert('Error saving configuration', 'error');
        });
    };

    // Click outside to close weight type picker dropdown
    document.addEventListener('click', function(e) {
        var dd = document.getElementById('weightTypePickerDropdown');
        var btn = document.getElementById('weightTypePickerBtn');
        if (dd && !dd.classList.contains('hidden') && btn && !btn.contains(e.target) && !dd.contains(e.target)) {
            dd.classList.add('hidden');
            var chevron = document.getElementById('weightTypePickerChevron');
            if (chevron) chevron.style.transform = '';
        }
    });

    // Click outside to close modals
    window.addEventListener('click', function(e) {
        var modals = ['containerModal', 'expenseModal', 'viewContainerModal', 'invoiceModal', 'invoiceConfigModal', 'proofModal', 'deleteContainerModal', 'customAlertModal'];
        modals.forEach(function(id) {
            var modal = document.getElementById(id);
            if (modal && e.target === modal) {
                if(id === 'containerModal' && typeof window.closeContainerModal === 'function') window.closeContainerModal();
                if(id === 'expenseModal' && typeof window.closeContainerExpenseModal === 'function') window.closeContainerExpenseModal();
                if(id === 'viewContainerModal' && typeof window.closeViewModal === 'function') window.closeViewModal();
                if(id === 'invoiceModal' && typeof window.closeInvoiceModal === 'function') window.closeInvoiceModal();
                if(id === 'invoiceConfigModal' && typeof window.closeInvoiceConfigModal === 'function') window.closeInvoiceConfigModal();
                if(id === 'proofModal' && typeof window.closeProofModal === 'function') window.closeProofModal();
                if(id === 'deleteContainerModal' && typeof window.closeDeleteContainerModal === 'function') window.closeDeleteContainerModal();
                if(id === 'customAlertModal' && typeof window.closeCustomAlert === 'function') window.closeCustomAlert();
            }
        });
    });

    // Store current container ID for print functions
    window.currentContainerId = null;

    window.viewContainer = function(id) {
        window.currentContainerId = id; // Store for print function
        
        fetch('containePKRphp?action=details&id=' + id, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(function(response) { return response.json(); })
        .then(function(response) {
            if (!response.success || !response.data) {
                throw new Error(response.message || 'Failed to load container details');
            }

            var data = response.data;
            document.getElementById('view_container_number').textContent = data.container_number || '-';
            document.getElementById('view_bl_number').textContent = 'BL: ' + (data.bl_number || 'N/A');
            document.getElementById('view_customer_name').textContent = data.customer_name || '-';
            document.getElementById('view_customer_phone').textContent = data.customer_phone ? ('Phone: ' + data.customer_phone) : '';
            document.getElementById('view_net_weight').textContent = parseFloat(data.net_weight || 0).toFixed(2);
            document.getElementById('view_gross_weight').textContent = parseFloat(data.gross_weight || 0).toFixed(2);
            document.getElementById('view_created_at').textContent = data.created_at || '-';

            var statusBadge = document.getElementById('view_status_badge');
            if (statusBadge) {
                statusBadge.textContent = data.status ? data.status.charAt(0).toUpperCase() + data.status.slice(1) : 'Pending';
                statusBadge.className = 'px-3 py-1 text-xs font-semibold rounded-full ' + (data.status === 'completed' ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700');
            }

            // Display total expenses
            var totalExpenses = response.total_expenses || 0;
            document.getElementById('view_total_expenses').textContent = parseFloat(totalExpenses).toFixed(2);

            var expensesBody = document.getElementById('view_expenses_table');
            expensesBody.innerHTML = '';
            if (response.expenses && response.expenses.length) {
                document.getElementById('view_expense_summary').textContent = response.expenses.length + ' record(s)';
                response.expenses.forEach(function(exp) {
                    var row = document.createElement('tr');
                    
                    // Type
                    var tdType = document.createElement('td');
                    tdType.className = 'px-4 py-2';
                    tdType.textContent = exp.expense_type || '-';
                    row.appendChild(tdType);

                    // Amount
                    var tdAmount = document.createElement('td');
                    tdAmount.className = 'px-4 py-2 font-semibold text-gray-800';
                    tdAmount.textContent = parseFloat(exp.amount || 0).toFixed(2);
                    row.appendChild(tdAmount);

                    // Date
                    var tdDate = document.createElement('td');
                    tdDate.className = 'px-4 py-2 text-gray-600';
                    tdDate.textContent = exp.expense_date || '-';
                    row.appendChild(tdDate);

                    // Agent
                    var tdAgent = document.createElement('td');
                    tdAgent.className = 'px-4 py-2 text-gray-600';
                    tdAgent.textContent = exp.agent_name || '-';
                    row.appendChild(tdAgent);

                    // Proof
                    var tdProof = document.createElement('td');
                    tdProof.className = 'px-4 py-2';
                    if (exp.proof) {
                        var proofBtn = document.createElement('button');
                        proofBtn.type = 'button';
                        proofBtn.className = 'inline-flex items-center gap-1 text-blue-600 hover:text-blue-800 hover:underline';
                        proofBtn.innerHTML = '<i class="fas fa-eye text-sm"></i> View';
                        proofBtn.onclick = function() {
                            viewProof('uploads/container_expenses/' + exp.proof);
                        };
                        tdProof.appendChild(proofBtn);
                    } else {
                        var noProof = document.createElement('span');
                        noProof.className = 'text-gray-400 text-xs italic';
                        noProof.textContent = 'No proof';
                        tdProof.appendChild(noProof);
                    }
                    row.appendChild(tdProof);

                    // Actions
                    var tdActions = document.createElement('td');
                    tdActions.className = 'px-4 py-2 text-right whitespace-nowrap';
                    
                    if (response.is_admin) {
                        var editBtn = document.createElement('button');
                        editBtn.type = 'button';
                        editBtn.className = 'w-8 h-8 inline-flex items-center justify-center rounded-full border border-blue-200 text-blue-600 hover:bg-blue-50 transition mx-1';
                        editBtn.title = 'Edit Expense';
                        editBtn.innerHTML = '<i class="fas fa-edit"></i>';
                        editBtn.onclick = function() {
                            editExpense(exp, data.id);
                        };
                        
                        var deleteBtn = document.createElement('button');
                        deleteBtn.type = 'button';
                        deleteBtn.className = 'w-8 h-8 inline-flex items-center justify-center rounded-full border border-red-200 text-red-600 hover:bg-red-50 transition mx-1';
                        deleteBtn.title = 'Delete Expense';
                        deleteBtn.innerHTML = '<i class="fas fa-trash"></i>';
                        deleteBtn.onclick = function() {
                            deleteExpense(exp.id, data.id);
                        };
                        
                        tdActions.appendChild(editBtn);
                        tdActions.appendChild(deleteBtn);
                    }
                    
                    row.appendChild(tdActions);

                    expensesBody.appendChild(row);
                });
            } else {
                document.getElementById('view_expense_summary').textContent = 'No expenses';
                var emptyRow = document.createElement('tr');
                emptyRow.innerHTML = '<td colspan="6" class="px-4 py-3 text-center text-gray-500">No expenses recorded</td>';
                expensesBody.appendChild(emptyRow);
            }

            window.openViewModal();
        })
        .catch(function(error) {
            console.error('Error:', error);
            alert('Unable to load container details.');
        });
    };

    window.editContainer = function(id) {
        // Fetch container data
        fetch('containePKRphp?action=get&id=' + id, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(function(response) { return response.json(); })
        .then(function(response) {
            if (response.success && response.data) {
                var data = response.data;
                document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit mr-2"></i>Edit Container';
                document.getElementById('container_id').value = data.id;
                document.getElementById('customer_id').value = data.customer_id;
                document.getElementById('customer_id').readOnly = false; // Enable for editing
                document.getElementById('bl_number').value = data.bl_number || '';
                document.getElementById('container_number').value = data.container_number || '';
                document.getElementById('hs_code').value = data.HS_code || '';
                document.getElementById('net_weight').value = data.net_weight || '';
                document.getElementById('gross_weight').value = data.gross_weight || '';
                document.getElementById('status').value = data.status || 'pending';
                document.getElementById('existing_invoice').value = data.invoice_file || '';
                document.getElementById('containerForm').action = 'containePKRphp?action=edit';
                openContainerModal(true);
            }
        })
        .catch(function(error) {
            console.error('Error:', error);
            alert('Error loading container data');
        });
    };

    window.editExpense = function(expData, containerId) {
        // Because JSON.stringify might escape characters weirdly in HTML attribute,
        // it is safer to pass ID and look it up, but since we have the obj locally, this works for simple data.
        openContainerExpenseModal(containerId, expData);
    };

    window.deleteExpense = function(expenseId, containerId) {
        if (!confirm('Are you sure you want to delete this expense?')) return;
        
        var formData = new FormData();
        formData.append('id', expenseId);
        
        fetch('containePKRphp?action=delete_expense', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                // Refresh view
                viewContainer(containerId);
            } else {
                alert('Failed to delete expense');
            }
        })
        .catch(function(error) {
            console.error('Error:', error);
            alert('Error deleting expense');
        });
    };

    window.deleteContainer = function(id, name) {
        // Store the id and name for use in confirmDeleteContainer
        window.deleteContainerData = { id: id, name: name };
        
        // Fill in the modal
        document.getElementById('deleteContainerName').textContent = name;
        
        // Open the modal
        var modal = document.getElementById('deleteContainerModal');
        if (modal) {
            modal.classList.remove('hidden');
            modal.style.display = 'flex';
            document.body.classList.add('overflow-hidden');
        }
    };

    window.closeDeleteContainerModal = function() {
        var modal = document.getElementById('deleteContainerModal');
        if (modal) {
            modal.classList.add('hidden');
            modal.style.display = 'none';
            document.body.classList.remove('overflow-hidden');
        }
        // Clear the stored data
        window.deleteContainerData = null;
    };

    window.confirmDeleteContainer = function() {
        if (!window.deleteContainerData) return;
        
        var id = window.deleteContainerData.id;
        var name = window.deleteContainerData.name;
        
        // Close the modal first
        closeDeleteContainerModal();
        
        var formData = new FormData();
        formData.append('id', id);
        
        fetch('containePKRphp?action=delete', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                showCustomAlert(data.message || 'Container deleted successfully. Invoice amount deducted from customer account.', 'success');
                
                // Wait a moment for user to see the message, then reload
                setTimeout(function() {
                    // Check if we are deleting the currently viewed container
                    var urlParams = new URLSearchParams(window.location.search);
                    if (urlParams.get('action') === 'view' && urlParams.get('id') == id) {
                        window.location.href = 'containePKRphp';
                    } else {
                        location.reload();
                    }
                }, 1500);
            } else {
                showCustomAlert(data.message || 'Failed to delete container', 'error');
            }
        })
        .catch(function(error) {
            console.error('Error:', error);
            showCustomAlert('Error deleting container: ' + error.message, 'error');
        });
    };

    // Auto-open container modal if action=view&id=X is in URL or action=add&customer_id=X
    (function() {
        var urlParams = new URLSearchParams(window.location.search);
        
        // Handle add container for specific customer
        if (urlParams.get('action') === 'add' && urlParams.get('customer_id')) {
            var customerId = parseInt(urlParams.get('customer_id'));
            if (customerId) {
                // Auto-open the add modal with customer pre-selected
                openContainerModal(false, customerId);
                
                // Clean URL without reloading
                if (window.history && window.history.replaceState) {
                    var cleanUrl = window.location.pathname + window.location.hash;
                    window.history.replaceState({}, document.title, cleanUrl);
                }
            }
        }
        // Handle view container
        else if (urlParams.get('action') === 'view' && urlParams.get('id')) {
            var containerId = parseInt(urlParams.get('id'));
            if (containerId) {
                // Auto-open the view modal
                viewContainer(containerId);
                
                // Clean URL without reloading
                if (window.history && window.history.replaceState) {
                    var cleanUrl = window.location.pathname + window.location.hash;
                    window.history.replaceState({}, document.title, cleanUrl);
                }
            }
        }
    })();
})();

// Move the closing </div> after the script block so the JS is inside the page-content div
</script>

<?php
if ($isAjax) {
    $html = ob_get_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'html' => $html]);
} else {
    include 'include/footer.php';
}
?>
</div>

