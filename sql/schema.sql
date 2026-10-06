CREATE DATABASE IF NOT EXISTS consumir_api
    DEFAULT CHARACTER SET utf8mb4
    DEFAULT COLLATE utf8mb4_unicode_ci;

USE consumir_api;

DROP TABLE IF EXISTS consultas;

CREATE TABLE consultas (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,

    cnpj               CHAR(14)      NOT NULL,
    razao_social       VARCHAR(255)  NOT NULL,
    nome_fantasia      VARCHAR(255)      NULL,
    situacao_cadastral VARCHAR(60)       NULL,
    municipio          VARCHAR(120)      NULL,
    uf                 CHAR(2)           NULL,
    cnae_principal     VARCHAR(255)      NULL,

    apelido            VARCHAR(100)      NULL,
    observacao         VARCHAR(255)      NULL,

    criado_em          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
                                     ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_consultas_cnpj (cnpj),
    KEY idx_consultas_uf (uf)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;
