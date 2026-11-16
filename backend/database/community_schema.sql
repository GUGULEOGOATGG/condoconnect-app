CREATE TABLE IF NOT EXISTS blocos (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 condominio_id BIGINT UNSIGNED NOT NULL,
 nome VARCHAR(80) NOT NULL,
 UNIQUE KEY uk_bloco (condominio_id,nome),
 UNIQUE KEY uk_bloco_cond (id,condominio_id),
 FOREIGN KEY (condominio_id) REFERENCES condominios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS unidades (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 bloco_id BIGINT UNSIGNED NOT NULL,
 condominio_id BIGINT UNSIGNED NOT NULL,
 numero VARCHAR(30) NOT NULL,
 UNIQUE KEY uk_unidade (bloco_id,numero),
 UNIQUE KEY uk_unidade_cond (id,condominio_id),
 FOREIGN KEY (bloco_id,condominio_id) REFERENCES blocos(id,condominio_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS condominio_sindicos (
 condominio_id BIGINT UNSIGNED PRIMARY KEY,
 usuario_id BIGINT UNSIGNED NOT NULL,
 atribuido_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (condominio_id) REFERENCES condominios(id),
 FOREIGN KEY (usuario_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS areas_comuns (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 condominio_id BIGINT UNSIGNED NOT NULL,
 nome VARCHAR(120) NOT NULL,
 descricao TEXT,
 capacidade INT UNSIGNED NOT NULL DEFAULT 10,
 regras TEXT,
 ativo TINYINT(1) NOT NULL DEFAULT 1,
 UNIQUE KEY uk_area (condominio_id,nome),
 UNIQUE KEY uk_area_cond (id,condominio_id),
 FOREIGN KEY (condominio_id) REFERENCES condominios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS reservas (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 area_id BIGINT UNSIGNED NOT NULL,
 condominio_id BIGINT UNSIGNED NOT NULL,
 usuario_id BIGINT UNSIGNED NOT NULL,
 inicio DATETIME NOT NULL,
 fim DATETIME NOT NULL,
 status ENUM('confirmada','cancelada') NOT NULL DEFAULT 'confirmada',
 observacao VARCHAR(500),
 criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_reserva_usuario_horario (area_id,usuario_id,inicio),
 KEY idx_reserva_agenda (area_id,status,inicio,fim),
 FOREIGN KEY (area_id,condominio_id) REFERENCES areas_comuns(id,condominio_id),
 FOREIGN KEY (usuario_id) REFERENCES users(id),
 CHECK (fim > inicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS comunicados (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 condominio_id BIGINT UNSIGNED NOT NULL,
 autor_id BIGINT UNSIGNED NOT NULL,
 titulo VARCHAR(190) NOT NULL,
 conteudo TEXT NOT NULL,
 status ENUM('rascunho','publicado','arquivado','excluido') NOT NULL DEFAULT 'publicado',
 publicado_em DATETIME NULL,
 criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 KEY idx_comunicado_cond (condominio_id,status,publicado_em),
 FOREIGN KEY (condominio_id) REFERENCES condominios(id),
 FOREIGN KEY (autor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS comunicado_leituras (
 comunicado_id BIGINT UNSIGNED NOT NULL,
 usuario_id BIGINT UNSIGNED NOT NULL,
 lido_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (comunicado_id,usuario_id),
 FOREIGN KEY (comunicado_id) REFERENCES comunicados(id) ON DELETE CASCADE,
 FOREIGN KEY (usuario_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS comunicado_historico (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 comunicado_id BIGINT UNSIGNED NOT NULL,
 usuario_id BIGINT UNSIGNED NOT NULL,
 titulo VARCHAR(190) NOT NULL,
 conteudo TEXT NOT NULL,
 status VARCHAR(20) NOT NULL,
 criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (comunicado_id) REFERENCES comunicados(id),
 FOREIGN KEY (usuario_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS chamado_propostas (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 chamado_id BIGINT UNSIGNED NOT NULL,
 prestador_id BIGINT UNSIGNED NOT NULL,
 resposta TEXT NOT NULL,
 taxa_visita DECIMAL(10,2) NULL,
 visita_em DATETIME NULL,
 orcamento_valor DECIMAL(10,2) NULL,
 orcamento_descricao TEXT NULL,
 orcamento_status ENUM('pendente','aceito','recusado') NOT NULL DEFAULT 'pendente',
 atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uk_proposta (chamado_id,prestador_id),
 FOREIGN KEY (chamado_id) REFERENCES chamados(id),
 FOREIGN KEY (prestador_id) REFERENCES users(id),
 CHECK (taxa_visita IS NULL OR taxa_visita >= 0),
 CHECK (orcamento_valor IS NULL OR orcamento_valor > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
