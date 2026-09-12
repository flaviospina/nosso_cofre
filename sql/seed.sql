-- ============================================================================
-- Nosso Cofre v2 — dados de exemplo (SOMENTE desenvolvimento/teste)
-- Contém: modelo global de categorias pt-BR (vai também para produção, é o padrão do app)
--         + lar de teste "Família Spina" com dois membros, contas, recorrências e plano de economia.
-- Em produção importe APENAS a seção 1 (categorias) ou o arquivo inteiro e depois exclua o lar de teste.
-- Idempotente: INSERT IGNORE com ids fixos. Senha dos usuários de teste: Cofre@2026teste
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ---------------------------------------------------------------------------
-- 1) Modelo global de categorias (household_id NULL). Ids 1-21 = pais; 100+ = filhas.
-- is_essential marca o que entra como "essencial" no comparativo essencial × supérfluo e na regra 50/30/20.
-- ---------------------------------------------------------------------------

INSERT IGNORE INTO categories (id, household_id, parent_id, name, icon, color, kind, is_essential, sort_order) VALUES
  (1,  NULL, NULL, 'Moradia',                 'house-door',        '#0f766e', 'expense', 1, 10),
  (2,  NULL, NULL, 'Alimentação',             'basket',            '#65a30d', 'expense', 1, 20),
  (3,  NULL, NULL, 'Transporte',              'bus-front',         '#0284c7', 'expense', 1, 30),
  (4,  NULL, NULL, 'Veículo',                 'car-front',         '#0369a1', 'expense', 1, 40),
  (5,  NULL, NULL, 'Saúde',                   'heart-pulse',       '#dc2626', 'expense', 1, 50),
  (6,  NULL, NULL, 'Educação',                'mortarboard',       '#7c3aed', 'expense', 1, 60),
  (7,  NULL, NULL, 'Pets',                    'egg-fried',         '#b45309', 'expense', 1, 70),
  (8,  NULL, NULL, 'Assinaturas e tecnologia','phone',             '#6d28d9', 'expense', 0, 80),
  (9,  NULL, NULL, 'Lazer',                   'controller',        '#db2777', 'expense', 0, 90),
  (10, NULL, NULL, 'Serviços domésticos',     'house-gear',        '#0d9488', 'expense', 1, 100),
  (11, NULL, NULL, 'Cuidados pessoais',       'person-hearts',     '#e11d48', 'expense', 0, 110),
  (12, NULL, NULL, 'Impostos e taxas',        'receipt',           '#475569', 'expense', 1, 120),
  (13, NULL, NULL, 'Dívidas e juros',         'bank',              '#991b1b', 'expense', 1, 130),
  (14, NULL, NULL, 'Seguros',                 'shield-check',      '#1d4ed8', 'expense', 1, 140),
  (15, NULL, NULL, 'Filhos e família',        'people',            '#c026d3', 'expense', 1, 150),
  (16, NULL, NULL, 'Doações e dízimo',        'gift',              '#ca8a04', 'expense', 0, 160),
  (17, NULL, NULL, 'Investimentos e reserva', 'graph-up-arrow',    '#15803d', 'expense', 1, 170),
  (18, NULL, NULL, 'Outros gastos',           'three-dots',        '#6b7280', 'expense', 0, 180),
  (19, NULL, NULL, 'Renda',                   'cash-stack',        '#15803d', 'income',  1, 10),
  (20, NULL, NULL, 'Renda extra',             'cash-coin',         '#16a34a', 'income',  0, 20),
  (21, NULL, NULL, 'Rendimentos',             'piggy-bank',        '#059669', 'income',  0, 30);

