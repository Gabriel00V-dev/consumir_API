# Trabalho 1 — Consumindo APIs públicas

**Linguagem de Programação VIII** · UNIFEG — Ciência da Computação, 8º período
Professor: José Bruno de Oliveira

| Item | Valor |
|---|---|
| API pública | **BrasilAPI** |
| Recurso escolhido | **CNPJ** — `GET https://brasilapi.com.br/api/cnpj/v1/{cnpj}` |
| Tabela | `consultas` |
| Banco | `consumir_api` (MySQL / MariaDB do XAMPP) |
| Pasta | `C:\xampp\htdocs\consumir_API` |
| URL da aplicação | <http://localhost/consumir_API/> |

A aplicação consulta um CNPJ na BrasilAPI, guarda o essencial no MySQL e mostra
os registros salvos em uma interface simples, com **CRUD completo** (criar, ler,
alterar e excluir).

---

## 1. Arquitetura — o caminho dos dados

```
┌──────────────────────┐                ┌──────────────────────┐              ┌──────────────────────┐
│      INTERFACE       │   fetch()      │    SEU SERVIDOR      │   cURL       │    API PÚBLICA       │
│   HTML + JavaScript  │ ─────────────► │    PHP + Apache      │ ───────────► │      BrasilAPI       │
│                      │ ◄───────────── │                      │ ◄─────────── │   /api/cnpj/v1/{cnpj}│
│  index.html          │   JSON         │  api/consultas.php   │   JSON       │                      │
│  assets/app.js       │                │  src/*.php           │              │                      │
└──────────────────────┘                └──────────┬───────────┘              └──────────────────────┘
           ╎                                       │
           ╎  acesso direto ✘                      │ INSERT / SELECT / UPDATE / DELETE
           ╎  (proibido)                           │ (sempre com prepared statement)
           ╎                                       ▼
           ╎                            ┌──────────────────────┐
           └ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─►│     BANCO MySQL      │
                         ✘              │  consumir_api        │
                                        │  tabela: consultas   │
                                        └──────────────────────┘
```

### Quem faz o quê

| Camada | Arquivo | Responsabilidade |
|---|---|---|
| Interface (View) | `index.html`, `assets/app.js` | Campo de busca, botão, tabela de resultados. Só fala com o **nosso** servidor, por `fetch()`. |
| Controller | `api/consultas.php` | Recebe a requisição, valida, decide o status HTTP e **delega**: chama a API pública e o Model. |
| Cliente da API | `src/BrasilApi.php` | Faz a chamada cURL à BrasilAPI e mapeia a resposta para as colunas da tabela. |
| Validação | `src/Validador.php` | Valida o CNPJ **no servidor** (14 dígitos + dígitos verificadores). |
| Model | `src/ConsultaModel.php` | Único lugar que fala SQL. Todo comando usa prepared statement. |
| Resposta | `src/Resposta.php` | Padroniza a saída JSON e o status HTTP. |
| Conexão | `config/conexao.php` | Credenciais do banco. **Roda só no servidor.** |

### Por que o navegador não chama a API pública nem o banco direto?

O navegador roda no computador do usuário e **não é confiável**: tudo que está
nele pode ser lido e alterado (F12 → Sources / Network).

1. **Credenciais expostas** — se o JavaScript conectasse no MySQL, usuário e
   senha do banco estariam no código, visíveis para qualquer um.
2. **Regras ignoradas** — a validação em JavaScript pode ser contornada por
   Postman, curl ou DevTools. A regra precisa ter a última palavra no servidor.
3. **Dados à mercê de qualquer um** — sem o servidor no meio, qualquer pessoa
   poderia ler, alterar ou apagar a tabela inteira.
4. **CORS e controle** — a API pública pode bloquear a chamada do navegador; e
   centralizando no servidor podemos tratar timeout, erro e cache em um só lugar.

Por isso: **a interface nunca acessa o banco e não chama a BrasilAPI
diretamente.** Quem faz isso é `api/consultas.php`.

---

## 2. Como rodar no XAMPP

1. Ligue o **Apache** e o **MySQL** no painel do XAMPP.
2. Coloque a pasta do projeto em `C:\xampp\htdocs\consumir_API`.
3. Crie o banco e a tabela — escolha um dos caminhos:
   - **phpMyAdmin**: <http://localhost/phpmyadmin> → aba *Importar* →
     selecione `sql/schema.sql` → *Executar*;
   - **linha de comando**:
     ```
     C:\xampp\mysql\bin\mysql.exe -u root < C:\xampp\htdocs\consumir_API\sql\schema.sql
     ```
4. Se o seu MySQL tiver senha para o `root`, ajuste `DB_PASS` em
   `config/conexao.php`.
5. Abra <http://localhost/consumir_API/>.

