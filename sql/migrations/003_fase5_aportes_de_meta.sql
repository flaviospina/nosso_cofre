-- Migração 003 — Fase 5 (recorrências, orçamento, metas, plano de ação)
-- Instalações novas não precisam deste arquivo: sql/schema.sql já contém tudo.
-- Instalações anteriores: aplique pela tela /sistema/migrar?token=SEU_CRON_TOKEN ou importe no phpMyAdmin.

-- Aportes em metas (histórico de quem guardou quanto e quando)
CREATE TABLE IF NOT EXISTS goal_contributions (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  household_id   INT UNSIGNED NOT NULL,
  goal_id        INT UNSIGNED NOT NULL,
  user_id        INT UNSIGNED NULL,
  amount         DECIMAL(12,2) NOT NULL COMMENT 'negativo = retirada',
  date           DATE NOT NULL,
  note           VARCHAR(190) NULL,
  transaction_id BIGINT UNSIGNED NULL COMMENT 'transferência ligada ao aporte, quando houver',
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_contrib_goal (goal_id, date),
  KEY idx_contrib_household (household_id),
  CONSTRAINT fk_contrib_household FOREIGN KEY (household_id) REFERENCES households (id) ON DELETE CASCADE,
  CONSTRAINT fk_contrib_goal FOREIGN KEY (goal_id) REFERENCES goals (id) ON DELETE CASCADE,
  CONSTRAINT fk_contrib_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_contrib_transaction FOREIGN KEY (transaction_id) REFERENCES transactions (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (`key`, `value`, description) VALUES ('schema.version', '3', 'Versão do esquema aplicada')
ON DUPLICATE KEY UPDATE `value` = '3';
