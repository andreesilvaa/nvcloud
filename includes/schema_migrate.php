<?php
/**
 * Migrações leves idempotentes — corre uma vez por pedido HTTP.
 */
if (!isset($pdo) || !($pdo instanceof PDO)) {
    return;
}

try {
    $cols = $pdo->query("SHOW COLUMNS FROM utilizadores LIKE 'must_change_password'")->fetch();
    if (!$cols) {
        $pdo->exec("ALTER TABLE utilizadores ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER password");
    }
} catch (Throwable $e) {
    error_log('[nvcloud] schema: must_change_password — ' . $e->getMessage());
}

try {
    $cols = $pdo->query("SHOW COLUMNS FROM envios LIKE 'ficheiro_path'")->fetch();
    if (!$cols) {
        $pdo->exec("ALTER TABLE envios ADD COLUMN ficheiro_path VARCHAR(512) NULL AFTER parceiro");
    }
} catch (Throwable $e) {
    error_log('[nvcloud] schema: envios.ficheiro_path — ' . $e->getMessage());
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS notificacoes_lidas (
        user_id INT NOT NULL,
        chave CHAR(32) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, chave)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {
    error_log('[nvcloud] schema: notificacoes_lidas — ' . $e->getMessage());
}

try {
    // Esta tabela era usada pela página Análises ("As minhas notificações")
    // mas nunca tinha sido incluída nas migrações — só existia no ambiente
    // local onde foi criada manualmente. Em produção isto causava um erro
    // fatal (PDOException sem catch) sempre que alguém abria a página.
    $pdo->exec("CREATE TABLE IF NOT EXISTS notificacoes_personalizadas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        titulo VARCHAR(120) NOT NULL,
        mensagem VARCHAR(255) NOT NULL,
        link VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_notif_pers_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {
    error_log('[nvcloud] schema: notificacoes_personalizadas — ' . $e->getMessage());
}
