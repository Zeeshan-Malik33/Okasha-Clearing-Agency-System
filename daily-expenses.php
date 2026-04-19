<?php
require 'config/auth.php';
require 'config/database.php';
require 'config/constants.php';

$isAdmin = ($_SESSION['role'] === 'admin');
$action  = $_GET['action'] ?? $_POST['action'] ?? 'list';
$filterDate = $_GET['date'] ?? '';
$filterMonth = $_GET['month'] ?? '';
$isAjax  = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
$openAddModal = false;
if (!isset($_SESSION['expense_account_notice'])) {
    $_SESSION['expense_account_notice'] = '';
}

function respondError($message, $isAjax)
{
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $message]);
        exit;
    }
    die($message);
}

/* ============================
   SERVE PROOF FILE
============================ */
if (isset($_GET['download_proof'])) {
    $file = $_GET['download_proof'];
    
    if (empty($file)) {
        http_response_code(400);
        die('No file specified');
    }
    
    // Security: Validate file path
    $file = basename($file); // Get only filename, prevent directory traversal
    $filePath = __DIR__ . '/uploads/daily_expenses/' . $file;
    
    // Check if file exists
    if (!file_exists($filePath)) {
        http_response_code(404);
        die('File not found');
    }
    
    // Get file extension and mime type
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $mimeTypes = [
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif'
    ];
    
    $mimeType = $mimeTypes[$ext] ?? 'application/octet-stream';
    
    // Clear any output buffers
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    // Set headers
    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: inline; filename="' . basename($filePath) . '"');
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: public, must-revalidate, max-age=0');
    header('Pragma: public');
    
    // Output file
    readfile($filePath);
    exit;
}

/* ============================
   GET SINGLE EXPENSE FOR EDITING
============================ */
if ($isAdmin && isset($_GET['get_expense']) && $isAjax) {
    $id = intval($_GET['get_expense']);
    $stmt = $conn->prepare("SELECT * FROM daily_expenses WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $expense = $result->fetch_assoc();
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'expense' => $expense]);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Expense not found']);
    }
    $stmt->close();
    exit;
}

if ($isAdmin && $action === 'add' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $openAddModal = true;
    $action = 'list';
}

/* ============================
   HANDLE EDIT DAILY EXPENSE
============================ */
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'edit') {
    $id = intval($_POST['expense_id']);
    $name = trim($_POST['name']);
    $amount = floatval($_POST['amount']);
    $description = trim($_POST['description']);
    $location = trim($_POST['location']);
    $reason = trim($_POST['reason']);
    $date = !empty($_POST['expense_date']) ? $_POST['expense_date'] : date('Y-m-d');

    // Get existing expense
    $stmt = $conn->prepare("SELECT proof FROM daily_expenses WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$existing) {
        respondError('Expense not found.', $isAjax);
    }

    $proofFile = $existing['proof'];
    
    // Handle new proof upload
    if (!empty($_FILES['proof']['name'])) {
        $allowed = ['pdf', 'jpg', 'jpeg', 'png'];
        $ext = strtolower(pathinfo($_FILES['proof']['name'], PATHINFO_EXTENSION));

        if (in_array($ext, $allowed, true)) {
            $uploadDir = 'uploads/daily_expenses/';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true)) {
                respondError('Failed to create upload directory.', $isAjax);
            }

            if (!is_uploaded_file($_FILES['proof']['tmp_name'])) {
                respondError('Invalid proof upload.', $isAjax);
            }

            // Delete old proof if exists
            if (!empty($proofFile) && file_exists($uploadDir . $proofFile)) {
                unlink($uploadDir . $proofFile);
            }

            $proofFile = time() . '_daily.' . $ext;
            if (!move_uploaded_file($_FILES['proof']['tmp_name'], $uploadDir . $proofFile)) {
                respondError('Failed to upload proof file.', $isAjax);
            }
        }
    }

    $stmt = $conn->prepare("
        UPDATE daily_expenses 
        SET name = ?, amount = ?, description = ?, location = ?, reason = ?, proof = ?, expense_date = ?, updated_at = NOW()
        WHERE id = ?
    ");
    if (!$stmt) {
        respondError('Prepare failed: ' . $conn->error, $isAjax);
    }

    $stmt->bind_param("sdsssssi", $name, $amount, $description, $location, $reason, $proofFile, $date, $id);
    $stmt->execute();
    if ($stmt->error) {
        respondError('Update failed: ' . $stmt->error, $isAjax);
    }
    $stmt->close();

    if (!$isAjax) {
        header("Location: daily-expenses.php");
        exit;
    }
    
    $action = 'list';
}

/* ============================
   HANDLE DELETE DAILY EXPENSE
============================ */
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete') {
    $id = intval($_POST['expense_id']);

    // Get expense details to delete proof file
    $stmt = $conn->prepare("SELECT proof FROM daily_expenses WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $expense = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($expense) {
        // Delete proof file if exists
        if (!empty($expense['proof'])) {
            $proofPath = 'uploads/daily_expenses/' . $expense['proof'];
            if (file_exists($proofPath)) {
                unlink($proofPath);
            }
        }

        // Delete expense record
        $stmt = $conn->prepare("DELETE FROM daily_expenses WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        if ($stmt->error) {
            respondError('Delete failed: ' . $stmt->error, $isAjax);
        }
        $stmt->close();
    }

    if (!$isAjax) {
        header("Location: daily-expenses.php");
        exit;
    }
    
    $action = 'list';
}

/* ============================
   HANDLE ADD DAILY EXPENSE
============================ */
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add') {

    $name = trim($_POST['name']);
    $amount = floatval($_POST['amount']);
    $description = trim($_POST['description']);
    $location = trim($_POST['location']);
    $reason = trim($_POST['reason']);
    $date = !empty($_POST['expense_date']) ? $_POST['expense_date'] : date('Y-m-d');

    // Upload proof
    $proofFile = null;
    if (!empty($_FILES['proof']['name'])) {
        $allowed = ['pdf', 'jpg', 'jpeg', 'png'];
        $ext = strtolower(pathinfo($_FILES['proof']['name'], PATHINFO_EXTENSION));

        if (in_array($ext, $allowed, true)) {
            $uploadDir = 'uploads/daily_expenses/';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true)) {
                respondError('Failed to create upload directory.', $isAjax);
            }

            if (!is_uploaded_file($_FILES['proof']['tmp_name'])) {
                respondError('Invalid proof upload.', $isAjax);
            }

            $proofFile = time() . '_daily.' . $ext;
            if (!move_uploaded_file($_FILES['proof']['tmp_name'], $uploadDir . $proofFile)) {
                respondError('Failed to upload proof file.', $isAjax);
            }
        }
    }

    $stmt = $conn->prepare("
        INSERT INTO daily_expenses (name, amount, description, location, reason, proof, expense_date)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) {
        respondError('Prepare failed: ' . $conn->error, $isAjax);
    }

    $stmt->bind_param("sdsssss", $name, $amount, $description, $location, $reason, $proofFile, $date);
    $stmt->execute();
    if ($stmt->error) {
        respondError('Insert failed: ' . $stmt->error, $isAjax);
    }

    if (!$isAjax) {
        header("Location: daily-expenses.php");
        exit;
    }
    
    // For AJAX, set action to list and let page render below
    $action = 'list';
}

/* ============================
   HANDLE ADD TO EXPENSE ACCOUNT
============================ */
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add_account_amount') {
    $addAmount = floatval($_POST['account_amount']);
    $referenceNumber = trim($_POST['reference_number'] ?? '');
    $fundDate = !empty($_POST['fund_date']) ? $_POST['fund_date'] : date('Y-m-d');
    
    if ($addAmount <= 0) {
        respondError('Amount must be greater than 0.', $isAjax);
    }

    // Generate reference number if not provided
    if (empty($referenceNumber)) {
        $referenceNumber = 'EXP-FUND-' . date('YmdHis');
    }

    // Update expense account
    $accountResult = $conn->query("SELECT id, total_amount FROM expense_account LIMIT 1");
    if ($accountResult && $accountResult->num_rows > 0) {
        $account = $accountResult->fetch_assoc();
        $newTotal = $account['total_amount'] + $addAmount;
        $stmt = $conn->prepare("UPDATE expense_account SET total_amount = ?, updated_at = NOW() WHERE id = ?");
        if (!$stmt) {
            respondError('Prepare failed: ' . $conn->error, $isAjax);
        }
        $stmt->bind_param("di", $newTotal, $account['id']);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $conn->prepare("INSERT INTO expense_account (total_amount, total_expense, remaining_amount, total_daily_expense, daily_expense_date) VALUES (?, 0, 0, 0, ?)");
        if (!$stmt) {
            respondError('Prepare failed: ' . $conn->error, $isAjax);
        }
        $today = date('Y-m-d');
        $stmt->bind_param("ds", $addAmount, $today);
        $stmt->execute();
        $stmt->close();
    }
    
    // Insert into funds_account for unified tracking
    $userId = $_SESSION['user_id'] ?? 0;
    $fundsStmt = $conn->prepare("
        INSERT INTO funds_account
        (fund_type, partner_id, partner_name, amount, transaction_date, proof, reference_number, created_by)
        VALUES ('expense_account', NULL, NULL, ?, ?, NULL, ?, ?)
    ");
    if ($fundsStmt) {
        $fundsStmt->bind_param("dssi", $addAmount, $fundDate, $referenceNumber, $userId);
        $fundsStmt->execute();
        $fundsStmt->close();
    }

    if (!$isAjax) {
        header("Location: daily-expenses.php");
        exit;
    }

    $action = 'list';
}

