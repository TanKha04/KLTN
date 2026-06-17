<?php
require 'config.php';

try {
    $pdo->beginTransaction();

    // Migrate conversations
    $stmt = $pdo->query("SELECT id, user_id, created_at, updated_at FROM chatbot_conversations");
    $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $insertConv = $pdo->prepare("INSERT IGNORE INTO ai_conversations (id, user_id, title, created_at, updated_at) VALUES (?, ?, ?, ?, ?)");
    foreach ($conversations as $conv) {
        $insertConv->execute([
            $conv['id'],
            $conv['user_id'],
            'Tư vấn y tế cũ',
            $conv['created_at'],
            $conv['updated_at']
        ]);
    }

    // Migrate messages
    $stmt = $pdo->query("SELECT id, conversation_id, role, content, created_at FROM chatbot_messages");
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $insertMsg = $pdo->prepare("INSERT IGNORE INTO ai_messages (id, conversation_id, role, content, source, created_at) VALUES (?, ?, ?, ?, ?, ?)");
    foreach ($messages as $msg) {
        $mapped_role = ($msg['role'] === 'user') ? 'user' : 'model';
        $source = ($msg['role'] === 'user') ? 'user' : 'bot';
        $insertMsg->execute([
            $msg['id'],
            $msg['conversation_id'],
            $mapped_role,
            $msg['content'],
            $source,
            $msg['created_at']
        ]);
    }

    $pdo->commit();
    echo "Migration successful!";
} catch (Exception $e) {
    $pdo->rollBack();
    echo "Migration failed: " . $e->getMessage();
}