INSERT IGNORE INTO categories (id, household_id, parent_id, name, icon, color, kind, is_essential, sort_order) VALUES
  -- Moradia
  (100, NULL, 1, 'Aluguel',                      'key',              NULL, 'expense', 1, 1),
  (101, NULL, 1, 'Condomínio',                   'building',         NULL, 'expense', 1, 2),
  (102, NULL, 1, 'Financiamento imobiliário',    'house-check',      NULL, 'expense', 1, 3),
  (103, NULL, 1, 'Água',                         'droplet',          NULL, 'expense', 1, 4),
  (104, NULL, 1, 'Energia',                      'lightning-charge', NULL, 'expense', 1, 5),
  (105, NULL, 1, 'Gás',                          'fire',             NULL, 'expense', 1, 6),
  (106, NULL, 1, 'Internet',                     'wifi',             NULL, 'expense', 1, 7),
  (107, NULL, 1, 'IPTU',                         'file-earmark-text',NULL, 'expense', 1, 8),
  (108, NULL, 1, 'Manutenção e reparos',         'tools',            NULL, 'expense', 1, 9),
  (109, NULL, 1, 'Equipamentos e eletrodomésticos','plug',           NULL, 'expense', 0, 10),
  -- Alimentação
  (110, NULL, 2, 'Mercado',                      'cart',             NULL, 'expense', 1, 1),
  (111, NULL, 2, 'Padaria',                      'cup-hot',          NULL, 'expense', 0, 2),
  (112, NULL, 2, 'Feira e hortifruti',           'flower1',          NULL, 'expense', 1, 3),
  (113, NULL, 2, 'Restaurante e delivery',       'shop',             NULL, 'expense', 0, 4),
  (114, NULL, 2, 'Mercado rápido (apps)',        'lightning',        NULL, 'expense', 0, 5),
  -- Transporte
  (120, NULL, 3, 'Combustível',                  'fuel-pump',        NULL, 'expense', 1, 1),
  (121, NULL, 3, 'Transporte público',           'train-front',      NULL, 'expense', 1, 2),
  (122, NULL, 3, 'Apps de transporte',           'taxi-front',       NULL, 'expense', 0, 3),
  (123, NULL, 3, 'Estacionamento e pedágio',     'p-square',         NULL, 'expense', 1, 4),
  -- Veículo
  (130, NULL, 4, 'Financiamento ou consórcio',   'car-front-fill',   NULL, 'expense', 1, 1),
  (131, NULL, 4, 'Seguro do veículo',            'shield',           NULL, 'expense', 1, 2),
  (132, NULL, 4, 'IPVA e licenciamento',         'file-text',        NULL, 'expense', 1, 3),
  (133, NULL, 4, 'Manutenção do veículo',        'wrench',           NULL, 'expense', 1, 4),
  (134, NULL, 4, 'Carro alugado',                'car-front',        NULL, 'expense', 1, 5),
  -- Saúde
  (140, NULL, 5, 'Plano de saúde',               'hospital',         NULL, 'expense', 1, 1),
  (141, NULL, 5, 'Farmácia',                     'capsule',          NULL, 'expense', 1, 2),
  (142, NULL, 5, 'Consultas e exames',           'clipboard2-pulse', NULL, 'expense', 1, 3),
  (143, NULL, 5, 'Dentista',                     'emoji-smile',      NULL, 'expense', 1, 4),
  (144, NULL, 5, 'Academia e esportes',          'bicycle',          NULL, 'expense', 0, 5),
  -- Educação
  (150, NULL, 6, 'Escola e faculdade',           'book',             NULL, 'expense', 1, 1),
  (151, NULL, 6, 'Cursos',                       'journal-bookmark', NULL, 'expense', 0, 2),
  (152, NULL, 6, 'Material escolar e livros',    'pencil',           NULL, 'expense', 1, 3),
  -- Pets
  (160, NULL, 7, 'Ração',                        'basket2',          NULL, 'expense', 1, 1),
  (161, NULL, 7, 'Veterinário',                  'heart',            NULL, 'expense', 1, 2),
  (162, NULL, 7, 'Petshop, banho e acessórios',  'scissors',         NULL, 'expense', 0, 3),
  -- Assinaturas e tecnologia
  (170, NULL, 8, 'Streaming (vídeo e música)',   'play-btn',         NULL, 'expense', 0, 1),
  (171, NULL, 8, 'Software e apps',              'app-indicator',    NULL, 'expense', 0, 2),
  (172, NULL, 8, 'Celular (plano)',              'phone-vibrate',    NULL, 'expense', 1, 3),
  (173, NULL, 8, 'Nuvem e armazenamento',        'cloud',            NULL, 'expense', 0, 4),
  (174, NULL, 8, 'Jogos',                        'joystick',         NULL, 'expense', 0, 5),
  (175, NULL, 8, 'Compras de tecnologia',        'laptop',           NULL, 'expense', 0, 6),
  -- Lazer
  (180, NULL, 9, 'Viagens',                      'airplane',         NULL, 'expense', 0, 1),
  (181, NULL, 9, 'Cinema, shows e eventos',      'ticket-perforated',NULL, 'expense', 0, 2),
  (182, NULL, 9, 'Bares e festas',               'cup-straw',        NULL, 'expense', 0, 3),
  (183, NULL, 9, 'Hobbies',                      'palette',          NULL, 'expense', 0, 4),
  (184, NULL, 9, 'Presentes',                    'gift',             NULL, 'expense', 0, 5),
  -- Serviços domésticos
  (190, NULL, 10, 'Faxineira',                   'stars',            NULL, 'expense', 1, 1),
  (191, NULL, 10, 'Passadeira',                  'water',            NULL, 'expense', 1, 2),
  (192, NULL, 10, 'Babá',                        'person-arms-up',   NULL, 'expense', 1, 3),
  (193, NULL, 10, 'Jardineiro e piscina',        'tree',             NULL, 'expense', 0, 4),
  (194, NULL, 10, 'Segurança e alarme',          'shield-lock',      NULL, 'expense', 0, 5),
  -- Cuidados pessoais
  (200, NULL, 11, 'Cabeleireiro e barbearia',    'scissors',         NULL, 'expense', 0, 1),
  (201, NULL, 11, 'Cosméticos',                  'droplet-half',     NULL, 'expense', 0, 2),
  (202, NULL, 11, 'Roupas e calçados',           'bag',              NULL, 'expense', 0, 3),
  -- Impostos e taxas
  (210, NULL, 12, 'Imposto de renda',            'file-earmark-ruled',NULL,'expense', 1, 1),
  (211, NULL, 12, 'Tarifas bancárias',           'bank2',            NULL, 'expense', 1, 2),
  (212, NULL, 12, 'Taxas e cartório',            'stamp',            NULL, 'expense', 1, 3),
  -- Dívidas e juros
  (220, NULL, 13, 'Parcela de empréstimo',       'cash',             NULL, 'expense', 1, 1),
  (221, NULL, 13, 'Juros de empréstimo',         'percent',          NULL, 'expense', 1, 2),
  (222, NULL, 13, 'Juros do cartão e rotativo',  'credit-card',      NULL, 'expense', 1, 3),
  (223, NULL, 13, 'Cheque especial',             'exclamation-diamond',NULL,'expense',1, 4),
  -- Seguros
  (230, NULL, 14, 'Seguro de vida',              'heart-fill',       NULL, 'expense', 1, 1),
  (231, NULL, 14, 'Seguro residencial',          'house-lock',       NULL, 'expense', 1, 2),
  -- Filhos e família
  (240, NULL, 15, 'Creche e escola infantil',    'balloon',          NULL, 'expense', 1, 1),
  (241, NULL, 15, 'Mesada',                      'wallet2',          NULL, 'expense', 0, 2),
  (242, NULL, 15, 'Atividades e brinquedos',     'puzzle',           NULL, 'expense', 0, 3),
  -- Investimentos e reserva
  (250, NULL, 17, 'Aporte em investimentos',     'graph-up',         NULL, 'expense', 1, 1),
  (251, NULL, 17, 'Reserva de emergência',       'safe2',            NULL, 'expense', 1, 2),
  (252, NULL, 17, 'Previdência',                 'hourglass',        NULL, 'expense', 1, 3),
  -- Renda
  (260, NULL, 19, 'Salário',                     'briefcase',        NULL, 'income',  1, 1),
  (261, NULL, 19, 'Pró-labore',                  'person-badge',     NULL, 'income',  1, 2),
  (262, NULL, 19, 'Aposentadoria e pensão',      'person-check',     NULL, 'income',  1, 3),
  (263, NULL, 19, 'Aluguel recebido',            'house-up',         NULL, 'income',  1, 4),
  -- Renda extra
  (270, NULL, 20, 'Freelance e bicos',           'laptop',           NULL, 'income',  0, 1),
  (271, NULL, 20, 'Bônus e PLR',                 'trophy',           NULL, 'income',  0, 2),
  (272, NULL, 20, 'Venda de itens',              'tag',              NULL, 'income',  0, 3),
  (273, NULL, 20, 'Reembolsos',                  'arrow-return-left',NULL, 'income',  0, 4),
  -- Rendimentos
  (280, NULL, 21, 'Rendimentos de aplicações',   'graph-up-arrow',   NULL, 'income',  0, 1),
  (281, NULL, 21, 'Dividendos',                  'coin',             NULL, 'income',  0, 2),
  (282, NULL, 21, 'Resgates',                    'box-arrow-in-down',NULL, 'income',  0, 3);