/* ============================
   HANDLE CLEAR EXPENSE ACCOUNT
============================ */
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'clear_account') {
    $accountResult = $conn->query("SELECT id, total_amount FROM expense_account LIMIT 1");
    if ($accountResult && $accountResult->num_rows > 0) {
        $account = $accountResult->fetch_assoc();
        $clearedAmount = $account['total_amount'];
        
        $stmt = $conn->prepare("UPDATE expense_account SET total_amount = 0, total_expense = 0, remaining_amount = 0, total_daily_expense = 0, updated_at = NOW() WHERE id = ?");
        if (!$stmt) {
            respondError('Prepare failed: ' . $conn->error, $isAjax);
        }
        $stmt->bind_param("i", $account['id']);
        $stmt->execute();
        $stmt->close();
        
        // Keep funds_account untouched here; its schema is transaction-based in this system.
    } else {
        $stmt = $conn->prepare("INSERT INTO expense_account (total_amount, total_expense, remaining_amount, total_daily_expense, daily_expense_date) VALUES (0, 0, 0, 0, ?)");
        if (!$stmt) {
            respondError('Prepare failed: ' . $conn->error, $isAjax);
        }
        $today = date('Y-m-d');
        $stmt->bind_param("s", $today);
        $stmt->execute();
        $stmt->close();
    }

    if (!$isAjax) {
        header("Location: daily-expenses.php");
        exit;
    }

    $action = 'list';
}

/* ============================
   DOWNLOAD MONTHLY EXPENSE REPORT
============================ */
if (isset($_GET['download_report'])) {
    $month = $_GET['report_month'] ?? date('Y-m');
    $monthStart = $month . '-01';
    $monthEnd = date('Y-m-t', strtotime($monthStart));
    
    // Get system settings
    $systemName = getSystemSetting('system_name', 'Container Management System');
    $systemLocation = getSystemSetting('system_location', '');
    $systemContact = getSystemSetting('system_contact', '');
    $systemEmail = getSystemSetting('system_email', '');
    $systemLogo = getSystemSetting('system_logo', '');
    
    // Get expenses for the month
    $stmt = $conn->prepare("
        SELECT expense_date, name, amount, description, location, reason
        FROM daily_expenses
        WHERE expense_date BETWEEN ? AND ?
        ORDER BY expense_date DESC, id DESC
    ");
    $stmt->bind_param("ss", $monthStart, $monthEnd);
    $stmt->execute();
    $result = $stmt->get_result();
    
    // Build expenses array and calculate total
    $expenses = [];
    $total = 0;
    while ($row = $result->fetch_assoc()) {
        $expenses[] = $row;
        $total += $row['amount'];
    }
    $stmt->close();
    
    // Clear any output buffers
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    // Generate HTML for PDF
    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Expense Report - ' . date('F Y', strtotime($monthStart)) . '</title>
        <style>
            @page {
                size: A4;
                margin: 20mm;
            }
            @media print {
                html, body {
                    margin: 0;
                    padding: 0;
                    width: 210mm;
                    height: 297mm;
                }
                @page {
                    size: A4;
                    margin: 20mm;
                }
                header, footer {
                    display: none;
                }
            }
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            body {
                font-family: Arial, Helvetica, sans-serif;
                font-size: 16px;
                color: #333;
                line-height: 1.6;
            }
            .header {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                padding: 0 0 20px 0;
                border-bottom: 3px solid #1e40af;
                margin-bottom: 30px;
            }
            .header-left {
                flex: 1;
            }
            .header-right {
                flex: 0 0 auto;
                padding-left: 20px;
            }
            .logo {
                max-width: 120px;
                max-height: 120px;
                display: block;
            }
            .company-name {
                font-size: 32pt;
                font-weight: bold;
                color: #1e40af;
                margin-bottom: 8px;
                line-height: 1.2;
            }
            .company-details {
                font-size: 14pt;
                color: #555;
                margin-top: 4px;
                line-height: 1.5;
            }
            .company-details div {
                margin: 2px 0;
            }
            .report-header {
                display: flex;
                justify-content: space-between;
                align-items: baseline;
                margin: 25px 0 20px 0;
                padding-bottom: 10px;
                border-bottom: 2px solid #e5e7eb;
            }
            .report-title {
                font-size: 20pt;
                font-weight: bold;
                color: #1e40af;
            }
            .report-period {
                font-size: 16pt;
                color: #666;
                font-weight: 600;
            }
            table {
                width: 100%;
                border-collapse: collapse;
                margin: 20px 0;
            }
            thead {
                background: #f8f9fa;
            }
            th {
                padding: 14px 10px;
                text-align: center;
                font-weight: bold;
                color: #1e40af;
                border-bottom: 2px solid #1e40af;
                font-size: 16px;
                text-transform: uppercase;
            }
            td {
                padding: 12px 10px;
                border-bottom: 1px solid #dee2e6;
                font-size: 16px;
                text-align: center;
            }
            tbody tr:nth-child(even) {
                background: #f8f9fa;
            }
            .amount {
                text-align: center;
                font-weight: 600;
                color: #dc2626;
            }
            .date {
                white-space: nowrap;
                color: #495057;
                font-weight: 500;
            }
            .total-row {
                background: transparent !important;
                color: #dc2626;
                font-weight: bold;
                font-size: 18px;
            }
            .total-row td {
                padding: 16px 10px;
                border-top: 2px solid #dc2626;
                border-bottom: 2px solid #dc2626;
            }
            .footer {
                display: none;
            }
            .no-data {
                text-align: center;
                padding: 40px;
                color: #6c757d;
                font-style: italic;
                font-size: 16px;
            }
            .print-notice {
                background: #fff3cd;
                border: 2px solid #ffc107;
                padding: 15px;
                margin: 20px;
                text-align: center;
                font-size: 14px;
                color: #856404;
                border-radius: 5px;
            }
            @media print {
                .print-notice {
                    display: none !important;
                }
            }
        </style>
    </head>
    <body>
        <div class="header">
            <div class="header-left">
                <div class="company-name">' . htmlspecialchars($systemName) . '</div>
                <div class="company-details">';
    
    if (!empty($systemEmail)) {
        $html .= '<div>Email: ' . htmlspecialchars($systemEmail) . '</div>';
    }
    if (!empty($systemContact)) {
        $html .= '<div>Contact: ' . htmlspecialchars($systemContact) . '</div>';
    }
    if (!empty($systemLocation)) {
        $html .= '<div>' . htmlspecialchars($systemLocation) . '</div>';
    }
    
    $html .= '
                </div>
            </div>
            <div class="header-right">';
    
    // Add logo if available
    if (!empty($systemLogo) && file_exists('uploads/system/' . $systemLogo)) {
        $logoPath = 'uploads/system/' . $systemLogo;
        $logoData = base64_encode(file_get_contents($logoPath));
        $logoMime = mime_content_type($logoPath);
        $html .= '<img src="data:' . $logoMime . ';base64,' . $logoData . '" class="logo" alt="Logo">';
    }
    
    $html .= '
            </div>
        </div>
        
        <div class="print-notice">
            <strong>⚠ Before Printing:</strong> In the print dialog, please disable "Headers and footers" under "More settings" for a clean PDF without browser-generated text.
        </div>
        
        <div class="report-header">
            <div class="report-title">Expense Report</div>
            <div class="report-period">' . date('F Y', strtotime($monthStart)) . '</div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th width="10%">Date</th>
                    <th width="15%">Name</th>
                    <th width="12%">Amount</th>
                    <th width="25%">Description</th>
                    <th width="18%">Location</th>
                    <th width="20%">Reason</th>
                </tr>
            </thead>
            <tbody>';
    
    if (count($expenses) > 0) {
        foreach ($expenses as $expense) {
            $html .= '
                <tr>
                    <td class="date">' . date('d/m/Y', strtotime($expense['expense_date'])) . '</td>
                    <td>' . htmlspecialchars($expense['name']) . '</td>
                    <td class="amount">' . number_format($expense['amount'], 2) . '</td>
                    <td>' . htmlspecialchars($expense['description']) . '</td>
                    <td>' . htmlspecialchars($expense['location']) . '</td>
                    <td>' . htmlspecialchars($expense['reason']) . '</td>
                </tr>';
        }
        
        $html .= '
                <tr class="total-row">
                    <td colspan="2">TOTAL</td>
                    <td class="amount">' . number_format($total, 2) . '</td>
                    <td colspan="3"></td>
                </tr>';
    } else {
        $html .= '
                <tr>
                    <td colspan="6" class="no-data">No expenses recorded for this period</td>
                </tr>';
    }
    
    $html .= '
            </tbody>
        </table>
    </body>
    </html>';
    
    // Set headers for PDF download
    $filename = 'expense_report_' . $month . '.pdf';
    
    // Try to use wkhtmltopdf if available (shell command)
    $wkhtmltopdf = 'wkhtmltopdf';
    $hasWkhtmltopdf = false;
    
    // Check if wkhtmltopdf is available
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        exec('where wkhtmltopdf 2>nul', $output, $return);
        $hasWkhtmltopdf = ($return === 0);
    } else {
        exec('which wkhtmltopdf 2>/dev/null', $output, $return);
        $hasWkhtmltopdf = ($return === 0);
    }
    
    if ($hasWkhtmltopdf) {
        // Use wkhtmltopdf to generate PDF
        $tempHtml = tempnam(sys_get_temp_dir(), 'expense_') . '.html';
        $tempPdf = tempnam(sys_get_temp_dir(), 'expense_') . '.pdf';
        
        file_put_contents($tempHtml, $html);
        
        $cmd = sprintf(
            '%s --page-size A4 --margin-top 20mm --margin-bottom 20mm --margin-left 20mm --margin-right 20mm --no-header-line --no-footer-line --disable-smart-shrinking "%s" "%s" 2>&1',
            $wkhtmltopdf,
            $tempHtml,
            $tempPdf
        );
        
        exec($cmd, $output, $return);
        
        if ($return === 0 && file_exists($tempPdf)) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($tempPdf));
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Pragma: public');
            
            readfile($tempPdf);
            
            unlink($tempHtml);
            unlink($tempPdf);
            exit;
        }
        
        // Cleanup temp files if PDF generation failed
        if (file_exists($tempHtml)) unlink($tempHtml);
        if (file_exists($tempPdf)) unlink($tempPdf);
    }
    
    // Fallback: Output HTML that can be printed to PDF via browser
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    echo '<script>
        window.onload = function() {
            // Add print instructions
            var style = document.createElement("style");
            style.innerHTML = "@page { margin: 20mm; size: A4; } @media print { html, body { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }";
            document.head.appendChild(style);
            
            // Trigger print dialog
            setTimeout(function() {
                window.print();
            }, 500);
        };
    </script>';
    exit;
}

