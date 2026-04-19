<?php
// Start output buffering to catch any stray output/errors
ob_start();

error_reporting(0);
ini_set('display_errors', 0);

require 'config/auth.php';
require 'config/database.php';
require 'config/constants.php';

$action = $_GET['action'] ?? 'list';
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// If this is an AJAX request, clear any buffered output before sending JSON
if ($isAjax) {
    ob_clean();
}

/* ============================
   HANDLE CREATE NOTIFICATION
============================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add') {

    $message = trim($_POST['message'] ?? '');
    $replyTo = isset($_POST['reply_to']) && is_numeric($_POST['reply_to']) ? (int)$_POST['reply_to'] : null;
    $success = false;
    $attachment = null;

    // Handle image upload
    if (!empty($_FILES['image']['name'])) {
        $uploadDir = 'uploads/chat/';
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $maxSize = 2 * 1024 * 1024; // 2MB
        
        $fileName = $_FILES['image']['name'];
        $fileTmp = $_FILES['image']['tmp_name'];
        $fileSize = $_FILES['image']['size'];
        $fileError = $_FILES['image']['error'];
        
        if ($fileError === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            
            if (in_array($ext, $allowed, true)) {
                if ($fileSize <= $maxSize) {
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0775, true);
                    }
                    
                    $attachment = time() . '_' . uniqid() . '.' . $ext;
                    
                    if (!move_uploaded_file($fileTmp, $uploadDir . $attachment)) {
                        $attachment = null;
                        error_log('Failed to move uploaded file');
                    }
                } else {
                    error_log('File size exceeds 2MB limit');
                }
            } else {
                error_log('Invalid file type: ' . $ext);
            }
        } else {
            error_log('File upload error: ' . $fileError);
        }
    }

    // Allow message-only or image-only or both
    if ($message !== '' || $attachment !== null) {
        $subject = 'User Request';
        
        // Get the current logged-in user's name
        $username = 'User'; // Default
        $userId = $_SESSION['user_id'] ?? 0;
        
        if ($userId > 0) {
            try {
                // Fetch name from database using prepared statement
                $userStmt = $conn->prepare("SELECT name FROM users WHERE id = ?");
                if ($userStmt) {
                    $userStmt->bind_param("i", $userId);
                    $userStmt->execute();
                    $userResult = $userStmt->get_result();
                    if ($userResult && $userResult->num_rows > 0) {
                        $user = $userResult->fetch_assoc();
                        $username = !empty($user['name']) ? trim($user['name']) : 'User';
                    }
                    $userStmt->close();
                }
            } catch (Exception $e) {
                error_log('User fetch error: ' . $e->getMessage());
                $username = 'User';
            }
        }
        
        // Trim username to ensure it's clean
        $username = trim($username) ?: 'User';
        
        try {
            // Check if created_by and attachment columns exist
            $checkColumn = $conn->query("SHOW COLUMNS FROM notifications LIKE 'created_by'");
            $hasCreatedBy = ($checkColumn && $checkColumn->num_rows > 0);
            
            $checkAttachment = $conn->query("SHOW COLUMNS FROM notifications LIKE 'attachment'");
            $hasAttachment = ($checkAttachment && $checkAttachment->num_rows > 0);
            
            $checkReplyTo = $conn->query("SHOW COLUMNS FROM notifications LIKE 'reply_to'");
            $hasReplyTo = ($checkReplyTo && $checkReplyTo->num_rows > 0);
            
            if ($hasCreatedBy && $hasAttachment && $hasReplyTo) {
                // New structure with created_by, attachment, and reply_to columns
                $stmt = $conn->prepare("INSERT INTO notifications (subject, description, created_by, attachment, reply_to) VALUES (?, ?, ?, ?, ?)");
                if ($stmt) {
                    $stmt->bind_param("ssssi", $subject, $message, $username, $attachment, $replyTo);
                    $success = $stmt->execute();
                    $insertedId = $conn->insert_id;
                    $stmt->close();
                    
                    if ($success) {
                        error_log("Message inserted successfully. ID: $insertedId, User: $username, Message: $message, Attachment: $attachment, ReplyTo: $replyTo");
                    }
                }
            } else if ($hasCreatedBy && $hasAttachment) {
                // Structure with created_by and attachment only
                $stmt = $conn->prepare("INSERT INTO notifications (subject, description, created_by, attachment) VALUES (?, ?, ?, ?)");
                if ($stmt) {
                    $stmt->bind_param("ssss", $subject, $message, $username, $attachment);
                    $success = $stmt->execute();
                    $insertedId = $conn->insert_id;
                    $stmt->close();
                    
                    if ($success) {
                        error_log("Message inserted successfully. ID: $insertedId, User: $username, Message: $message, Attachment: $attachment");
                    }
                }
            } else if ($hasCreatedBy) {
                // Structure with created_by only
                $stmt = $conn->prepare("INSERT INTO notifications (subject, description, created_by) VALUES (?, ?, ?)");
                if ($stmt) {
                    $stmt->bind_param("sss", $subject, $message, $username);
                    $success = $stmt->execute();
                    $insertedId = $conn->insert_id;
                    $stmt->close();
                    
                    if ($success) {
                        error_log("Message inserted successfully. ID: $insertedId, User: $username, Message: $message");
                    }
                }
            } else {
                // Old structure without created_by column
                $stmt = $conn->prepare("INSERT INTO notifications (subject, description) VALUES (?, ?)");
                if ($stmt) {
                    $stmt->bind_param("ss", $subject, $message);
                    $success = $stmt->execute();
                    $insertedId = $conn->insert_id;
                    $stmt->close();
                    
                    if ($success) {
                        error_log("Message inserted successfully (no created_by). ID: $insertedId, Message: $message");
                    }
                }
            }
            
            // No need to manually commit if autocommit is enabled (default in MySQL)
            // The execute() already commits the transaction
        } catch (Exception $e) {
            error_log('Insert error: ' . $e->getMessage());
            $success = false;
        }
    } else {
        error_log('Both message and attachment are empty, not inserting');
    }

    if ($isAjax) {
        // Clean output buffer and send JSON
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/json');
        echo json_encode([
            'success' => $success, 
            'message' => $success ? 'Message sent successfully' : 'Failed to send message'
        ]);
        exit;
    }
    
    header("Location: notifications.php");
    exit;
}

/* ============================
   HANDLE PANEL VIEW (AJAX)
============================ */
if ($action === 'panel' && $isAjax) {
    $currentUserId = $_SESSION['user_id'] ?? 0;
    
    // Check if read_by_users column exists
    $checkColumn = $conn->query("SHOW COLUMNS FROM notifications LIKE 'read_by_users'");
    
    if ($checkColumn && $checkColumn->num_rows > 0) {
        // Mark all as read for current user if column exists
        $conn->query("UPDATE notifications SET read_by_users = CONCAT(COALESCE(read_by_users, ''), ',$currentUserId,') WHERE (read_by_users NOT LIKE '%,$currentUserId,%' OR read_by_users IS NULL)");
    }
    
    // Simple query - fetch all notifications (ASC so newest messages appear at bottom for chat)
    $notifications = $conn->query("SELECT * FROM notifications ORDER BY created_at ASC LIMIT 50");
    
    if (!$notifications) {
        // If query fails, send error response
        error_log('Notifications query failed: ' . $conn->error);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Database query failed',
            'html' => '<div class="text-center py-8 text-red-500"><p>Error loading messages</p></div>',
            'count' => 0
        ]);
        exit;
    }
    
    error_log('Query successful, rows found: ' . $notifications->num_rows);
    
    // Start fresh buffer for HTML generation
    while (ob_get_level() > 1) {
        ob_end_clean();
    }
    ob_start();
    
    if ($notifications && $notifications->num_rows > 0):
        while ($n = $notifications->fetch_assoc()):
            // Get sender name - from created_by or default to 'User'
            $senderName = !empty($n['created_by']) ? trim($n['created_by']) : 'User';
            
            // Fetch replied message details if this is a reply
            $repliedMsg = null;
            if (!empty($n['reply_to'])) {
                $replyStmt = $conn->prepare("SELECT id, description, created_by, attachment FROM notifications WHERE id = ? LIMIT 1");
                if ($replyStmt) {
                    $replyStmt->bind_param("i", $n['reply_to']);
                    $replyStmt->execute();
                    $replyResult = $replyStmt->get_result();
                    if ($replyResult && $replyResult->num_rows > 0) {
                        $repliedMsg = $replyResult->fetch_assoc();
                    }
                    $replyStmt->close();
                }
            }
            
            // Determine if current user is sender
            $isCurrentUser = false;
            if ($currentUserId > 0) {
                try {
                    $userCheckStmt = $conn->prepare("SELECT name FROM users WHERE id = ?");
                    if ($userCheckStmt) {
                        $userCheckStmt->bind_param("i", $currentUserId);
                        $userCheckStmt->execute();
                        $userCheckResult = $userCheckStmt->get_result();
                        if ($userCheckResult && $userCheckResult->num_rows > 0) {
                            $currentUser = $userCheckResult->fetch_assoc();
                            $currentUserName = !empty($currentUser['name']) ? trim($currentUser['name']) : '';
                            $isCurrentUser = ($senderName === $currentUserName);
                        }
                        $userCheckStmt->close();
                    }
                } catch (Exception $e) {
                    error_log('User check error: ' . $e->getMessage());
                }
            }