-- ---------------------------------------------------------------------------
-- 2) Lar de teste "Família Spina" — NÃO importar em produção
-- Senha de ambos: Cofre@2026teste (hash bcrypt, verificável em qualquer PHP; o login migra para Argon2id quando disponível)
-- ---------------------------------------------------------------------------

INSERT IGNORE INTO users (id, name, email, email_verified_at, password_hash, color, timezone, adult_confirmed_at, status, session_idle_minutes) VALUES
  (1, 'Flávio',   'flavio@exemplo.test',   UTC_TIMESTAMP(), '$2y$12$2HfibEmfMxAu6BO95gVYdOPwFVWSY7Y7zVJkaujcZOsdXpJY/CJ5e', '#0d6efd', 'America/Sao_Paulo', UTC_TIMESTAMP(), 'active', 30),
  (2, 'Priscila', 'priscila@exemplo.test', UTC_TIMESTAMP(), '$2y$12$2HfibEmfMxAu6BO95gVYdOPwFVWSY7Y7zVJkaujcZOsdXpJY/CJ5e', '#d63384', 'America/Sao_Paulo', UTC_TIMESTAMP(), 'active', 30);

INSERT IGNORE INTO households (id, name, type, currency, fiscal_month_start_day, settings, owner_user_id, status) VALUES
  (1, 'Família Spina', 'family', 'BRL', 1, '{"members_can_edit_others": false, "members_can_see_income": true}', 1, 'active');

