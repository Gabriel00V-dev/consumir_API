-- =====================================================================
-- Trabalho 1 - Linguagem de Programacao VIII
-- API publica sorteada: BrasilAPI  (recurso escolhido: CNPJ)
-- Banco: consumir_api        Tabela: consultas
--
-- Como executar:
--   phpMyAdmin > aba "Importar" > selecione este arquivo > Executar
--   ou:  C:\xampp\mysql\bin\mysql.exe -u root < sql\schema.sql
-- =====================================================================

CREATE DATABASE IF NOT EXISTS consumir_api
    DEFAULT CHARACTER SET utf8mb4
    DEFAULT COLLATE utf8mb4_unicode_ci;

USE consumir_api;

DROP TABLE IF EXISTS consultas;

CREATE TABLE consultas (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- dados vindos da BrasilAPI (/api/cnpj/v1/{cnpj})
    cnpj               CHAR(14)      NOT NULL,            -- somente digitos
    razao_social       VARCHAR(255)  NOT NULL,
    nome_fantasia      VARCHAR(255)      NULL,
    situacao_cadastral VARCHAR(60)       NULL,
    municipio          VARCHAR(120)      NULL,
    uf                 CHAR(2)           NULL,
    cnae_principal     VARCHAR(255)      NULL,

    -- dados do usuario (editaveis por PATCH / PUT)
    apelido            VARCHAR(100)      NULL,
    observacao         VARCHAR(255)      NULL,

    criado_em          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
                                     ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    -- evita que o duplo clique no botao "Consultar" crie dois registros iguais
    UNIQUE KEY uq_consultas_cnpj (cnpj),
    KEY idx_consultas_uf (uf)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;
