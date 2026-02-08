<?php
error_reporting(E_ALL & ~E_DEPRECATED);
require_once 'auth.php';
requireLogin();

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$feedbackFile = DB_DIR . 'feedback.json';

// Initialize feedback file if not exists
if (!file_exists($feedbackFile)) {
    file_put_contents($feedbackFile, json_encode([]));
}

function loadFeedback() {
    global $feedbackFile;
    $data = file_get_contents($feedbackFile);
    return json_decode($data, true) ?: [];
}

function saveFeedback($data) {
    global $feedbackFile;
    return file_put_contents($feedbackFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

// POST: Submit feedback
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    // If "action" is delete, handle it (Admin only)
    if (isset($input['action']) && $input['action'] === 'delete') {
        requireAdmin();
        $id = $input['id'] ?? null;
        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'ID required']);
            exit;
        }

        $allFeedback = loadFeedback();
        $newFeedback = array_filter($allFeedback, function($item) use ($id) {
            return $item['id'] !== $id;
        });

        if (count($allFeedback) === count($newFeedback)) {
             echo json_encode(['success' => false, 'error' => 'Feedback not found']);
             exit;
        }

        // Re-index array
        $newFeedback = array_values($newFeedback);
        saveFeedback($newFeedback);
        echo json_encode(['success' => true]);
        exit;
    }

    // Otherwise, it's a new submission
    $type = $input['type'] ?? 'suggestion';
    $message = trim($input['message'] ?? '');

    if (empty($message)) {
        echo json_encode(['success' => false, 'error' => 'Message cannot be empty']);
        exit;
    }

    $user = getCurrentUser();
    $newEntry = [
        'id' => uniqid(),
        'user' => $user['username'] ?? 'Unknown',
        'type' => $type,
        'message' => $message,
        'timestamp' => time(),
        'date' => date('Y-m-d H:i:s')
    ];

    $allFeedback = loadFeedback();
    // Prepend to show newest first
    array_unshift($allFeedback, $newEntry);

    if (saveFeedback($allFeedback)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to save feedback']);
    }
    exit;
}

// GET: List feedback (Admin only)
if ($method === 'GET') {
    requireAdmin();
    $allFeedback = loadFeedback();
    echo json_encode(['success' => true, 'feedback' => $allFeedback]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid request']);