INSERT IGNORE INTO household_members (id, household_id, user_id, role, estimated_income, joined_at) VALUES
  (1, 1, 1, 'owner', NULL, UTC_TIMESTAMP()),
  (2, 1, 2, 'admin', NULL, UTC_TIMESTAMP());

-- Consentimentos de teste (termos e política v1.0)
INSERT IGNORE INTO consents (id, user_id, kind, granted, document_version, ip, user_agent) VALUES
  (1, 1, 'terms',   1, '1.0', '127.0.0.1', 'seed'),
  (2, 1, 'privacy', 1, '1.0', '127.0.0.1', 'seed'),
  (3, 1, 'adult',   1, '1.0', '127.0.0.1', 'seed'),
  (4, 2, 'terms',   1, '1.0', '127.0.0.1', 'seed'),
  (5, 2, 'privacy', 1, '1.0', '127.0.0.1', 'seed'),
  (6, 2, 'adult',   1, '1.0', '127.0.0.1', 'seed');

-- Nenhum aviso ligado por padrão (o usuário escolhe em /conta/notificacoes)
INSERT IGNORE INTO notification_settings (user_id, settings) VALUES
  (1, '{"version": 1, "enabled": false, "types": {}}'),
  (2, '{"version": 1, "enabled": false, "types": {}}');

-- Contas e cartões
INSERT IGNORE INTO accounts (id, household_id, name, type, owner_user_id, institution, initial_balance, closing_day, due_day, limit_amount, color, icon, sort_order) VALUES
  (1, 1, 'Conta corrente Flávio',   'checking',    1,    NULL, 0.00, NULL, NULL, NULL,     '#0d6efd', 'bank',        1),
  (2, 1, 'Conta corrente Priscila', 'checking',    2,    NULL, 0.00, NULL, NULL, NULL,     '#d63384', 'bank',        2),
  (3, 1, 'Cartão tecnologia',       'credit_card', 1,    NULL, 0.00, 25,   5,    5000.00,  '#6d28d9', 'credit-card', 3),
  (4, 1, 'Cartão Priscila',         'credit_card', 2,    NULL, 0.00, 20,   1,    8000.00,  '#d63384', 'credit-card', 4),
  (5, 1, 'Dinheiro (casa)',         'cash',        NULL, NULL, 0.00, NULL, NULL, NULL,     '#15803d', 'cash',        5),
  (6, 1, 'Reserva de emergência',   'savings',     NULL, NULL, 0.00, NULL, NULL, NULL,     '#0f766e', 'safe2',       6);