/* ============================
   DAILY EXPENSE ACCOUNT
============================ */
$today = date('Y-m-d');
$dailyDate = !empty($filterDate) ? $filterDate : $today;

$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');
if (!empty($filterMonth)) {
    $monthStart = $filterMonth . '-01';
    $monthEnd = date('Y-m-t', strtotime($monthStart));
}

// Total Expense (All time)
$computedTotalExpense = $conn->query("
    SELECT SUM(amount) AS total FROM daily_expenses
")->fetch_assoc()['total'] ?? 0;

// Total Daily Expense (Today's expenses only)
$todayStmt = $conn->prepare("
    SELECT SUM(amount) AS total
    FROM daily_expenses
    WHERE expense_date = ?
");
$todayStmt->bind_param("s", $dailyDate);
$todayStmt->execute();
$computedDailyExpense = $todayStmt->get_result()->fetch_assoc()['total'] ?? 0;
$todayStmt->close();

$remainingAmount = 0;

// Ensure account row exists
$accountResult = $conn->query("SELECT * FROM expense_account LIMIT 1");
if (!$accountResult || $accountResult->num_rows === 0) {
    $insertStmt = $conn->prepare("
        INSERT INTO expense_account
        (total_amount, total_expense, remaining_amount, total_daily_expense, daily_expense_date)
        VALUES (?, ?, ?, ?, ?)
    ");
    if (!$insertStmt) {
        respondError('Prepare failed: ' . $conn->error, $isAjax);
    }
    $insertStmt->bind_param(
        "dddds",
        $remainingAmount,
        $computedTotalExpense,
        $remainingAmount,
        $computedDailyExpense,
        $dailyDate
    );
    $insertStmt->execute();
    $insertStmt->close();
}

// Update account totals after computing

// Read account values, update computed totals, then re-read for display
$account = $conn->query("SELECT * FROM expense_account LIMIT 1")->fetch_assoc();
$totalAmount = $account['total_amount'] ?? 0;
$remainingAmount = $totalAmount - $computedTotalExpense;

if (!empty($account['id'])) {
    $stmt = $conn->prepare("UPDATE expense_account SET total_expense = ?, remaining_amount = ?, total_daily_expense = ?, daily_expense_date = ?, updated_at = NOW() WHERE id = ?");
    if (!$stmt) {
        respondError('Prepare failed: ' . $conn->error, $isAjax);
    }
    $stmt->bind_param("dddsi", $computedTotalExpense, $remainingAmount, $computedDailyExpense, $dailyDate, $account['id']);
    $stmt->execute();
    $stmt->close();
}

$account = $conn->query("SELECT * FROM expense_account LIMIT 1")->fetch_assoc();
$totalAmount = $account['total_amount'] ?? 0;
$totalExpense = $account['total_expense'] ?? 0;
$totalDailyExpense = $account['total_daily_expense'] ?? 0;
$remainingAmount = $account['remaining_amount'] ?? 0;

// Determine what to show in the 4th card based on active filter
$filterCardLabel = 'Daily Expense';
$filterCardAmount = $computedDailyExpense;
$filterCardColor = 'orange';
$filterCardSubtext = 'Today';

if (!empty($filterMonth)) {
    // Monthly filter is active
    $filterCardLabel = 'Monthly Expense';
    $filterCardColor = 'purple';
    $filterCardSubtext = date('F Y', strtotime($monthStart));
    
    // Calculate monthly total
    $monthStmt = $conn->prepare("
        SELECT SUM(amount) AS total
        FROM daily_expenses
        WHERE expense_date BETWEEN ? AND ?
    ");
    $monthStmt->bind_param("ss", $monthStart, $monthEnd);
    $monthStmt->execute();
    $filterCardAmount = $monthStmt->get_result()->fetch_assoc()['total'] ?? 0;
    $monthStmt->close();
} elseif (!empty($filterDate)) {
    // Daily filter is active
    $filterCardLabel = 'Daily Expense';
    $filterCardColor = 'orange';
    $filterCardSubtext = date('d M Y', strtotime($filterDate));
    $filterCardAmount = $computedDailyExpense;
}

if (!$isAjax) {
    include 'include/header.php';
    include 'include/sidebar.php';
    ?>
    <div id="mobileSidebarOverlay" class="md:hidden" onclick="closeMobileSidebar()"></div>

    <div id="page-content" class="flex-1 flex flex-col min-w-0 overflow-y-auto">
    <?php
} else {
    // For AJAX requests, capture only the page-content inner HTML.
    ob_start();
}
?>

<!-- Header - Responsive -->
<header class="sticky top-0 z-40 w-full bg-white/80 dark:bg-slate-900/80 backdrop-blur-md border-b border-slate-200 dark:border-slate-800">
    <div class="flex items-center justify-between px-4 md:px-6 lg:px-8 h-16">
    <!-- Mobile Menu Button -->
    <button type="button" onclick="toggleMobileSidebar()" class="flex md:hidden items-center justify-center h-11 w-11 rounded-lg hover:bg-slate-200/50 dark:hover:bg-slate-800/50 transition-colors" aria-label="Open navigation menu">
        <span class="material-symbols-outlined text-slate-700 dark:text-slate-300">menu</span>
    </button>
    
    <!-- Title Section -->
    <div class="flex items-center gap-2 md:gap-3 flex-1 md:flex-initial">
        <div class="hidden sm:flex size-8 md:size-10 rounded-lg bg-gradient-to-br from-emerald-500 to-emerald-600 items-center justify-center text-white shadow-lg shadow-emerald-500/20">
            <span class="material-symbols-outlined text-lg md:text-xl">receipt_long</span>
        </div>
        <div>
            <h2 class="text-base sm:text-lg md:text-xl lg:text-2xl font-bold text-slate-900 dark:text-white tracking-tight">Daily Expenses</h2>
            <p class="hidden md:block text-xs text-slate-500 dark:text-slate-400">Track and manage daily operational expenditures</p>
        </div>
    </div>

    <!-- Desktop Actions -->
    <div class="hidden lg:flex gap-3 items-center">
        <?php if (!empty($filterDate) || !empty($filterMonth)): ?>
        <!-- Reset Filter Button (shown when filter is active) -->
        <a href="daily-expenses.php" class="ajax-link px-4 py-2 bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400 border border-red-200 dark:border-red-800 rounded-lg text-sm font-semibold hover:bg-red-100 dark:hover:bg-red-900/30 transition-colors flex items-center gap-2">
            <span class="material-symbols-outlined text-[18px]">close</span>
            Reset Filter
        </a>
        <?php endif; ?>
            
        <button id="openFilterModal" class="px-4 py-2 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded-lg text-sm font-semibold hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors border border-slate-200 dark:border-slate-700 flex items-center gap-2">
            <span class="material-symbols-outlined text-[18px]">filter_list</span>
            Filter
        </button>

        <?php if ($isAdmin): ?>
        <button id="openDownloadReportModal"
                class="px-4 py-2 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded-lg text-sm font-semibold hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors border border-slate-200 dark:border-slate-700 flex items-center gap-2">
            <span class="material-symbols-outlined text-[18px]">print</span>
            <span>Print</span>
        </button>
        
        <button id="openAddFundsModal"
                class="px-4 py-2 bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-slate-700 dark:text-slate-300 rounded-lg text-sm font-semibold hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors flex items-center gap-2">
            <span class="material-symbols-outlined text-[18px]">add_circle</span>
            <span>Add Funds</span>
        </button>

        <button id="openExpenseModal"
                class="px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm font-semibold hover:bg-emerald-700 transition-colors flex items-center gap-2 shadow-lg shadow-emerald-500/20">
            <span class="material-symbols-outlined text-[18px]">add</span>
            <span>New Expense</span>
        </button>
        <?php endif; ?>
    </div>
    
    <!-- Mobile/Tablet Actions -->
    <div class="flex lg:hidden items-center gap-2 relative">
        <?php if (!empty($filterDate) || !empty($filterMonth)): ?>
        <a href="daily-expenses.php" class="ajax-link h-10 w-10 bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400 border border-red-200 dark:border-red-800 rounded-lg flex items-center justify-center hover:bg-red-100 dark:hover:bg-red-900/30 transition-colors" title="Reset Filter">
            <span class="material-symbols-outlined text-[18px]">close</span>
        </a>
        <?php endif; ?>
        
        <button id="openFilterModalMobile" class="h-10 w-10 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded-lg flex items-center justify-center hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors" title="Filter">
            <span class="material-symbols-outlined text-[18px]">filter_list</span>
        </button>

        <?php if ($isAdmin): ?>
        <button id="openDownloadReportModalMobile"
                class="h-10 w-10 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded-lg flex items-center justify-center hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors" title="Print Report">
            <span class="material-symbols-outlined text-[18px]">print</span>
        </button>
        
        <button id="openAddFundsModalMobile"
                class="h-10 w-10 bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-slate-700 dark:text-slate-300 rounded-lg flex items-center justify-center hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors" title="Add Funds">
            <span class="material-symbols-outlined text-[18px]">add_circle</span>
        </button>
        
        <button id="openExpenseModalMobile"
                class="h-10 px-3 sm:px-4 bg-emerald-600 text-white rounded-lg font-semibold hover:bg-emerald-700 transition-colors flex items-center gap-1 sm:gap-2 shadow-lg shadow-emerald-500/20">
            <span class="material-symbols-outlined text-[18px]">add</span>
            <span class="text-sm hidden sm:inline">Add</span>
        </button>
        <?php endif; ?>
    </div>
    </div>
    
</header>

<!-- Page Body - Responsive Container -->
<div class="p-4 md:p-6 lg:p-8 max-w-7xl mx-auto w-full space-y-4 md:space-y-6 pb-20 md:pb-8">

<?php
/* ============================
    LIST DAILY EXPENSES
============================ */
if (!empty($filterDate)) {
    $stmt = $conn->prepare("
        SELECT * FROM daily_expenses
        WHERE expense_date = ?
        ORDER BY expense_date DESC
    ");
    $stmt->bind_param("s", $filterDate);
    $stmt->execute();
    $expenses = $stmt->get_result();
    $stmt->close();
} elseif (!empty($filterMonth)) {
    $stmt = $conn->prepare("
        SELECT * FROM daily_expenses
        WHERE expense_date BETWEEN ? AND ?
        ORDER BY expense_date DESC
    ");
    $stmt->bind_param("ss", $monthStart, $monthEnd);
    $stmt->execute();
    $expenses = $stmt->get_result();
    $stmt->close();
} else {
    // By default, show today's expenses
    $stmt = $conn->prepare("
        SELECT * FROM daily_expenses
        WHERE expense_date = ?
        ORDER BY expense_date DESC
    ");
    $stmt->bind_param("s", $today);
    $stmt->execute();
    $expenses = $stmt->get_result();
    $stmt->close();
}
?>

<!-- ACCOUNT SUMMARY -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 md:gap-4 lg:gap-6">
    <div class="bg-white dark:bg-slate-900 rounded-lg md:rounded-xl p-4 md:p-5 lg:p-6 border border-slate-200 dark:border-slate-800 shadow-sm hover:border-primary/30 dark:hover:border-primary/30 transition-all group">
        <div class="flex items-center justify-between mb-2 md:mb-3">
            <p class="text-xs md:text-sm font-medium text-slate-500 dark:text-slate-400">Total Amount</p>
            <div class="size-8 md:size-10 bg-blue-100 dark:bg-blue-900/30 rounded-lg flex items-center justify-center text-blue-600 dark:text-blue-400 group-hover:scale-110 transition-transform">
                <span class="material-symbols-outlined text-lg md:text-xl">account_balance_wallet</span>
            </div>
        </div>
        <h2 class="text-xl md:text-2xl font-bold text-slate-900 dark:text-slate-100">
            Rs. <?= number_format($totalAmount, 0) ?>
        </h2>
    </div>
    <div class="bg-white dark:bg-slate-900 rounded-lg md:rounded-xl p-4 md:p-5 lg:p-6 border border-slate-200 dark:border-slate-800 shadow-sm hover:border-primary/30 dark:hover:border-primary/30 transition-all group">
        <div class="flex items-center justify-between mb-2 md:mb-3">
            <p class="text-xs md:text-sm font-medium text-slate-500 dark:text-slate-400">Total Expense</p>
            <div class="size-8 md:size-10 bg-red-100 dark:bg-red-900/30 rounded-lg flex items-center justify-center text-red-600 dark:text-red-400 group-hover:scale-110 transition-transform">
                <span class="material-symbols-outlined text-lg md:text-xl">payments</span>
            </div>
        </div>
        <h2 class="text-xl md:text-2xl font-bold text-slate-900 dark:text-slate-100">
            Rs. <?= number_format($totalExpense, 0) ?>
        </h2>
    </div>
    <div class="bg-white dark:bg-slate-900 rounded-lg md:rounded-xl p-4 md:p-5 lg:p-6 border border-slate-200 dark:border-slate-800 shadow-sm hover:border-emerald-500/30 dark:hover:border-emerald-500/30 transition-all group">
        <div class="flex items-center justify-between mb-2 md:mb-3">
            <p class="text-xs md:text-sm font-medium text-slate-500 dark:text-slate-400">Remaining Amount</p>
            <div class="size-8 md:size-10 bg-emerald-100 dark:bg-emerald-900/30 rounded-lg flex items-center justify-center text-emerald-600 dark:text-emerald-400 group-hover:scale-110 transition-transform">
                <span class="material-symbols-outlined text-lg md:text-xl">savings</span>
            </div>
        </div>
        <h2 class="text-xl md:text-2xl font-bold text-slate-900 dark:text-slate-100">
            Rs. <?= number_format($remainingAmount, 0) ?>
        </h2>
    </div>
    <div class="bg-white dark:bg-slate-900 rounded-lg md:rounded-xl p-4 md:p-5 lg:p-6 border border-slate-200 dark:border-slate-800 shadow-sm hover:border-primary/30 dark:hover:border-primary/30 transition-all group">
        <div class="flex items-center justify-between mb-2 md:mb-3">
            <p class="text-xs md:text-sm font-medium text-slate-500 dark:text-slate-400"><?= $filterCardLabel ?></p>
            <div class="size-8 md:size-10 bg-<?= $filterCardColor == 'orange' ? 'orange' : 'purple' ?>-100 dark:bg-<?= $filterCardColor == 'orange' ? 'orange' : 'purple' ?>-900/30 rounded-lg flex items-center justify-center text-<?= $filterCardColor == 'orange' ? 'orange' : 'purple' ?>-600 dark:text-<?= $filterCardColor == 'orange' ? 'orange' : 'purple' ?>-400 group-hover:scale-110 transition-transform">
                <span class="material-symbols-outlined text-lg md:text-xl">calendar_today</span>
            </div>
        </div>
        <h2 class="text-xl md:text-2xl font-bold text-slate-900 dark:text-slate-100">
            Rs. <?= number_format($filterCardAmount, 0) ?>
        </h2>
        <p class="text-xs text-slate-500 dark:text-slate-400 mt-2"><?= $filterCardSubtext ?></p>
    </div>
</div>

<!-- EXPENSE TABLE -->
<div class="bg-white dark:bg-slate-900 rounded-lg md:rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden shadow-sm">
    <div class="border-b border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-900/50 px-4 md:px-6 py-3 md:py-4">
        <h3 class="text-xs md:text-sm font-bold text-slate-900 dark:text-slate-100 uppercase tracking-wide flex items-center gap-2">
            <span class="material-symbols-outlined text-[16px] md:text-[18px] text-emerald-600">receipt</span>
            Expense Records
        </h3>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full min-w-[800px]">
            <thead>
                <tr class="bg-slate-50 dark:bg-slate-900/50 border-b border-slate-200 dark:border-slate-800">
                    <th class="px-3 md:px-4 py-2.5 md:py-3.5 text-left text-[10px] md:text-xs font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider whitespace-nowrap">Date</th>
                    <th class="px-3 md:px-4 py-2.5 md:py-3.5 text-left text-[10px] md:text-xs font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider whitespace-nowrap">Name</th>
                    <th class="px-3 md:px-4 py-2.5 md:py-3.5 text-left text-[10px] md:text-xs font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider whitespace-nowrap">Amount</th>
                    <th class="px-3 md:px-4 py-2.5 md:py-3.5 text-left text-[10px] md:text-xs font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider whitespace-nowrap">Description</th>
                    <th class="px-3 md:px-4 py-2.5 md:py-3.5 text-left text-[10px] md:text-xs font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider whitespace-nowrap">Location</th>
                    <th class="px-3 md:px-4 py-2.5 md:py-3.5 text-left text-[10px] md:text-xs font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider whitespace-nowrap">Reason</th>
                    <th class="px-3 md:px-4 py-2.5 md:py-3.5 text-center text-[10px] md:text-xs font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider whitespace-nowrap">Proof</th>
                    <?php if ($isAdmin): ?>
                    <th class="px-3 md:px-4 py-2.5 md:py-3.5 text-center text-[10px] md:text-xs font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider whitespace-nowrap">Actions</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody class="bg-white dark:bg-slate-900 divide-y divide-slate-200 dark:divide-slate-800">
<?php
$total = 0;
while ($row = $expenses->fetch_assoc()):
    $total += $row['amount'];
?>
                <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition group">
                    <td class="px-3 md:px-4 py-2.5 md:py-3.5 text-xs md:text-sm text-slate-900 dark:text-slate-100 whitespace-nowrap"><?= date('d/m/Y', strtotime($row['expense_date'])) ?></td>
                    <td class="px-3 md:px-4 py-2.5 md:py-3.5 text-xs md:text-sm font-semibold text-slate-900 dark:text-slate-100"><?= htmlspecialchars($row['name']) ?></td>
                    <td class="px-3 md:px-4 py-2.5 md:py-3.5 text-xs md:text-sm font-bold text-slate-900 dark:text-slate-100 whitespace-nowrap">Rs. <?= number_format($row['amount'], 2) ?></td>
                    <td class="px-3 md:px-4 py-2.5 md:py-3.5 text-xs md:text-sm text-slate-600 dark:text-slate-400"><?= htmlspecialchars($row['description']) ?></td>
                    <td class="px-3 md:px-4 py-2.5 md:py-3.5 text-xs md:text-sm text-slate-600 dark:text-slate-400"><?= htmlspecialchars($row['location']) ?></td>
                    <td class="px-3 md:px-4 py-2.5 md:py-3.5 text-xs md:text-sm text-slate-600 dark:text-slate-400"><?= htmlspecialchars($row['reason']) ?></td>
                    <td class="px-3 md:px-4 py-2.5 md:py-3.5 text-center">
                        <?php if (!empty($row['proof'])): ?>
                            <button onclick="showProof('uploads/daily_expenses/<?= htmlspecialchars($row['proof']) ?>', '<?= htmlspecialchars($row['name']) ?>')" 
                                    class="inline-flex items-center gap-1 px-3 py-1.5 border border-slate-300 dark:border-slate-700 text-xs font-semibold rounded-lg text-slate-700 dark:text-slate-300 bg-white dark:bg-slate-800 hover:bg-slate-50 dark:hover:bg-slate-700 transition">
                                <span class="material-symbols-outlined text-[16px]">visibility</span>
                                View
                            </button>
                        <?php else: ?>
                            <span class="text-slate-400 dark:text-slate-600 text-sm">—</span>
                        <?php endif; ?>
                    </td>
                    <?php if ($isAdmin): ?>
                    <td class="px-4 py-3.5 text-center">
                        <div class="flex items-center justify-center gap-2">
                            <button onclick="editExpense(<?= $row['id'] ?>)" 
                                    class="inline-flex items-center justify-center size-8 bg-primary text-white text-xs font-medium rounded-lg hover:bg-blue-700 transition shadow-sm"
                                    title="Edit">
                                <span class="material-symbols-outlined text-[18px]">edit</span>
                            </button>
                            <button onclick="deleteExpense(<?= $row['id'] ?>, '<?= htmlspecialchars($row['name'], ENT_QUOTES) ?>')" 
                                    class="inline-flex items-center justify-center size-8 bg-red-600 text-white text-xs font-medium rounded-lg hover:bg-red-700 transition shadow-sm"
                                    title="Delete">
                                <span class="material-symbols-outlined text-[18px]">delete</span>
                            </button>
                        </div>
                    </td>
                    <?php endif; ?>
                </tr>
<?php endwhile; ?>
                <tr class="bg-slate-50 dark:bg-slate-900/50 border-t-2 border-slate-300 dark:border-slate-700">
                    <td class="px-3 md:px-4 py-2.5 md:py-3.5 text-xs md:text-sm font-bold text-slate-900 dark:text-slate-100 whitespace-nowrap">TOTAL</td>
                    <td class="px-3 md:px-4 py-2.5 md:py-3.5"></td>
                    <td class="px-3 md:px-4 py-2.5 md:py-3.5 text-xs md:text-sm font-bold text-slate-900 dark:text-slate-100 whitespace-nowrap">Rs. <?= number_format($total, 2) ?></td>
                    <td colspan="<?= $isAdmin ? '5' : '4' ?>"></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

</div>
</div>

<!-- FILTER MODAL -->
<div id="filterModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50 p-4" style="display: none;">
    <div class="bg-white dark:bg-slate-900 w-full max-w-2xl rounded-xl md:rounded-2xl shadow-2xl overflow-hidden max-h-[90vh] flex flex-col">
        <div class="px-4 py-3 border-b border-slate-200 dark:border-slate-800 flex justify-between items-center">
            <h3 class="text-base font-bold text-slate-900 dark:text-slate-100 flex items-center gap-2">
                <span class="material-symbols-outlined text-[20px]">filter_list</span>
                Filter Expenses
            </h3>
            <button id="closeFilterModal" type="button" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 transition">
                <span class="material-symbols-outlined text-[20px]">close</span>
            </button>
        </div>
        
        <div class="p-4 md:p-5 overflow-y-auto flex-1">
            <form method="GET" id="filterForm" class="space-y-3 md:space-y-4">
                <!-- Filter Type Selection -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <label class="cursor-pointer">
                        <input type="radio" name="filter_type" value="date" class="peer hidden" <?= !empty($filterDate) ? 'checked' : (!empty($filterMonth) ? '' : 'checked') ?>>
                        <div class="border border-slate-300 dark:border-slate-700 rounded-lg p-3 peer-checked:border-emerald-500 peer-checked:bg-emerald-50 dark:peer-checked:bg-emerald-900/20 hover:border-emerald-300 transition">
                            <div class="flex items-center gap-2">
                                <span class="material-symbols-outlined text-emerald-600 dark:text-emerald-400 text-[20px]">calendar_today</span>
                                <div>
                                    <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">Specific Date</p>
                                    <p class="text-xs text-slate-600 dark:text-slate-400">View daily expenses</p>
                                </div>
                            </div>
                        </div>
                    </label>
                    
                    <label class="cursor-pointer">
                        <input type="radio" name="filter_type" value="month" class="peer hidden" <?= !empty($filterMonth) ? 'checked' : '' ?>>
                        <div class="border border-slate-300 dark:border-slate-700 rounded-lg p-3 peer-checked:border-emerald-500 peer-checked:bg-emerald-50 dark:peer-checked:bg-emerald-900/20 hover:border-emerald-300 transition">
                            <div class="flex items-center gap-2">
                                <span class="material-symbols-outlined text-emerald-600 dark:text-emerald-400 text-[20px]">date_range</span>
                                <div>
                                    <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">Select Month</p>
                                    <p class="text-xs text-slate-600 dark:text-slate-400">View monthly expenses</p>
                                </div>
                            </div>
                        </div>
                    </label>
                </div>
                
                <!-- Filter Input Areas -->
                <div id="dateFilterInput" class="<?= !empty($filterMonth) && empty($filterDate) ? 'hidden' : '' ?>">
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Select Date</label>
                    <input type="date" name="date" id="dateInput" 
                           class="w-full border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-1.5 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent" 
                           value="<?= htmlspecialchars($filterDate) ?>">
                </div>
                
                <div id="monthFilterInput" class="<?= !empty($filterDate) && empty($filterMonth) ? 'hidden' : (!empty($filterMonth) ? '' : 'hidden') ?>">
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Select Month</label>
                    <input type="month" name="month" id="monthInput" 
                           class="w-full border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-1.5 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent" 
                           value="<?= htmlspecialchars($filterMonth) ?>">
                </div>
                
                <!-- Action Buttons -->
                <div class="flex gap-3 pt-1">
                    <button type="submit" class="bg-emerald-600 text-white px-5 py-2 rounded-lg hover:bg-emerald-700 font-semibold text-sm transition shadow-lg shadow-emerald-500/20">
                        Apply Filter
                    </button>
                    <button type="button" id="cancelFilterModal" class="px-5 py-2 border border-slate-300 dark:border-slate-700 rounded-lg text-slate-700 dark:text-slate-300 font-semibold text-sm hover:bg-slate-50 dark:hover:bg-slate-800 transition">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- DELETE CONFIRMATION MODAL -->
<div id="deleteConfirmModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50" style="display: none;">
    <div class="bg-white dark:bg-slate-900 rounded-xl shadow-2xl max-w-md w-full m-4">
        <div class="p-6">
            <div class="flex items-center justify-center w-12 h-12 mx-auto bg-red-100 dark:bg-red-900/30 rounded-full mb-4">
                <span class="material-symbols-outlined text-red-600 dark:text-red-400 text-[28px]">warning</span>
            </div>
            <h3 class="text-lg font-bold text-slate-900 dark:text-slate-100 text-center mb-2">Delete Expense</h3>
            <p class="text-sm text-slate-600 dark:text-slate-400 text-center mb-1">Are you sure you want to delete this expense?</p>
            <p class="text-sm font-semibold text-slate-900 dark:text-slate-100 text-center mb-4" id="deleteExpenseName"></p>
            <p class="text-xs text-red-600 dark:text-red-400 text-center mb-6">This action cannot be undone.</p>
        </div>
        <div class="bg-slate-50 dark:bg-slate-800 px-6 py-4 flex gap-3 justify-end rounded-b-xl">
            <button onclick="closeDeleteModal()" class="px-5 py-2 border border-slate-300 dark:border-slate-700 rounded-lg text-slate-700 dark:text-slate-300 font-semibold text-sm hover:bg-white dark:hover:bg-slate-900 transition">
                Cancel
            </button>
            <button onclick="confirmDelete()" class="px-5 py-2 bg-red-600 text-white rounded-lg font-semibold text-sm hover:bg-red-700 transition shadow-lg shadow-red-500/20">
                Delete
            </button>
        </div>
    </div>
</div>

<!-- PROOF PREVIEW MODAL -->
<div id="proofModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50" style="display: none;">
    <div class="bg-white dark:bg-slate-900 rounded-xl shadow-2xl max-w-4xl w-full m-4 max-h-[90vh] overflow-hidden">
        <div class="border-b border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 px-6 py-4 flex justify-between items-center">
            <h3 class="text-lg font-bold text-slate-900 dark:text-slate-100 flex items-center gap-2" id="proofTitle">
                <span class="material-symbols-outlined text-primary">description</span>
                Proof Document
            </h3>
            <button onclick="closeProof()" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 transition">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <div class="p-6 overflow-auto bg-slate-50 dark:bg-slate-800" style="max-height: calc(90vh - 140px);">
            <div id="proofContent" class="flex items-center justify-center">
                <img id="proofImage" src="" alt="Proof" class="max-w-full h-auto rounded-lg border border-slate-200 dark:border-slate-700" style="display: none;">
            </div>
        </div>
        <div class="bg-white dark:bg-slate-900 px-6 py-4 flex justify-between items-center border-t border-slate-200 dark:border-slate-800">
            <a id="proofDownloadLink" href="" download="" class="inline-flex items-center gap-2 px-5 py-2 bg-primary text-white rounded-lg hover:bg-blue-700 font-semibold transition shadow-lg shadow-blue-500/20">
                <span class="material-symbols-outlined text-[18px]">download</span>
                Download
            </a>
            <button onclick="closeProof()" class="px-5 py-2 border border-slate-300 dark:border-slate-700 rounded-lg text-slate-700 dark:text-slate-300 font-semibold hover:bg-slate-50 dark:hover:bg-slate-800 transition">
                Close
            </button>
        </div>
    </div>
</div>

<?php if ($isAdmin): ?>
<!-- ADD FUNDS MODAL -->
<div id="addFundsModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50 p-4" style="display: none;">
    <div class="bg-white dark:bg-slate-900 w-full max-w-sm md:max-w-md rounded-xl shadow-2xl overflow-hidden">
        <div class="border-b border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 px-4 md:px-6 py-3 md:py-4 flex justify-between items-center">
            <h2 class="text-base md:text-lg font-bold text-slate-900 dark:text-slate-100 flex items-center gap-2">
                <span class="material-symbols-outlined text-emerald-600 text-[20px]">add_circle</span>
                <span class="hidden xs:inline">Add Funds to Expense Account</span>
                <span class="xs:hidden">Add Funds</span>
            </h2>
            <button id="closeAddFundsModal" type="button" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 transition">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <div class="p-4 md:p-6">
            <form method="POST" id="addFundsForm">
                <input type="hidden" name="action" value="add_account_amount">
                
                <div class="mb-4">
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1.5">Amount <span class="text-red-500">*</span></label>
                    <input type="number" name="account_amount" step="0.01" min="0.01"
                           placeholder="Enter amount to add (must be > 0)"
                           class="w-full border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent" required>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">This amount will be added to the expense account. Must be greater than 0.</p>
                </div>

                <div class="mb-4">
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1.5">Date <span class="text-red-500">*</span></label>
                    <input type="date" name="fund_date" value="<?= date('Y-m-d') ?>"
                           class="w-full border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent" required>
                </div>

                <div class="mb-4">
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1.5">Reference Number</label>
                    <input type="text" name="reference_number" placeholder="Auto-generated if left empty"
                           class="w-full border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent">
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Optional. Leave empty for auto-generated reference.</p>
                </div>

                <div class="flex gap-3 pt-2">
                    <button type="submit" class="bg-emerald-600 text-white px-5 py-2 rounded-lg hover:bg-emerald-700 font-semibold text-sm transition shadow-lg shadow-emerald-500/20">
                        Add Funds
                    </button>
                    <button id="cancelAddFundsModal" type="button" class="px-5 py-2 border border-slate-300 dark:border-slate-700 rounded-lg text-slate-700 dark:text-slate-300 font-semibold text-sm hover:bg-slate-50 dark:hover:bg-slate-800 transition">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- DOWNLOAD REPORT MODAL -->
<div id="downloadReportModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50" style="display: none;">
    <div class="bg-white dark:bg-slate-900 w-full max-w-md rounded-xl shadow-2xl overflow-hidden m-4">
        <div class="border-b border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 px-6 py-4 flex justify-between items-center">
            <h2 class="text-lg font-bold text-slate-900 dark:text-slate-100 flex items-center gap-2">
                <span class="material-symbols-outlined text-primary">print</span>
                Print Monthly Report
            </h2>
            <button id="closeDownloadReportModal" type="button" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 transition">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <div class="p-6">
            <form method="GET" action="daily-expenses.php" id="downloadReportForm">
                <input type="hidden" name="download_report" value="1">
                
                <div class="mb-4">
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1.5">Select Month <span class="text-red-500">*</span></label>
                    <input type="month" name="report_month" id="reportMonthInput"
                           value="<?= date('Y-m') ?>"
                           max="<?= date('Y-m') ?>"
                           class="w-full border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent" required>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Select the month for which you want to print the expense report with company header and details.</p>
                </div>

                <div class="flex gap-3 pt-2">
                    <button type="submit" class="bg-primary text-white px-5 py-2 rounded-lg hover:bg-blue-700 font-semibold text-sm transition flex items-center gap-2 shadow-lg shadow-blue-500/20">
                        <span class="material-symbols-outlined text-[18px]">print</span>
                        Print Report
                    </button>
                    <button id="cancelDownloadReportModal" type="button" class="px-5 py-2 border border-slate-300 dark:border-slate-700 rounded-lg text-slate-700 dark:text-slate-300 font-semibold text-sm hover:bg-slate-50 dark:hover:bg-slate-800 transition">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- EDIT EXPENSE MODAL -->
<div id="editExpenseModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50 p-4" style="display: none;">
    <div class="bg-white dark:bg-slate-900 w-full max-w-2xl rounded-xl md:rounded-2xl shadow-2xl overflow-hidden max-h-[90vh] flex flex-col">
        <div class="border-b border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 px-4 py-3 flex justify-between items-center">
            <h2 class="text-base font-bold text-slate-900 dark:text-slate-100 flex items-center gap-2">
                <span class="material-symbols-outlined text-[20px] text-emerald-600">edit</span>
                Edit Daily Expense
            </h2>
            <button id="closeEditExpenseModal" type="button" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 transition">
                <span class="material-symbols-outlined text-[20px]">close</span>
            </button>
        </div>
        <div class="p-4 md:p-5 overflow-y-auto flex-1">
            <form method="POST" enctype="multipart/form-data" id="expenseEditForm" class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="expense_id" id="edit_expense_id">

                <div class="col-span-2">
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Expense Name <span class="text-red-500">*</span></label>
                    <input name="name" id="edit_name" placeholder="Enter expense name"
                         class="w-full border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-1.5 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent" required>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Amount <span class="text-red-500">*</span></label>
                    <input name="amount" id="edit_amount" type="number" step="0.01"
                           placeholder="0.00"
                           class="w-full border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-1.5 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent" required>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Date</label>
                    <input type="date" name="expense_date" id="edit_expense_date"
                         class="w-full border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-1.5 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Location</label>
                    <input name="location" id="edit_location" placeholder="Enter location"
                         class="w-full border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-1.5 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Reason</label>
                    <input name="reason" id="edit_reason" placeholder="Enter reason"
                         class="w-full border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-1.5 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent">
                </div>

                <div class="col-span-2">
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Description</label>
                    <textarea name="description" id="edit_description" placeholder="Enter description" rows="2"
                         class="w-full border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-1.5 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent"></textarea>
                </div>

                <div class="col-span-2">
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Replace Proof Document (Optional)</label>
                    <input type="file" name="proof" id="edit_proof"
                         class="w-full border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-1.5 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent">
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5" id="current_proof">Accepted formats: PDF, JPG, PNG. Leave empty to keep existing proof.</p>
                </div>

                <div class="col-span-2 flex gap-3">
                    <button type="submit" class="bg-emerald-600 text-white px-5 py-2 rounded-lg hover:bg-emerald-700 font-semibold text-sm transition shadow-lg shadow-emerald-500/20">
                        Update Expense
                    </button>
                    <button id="cancelEditExpenseModal" type="button" class="px-5 py-2 border border-slate-300 dark:border-slate-700 rounded-lg text-slate-700 dark:text-slate-300 font-semibold text-sm hover:bg-slate-50 dark:hover:bg-slate-800 transition">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ADD EXPENSE MODAL -->
<div id="expenseModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50 p-4" style="display: none;" data-open="<?= $openAddModal ? 'true' : 'false' ?>">
    <div class="bg-white dark:bg-slate-900 w-full max-w-2xl rounded-xl md:rounded-2xl shadow-2xl overflow-hidden max-h-[90vh] flex flex-col">
        <div class="border-b border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 px-4 py-3 flex justify-between items-center">
            <h2 class="text-base font-bold text-slate-900 dark:text-slate-100 flex items-center gap-2">
                <span class="material-symbols-outlined text-[20px] text-emerald-600">add_circle</span>
                Add Daily Expense
            </h2>
            <button id="closeExpenseModal" type="button" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 transition">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <div class="p-4 md:p-5 overflow-y-auto flex-1">
            <form method="POST" enctype="multipart/form-data" id="expenseAddForm"
                  action=""
                  class="grid grid-cols-1 sm:grid-cols-2 gap-3">

                <input type="hidden" name="action" value="add">

                <div class="col-span-2">
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Expense Name <span class="text-red-500">*</span></label>
                    <input name="name" placeholder="Enter expense name"
                         class="w-full border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-1.5 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent" required>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Amount <span class="text-red-500">*</span></label>
                    <input name="amount" type="number" step="0.01" min="0.01"
                           placeholder="0.00"
                           class="w-full border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-1.5 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent" required>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Date</label>
                    <input type="date" name="expense_date" value="<?= date('Y-m-d') ?>"
                         class="w-full border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-1.5 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Location</label>
                    <input name="location" placeholder="Enter location"
                         class="w-full border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-1.5 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Reason</label>
                    <input name="reason" placeholder="Enter reason"
                         class="w-full border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-1.5 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent">
                </div>

                <div class="col-span-2">
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Description</label>
                    <textarea name="description" placeholder="Enter description" rows="2"
                         class="w-full border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-1.5 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent"></textarea>
                </div>

                <div class="col-span-2">
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Proof Document (Optional)</label>
                    <input type="file" name="proof"
                         class="w-full border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-1.5 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent">
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Accepted formats: PDF, JPG, PNG</p>
                </div>

                <div class="col-span-2 flex gap-3">
                    <button type="submit" class="bg-emerald-600 text-white px-5 py-2 rounded-lg hover:bg-emerald-700 font-semibold text-sm transition shadow-lg shadow-emerald-500/20">
                        Save Expense
                    </button>
                    <button id="cancelExpenseModal" type="button" class="px-5 py-2 border border-slate-300 dark:border-slate-700 rounded-lg text-slate-700 dark:text-slate-300 font-semibold text-sm hover:bg-slate-50 dark:hover:bg-slate-800 transition">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
<?php
if ($isAjax) {
    $html = ob_get_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'html' => $html]);
    exit;
}
?>

</div>
</div>

<?php
if (!$isAjax) {
    include 'include/footer.php';
?>

<style>
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
</style>

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

    // AJAX form submission handler
    function handleFormSubmit(form, callback) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            var formData = new FormData(form);
            var actionAttr = form.getAttribute('action');
            var url = actionAttr ? actionAttr : window.location.href;
            
            // For GET forms, build query string from form data
            if (form.method.toUpperCase() === 'GET') {
                var params = new URLSearchParams();
                for (var pair of formData.entries()) {
                    params.append(pair[0], pair[1]);
                }
                url = url.split('?')[0] + '?' + params.toString();
                
                fetch(url, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(function(response) {
                    return response.text();
                })
                .then(function(text) {
                    var data;
                    try {
                        data = JSON.parse(text);
                    } catch (err) {
                        throw new Error(text || 'Invalid server response');
                    }
                    if (data.success && data.html) {
                        document.getElementById('page-content').innerHTML = data.html;
                        window.initializeAjax();
                        if (callback) callback();
                    } else {
                        alert(data.error || 'Request failed');
                    }
                })
                .catch(function(error) {
                    console.error('Error:', error);
                    alert('Request failed');
                });
            } else {
                fetch(url, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(function(response) {
                    return response.text();
                })
                .then(function(text) {
                    console.log('POST Response:', text);
                    var data;
                    try {
                        data = JSON.parse(text);
                    } catch (err) {
                        throw new Error(text || 'Invalid server response');
                    }
                    if (data.success && data.html) {
                        document.getElementById('page-content').innerHTML = data.html;
                        window.initializeAjax();
                        if (callback) callback();
                    } else {
                        alert(data.error || 'Request failed');
                    }
                })
                .catch(function(error) {
                    console.error('Error:', error);
                    alert('Request failed');
                });
            }
        });
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
                window.initializeAjax();
                history.pushState({}, '', url);
                closeMobileSidebar();
            }
        })
        .catch(function(error) {
            console.error('Error:', error);
        });
    });

    // Initialize AJAX handlers - make it global
    window.initializeAjax = function() {
        // Handle add funds modal
        var fundsModal = document.getElementById('addFundsModal');
        if (fundsModal) {
            var openFundsBtn = document.getElementById('openAddFundsModal');
            var openFundsBtnMobile = document.getElementById('openAddFundsModalMobile');
            var closeFundsBtn = document.getElementById('closeAddFundsModal');
            var cancelFundsBtn = document.getElementById('cancelAddFundsModal');

            function openFundsModal() {
                fundsModal.style.display = 'flex';
                fundsModal.classList.remove('hidden');
                document.body.classList.add('overflow-hidden');
            }

            function closeFundsModal() {
                fundsModal.style.display = 'none';
                fundsModal.classList.add('hidden');
                document.body.classList.remove('overflow-hidden');
            }

            // Always rebind buttons since they're new after AJAX replacement
            if (openFundsBtn && !openFundsBtn.dataset.bound) {
                openFundsBtn.dataset.bound = 'true';
                openFundsBtn.addEventListener('click', openFundsModal);
            }
            
            if (openFundsBtnMobile && !openFundsBtnMobile.dataset.bound) {
                openFundsBtnMobile.dataset.bound = 'true';
                openFundsBtnMobile.addEventListener('click', openFundsModal);
            }
            
            if (closeFundsBtn && !closeFundsBtn.dataset.bound) {
                closeFundsBtn.dataset.bound = 'true';
                closeFundsBtn.addEventListener('click', closeFundsModal);
            }
            
            if (cancelFundsBtn && !cancelFundsBtn.dataset.bound) {
                cancelFundsBtn.dataset.bound = 'true';
                cancelFundsBtn.addEventListener('click', closeFundsModal);
            }
            
            // Bind modal backdrop click only once
            if (!fundsModal.dataset.backdropBound) {
                fundsModal.dataset.backdropBound = 'true';
                fundsModal.addEventListener('click', function(e) {
                    if (e.target === fundsModal) {
                        closeFundsModal();
                    }
                });
            }
        }

        // Handle add funds form
        var addFundsForm = document.getElementById('addFundsForm');
        if (addFundsForm && addFundsForm.dataset.bound !== 'true') {
            addFundsForm.dataset.bound = 'true';
            
            // Add custom validation for funds amount
            var fundsAmountInput = addFundsForm.querySelector('input[name="account_amount"]');
            if (fundsAmountInput) {
                // Prevent negative values and zero
                fundsAmountInput.addEventListener('keydown', function(e) {
                    // Prevent minus sign
                    if (e.key === '-' || e.key === 'Subtract') {
                        e.preventDefault();
                    }
                });
                
                fundsAmountInput.addEventListener('input', function() {
                    var value = parseFloat(this.value);
                    // Clear invalid values
                    if (this.value && (isNaN(value) || value <= 0)) {
                        this.setCustomValidity('Amount must be greater than 0');
                    } else {
                        this.setCustomValidity('');
                    }
                });
                
                fundsAmountInput.addEventListener('blur', function() {
                    var value = parseFloat(this.value);
                    // Force minimum value if entered value is invalid
                    if (this.value && (isNaN(value) || value <= 0)) {
                        this.value = '0.01';
                    }
                });
            }
            
            addFundsForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                var value = parseFloat(fundsAmountInput.value);
                if (isNaN(value) || value <= 0) {
                    alert('Please enter an amount greater than 0');
                    return false;
                }
                
                // Close modal immediately before submission
                var fundsModal = document.getElementById('addFundsModal');
                if (fundsModal) {
                    fundsModal.style.display = 'none';
                    fundsModal.classList.add('hidden');
                }
                document.body.classList.remove('overflow-hidden');
                
                // Submit the form via AJAX
                var formData = new FormData(addFundsForm);
                fetch(window.location.href, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(function(response) {
                    return response.text();
                })
                .then(function(text) {
                    var data;
                    try {
                        data = JSON.parse(text);
                    } catch (err) {
                        throw new Error(text || 'Invalid server response');
                    }
                    if (data.success && data.html) {
                        document.getElementById('page-content').innerHTML = data.html;
                        window.initializeAjax();
                        showMiniToast('Funds added successfully.', 'success');
                    } else {
                        alert(data.error || 'Request failed');
                    }
                })
                .catch(function(error) {
                    console.error('Error:', error);
                    alert('Request failed');
                });
            });
        }

        // Handle filter modal
        var filterModal = document.getElementById('filterModal');
        if (filterModal) {
            var openFilterBtn = document.getElementById('openFilterModal');
            var openFilterBtnMobile = document.getElementById('openFilterModalMobile');
            var closeFilterBtn = document.getElementById('closeFilterModal');
            var cancelFilterBtn = document.getElementById('cancelFilterModal');
            
            function openFilterModalFn() {
                filterModal.style.display = 'flex';
                filterModal.classList.remove('hidden');
                document.body.classList.add('overflow-hidden');
                // Initialize filter inputs
                toggleFilterInputs();
            }
            
            function closeFilterModalFn() {
                filterModal.style.display = 'none';
                filterModal.classList.add('hidden');
                document.body.classList.remove('overflow-hidden');
            }
            
            // Always rebind buttons since they're new after AJAX replacement
            if (openFilterBtn && !openFilterBtn.dataset.bound) {
                openFilterBtn.dataset.bound = 'true';
                openFilterBtn.addEventListener('click', openFilterModalFn);
            }
            
            if (openFilterBtnMobile && !openFilterBtnMobile.dataset.bound) {
                openFilterBtnMobile.dataset.bound = 'true';
                openFilterBtnMobile.addEventListener('click', openFilterModalFn);
            }
            
            if (closeFilterBtn && !closeFilterBtn.dataset.bound) {
                closeFilterBtn.dataset.bound = 'true';
                closeFilterBtn.addEventListener('click', closeFilterModalFn);
            }
            
            if (cancelFilterBtn && !cancelFilterBtn.dataset.bound) {
                cancelFilterBtn.dataset.bound = 'true';
                cancelFilterBtn.addEventListener('click', closeFilterModalFn);
            }
            
            // Bind modal backdrop click only once
            if (!filterModal.dataset.backdropBound) {
                filterModal.dataset.backdropBound = 'true';
                filterModal.addEventListener('click', function(e) {
                    if (e.target === filterModal) {
                        closeFilterModalFn();
                    }
                });
            }
            
            // Filter type toggle functionality
            var filterRadios = document.querySelectorAll('input[name="filter_type"]');
            var dateFilterInput = document.getElementById('dateFilterInput');
            var monthFilterInput = document.getElementById('monthFilterInput');
            var dateInput = document.getElementById('dateInput');
            var monthInput = document.getElementById('monthInput');
            
            function toggleFilterInputs() {
                var selectedFilter = document.querySelector('input[name="filter_type"]:checked');
                if (selectedFilter && selectedFilter.value === 'date') {
                    if (dateFilterInput) dateFilterInput.classList.remove('hidden');
                    if (monthFilterInput) monthFilterInput.classList.add('hidden');
                    if (monthInput) monthInput.value = ''; // Clear month input
                } else if (selectedFilter && selectedFilter.value === 'month') {
                    if (monthFilterInput) monthFilterInput.classList.remove('hidden');
                    if (dateFilterInput) dateFilterInput.classList.add('hidden');
                    if (dateInput) dateInput.value = ''; // Clear date input
                }
            }
            
            filterRadios.forEach(function(radio) {
                radio.addEventListener('change', toggleFilterInputs);
            });
            
            // Initialize on page load
            toggleFilterInputs();
        }

        // Handle edit expense modal
        var editModal = document.getElementById('editExpenseModal');
        if (editModal) {
            var closeEditBtn = document.getElementById('closeEditExpenseModal');
            var cancelEditBtn = document.getElementById('cancelEditExpenseModal');

            function closeEditModal() {
                editModal.style.display = 'none';
                editModal.classList.add('hidden');
                document.body.classList.remove('overflow-hidden');
            }

            // Always rebind buttons since they're new after AJAX replacement
            if (closeEditBtn && !closeEditBtn.dataset.bound) {
                closeEditBtn.dataset.bound = 'true';
                closeEditBtn.addEventListener('click', closeEditModal);
            }
            
            if (cancelEditBtn && !cancelEditBtn.dataset.bound) {
                cancelEditBtn.dataset.bound = 'true';
                cancelEditBtn.addEventListener('click', closeEditModal);
            }
            
            // Bind modal backdrop click only once
            if (!editModal.dataset.backdropBound) {
                editModal.dataset.backdropBound = 'true';
                editModal.addEventListener('click', function(e) {
                    if (e.target === editModal) {
                        closeEditModal();
                    }
                });
            }
        }

        // Handle edit expense form
        var editForm = document.getElementById('expenseEditForm');
        if (editForm && editForm.dataset.bound !== 'true') {
            editForm.dataset.bound = 'true';
            
            editForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                // Close modal immediately before submission
                var editModal = document.getElementById('editExpenseModal');
                if (editModal) {
                    editModal.style.display = 'none';
                    editModal.classList.add('hidden');
                }
                document.body.classList.remove('overflow-hidden');
                
                // Submit the form via AJAX
                var formData = new FormData(editForm);
                fetch(window.location.href, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(function(response) {
                    return response.text();
                })
                .then(function(text) {
                    var data;
                    try {
                        data = JSON.parse(text);
                    } catch (err) {
                        throw new Error(text || 'Invalid server response');
                    }
                    if (data.success && data.html) {
                        document.getElementById('page-content').innerHTML = data.html;
                        window.initializeAjax();
                        showMiniToast('Expense updated successfully.', 'success');
                    } else {
                        alert(data.error || 'Request failed');
                    }
                })
                .catch(function(error) {
                    console.error('Error:', error);
                    alert('Request failed');
                });
            });
        }

        // Handle expense modal
        var modal = document.getElementById('expenseModal');
        if (modal) {
            var openBtn = document.getElementById('openExpenseModal');
            var openBtnMobile = document.getElementById('openExpenseModalMobile');
            var closeBtn = document.getElementById('closeExpenseModal');
            var cancelBtn = document.getElementById('cancelExpenseModal');

            function openModal() {
                modal.style.display = 'flex';
                modal.classList.remove('hidden');
                modal.classList.add('flex');
                document.body.classList.add('overflow-hidden');
            }

            function closeModal() {
                modal.style.display = 'none';
                modal.classList.add('hidden');
                modal.classList.remove('flex');
                document.body.classList.remove('overflow-hidden');
            }

            // Always rebind buttons since they're new after AJAX replacement
            if (openBtn && !openBtn.dataset.bound) {
                openBtn.dataset.bound = 'true';
                openBtn.addEventListener('click', openModal);
            }
            
            if (openBtnMobile && !openBtnMobile.dataset.bound) {
                openBtnMobile.dataset.bound = 'true';
                openBtnMobile.addEventListener('click', openModal);
            }
            
            if (closeBtn && !closeBtn.dataset.bound) {
                closeBtn.dataset.bound = 'true';
                closeBtn.addEventListener('click', closeModal);
            }
            
            if (cancelBtn && !cancelBtn.dataset.bound) {
                cancelBtn.dataset.bound = 'true';
                cancelBtn.addEventListener('click', closeModal);
            }
            
            // Bind modal backdrop click only once
            if (!modal.dataset.backdropBound) {
                modal.dataset.backdropBound = 'true';
                modal.addEventListener('click', function(e) {
                    if (e.target === modal) {
                        closeModal();
                    }
                });
            }

            if (modal.dataset.open === 'true') {
                openModal();
            }
        }

        // Handle expense add form
        var addForm = document.getElementById('expenseAddForm');
        if (addForm && addForm.dataset.bound !== 'true') {
            addForm.dataset.bound = 'true';
            
            addForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                // Close modal immediately before submission
                var modal = document.getElementById('expenseModal');
                if (modal) {
                    modal.style.display = 'none';
                    modal.classList.add('hidden');
                    modal.classList.remove('flex');
                }
                document.body.classList.remove('overflow-hidden');
                
                // Submit the form via AJAX
                var formData = new FormData(addForm);
                fetch(window.location.href, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(function(response) {
                    return response.text();
                })
                .then(function(text) {
                    var data;
                    try {
                        data = JSON.parse(text);
                    } catch (err) {
                        throw new Error(text || 'Invalid server response');
                    }
                    if (data.success && data.html) {
                        document.getElementById('page-content').innerHTML = data.html;
                        window.initializeAjax();
                        showMiniToast('Expense added successfully.', 'success');
                    } else {
                        alert(data.error || 'Request failed');
                    }
                })
                .catch(function(error) {
                    console.error('Error:', error);
                    alert('Request failed');
                });
            });
        }

        // Handle filter form
        var filterForm = document.getElementById('filterForm');
        if (filterForm && filterForm.dataset.bound !== 'true') {
            filterForm.dataset.bound = 'true';
            handleFormSubmit(filterForm, function() {
                var filterModal = document.getElementById('filterModal');
                if (filterModal) {
                    filterModal.style.display = 'none';
                    filterModal.classList.add('hidden');
                    document.body.classList.remove('overflow-hidden');
                }
            });
        }

        // Handle download report modal
        var downloadReportModal = document.getElementById('downloadReportModal');
        if (downloadReportModal && downloadReportModal.dataset.bound !== 'true') {
            downloadReportModal.dataset.bound = 'true';
            var openDownloadReportBtn = document.getElementById('openDownloadReportModal');
            var openDownloadReportBtnMobile = document.getElementById('openDownloadReportModalMobile');
            var closeDownloadReportBtn = document.getElementById('closeDownloadReportModal');
            var cancelDownloadReportBtn = document.getElementById('cancelDownloadReportModal');
            var downloadReportForm = document.getElementById('downloadReportForm');

            function openDownloadReportModal() {
                downloadReportModal.style.display = 'flex';
                downloadReportModal.classList.remove('hidden');
                document.body.classList.add('overflow-hidden');
            }

            function closeDownloadReportModal() {
                downloadReportModal.style.display = 'none';
                downloadReportModal.classList.add('hidden');
                document.body.classList.remove('overflow-hidden');
            }

            if (openDownloadReportBtn) {
                openDownloadReportBtn.addEventListener('click', openDownloadReportModal);
            }
            if (openDownloadReportBtnMobile) {
                openDownloadReportBtnMobile.addEventListener('click', openDownloadReportModal);
            }
            if (closeDownloadReportBtn) {
                closeDownloadReportBtn.addEventListener('click', closeDownloadReportModal);
            }
            if (cancelDownloadReportBtn) {
                cancelDownloadReportBtn.addEventListener('click', closeDownloadReportModal);
            }
            downloadReportModal.addEventListener('click', function(e) {
                if (e.target === downloadReportModal) {
                    closeDownloadReportModal();
                }
            });

            // Handle print report form submission
            if (downloadReportForm) {
                downloadReportForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    var reportMonth = document.getElementById('reportMonthInput').value;
                    var url = 'daily-expenses.php?download_report=1&report_month=' + encodeURIComponent(reportMonth);
                    
                    // Close modal before printing
                    closeDownloadReportModal();
                    
                    // Create or reuse hidden iframe for printing
                    var printIframe = document.getElementById('printReportIframe');
                    if (!printIframe) {
                        printIframe = document.createElement('iframe');
                        printIframe.id = 'printReportIframe';
                        printIframe.style.display = 'none';
                        document.body.appendChild(printIframe);
                    }
                    
                    // Load the report in iframe and print
                    printIframe.onload = function() {
                        setTimeout(function() {
                            printIframe.contentWindow.focus();
                            printIframe.contentWindow.print();
                        }, 500);
                    };
                    printIframe.src = url;
                });
            }
        }

        // All ajax-link handling is done via event delegation above
    };

    // Initialize on page load
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', window.initializeAjax);
    } else {
        window.initializeAjax();
    }

    // Handle browser back/forward
    window.addEventListener('popstate', function() {
        location.reload();
    });
})();