?>
        <div class="mb-2 md:mb-3" data-message-id="<?= $n['id'] ?>" data-message-user="<?= htmlspecialchars($senderName) ?>">
            <div class="flex items-start gap-1.5 md:gap-2">
                <?php 
                    $initial = strtoupper(substr($senderName, 0, 1));
                    $colors = ['bg-blue-500', 'bg-green-500', 'bg-purple-500', 'bg-pink-500', 'bg-indigo-500', 'bg-red-500'];
                    $colorIndex = ord($initial) % count($colors);
                    $bgColor = $colors[$colorIndex];
                ?>
                <div class="<?= $bgColor ?> text-white rounded-full w-7 h-7 md:w-8 md:h-8 flex items-center justify-center text-xs md:text-sm font-semibold flex-shrink-0">
                    <?= $initial ?>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-1.5 md:gap-2 mb-1 md:mb-2 flex-wrap">
                        <span class="text-xs md:text-sm font-bold <?= $isCurrentUser ? 'text-blue-600 dark:text-blue-400' : 'text-gray-900 dark:text-gray-100' ?> truncate max-w-[150px] md:max-w-none">
                            <?= htmlspecialchars($senderName) ?>
                        </span>
                        <?= $isCurrentUser ? '<span class="text-[10px] md:text-xs bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 px-1.5 md:px-2 py-0.5 rounded-full font-medium">(You)</span>' : '' ?>
                        <span class="text-[10px] md:text-xs text-gray-400 dark:text-gray-500 ml-auto whitespace-nowrap">
                            <?php
                            $timestamp = strtotime($n['created_at']);
                            echo date('M j, g:i A', $timestamp);
                            ?>
                        </span>
                    </div>
                    <div class="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg rounded-tl-none p-2 md:p-3 shadow-sm">
                        <?php if ($repliedMsg): ?>
                            <div class="bg-blue-50 dark:bg-blue-900/20 border-l-4 border-blue-500 rounded px-2 py-1.5 md:px-3 md:py-2 mb-1.5 md:mb-2 text-xs flex gap-1.5 md:gap-2">
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-1 mb-0.5 md:mb-1">
                                        <svg class="w-3 h-3 text-blue-600 dark:text-blue-400 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M7.707 3.293a1 1 0 010 1.414L5.414 7H11a7 7 0 017 7v2a1 1 0 11-2 0v-2a5 5 0 00-5-5H5.414l2.293 2.293a1 1 0 11-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                        </svg>
                                        <span class="font-semibold text-blue-700 dark:text-blue-300 text-[10px] md:text-xs truncate"><?= htmlspecialchars($repliedMsg['created_by'] ?? 'User') ?></span>
                                    </div>
                                    <?php if (!empty($repliedMsg['attachment']) && empty($repliedMsg['description'])): ?>
                                        <div class="flex items-center gap-1 text-gray-600 dark:text-gray-400 text-[10px] md:text-xs">
                                            <svg class="w-3 h-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M4 3a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V5a2 2 0 00-2-2H4zm12 12H4l4-8 3 6 2-4 3 6z" clip-rule="evenodd"></path>
                                            </svg>
                                            <span class="italic">Image</span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($repliedMsg['description'])): ?>
                                        <p class="text-gray-700 dark:text-gray-300 line-clamp-2 text-[10px] md:text-xs"><?= htmlspecialchars(substr($repliedMsg['description'], 0, 100)) ?><?= strlen($repliedMsg['description']) > 100 ? '...' : '' ?></p>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($repliedMsg['attachment'])): ?>
                                    <div class="flex-shrink-0">
                                        <img src="uploads/chat/<?= htmlspecialchars($repliedMsg['attachment']) ?>" 
                                             alt="Preview" 
                                             data-chat-image="1"
                                             class="w-10 h-10 md:w-12 md:h-12 rounded object-cover cursor-pointer border-2 border-blue-300 dark:border-blue-600 hover:border-blue-500 dark:hover:border-blue-400 transition-colors"
                                             onclick="openImageModal(this.src); event.stopPropagation();">
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($n['attachment'])): ?>
                            <div class="mb-1.5 md:mb-2">
                                <img src="uploads/chat/<?= htmlspecialchars($n['attachment']) ?>" 
                                     alt="Attachment" 
                                     data-chat-image="1"
                                     class="max-w-full rounded-lg cursor-pointer hover:opacity-90 transition-opacity"
                                     style="max-height: 200px; object-fit: contain;"
                                     onclick="openImageModal(this.src); event.preventDefault(); event.stopPropagation();">
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($n['description'])): ?>
                            <p class="text-gray-800 dark:text-gray-200 text-xs md:text-sm break-words"><?= nl2br(htmlspecialchars($n['description'])) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