-- Recorrências (valores estimados de exemplo; ajuste na tela). day_of_month = dia de vencimento.
INSERT IGNORE INTO recurring_rules (id, household_id, description, kind, category_id, account_id, responsible_user_id, expected_amount, expected_amount_source, frequency, day_of_month, month_of_year, start_date, next_run_date, auto_debit, is_subscription, is_major_event, notify_days_before) VALUES
  -- Responsável: Flávio
  (1,  1, 'Brastemp (casa)',                       'expense', 109, 1, 1,  189.90,  'fixed',   'monthly', 10, NULL, '2026-01-01', DATE_FORMAT(CURDATE(), '%Y-%m-10'), 0, 1, 0, NULL),
  (2,  1, 'Brastemp (Araraquara)',                 'expense', 109, 1, 1,  189.90,  'fixed',   'monthly', 10, NULL, '2026-01-01', DATE_FORMAT(CURDATE(), '%Y-%m-10'), 0, 1, 0, NULL),
  (3,  1, 'Água',                                  'expense', 103, 1, 1,  120.00,  'average', 'monthly', 15, NULL, '2026-01-01', DATE_FORMAT(CURDATE(), '%Y-%m-15'), 0, 0, 0, NULL),
  (4,  1, 'Internet',                              'expense', 106, 1, 1,  129.90,  'fixed',   'monthly', 12, NULL, '2026-01-01', DATE_FORMAT(CURDATE(), '%Y-%m-12'), 0, 1, 0, NULL),
  (5,  1, 'Energia',                               'expense', 104, 1, 1,  350.00,  'average', 'monthly', 18, NULL, '2026-01-01', DATE_FORMAT(CURDATE(), '%Y-%m-18'), 0, 0, 0, NULL),
  (6,  1, 'Jeep (financiamento)',                  'expense', 130, 1, 1,  1890.00, 'fixed',   'monthly', 8,  NULL, '2026-01-01', DATE_FORMAT(CURDATE(), '%Y-%m-08'), 0, 0, 0, NULL),
  -- Responsável: Priscila
  (7,  1, 'Aluguel',                               'expense', 100, 2, 2,  3200.00, 'fixed',   'monthly', 5,  NULL, '2026-01-01', DATE_FORMAT(CURDATE(), '%Y-%m-05'), 0, 0, 0, NULL),
  (8,  1, 'Financiamento bancário',                'expense', 220, 2, 2,  980.00,  'fixed',   'monthly', 10, NULL, '2026-01-01', DATE_FORMAT(CURDATE(), '%Y-%m-10'), 0, 0, 0, NULL),
  (9,  1, 'Parcela da casa (trade-off Araraquara)','expense', 102, 2, 2,  1450.00, 'fixed',   'monthly', 15, NULL, '2026-01-01', DATE_FORMAT(CURDATE(), '%Y-%m-15'), 0, 0, 0, NULL),
  (10, 1, 'Carro alugado',                         'expense', 134, 2, 2,  1600.00, 'fixed',   'monthly', 20, NULL, '2026-01-01', DATE_FORMAT(CURDATE(), '%Y-%m-20'), 0, 0, 0, NULL),
  (11, 1, 'Babá',                                  'expense', 192, 2, 2,  2200.00, 'fixed',   'monthly', 5,  NULL, '2026-01-01', DATE_FORMAT(CURDATE(), '%Y-%m-05'), 0, 0, 0, NULL),
  (12, 1, 'Juros do empréstimo (mamãe)',           'expense', 221, 2, 2,  450.00,  'fixed',   'monthly', 10, NULL, '2026-01-01', DATE_FORMAT(CURDATE(), '%Y-%m-10'), 0, 0, 0, NULL),
  (13, 1, 'Faxineira',                             'expense', 190, 2, 2,  720.00,  'fixed',   'monthly', 30, NULL, '2026-01-01', LAST_DAY(CURDATE()),                0, 0, 0, NULL),
  (14, 1, 'Passadeira',                            'expense', 191, 2, 2,  360.00,  'fixed',   'monthly', 30, NULL, '2026-01-01', LAST_DAY(CURDATE()),                0, 0, 0, NULL),
  (15, 1, 'Despesas de mercado',                   'expense', 110, 4, 2,  2400.00, 'average', 'monthly', 1,  NULL, '2026-01-01', DATE_FORMAT(CURDATE(), '%Y-%m-01'), 0, 0, 0, NULL),
  -- Assinaturas (radar): Amazon Prime aparece duas vezes de propósito, para o detector de duplicidade
  (16, 1, 'Amazon Prime',                          'expense', 170, 3, 1,  19.90,   'fixed',   'monthly', 3,  NULL, '2026-01-01', DATE_FORMAT(CURDATE(), '%Y-%m-03'), 1, 1, 0, NULL),
  (17, 1, 'Amazon Prime',                          'expense', 170, 4, 2,  19.90,   'fixed',   'monthly', 7,  NULL, '2026-01-01', DATE_FORMAT(CURDATE(), '%Y-%m-07'), 1, 1, 0, NULL),
  (18, 1, 'Verisure (alarme)',                     'expense', 194, 2, 2,  149.90,  'fixed',   'monthly', 12, NULL, '2026-01-01', DATE_FORMAT(CURDATE(), '%Y-%m-12'), 1, 1, 0, NULL),
  (19, 1, 'Netflix',                               'expense', 170, 3, 1,  44.90,   'fixed',   'monthly', 15, NULL, '2026-01-01', DATE_FORMAT(CURDATE(), '%Y-%m-15'), 1, 1, 0, NULL),
  (20, 1, 'iCloud',                                'expense', 173, 3, 1,  14.90,   'fixed',   'monthly', 22, NULL, '2026-01-01', DATE_FORMAT(CURDATE(), '%Y-%m-22'), 1, 1, 0, NULL),
  -- Receita anual: PLR da Priscila todo mês de março (evento previsto grande, aviso 30 dias antes)
  (21, 1, 'PLR — participação nos lucros',         'income',  271, 2, 2,  15000.00,'fixed',   'yearly',  20, 3,    '2026-03-20', CONCAT(YEAR(CURDATE()) + IF(MONTH(CURDATE()) > 3, 1, 0), '-03-20'), 0, 0, 1, 30);

