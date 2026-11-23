CREATE DATABASE IF NOT EXISTS cmdb
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE cmdb;

CREATE TABLE IF NOT EXISTS usuario (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nome          VARCHAR(120) NOT NULL,
  login         VARCHAR(60)  NOT NULL,
  senha_hash    VARCHAR(255) NOT NULL,
  perfil        ENUM('ADMIN','ANALISTA') NOT NULL DEFAULT 'ANALISTA',
  ativo         TINYINT(1) NOT NULL DEFAULT 1,
  criado_em     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_usuario_login (login)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ambiente (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo          VARCHAR(60)  NOT NULL,
  nome          VARCHAR(120) NOT NULL,
  host          VARCHAR(190) NOT NULL,
  porta         SMALLINT UNSIGNED NOT NULL DEFAULT 8006,
  usuario_api   VARCHAR(190) NOT NULL,
  token_cifrado TEXT NOT NULL,
  ordem         INT NOT NULL DEFAULT 100,
  ativo         TINYINT(1) NOT NULL DEFAULT 1,
  ultimo_estado    VARCHAR(20)  NULL,
  ultima_mensagem  VARCHAR(500) NULL,
  ultima_coleta_em DATETIME NULL,
  criado_em     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ambiente_codigo (codigo)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS coleta (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  gerada_em       DATETIME NULL,
  cluster_id      BIGINT UNSIGNED NULL,
  importada_em    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  itens_criados   INT UNSIGNED NOT NULL DEFAULT 0,
  itens_alterados INT UNSIGNED NOT NULL DEFAULT 0,
  itens_inativados INT UNSIGNED NOT NULL DEFAULT 0,
  itens_lidos     INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS iconf_item (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  iconf         VARCHAR(20) NULL,
  tipo          ENUM('CLUSTER','NODE','VM','CT') NOT NULL,
  chave_origem  VARCHAR(190) NOT NULL,
  nome          VARCHAR(190) NOT NULL,
  pai_id        BIGINT UNSIGNED NULL,
  ativo         TINYINT(1) NOT NULL DEFAULT 1,
  criado_em     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  ultima_coleta_id BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_iconf (iconf),
  KEY ix_tipo_chave (tipo, chave_origem),
  KEY ix_pai (pai_id),
  CONSTRAINT fk_item_pai FOREIGN KEY (pai_id) REFERENCES iconf_item (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_item_coleta FOREIGN KEY (ultima_coleta_id) REFERENCES coleta (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS iconf_atributo (
  item_id       BIGINT UNSIGNED NOT NULL,
  campo         VARCHAR(60) NOT NULL,
  valor         TEXT NULL,
  atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (item_id, campo),
  CONSTRAINT fk_attr_item FOREIGN KEY (item_id) REFERENCES iconf_item (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS iconf_historico (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  item_id         BIGINT UNSIGNED NOT NULL,
  coleta_id       BIGINT UNSIGNED NOT NULL,
  campo           VARCHAR(60) NOT NULL,
  valor_anterior  TEXT NULL,
  valor_novo      TEXT NULL,
  alterado_por    VARCHAR(190) NULL,
  registrado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_hist_item (item_id, id),
  KEY ix_hist_coleta (coleta_id),
  CONSTRAINT fk_hist_item FOREIGN KEY (item_id) REFERENCES iconf_item (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_hist_coleta FOREIGN KEY (coleta_id) REFERENCES coleta (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS agendamento (
  id              TINYINT UNSIGNED NOT NULL,
  modo            VARCHAR(10) NOT NULL DEFAULT 'desligado',
  hora            CHAR(5) NOT NULL DEFAULT '02:00',
  ultimo_slot     DATETIME NULL,
  ultima_execucao DATETIME NULL,
  ultimo_estado   VARCHAR(20) NULL,
  ultima_mensagem TEXT NULL,
  ultimo_pulso    DATETIME NULL,
  atualizado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB;
INSERT IGNORE INTO agendamento (id, modo, hora) VALUES (1, 'desligado', '02:00');

DELIMITER //

DROP TRIGGER IF EXISTS trg_item_no_delete//
CREATE TRIGGER trg_item_no_delete BEFORE DELETE ON iconf_item
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CMDB: itens de configuracao nao podem ser removidos';
END//

DROP TRIGGER IF EXISTS trg_atributo_no_delete//
CREATE TRIGGER trg_atributo_no_delete BEFORE DELETE ON iconf_atributo
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CMDB: atributos nao podem ser removidos';
END//

DROP TRIGGER IF EXISTS trg_historico_no_delete//
CREATE TRIGGER trg_historico_no_delete BEFORE DELETE ON iconf_historico
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CMDB: historico nao pode ser removido';
END//

DROP TRIGGER IF EXISTS trg_historico_no_update//
CREATE TRIGGER trg_historico_no_update BEFORE UPDATE ON iconf_historico
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CMDB: historico nao pode ser alterado';
END//

DROP TRIGGER IF EXISTS trg_coleta_no_delete//
CREATE TRIGGER trg_coleta_no_delete BEFORE DELETE ON coleta
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CMDB: coletas nao podem ser removidas';
END//

DROP TRIGGER IF EXISTS trg_item_iconf_imutavel//
CREATE TRIGGER trg_item_iconf_imutavel BEFORE UPDATE ON iconf_item
FOR EACH ROW
BEGIN
  IF OLD.iconf IS NOT NULL AND NOT (NEW.iconf <=> OLD.iconf) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CMDB: ICONF nao pode ser alterado';
  END IF;
  IF NEW.tipo <> OLD.tipo THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CMDB: tipo do item nao pode ser alterado';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_usuario_no_delete//
CREATE TRIGGER trg_usuario_no_delete BEFORE DELETE ON usuario
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CMDB: usuarios nao podem ser removidos (desative-os)';
END//

DROP TRIGGER IF EXISTS trg_ambiente_no_delete//
CREATE TRIGGER trg_ambiente_no_delete BEFORE DELETE ON ambiente
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CMDB: ambientes nao podem ser removidos (desative-os)';
END//

DROP TRIGGER IF EXISTS trg_ambiente_codigo_imutavel//
CREATE TRIGGER trg_ambiente_codigo_imutavel BEFORE UPDATE ON ambiente
FOR EACH ROW
BEGIN
  IF NEW.codigo <> OLD.codigo THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CMDB: código do ambiente nao pode ser alterado';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_agendamento_no_delete//
CREATE TRIGGER trg_agendamento_no_delete BEFORE DELETE ON agendamento
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CMDB: agendamento nao pode ser removido';
END//

DELIMITER ;

CREATE USER IF NOT EXISTS 'cmdb_app'@'localhost' IDENTIFIED BY 'TROQUE_ESTA_SENHA';
GRANT SELECT, INSERT, UPDATE ON cmdb.* TO 'cmdb_app'@'localhost';
FLUSH PRIVILEGES;