// Toast notification function
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

// Edit Expense Function
function editExpense(id) {
    // Fetch expense data
    fetch('daily-expenses.php?get_expense=' + id, {
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (data.success) {
            var expense = data.expense;
            document.getElementById('edit_expense_id').value = expense.id;
            document.getElementById('edit_name').value = expense.name;
            document.getElementById('edit_amount').value = expense.amount;
            document.getElementById('edit_expense_date').value = expense.expense_date;
            document.getElementById('edit_location').value = expense.location;
            document.getElementById('edit_reason').value = expense.reason;
            document.getElementById('edit_description').value = expense.description;
            
            var currentProof = document.getElementById('current_proof');
            if (expense.proof) {
                currentProof.textContent = 'Current proof: ' + expense.proof + '. Accepted formats: PDF, JPG, PNG. Leave empty to keep existing proof.';
            } else {
                currentProof.textContent = 'Accepted formats: PDF, JPG, PNG. Leave empty to keep existing proof.';
            }
            
            var editModal = document.getElementById('editExpenseModal');
            editModal.style.display = 'flex';
            editModal.classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
        } else {
            alert('Failed to load expense data');
        }
    })
    .catch(function(error) {
        console.error('Error:', error);
        alert('Failed to load expense data');
    });
}

// Delete Expense Function - Store pending delete data
var pendingDelete = null;

