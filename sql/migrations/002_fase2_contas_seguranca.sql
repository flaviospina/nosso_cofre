-- Migração 002 — Fase 2 (contas e segurança)
-- Instalações novas não precisam deste arquivo: sql/schema.sql já contém tudo.
-- Instalações da fase 1: aplique pela tela /sistema/migrar?token=SEU_CRON_TOKEN ou importe no phpMyAdmin.

-- Último contador TOTP aceito (impede reuso do mesmo código dentro da janela)
ALTER TABLE users ADD COLUMN totp_last_counter BIGINT UNSIGNED NULL AFTER totp_recovery_codes;

-- Identificação do aparelho nas tentativas de login (aviso de "novo dispositivo")
ALTER TABLE login_attempts ADD COLUMN device_hash CHAR(64) NULL AFTER user_agent;
ALTER TABLE login_attempts ADD INDEX idx_attempts_device (email, device_hash, succeeded);

-- Convites: quantas vezes o e-mail foi reenviado
ALTER TABLE invitations ADD COLUMN sent_count TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER expires_at;

INSERT INTO settings (`key`, `value`, description) VALUES ('schema.version', '2', 'Versão do esquema aplicada')
ON DUPLICATE KEY UPDATE `value` = '2';