-- Plano de ação de economia (estimativas de exemplo)
INSERT IGNORE INTO savings_actions (id, household_id, title, description, responsible_user_id, category_id, status, estimated_saving_month, sort_order) VALUES
  (1, 1, 'Revisar de onde sai a Brastemp de Araraquara',           'Confirmar se a cobrança ainda faz sentido e cancelar se não houver uso.', 1,    109, 'todo', 189.90, 1),
  (2, 1, 'Revisar tudo que cai nos cartões de tecnologia e cortar', 'Listar cada cobrança recorrente do cartão de tecnologia e cancelar o que não é usado.', 1, 8, 'todo', 150.00, 2),
  (3, 1, 'Pet só ração',                                            'Sem petshop, banho ou acessórios por enquanto.', NULL, 162, 'todo', 120.00, 3),
  (4, 1, 'Reduzir padaria e não comprar mais no Daki',              'Padaria só no fim de semana; nada de mercado rápido por app.', NULL, 111, 'todo', 300.00, 4),
  (5, 1, 'Resolver o Verisure',                                     'Renegociar ou cancelar o alarme.', 2, 194, 'todo', 149.90, 5),
  (6, 1, 'Débito automático nas contas de sua responsabilidade',    'Cada um coloca em débito automático as contas pelas quais responde, para evitar atraso e juros.', NULL, NULL, 'todo', 60.00, 6),
  (7, 1, 'Custos duplicados do Amazon Prime',                       'Manter uma única assinatura para o lar.', 1, 170, 'todo', 19.90, 7);

-- Meta e orçamentos do mês corrente
INSERT IGNORE INTO goals (id, household_id, name, target_amount, saved_amount, deadline, linked_account_id, user_id, status, color, icon) VALUES
  (1, 1, 'Reserva de emergência (6 meses)', 30000.00, 0.00, DATE_ADD(CURDATE(), INTERVAL 18 MONTH), 6, NULL, 'active', '#0f766e', 'safe2');

INSERT IGNORE INTO budgets (id, household_id, category_id, user_id, period_month, limit_amount) VALUES
  (1, 1, 111, NULL, DATE_FORMAT(CURDATE(), '%Y-%m-01'), 400.00),
  (2, 1, 113, NULL, DATE_FORMAT(CURDATE(), '%Y-%m-01'), 600.00),
  (3, 1, 8,   NULL, DATE_FORMAT(CURDATE(), '%Y-%m-01'), 300.00),
  (4, 1, 7,   NULL, DATE_FORMAT(CURDATE(), '%Y-%m-01'), 250.00),
  (5, 1, 110, NULL, DATE_FORMAT(CURDATE(), '%Y-%m-01'), 2400.00);