> **Atenção:** abrir o `index.html` com duplo clique (`file://`) **não funciona** —
> o PHP não é executado assim. Tem que ser pelo `http://localhost/`.

### CNPJs para testar

| CNPJ | O que acontece | Status |
|---|---|---|
| `00.000.000/0001-91` | Banco do Brasil — existe | **201 Created** (ou 200 se já estava salvo) |
| `99.999.999/9999-62` | Formato válido, não existe na Receita | **404 Not Found** |
| `11.111.111/1111-11` | Dígitos verificadores errados | **400 Bad Request** |

---

## 3. Endpoints

Base: `http://localhost/consumir_API/api/consultas.php`

| Operação | Método | URL | Corpo | Sucesso |
|---|---|---|---|---|
| **Create** — consultar e salvar | `POST` | `/api/consultas.php` | `{"cnpj":"...", "apelido":"...", "observacao":"..."}` | `201 Created` |
| **Read** — listar salvos | `GET` | `/api/consultas.php` | — | `200 OK` |
| **Read** — filtrar | `GET` | `/api/consultas.php?busca=banco` | — | `200 OK` |
| **Read** — um registro | `GET` | `/api/consultas.php?id=1` | — | `200 OK` |
| **Update parcial** | `PATCH` | `/api/consultas.php?id=1` | `{"apelido":"..."}` | `200 OK` |
| **Update total** | `PUT` | `/api/consultas.php?id=1` | `{"apelido":"...","observacao":"..."}` | `200 OK` |
| **Delete** | `DELETE` | `/api/consultas.php?id=1` | — | `204 No Content` |

### PUT × PATCH neste projeto

- **PATCH** envia **só o que mudou**. Mandar `{"apelido":"BB"}` altera o apelido
  e deixa a observação como estava.
- **PUT** **substitui** os campos editáveis. Mandar `{"apelido":"BB"}` sem a
  observação faz a observação virar `NULL`.

### Códigos de status usados

| Status | Quando |
|---|---|
| `200 OK` | Listagem, leitura, PATCH, PUT e POST de um CNPJ já salvo |
| `201 Created` | POST que criou um registro novo |
| `204 No Content` | DELETE concluído (resposta sem corpo) |
| `400 Bad Request` | CNPJ ausente, CNPJ com dígito verificador inválido, `id` não numérico, PATCH sem nenhum campo |
| `404 Not Found` | CNPJ não existe na Receita, ou `id` não existe na tabela |
| `405 Method Not Allowed` | Método fora de GET/POST/PATCH/PUT/DELETE (devolve `Allow`) |
| `429 Too Many Requests` | Limite de requisições da BrasilAPI |
| `500 Internal Server Error` | Falha nossa: MySQL parado, banco não criado, erro de SQL |
| `502 Bad Gateway` | A BrasilAPI não respondeu ou respondeu com erro |

Todo erro sai no mesmo formato:

```json
{
  "erro": {
    "codigo": "cnpj_nao_encontrado",
    "mensagem": "CNPJ nao encontrado na base da Receita Federal (a BrasilAPI respondeu 404)."
  }
}
```

---

## 4. Banco de dados

Script completo em [`sql/schema.sql`](sql/schema.sql).

```sql
CREATE TABLE consultas (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    cnpj               CHAR(14)      NOT NULL,   -- somente dígitos
    razao_social       VARCHAR(255)  NOT NULL,
    nome_fantasia      VARCHAR(255)      NULL,
    situacao_cadastral VARCHAR(60)       NULL,
    municipio          VARCHAR(120)      NULL,
    uf                 CHAR(2)           NULL,
    cnae_principal     VARCHAR(255)      NULL,
    apelido            VARCHAR(100)      NULL,   -- campo do usuário (PATCH/PUT)
    observacao         VARCHAR(255)      NULL,   -- campo do usuário (PATCH/PUT)
    criado_em          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
                                     ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_consultas_cnpj (cnpj),
    KEY idx_consultas_uf (uf)
) ENGINE = InnoDB;
```

Guardamos **só o essencial** da resposta da API, não o JSON inteiro.

A chave `UNIQUE` em `cnpj` é proteção de **idempotência**: um duplo clique no
botão não cria dois registros iguais. O front também desabilita o botão durante
a requisição, mas isso sozinho não bastaria — o Postman passaria direto.

---

## 5. Boas práticas atendidas

- **Prepared statements** em todo comando que recebe dado de fora
  (`src/ConsultaModel.php`). O SQL vai separado dos valores → sem injeção de SQL.
  O PATCH ainda usa uma **lista branca** de colunas (`apelido`, `observacao`),
  então nem o nome da coluna vem do cliente.
- **Validação no servidor**, mesmo com o front validando: o JavaScript só avisa
  rápido; `src/Validador.php` é quem decide.
