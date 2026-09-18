-- ============================================================================
-- Nosso Cofre v2 — esquema do banco (MySQL 8.0 / MariaDB 10.6+)
-- Idempotente: pode ser importado mais de uma vez (CREATE TABLE IF NOT EXISTS).
-- Convenções: DATETIME sempre em UTC (a aplicação converte para o fuso do usuário);
--             DATE para datas de calendário (lançamentos); DECIMAL(12,2) para dinheiro;
--             deleted_at = soft delete (lixeira com restauração).
-- Importar no phpMyAdmin: selecione o banco → Importar → este arquivo.
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO';

-- ---------------------------------------------------------------------------
-- Usuários e lares
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS users (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name                 VARCHAR(120) NOT NULL,
  email                VARCHAR(190) NOT NULL,
  email_verified_at    DATETIME NULL,
  password_hash        VARCHAR(255) NOT NULL,
  totp_secret          VARCHAR(255) NULL COMMENT 'segredo TOTP criptografado (Crypto v1)',
  totp_enabled_at      DATETIME NULL,
  totp_recovery_codes  JSON NULL COMMENT 'hashes dos códigos de recuperação',
  totp_last_counter    BIGINT UNSIGNED NULL COMMENT 'último contador TOTP aceito (anti-reuso)',
  color                CHAR(7) NOT NULL DEFAULT '#0d6efd' COMMENT 'cor do membro em gráficos e badges',
  timezone             VARCHAR(64) NOT NULL DEFAULT 'America/Sao_Paulo',
  locale               VARCHAR(10) NOT NULL DEFAULT 'pt_BR',
  document             VARCHAR(255) NULL COMMENT 'CPF opcional, criptografado',
  adult_confirmed_at   DATETIME NULL COMMENT 'confirmação de maioridade no cadastro',
  status               ENUM('active','pending_deletion','anonymized','blocked') NOT NULL DEFAULT 'active',
  session_idle_minutes TINYINT UNSIGNED NOT NULL DEFAULT 30,
  last_login_at        DATETIME NULL,
  last_login_ip        VARCHAR(45) NULL,
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  deleted_at           DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_users_email (email),
  KEY idx_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS households (
  id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name                   VARCHAR(120) NOT NULL,
  type                   ENUM('individual','family') NOT NULL DEFAULT 'individual',
  currency               CHAR(3) NOT NULL DEFAULT 'BRL',
  fiscal_month_start_day TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'dia em que começa o mês financeiro (1-28)',
  settings               JSON NULL COMMENT 'ex.: {"members_can_edit_others": false}',
  owner_user_id          INT UNSIGNED NOT NULL,
  status                 ENUM('active','pending_deletion') NOT NULL DEFAULT 'active',
  deletion_scheduled_at  DATETIME NULL,
  created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  deleted_at             DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_households_owner (owner_user_id),
  CONSTRAINT fk_households_owner FOREIGN KEY (owner_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS household_members (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  household_id     INT UNSIGNED NOT NULL,
  user_id          INT UNSIGNED NOT NULL,
  role             ENUM('owner','admin','member','viewer') NOT NULL DEFAULT 'member',
  estimated_income DECIMAL(12,2) NULL COMMENT 'renda mensal estimada (opcional)',
  invited_by       INT UNSIGNED NULL,
  joined_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  left_at          DATETIME NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_members_household_user (household_id, user_id),
  KEY idx_members_user (user_id),
  CONSTRAINT fk_members_household FOREIGN KEY (household_id) REFERENCES households (id) ON DELETE CASCADE,
  CONSTRAINT fk_members_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT fk_members_invited_by FOREIGN KEY (invited_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invitations (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  household_id     INT UNSIGNED NOT NULL,
  email            VARCHAR(190) NOT NULL,
  token_hash       CHAR(64) NOT NULL,
  role             ENUM('admin','member','viewer') NOT NULL DEFAULT 'member',
  invited_by       INT UNSIGNED NOT NULL,
  expires_at       DATETIME NOT NULL,
  sent_count       TINYINT UNSIGNED NOT NULL DEFAULT 1,
  accepted_at      DATETIME NULL,
  accepted_user_id INT UNSIGNED NULL,
  revoked_at       DATETIME NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_invitations_token (token_hash),
  KEY idx_invitations_household (household_id),
  KEY idx_invitations_email (email),
  CONSTRAINT fk_invitations_household FOREIGN KEY (household_id) REFERENCES households (id) ON DELETE CASCADE,
  CONSTRAINT fk_invitations_invited_by FOREIGN KEY (invited_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- LGPD: consentimentos, exportações, exclusões, incidentes
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS consents (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id          INT UNSIGNED NOT NULL,
  kind             VARCHAR(40) NOT NULL COMMENT 'terms, privacy, adult, push, transactional_email, digest_email, share_with_household',
  granted          TINYINT(1) NOT NULL DEFAULT 1,
  document_version VARCHAR(20) NULL,
  ip               VARCHAR(45) NULL,
  user_agent       VARCHAR(255) NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_consents_user_kind (user_id, kind, created_at),
  CONSTRAINT fk_consents_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_exports (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       INT UNSIGNED NOT NULL,
  file_path     VARCHAR(255) NULL,
  token_hash    CHAR(64) NOT NULL,
  size_bytes    INT UNSIGNED NULL,
  status        ENUM('pending','ready','downloaded','expired','failed') NOT NULL DEFAULT 'pending',
  expires_at    DATETIME NOT NULL,
  downloaded_at DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_exports_token (token_hash),
  KEY idx_exports_user (user_id),
  CONSTRAINT fk_exports_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deletion_requests (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  kind          ENUM('account','household') NOT NULL,
  user_id       INT UNSIGNED NOT NULL COMMENT 'quem pediu',
  household_id  INT UNSIGNED NULL COMMENT 'para kind = household',
  scheduled_for DATETIME NOT NULL COMMENT 'fim da carência (7 dias)',
  cancelled_at  DATETIME NULL,
  executed_at   DATETIME NULL,
  ip            VARCHAR(45) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_deletion_user (user_id),
  KEY idx_deletion_scheduled (scheduled_for, executed_at, cancelled_at),
  CONSTRAINT fk_deletion_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT fk_deletion_household FOREIGN KEY (household_id) REFERENCES households (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS incidents (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title                VARCHAR(190) NOT NULL,
  description          TEXT NOT NULL,
  occurred_at          DATETIME NULL,
  detected_at          DATETIME NOT NULL,
  affected_users_count INT UNSIGNED NOT NULL DEFAULT 0,
  notified_at          DATETIME NULL COMMENT 'quando os afetados foram comunicados',
  anpd_notified_at     DATETIME NULL COMMENT 'comunicação à ANPD, se aplicável',
  status               ENUM('open','notified','closed') NOT NULL DEFAULT 'open',
  notes                TEXT NULL,
  created_by           INT UNSIGNED NULL,
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_incidents_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Segurança: sessões, tokens, tentativas, auditoria
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS sessions (
  id            VARCHAR(128) NOT NULL,
  user_id       INT UNSIGNED NULL,
  payload       MEDIUMBLOB NULL,
  ip            VARCHAR(45) NULL,
  user_agent    VARCHAR(255) NULL,
  device_label  VARCHAR(80) NULL,
  last_activity DATETIME NOT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sessions_user (user_id),
  KEY idx_sessions_activity (last_activity),
  CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS remember_tokens (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id        INT UNSIGNED NOT NULL,
  selector       CHAR(24) NOT NULL,
  validator_hash CHAR(64) NOT NULL,
  device_label   VARCHAR(80) NULL,
  ip             VARCHAR(45) NULL,
  expires_at     DATETIME NOT NULL,
  last_used_at   DATETIME NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_remember_selector (selector),
  KEY idx_remember_user (user_id),
  CONSTRAINT fk_remember_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at    DATETIME NULL,
  ip         VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_resets_token (token_hash),
  KEY idx_resets_user (user_id),
  CONSTRAINT fk_resets_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_verifications (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NOT NULL,
  email       VARCHAR(190) NOT NULL COMMENT 'permite confirmar troca de e-mail',
  token_hash  CHAR(64) NOT NULL,
  expires_at  DATETIME NOT NULL,
  verified_at DATETIME NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_verifications_token (token_hash),
  KEY idx_verifications_user (user_id),
  CONSTRAINT fk_verifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email      VARCHAR(190) NULL,
  ip         VARCHAR(45) NOT NULL,
  succeeded  TINYINT(1) NOT NULL DEFAULT 0,
  kind       ENUM('login','register','reset','totp','invite') NOT NULL DEFAULT 'login',
  user_agent VARCHAR(255) NULL,
  device_hash CHAR(64) NULL COMMENT 'hash(ip + user-agent) para detectar aparelho novo',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_attempts_ip (ip, created_at),
  KEY idx_attempts_email (email, created_at),
  KEY idx_attempts_device (email, device_hash, succeeded)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  household_id INT UNSIGNED NULL,
  user_id      INT UNSIGNED NULL,
  action       VARCHAR(60) NOT NULL COMMENT 'ex.: transaction.create, member.invite, consent.revoke',
  entity_type  VARCHAR(40) NULL,
  entity_id    BIGINT UNSIGNED NULL,
  before_data  JSON NULL,
  after_data   JSON NULL,
  ip           VARCHAR(45) NULL,
  user_agent   VARCHAR(255) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_household (household_id, created_at),
  KEY idx_audit_user (user_id, created_at),
  KEY idx_audit_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Cadastros financeiros
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS accounts (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  household_id    INT UNSIGNED NOT NULL,
  name            VARCHAR(80) NOT NULL,
  type            ENUM('checking','savings','credit_card','cash','investment') NOT NULL DEFAULT 'checking',
  owner_user_id   INT UNSIGNED NULL COMMENT 'NULL = conta conjunta',
  institution     VARCHAR(80) NULL,
  initial_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  closing_day     TINYINT UNSIGNED NULL COMMENT 'cartão: dia de fechamento',
  due_day         TINYINT UNSIGNED NULL COMMENT 'cartão: dia de vencimento',
  limit_amount    DECIMAL(12,2) NULL,
  color           CHAR(7) NULL,
  icon            VARCHAR(40) NULL,
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  sort_order      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  deleted_at      DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_accounts_household (household_id, is_active),
  CONSTRAINT fk_accounts_household FOREIGN KEY (household_id) REFERENCES households (id) ON DELETE CASCADE,
  CONSTRAINT fk_accounts_owner FOREIGN KEY (owner_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  household_id INT UNSIGNED NULL COMMENT 'NULL = modelo global (pt-BR) visível para todos os lares',
  parent_id    INT UNSIGNED NULL,
  name         VARCHAR(80) NOT NULL,
  icon         VARCHAR(40) NULL COMMENT 'nome do ícone Bootstrap Icons',
  color        CHAR(7) NULL,
  kind         ENUM('expense','income') NOT NULL DEFAULT 'expense',
  is_essential TINYINT(1) NOT NULL DEFAULT 0,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  sort_order   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  deleted_at   DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_categories_household (household_id, kind),
  KEY idx_categories_parent (parent_id),
  CONSTRAINT fk_categories_household FOREIGN KEY (household_id) REFERENCES households (id) ON DELETE CASCADE,
  CONSTRAINT fk_categories_parent FOREIGN KEY (parent_id) REFERENCES categories (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS category_rules (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  household_id INT UNSIGNED NOT NULL,
  pattern      VARCHAR(190) NOT NULL COMMENT 'texto da descrição (normalizado) que sugere a categoria',
  match_type   ENUM('contains','starts','exact') NOT NULL DEFAULT 'contains',
  category_id  INT UNSIGNED NOT NULL,
  source       ENUM('learned','manual') NOT NULL DEFAULT 'learned',
  hits         INT UNSIGNED NOT NULL DEFAULT 1,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_rules_household (household_id, pattern),
  CONSTRAINT fk_rules_household FOREIGN KEY (household_id) REFERENCES households (id) ON DELETE CASCADE,
  CONSTRAINT fk_rules_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recurring_rules (
  id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  household_id           INT UNSIGNED NOT NULL,
  description            VARCHAR(190) NOT NULL,
  kind                   ENUM('expense','income') NOT NULL DEFAULT 'expense',
  category_id            INT UNSIGNED NULL,
  account_id             INT UNSIGNED NULL,
  responsible_user_id    INT UNSIGNED NULL COMMENT 'NULL = todos',
  expected_amount        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  expected_amount_source ENUM('fixed','average') NOT NULL DEFAULT 'fixed' COMMENT 'fixo ou média dos últimos 3 meses',
  frequency              ENUM('weekly','monthly','yearly','custom') NOT NULL DEFAULT 'monthly',
  interval_count         SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'a cada N períodos (custom)',
  day_of_month           TINYINT UNSIGNED NULL,
  day_of_week            TINYINT UNSIGNED NULL COMMENT '1=segunda … 7=domingo',
  month_of_year          TINYINT UNSIGNED NULL COMMENT 'para yearly',
  start_date             DATE NOT NULL,
  end_date               DATE NULL,
  next_run_date          DATE NULL COMMENT 'próxima ocorrência a gerar',
  generate_days_ahead    SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  auto_debit             TINYINT(1) NOT NULL DEFAULT 0,
  is_subscription        TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'entra no radar de assinaturas',
  is_major_event         TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'evento previsto grande (ex.: PLR)',
  notify_days_before     SMALLINT UNSIGNED NULL,
  last_usage_confirmed_at DATE NULL COMMENT 'assinaturas: última confirmação de uso',
  is_active              TINYINT(1) NOT NULL DEFAULT 1,
  created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  deleted_at             DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_recurring_household (household_id, is_active, next_run_date),
  KEY idx_recurring_responsible (responsible_user_id),
  CONSTRAINT fk_recurring_household FOREIGN KEY (household_id) REFERENCES households (id) ON DELETE CASCADE,
  CONSTRAINT fk_recurring_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE SET NULL,
  CONSTRAINT fk_recurring_account FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE SET NULL,
  CONSTRAINT fk_recurring_responsible FOREIGN KEY (responsible_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS import_batches (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  household_id    INT UNSIGNED NOT NULL,
  user_id         INT UNSIGNED NOT NULL,
  account_id      INT UNSIGNED NULL,
  filename        VARCHAR(190) NOT NULL,
  format          ENUM('csv','ofx') NOT NULL,
  mapping         JSON NULL,
  rows_total      INT UNSIGNED NOT NULL DEFAULT 0,
  rows_imported   INT UNSIGNED NOT NULL DEFAULT 0,
  rows_duplicated INT UNSIGNED NOT NULL DEFAULT 0,
  status          ENUM('pending','done','failed','undone') NOT NULL DEFAULT 'pending',
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_imports_household (household_id),
  CONSTRAINT fk_imports_household FOREIGN KEY (household_id) REFERENCES households (id) ON DELETE CASCADE,
  CONSTRAINT fk_imports_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT fk_imports_account FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transactions (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  household_id        INT UNSIGNED NOT NULL,
  account_id          INT UNSIGNED NOT NULL,
  category_id         INT UNSIGNED NULL,
  responsible_user_id INT UNSIGNED NULL COMMENT 'NULL = todos',
  created_by          INT UNSIGNED NOT NULL,
  type                ENUM('income','expense','transfer') NOT NULL,
  amount              DECIMAL(12,2) NOT NULL,
  date                DATE NOT NULL COMMENT 'data de competência/vencimento',
  paid_at             DATE NULL COMMENT 'data efetiva do pagamento/recebimento',
  description         VARCHAR(190) NOT NULL,
  notes               TEXT NULL COMMENT 'observações, criptografadas (Crypto v1)',
  attachment_path     VARCHAR(255) NULL COMMENT 'caminho relativo em storage/uploads',
  attachment_name     VARCHAR(190) NULL,
  attachment_mime     VARCHAR(80) NULL,
  tags                JSON NULL,
  status              ENUM('pending','paid','scheduled') NOT NULL DEFAULT 'paid',
  recurring_id        INT UNSIGNED NULL,
  installment_no      SMALLINT UNSIGNED NULL,
  installment_total   SMALLINT UNSIGNED NULL,
  installment_group   CHAR(36) NULL COMMENT 'agrupa parcelas de uma mesma compra',
  transfer_account_id INT UNSIGNED NULL COMMENT 'transfer: conta de destino',
  transfer_pair_id    BIGINT UNSIGNED NULL COMMENT 'transfer: lançamento espelho',
  auto_debit          TINYINT(1) NOT NULL DEFAULT 0,
  is_private          TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'visível só para quem criou (entra nos totais)',
  import_batch_id     INT UNSIGNED NULL,
  import_hash         CHAR(64) NULL COMMENT 'hash(date|amount|description) para detectar duplicados',
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  deleted_at          DATETIME NULL,
  deleted_by          INT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY idx_tx_household_date (household_id, date),
  KEY idx_tx_household_status (household_id, status, date),
  KEY idx_tx_household_category (household_id, category_id, date),
  KEY idx_tx_household_responsible (household_id, responsible_user_id, date),
  KEY idx_tx_household_account (household_id, account_id, date),
  KEY idx_tx_recurring (recurring_id),
  KEY idx_tx_import_hash (household_id, import_hash),
  KEY idx_tx_deleted (household_id, deleted_at),
  CONSTRAINT fk_tx_household FOREIGN KEY (household_id) REFERENCES households (id) ON DELETE CASCADE,
  CONSTRAINT fk_tx_account FOREIGN KEY (account_id) REFERENCES accounts (id),
  CONSTRAINT fk_tx_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE SET NULL,
  CONSTRAINT fk_tx_responsible FOREIGN KEY (responsible_user_id) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_tx_created_by FOREIGN KEY (created_by) REFERENCES users (id),
  CONSTRAINT fk_tx_recurring FOREIGN KEY (recurring_id) REFERENCES recurring_rules (id) ON DELETE SET NULL,
  CONSTRAINT fk_tx_transfer_account FOREIGN KEY (transfer_account_id) REFERENCES accounts (id) ON DELETE SET NULL,
  CONSTRAINT fk_tx_import FOREIGN KEY (import_batch_id) REFERENCES import_batches (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transaction_templates (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  household_id        INT UNSIGNED NOT NULL,
  user_id             INT UNSIGNED NOT NULL,
  name                VARCHAR(80) NOT NULL,
  type                ENUM('income','expense','transfer') NOT NULL DEFAULT 'expense',
  amount              DECIMAL(12,2) NULL,
  category_id         INT UNSIGNED NULL,
  account_id          INT UNSIGNED NULL,
  responsible_user_id INT UNSIGNED NULL,
  description         VARCHAR(190) NULL,
  tags                JSON NULL,
  sort_order          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  deleted_at          DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_templates_household (household_id, user_id),
  CONSTRAINT fk_templates_household FOREIGN KEY (household_id) REFERENCES households (id) ON DELETE CASCADE,
  CONSTRAINT fk_templates_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_templates_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE SET NULL,
  CONSTRAINT fk_templates_account FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE SET NULL,
  CONSTRAINT fk_templates_responsible FOREIGN KEY (responsible_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Orçamento e economia
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS budgets (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  household_id     INT UNSIGNED NOT NULL,
  category_id      INT UNSIGNED NOT NULL,
  user_id          INT UNSIGNED NULL COMMENT 'NULL = lar inteiro; a aplicação garante 1 orçamento por (categoria, membro, mês)',
  period_month     DATE NOT NULL COMMENT 'primeiro dia do mês',
  limit_amount     DECIMAL(12,2) NOT NULL,
  alert_thresholds JSON NULL COMMENT 'ex.: [80,100]; NULL usa os limiares do usuário',
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_budgets_household_month (household_id, period_month),
  KEY idx_budgets_category (category_id),
  CONSTRAINT fk_budgets_household FOREIGN KEY (household_id) REFERENCES households (id) ON DELETE CASCADE,
  CONSTRAINT fk_budgets_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE CASCADE,
  CONSTRAINT fk_budgets_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS goals (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  household_id       INT UNSIGNED NOT NULL,
  name               VARCHAR(120) NOT NULL,
  target_amount      DECIMAL(12,2) NOT NULL,
  saved_amount       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  deadline           DATE NULL,
  linked_category_id INT UNSIGNED NULL,
  linked_account_id  INT UNSIGNED NULL,
  user_id            INT UNSIGNED NULL COMMENT 'NULL = meta do lar',
  status             ENUM('active','done','archived') NOT NULL DEFAULT 'active',
  achieved_at        DATETIME NULL,
  color              CHAR(7) NULL,
  icon               VARCHAR(40) NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  deleted_at         DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_goals_household (household_id, status),
  CONSTRAINT fk_goals_household FOREIGN KEY (household_id) REFERENCES households (id) ON DELETE CASCADE,
  CONSTRAINT fk_goals_category FOREIGN KEY (linked_category_id) REFERENCES categories (id) ON DELETE SET NULL,
  CONSTRAINT fk_goals_account FOREIGN KEY (linked_account_id) REFERENCES accounts (id) ON DELETE SET NULL,
  CONSTRAINT fk_goals_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS savings_actions (
  id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  household_id           INT UNSIGNED NOT NULL,
  title                  VARCHAR(190) NOT NULL,
  description            TEXT NULL,
  responsible_user_id    INT UNSIGNED NULL COMMENT 'NULL = ambos/todos',
  category_id            INT UNSIGNED NULL COMMENT 'categoria usada para medir a economia realizada',
  status                 ENUM('todo','doing','done') NOT NULL DEFAULT 'todo',
  estimated_saving_month DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  baseline_amount        DECIMAL(12,2) NULL COMMENT 'média dos 3 meses anteriores ao início',
  measured_saving_month  DECIMAL(12,2) NULL COMMENT 'baseline - mês atual (calculado)',
  started_at             DATE NULL,
  done_at                DATE NULL,
  sort_order             SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  deleted_at             DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_actions_household (household_id, status),
  CONSTRAINT fk_actions_household FOREIGN KEY (household_id) REFERENCES households (id) ON DELETE CASCADE,
  CONSTRAINT fk_actions_responsible FOREIGN KEY (responsible_user_id) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_actions_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Notificações
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS notification_settings (
  user_id    INT UNSIGNED NOT NULL,
  settings   JSON NOT NULL COMMENT 'validado por schema no NotificationSettingsService',
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_notif_settings_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS push_subscriptions (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id         INT UNSIGNED NOT NULL,
  endpoint        VARCHAR(1000) NOT NULL,
  endpoint_hash   CHAR(64) NOT NULL,
  p256dh          VARCHAR(255) NOT NULL,
  auth            VARCHAR(255) NOT NULL,
  user_agent      VARCHAR(255) NULL,
  device_label    VARCHAR(80) NULL,
  last_success_at DATETIME NULL,
  failures        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  disabled_at     DATETIME NULL COMMENT 'marcada inválida após 3 falhas seguidas',
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_push_endpoint (endpoint_hash),
  KEY idx_push_user (user_id),
  CONSTRAINT fk_push_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alerts (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  household_id  INT UNSIGNED NULL,
  user_id       INT UNSIGNED NOT NULL,
  type          VARCHAR(40) NOT NULL COMMENT 'income, expense, due, overdue, budget, subscription, event, goal, member_activity, digest, security',
  severity      ENUM('info','success','warning','danger') NOT NULL DEFAULT 'info',
  color         CHAR(7) NULL,
  sound         VARCHAR(20) NULL COMMENT 'entrada, saida, alerta, conquista ou NULL',
  title         VARCHAR(150) NOT NULL,
  body          TEXT NULL,
  payload       JSON NULL,
  url           VARCHAR(255) NULL,
  dedupe_key    VARCHAR(120) NULL COMMENT 'evita alertas repetidos (ex.: due:tx:123:d3)',
  channel       ENUM('push','email','both','none') NOT NULL DEFAULT 'none',
  scheduled_for DATETIME NULL COMMENT 'adiado por horário silencioso/agrupamento',
  sent_at       DATETIME NULL,
  read_at       DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_alerts_dedupe (user_id, dedupe_key),
  KEY idx_alerts_user_unread (user_id, read_at, created_at),
  KEY idx_alerts_pending (sent_at, scheduled_for),
  KEY idx_alerts_household (household_id, created_at),
  CONSTRAINT fk_alerts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_alerts_household FOREIGN KEY (household_id) REFERENCES households (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_outbox (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       INT UNSIGNED NULL,
  to_email      VARCHAR(190) NOT NULL,
  to_name       VARCHAR(120) NULL,
  subject       VARCHAR(190) NOT NULL,
  body_html     MEDIUMTEXT NOT NULL,
  body_text     MEDIUMTEXT NULL,
  kind          VARCHAR(40) NOT NULL DEFAULT 'transactional',
  status        ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  attempts      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error    VARCHAR(255) NULL,
  scheduled_for DATETIME NULL,
  sent_at       DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_outbox_status (status, scheduled_for),
  CONSTRAINT fk_outbox_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Sistema
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS settings (
  `key`       VARCHAR(80) NOT NULL,
  `value`     TEXT NULL,
  description VARCHAR(255) NULL,
  updated_at  DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cron_runs (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  task        VARCHAR(60) NOT NULL,
  started_at  DATETIME NOT NULL,
  finished_at DATETIME NULL,
  status      ENUM('ok','error') NOT NULL DEFAULT 'ok',
  message     VARCHAR(255) NULL,
  PRIMARY KEY (id),
  KEY idx_cron_task (task, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Prazos de retenção (LGPD) e versão do esquema
INSERT INTO settings (`key`, `value`, description) VALUES
  ('schema.version', '3', 'Versão do esquema aplicada'),
  ('retention.login_attempts_months', '12', 'Meses de retenção de tentativas de login'),
  ('retention.audit_logs_months', '24', 'Meses de retenção do log de auditoria'),
  ('retention.trash_days', '30', 'Dias na lixeira antes da exclusão definitiva'),
  ('retention.exports_hours', '24', 'Horas de validade de uma exportação de dados'),
  ('retention.backups_days', '30', 'Dias de retenção dos backups'),
  ('retention.sessions_days', '14', 'Dias de retenção de sessões inativas'),
  ('retention.alerts_days', '90', 'Dias de retenção do histórico de notificações'),
  ('retention.deletion_grace_days', '7', 'Dias de carência antes da exclusão de conta/lar'),
  ('legal.terms_version', '1.0', 'Versão vigente dos Termos de Uso'),
  ('legal.privacy_version', '1.0', 'Versão vigente da Política de Privacidade')
ON DUPLICATE KEY UPDATE description = VALUES(description), `value` = IF(`key` = 'schema.version' AND CAST(`value` AS UNSIGNED) < 3, '3', `value`);
