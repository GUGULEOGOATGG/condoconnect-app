-- CondoConnect: banco da aplicação PHP principal (não da versão alternativa).
-- MySQL 8 / MariaDB 10.6+. UTF-8 completo. Não apaga bancos ou registros.
-- Importe com um usuário autorizado a criar banco/tabelas.
CREATE DATABASE IF NOT EXISTS condominio_connect CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE condominio_connect;

CREATE TABLE IF NOT EXISTS users (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 nome VARCHAR(190) NOT NULL,
 cpf VARCHAR(20) NOT NULL,
 email VARCHAR(190) NOT NULL UNIQUE,
 telefone VARCHAR(30) NOT NULL,
 senha VARCHAR(255) NOT NULL,
 tipo_usuario ENUM('morador','prestador','sindico') NOT NULL DEFAULT 'morador',
 condominio VARCHAR(190) NOT NULL,
 unidade VARCHAR(190) NOT NULL,
 status ENUM('ativo','bloqueado') NOT NULL DEFAULT 'ativo',
 criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS chamados (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 usuario_id BIGINT UNSIGNED NOT NULL,
 categoria VARCHAR(100) NOT NULL,
 titulo VARCHAR(190) NOT NULL,
 descricao TEXT NOT NULL,
 local_problema VARCHAR(255) NOT NULL,
 prioridade VARCHAR(20) NOT NULL,
 data_servico DATE NOT NULL,
 protocolo VARCHAR(64) NOT NULL UNIQUE,
 status ENUM('aberto','andamento','concluido','cancelado') NOT NULL DEFAULT 'aberto',
 criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_chamados_usuario_data(usuario_id,criado_em),
 FOREIGN KEY (usuario_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS anexos (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 chamado_id BIGINT UNSIGNED NOT NULL,
 arquivo VARCHAR(255) NOT NULL,
 criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (chamado_id) REFERENCES chamados(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS mensagens (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 chamado_id BIGINT UNSIGNED NOT NULL,
 remetente_id BIGINT UNSIGNED NOT NULL,
 mensagem TEXT NOT NULL,
 criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (chamado_id) REFERENCES chamados(id) ON DELETE CASCADE,
 FOREIGN KEY (remetente_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS notificacoes (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 usuario_id BIGINT UNSIGNED NOT NULL,
 titulo VARCHAR(190) NOT NULL,
 mensagem TEXT NOT NULL,
 lida TINYINT(1) NOT NULL DEFAULT 0,
 criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (usuario_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS avaliacoes (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 chamado_id BIGINT UNSIGNED NOT NULL,
 morador_id BIGINT UNSIGNED NOT NULL,
 prestador_id BIGINT UNSIGNED NOT NULL,
 nota TINYINT UNSIGNED NOT NULL,
 comentario TEXT,
 criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CHECK (nota BETWEEN 1 AND 5),
 FOREIGN KEY (chamado_id) REFERENCES chamados(id) ON DELETE CASCADE,
 FOREIGN KEY (morador_id) REFERENCES users(id) ON DELETE CASCADE,
 FOREIGN KEY (prestador_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS sessions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 token VARCHAR(190) NOT NULL UNIQUE,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS contatos (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 nome VARCHAR(190) NOT NULL,
 email VARCHAR(190) NOT NULL,
 assunto VARCHAR(190) NOT NULL,
 mensagem TEXT NOT NULL,
 criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Nenhuma senha compartilhada é criada. Use pages/cadastro.php para a primeira conta.
-- As sessões web usam o mecanismo nativo do PHP; sessions fica disponível para evolução.
-- CREATE TABLE IF NOT EXISTS não migra tabelas preexistentes com estrutura diferente.