<?php
        endwhile;
    else:
?>
        <div class="text-center py-6 md:py-8 text-gray-500 dark:text-gray-400 px-4">
            <svg class="w-12 h-12 md:w-16 md:h-16 mx-auto mb-2 md:mb-3 text-gray-300 dark:text-gray-600" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M18 10c0 3.866-3.582 7-8 7a8.841 8.841 0 01-4.083-.98L2 17l1.338-3.123C2.493 12.767 2 11.434 2 10c0-3.866 3.582-7 8-7s8 3.134 8 7zM7 9H5v2h2V9zm8 0h-2v2h2V9zM9 9h2v2H9V9z" clip-rule="evenodd"></path>
            </svg>
            <p class="text-xs md:text-sm font-medium">No messages yet</p>
            <p class="text-[10px] md:text-xs mt-1">Start the conversation below</p>
        </div>
<?php
    endif;
    
    $html = ob_get_clean();
    
    error_log('Generated HTML length: ' . strlen($html));
    error_log('First 200 chars: ' . substr($html, 0, 200));
    
    // Clear remaining buffers but keep one for the JSON response
    while (ob_get_level() > 1) {
        ob_end_clean();
    }
    
    // Clear the last buffer and send JSON
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'html' => $html,
        'count' => $notifications->num_rows
    ]);
    ob_end_flush();
    exit;
}

/* ============================
   HANDLE COUNT (AJAX)
============================ */
if ($action === 'count' && $isAjax) {
    // Check if read_by_users column exists
    $checkColumn = $conn->query("SHOW COLUMNS FROM notifications LIKE 'read_by_users'");
    
    if ($checkColumn && $checkColumn->num_rows > 0) {
        // Count unread with read_by_users column
        $userId = $_SESSION['user_id'] ?? 0;
        $result = $conn->query("SELECT COUNT(*) as count FROM notifications WHERE (read_by_users NOT LIKE '%,$userId,%' OR read_by_users IS NULL)");
    } else {
        // Count all recent messages if no read tracking
        $result = $conn->query("SELECT COUNT(*) as count FROM notifications WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    }
    
    $row = $result->fetch_assoc();
    
    // Clear all output buffers before sending JSON
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'count' => (int)$row['count']
    ]);
    exit;
}

/* ============================
   REDIRECT TO DASHBOARD
   (Notifications now in panel)
============================ */
header("Location: dashboard.php");
exit;
?>