- **Nenhuma credencial do banco no JavaScript** — `config/conexao.php` só roda
  no servidor e nunca é enviado ao navegador.
- **Sem exclusão ou alteração por link GET** — `DELETE`, `PATCH` e `PUT` são
  disparados por `fetch()`, com confirmação do usuário antes de excluir.
  GET é um método *seguro*: só lê. Excluir por `<a href="excluir.php?id=15">`
  deixaria a aplicação exposta a robôs de busca, pré-carregamento do navegador
  e requisições forjadas por outra página.
- **Tratamento de erro em todas as camadas**: CNPJ inválido (400), inexistente
  (404), API fora do ar (502), limite excedido (429), MySQL parado (500). A
  interface transforma cada um deles em mensagem amigável.
- **Status HTTP coerentes** e corpo sempre em JSON.

---

## 6. Postman

Coleção pronta em
[`postman-collection/consumir_API.postman_collection.json`](postman-collection/consumir_API.postman_collection.json) —
importe no Postman em *Import → File*.

> **Importante:** importe copiando esse arquivo para fora da pasta do projeto
> (ex.: Área de Trabalho) antes de abrir no Postman. Se o Postman estiver em
> modo "Local"/Scratch Pad, ele pode "adotar" a pasta de origem como área de
> armazenamento dele e reescrever o `.json` no formato interno dele (YAML).
> Isso não afeta o funcionamento da aplicação — só evite editar a coleção
> diretamente dentro da pasta do repositório.

São 12 requisições, incluindo as três exigidas e **5 casos de erro propositais**:

| # | Requisição | Esperado |
|---|---|---|
| 1 | Direto na API pública (BrasilAPI) | 200 |
| 2 | Nosso servidor — POST consultar e salvar | 201 |
| 3 | Nosso servidor — GET listar salvos | 200 |
| 4 | Nosso servidor — GET um registro | 200 |
| 5 | **Erro:** CNPJ com dígito inválido | **400** |
| 6 | **Erro:** CNPJ inexistente na Receita | **404** |
| 7 | **Erro:** POST sem o campo `cnpj` | **400** |
| 8 | **Erro:** DELETE de id inexistente | **404** |
| 9 | PATCH — alteração parcial | 200 |
| 10 | PUT — substituição | 200 |
| 11 | DELETE — exclusão | 204 |
| 12 | **Erro:** método não permitido | **405** |

As requisições usam as variáveis `{{base}}`, `{{cnpj_valido}}`,
`{{cnpj_inexistente}}`, `{{cnpj_invalido}}` e `{{id}}` — a de id é preenchida
automaticamente pela requisição 2.

---

## 7. Estrutura de arquivos

```
consumir_API/
├── index.html                                   interface (busca, lista, modal de edição)
├── assets/
│   ├── app.js                                   fetch() para o nosso servidor; sem credenciais
│   └── style.css
├── api/
│   └── consultas.php                            CONTROLLER: GET, POST, PATCH, PUT, DELETE
├── src/
│   ├── BrasilApi.php                            chamada cURL à API pública
│   ├── ConsultaModel.php                        MODEL: SQL com prepared statements
│   ├── Validador.php                            validação de CNPJ no servidor
│   └── Resposta.php                             saída JSON + status HTTP padronizados
├── config/
│   └── conexao.php                              credenciais do MySQL (só no servidor)
├── sql/
│   └── schema.sql                               criação do banco e da tabela
├── postman-collection/
│   └── consumir_API.postman_collection.json     coleção exportada
└── README.md
```

---

## 8. Roteiro da apresentação

1. **API e aplicação** — BrasilAPI, recurso CNPJ; a aplicação consulta, salva e
   gerencia os registros.
2. **Arquitetura** — o diagrama da seção 1: quem fala com quem.
3. **Postman** — requisição 2 (sucesso, 201) e requisição 6 (erro, 404).
4. **Interface** — consultar `00.000.000/0001-91`, ver a listagem, editar por
   PATCH e excluir por DELETE.
5. **phpMyAdmin** — `consumir_api` → `consultas` com o registro recém-gravado.
6. **Decisão técnica** — por que o front não chama a API nem o banco direto
   (seção 1, "Por que o navegador...").

---

## Referências

- BrasilAPI — <https://brasilapi.com.br/docs> · <https://github.com/BrasilAPI/BrasilAPI>
- IETF, RFC 9110 — HTTP Semantics (métodos seguros e idempotentes) — <https://rfc-editor.org/rfc/rfc9110>
- MDN Web Docs — HTTP request methods — <https://developer.mozilla.org/pt-BR/docs/Web/HTTP/Methods>
- PHP Manual — Prepared Statements (mysqli) — <https://php.net/manual/pt_BR/mysqli.quickstart.prepared-statements.php>