function deleteExpense(id, name) {
    pendingDelete = { id: id, name: name };
    document.getElementById('deleteExpenseName').textContent = '"' + name + '"';
    var modal = document.getElementById('deleteConfirmModal');
    modal.style.display = 'flex';
    modal.classList.remove('hidden');
    document.body.classList.add('overflow-hidden');
    
    // Add click-outside-to-close handler
    setTimeout(function() {
        modal.addEventListener('click', handleDeleteModalClick);
    }, 100);
}

function handleDeleteModalClick(e) {
    var modal = document.getElementById('deleteConfirmModal');
    if (e.target === modal) {
        closeDeleteModal();
    }
}

function closeDeleteModal() {
    pendingDelete = null;
    var modal = document.getElementById('deleteConfirmModal');
    modal.removeEventListener('click', handleDeleteModalClick);
    modal.style.display = 'none';
    modal.classList.add('hidden');
    document.body.classList.remove('overflow-hidden');
}

function confirmDelete() {
    if (!pendingDelete) return;
    
    var formData = new FormData();
    formData.append('action', 'delete');
    formData.append('expense_id', pendingDelete.id);
    
    // Close modal immediately
    closeDeleteModal();
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(function(response) {
        return response.text();
    })
    .then(function(text) {
        var data;
        try {
            data = JSON.parse(text);
        } catch (err) {
            throw new Error(text || 'Invalid server response');
        }
        if (data.success && data.html) {
            document.getElementById('page-content').innerHTML = data.html;
            window.initializeAjax();
        } else {
            alert(data.error || 'Delete failed');
        }
    })
    .catch(function(error) {
        console.error('Error:', error);
        alert('Delete failed');
    });
}

// Proof Preview Functions
function showProof(filePath, name) {
    // Extract just the filename from the path
    var filename = filePath.split('/').pop();
    
    // Check file extension
    var ext = filename.split('.').pop().toLowerCase();
    var imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
    
    // If it's an image, show in preview modal
    if (imageExtensions.indexOf(ext) !== -1) {
        var modal = document.getElementById('proofModal');
        var title = document.getElementById('proofTitle');
        var img = document.getElementById('proofImage');
        var downloadLink = document.getElementById('proofDownloadLink');
        
        title.textContent = 'Proof Document - ' + name;
        
        img.src = filePath;
        img.style.display = 'block';
        
        // Set download link
        downloadLink.href = filePath;
        downloadLink.download = name + '_proof.' + ext;
        
        modal.style.display = 'flex';
        document.body.classList.add('overflow-hidden');
    } else {
        // For non-image files, use PHP proxy to serve the file in same tab
        var proxyUrl = 'daily-expenses.php?download_proof=' + encodeURIComponent(filename);
        window.location.href = proxyUrl;
    }
}

function closeProof() {
    var modal = document.getElementById('proofModal');
    modal.style.display = 'none';
    document.body.classList.remove('overflow-hidden');
    
    // Clear image source and download link
    document.getElementById('proofImage').src = '';
    document.getElementById('proofImage').style.display = 'none';
    document.getElementById('proofDownloadLink').href = '';
    document.getElementById('proofDownloadLink').download = '';
}

// Close modal on outside click
document.addEventListener('click', function(e) {
    var modal = document.getElementById('proofModal');
    if (e.target === modal) {
        closeProof();
    }
});

// Close modal on ESC key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeProof();
    }
});
</script>

<?php
}
?>
