<?php
/**
 * CONTROLLER - endpoints da aplicacao.
 *
 * Fluxo:  Interface (fetch) -> ESTE ARQUIVO -> BrasilAPI (cURL) -> MySQL
 *
 *  POST   /api/consultas.php           cria   - consulta o CNPJ na BrasilAPI e grava  (201)
 *  GET    /api/consultas.php           lista  - todos os registros salvos             (200)
 *  GET    /api/consultas.php?id=1      le     - um registro                      (200 / 404)
 *  GET    /api/consultas.php?busca=x   lista  - filtrada                              (200)
 *  PATCH  /api/consultas.php?id=1      altera - parcial (apelido e/ou observacao)     (200)
 *  PUT    /api/consultas.php?id=1      altera - substitui os campos editaveis         (200)
 *  DELETE /api/consultas.php?id=1      exclui - remove o registro                     (204)
 *
 * Nenhuma alteracao ou exclusao acontece por GET: GET e um metodo "seguro",
 * ou seja, so le. Alterar por link GET deixaria o sistema aberto a buscadores,
 * pre-carregamento do navegador e requisicoes forjadas por outra pagina.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../src/Resposta.php';
require_once __DIR__ . '/../src/Validador.php';
require_once __DIR__ . '/../src/BrasilApi.php';
require_once __DIR__ . '/../src/ConsultaModel.php';

// O erro vai para o log do Apache, nunca para o corpo da resposta JSON.
ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($metodo === 'OPTIONS') {
    header('Allow: GET, POST, PATCH, PUT, DELETE');
    Resposta::json(204, []);
}

try {
    $model = new ConsultaModel(conectar());

    switch ($metodo) {
        case 'GET':
            tratarGet($model);
            break;

        case 'POST':
            tratarPost($model);
            break;

        case 'PATCH':
            tratarPatch($model);
            break;

        case 'PUT':
            tratarPut($model);
            break;

        case 'DELETE':
            tratarDelete($model);
            break;

        default:
            header('Allow: GET, POST, PATCH, PUT, DELETE');
            Resposta::erro(405, "Metodo $metodo nao e aceito neste endpoint.", 'metodo_nao_permitido');
    }
} catch (Throwable $e) {
    // Qualquer falha nossa (MySQL parado, SQL errado) e 500 - erro do servidor.
    error_log('[consumir_API] ' . $e->getMessage());
    Resposta::erro(
        500,
        'Erro interno no servidor. Verifique se o MySQL esta ativo e se o script sql/schema.sql foi executado.',
        'erro_servidor'
    );
}

// ---------------------------------------------------------------------------
// Handlers
// ---------------------------------------------------------------------------

/** GET - lista tudo, filtra por busca ou devolve um registro. */
function tratarGet(ConsultaModel $model): void
{
    if (isset($_GET['id'])) {
        $id = lerId($_GET['id']);
        $registro = $model->buscarPorId($id);

        if ($registro === null) {
            Resposta::erro(404, "Nenhum registro salvo com o id $id.", 'nao_encontrado');
        }

        Resposta::json(200, ['dados' => $registro]);
    }

    $busca = isset($_GET['busca']) ? trim((string) $_GET['busca']) : null;
    $lista = $model->listar($busca !== '' ? $busca : null);

    Resposta::json(200, [
        'total' => count($lista),
        'dados' => $lista,
    ]);
}

/**
 * POST - o coracao do trabalho:
 * valida -> chama a API publica -> grava no banco -> devolve JSON.
 */
function tratarPost(ConsultaModel $model): void
{
    $corpo = Resposta::corpoJson();

    // Aceita tambem formulario tradicional ($_POST), caso o cliente envie assim.
    if ($corpo === [] && $_POST !== []) {
        $corpo = $_POST;
    }

    $cnpjBruto = isset($corpo['cnpj']) ? (string) $corpo['cnpj'] : '';

    if (trim($cnpjBruto) === '') {
        Resposta::erro(400, 'Informe o campo "cnpj" no corpo da requisicao.', 'cnpj_ausente');
    }

    $cnpj = Validador::somenteDigitos($cnpjBruto);

    // VALIDACAO NO SERVIDOR: nao adianta so o front validar, o Postman passa direto.
    if (!Validador::cnpjValido($cnpj)) {
        Resposta::erro(
            400,
            'CNPJ invalido. Envie 14 digitos com digitos verificadores corretos (ex.: 11.222.333/0001-81).',
            'cnpj_invalido'
        );
    }

    $apelido    = Validador::textoOpcional($corpo['apelido']    ?? null, 100);
    $observacao = Validador::textoOpcional($corpo['observacao'] ?? null, 255);

    // --- chamada a API publica (feita pelo servidor, nunca pelo navegador) ---
    $resposta = BrasilApi::consultarCnpj($cnpj);

    if ($resposta['falhaRede'] !== null) {
        Resposta::erro(
            502,
            'Nao foi possivel falar com a BrasilAPI: ' . $resposta['falhaRede'],
            'api_externa_indisponivel'
        );
    }

    if ($resposta['status'] === 404) {
        Resposta::erro(
            404,
            'CNPJ nao encontrado na base da Receita Federal (a BrasilAPI respondeu 404).',
            'cnpj_nao_encontrado'
        );
    }

    if ($resposta['status'] === 400) {
        Resposta::erro(400, 'A BrasilAPI recusou o CNPJ informado (status 400).', 'cnpj_recusado_pela_api');
    }

    if ($resposta['status'] === 429) {
        Resposta::erro(
            429,
            'Limite de requisicoes da BrasilAPI atingido. Tente novamente em instantes.',
            'limite_excedido'
        );
    }

    if ($resposta['status'] < 200 || $resposta['status'] >= 300 || $resposta['dados'] === null) {
        Resposta::erro(502, 'A BrasilAPI respondeu com status ' . $resposta['status'] . '.', 'api_externa_com_erro');
    }

    $dados = BrasilApi::mapearParaTabela($resposta['dados']);

    // --- grava no banco ---
    $existente = $model->buscarPorCnpj($cnpj);

    if ($existente !== null) {
        // CNPJ ja salvo: atualiza em vez de duplicar. A coluna cnpj e UNIQUE
        // justamente para que um duplo clique nao crie dois registros iguais.
        $model->atualizarDadosDaApi((int) $existente['id'], $dados);

        $camposUsuario = [];
        if ($apelido !== null) {
            $camposUsuario['apelido'] = $apelido;
        }
        if ($observacao !== null) {
            $camposUsuario['observacao'] = $observacao;
        }
        $model->atualizarParcial((int) $existente['id'], $camposUsuario);

        Resposta::json(200, [
            'mensagem' => 'Este CNPJ ja estava salvo. Os dados foram atualizados.',
            'novo'     => false,
            'dados'    => $model->buscarPorId((int) $existente['id']),
        ]);
    }

    $id = $model->inserir($cnpj, $dados, $apelido, $observacao);

    header('Location: /consumir_API/api/consultas.php?id=' . $id);
    Resposta::json(201, [
        'mensagem' => 'Consulta realizada e salva com sucesso.',
        'novo'     => true,
        'dados'    => $model->buscarPorId($id),
    ]);
}

/** PATCH - atualizacao PARCIAL: envia so o que mudou. */
function tratarPatch(ConsultaModel $model): void
{
    $id    = lerId($_GET['id'] ?? null);
    $corpo = Resposta::corpoJson();

    if ($model->buscarPorId($id) === null) {
        Resposta::erro(404, "Nenhum registro salvo com o id $id.", 'nao_encontrado');
    }

    $campos = [];

    if (array_key_exists('apelido', $corpo)) {
        $campos['apelido'] = Validador::textoOpcional($corpo['apelido'], 100);
    }

    if (array_key_exists('observacao', $corpo)) {
        $campos['observacao'] = Validador::textoOpcional($corpo['observacao'], 255);
    }

    if ($campos === []) {
        Resposta::erro(
            400,
            'Envie ao menos um campo editavel: "apelido" ou "observacao".',
            'nada_para_atualizar'
        );
    }

    $model->atualizarParcial($id, $campos);

    Resposta::json(200, [
        'mensagem' => 'Registro atualizado (atualizacao parcial).',
        'dados'    => $model->buscarPorId($id),
    ]);
}

/** PUT - substitui os campos editaveis por inteiro (o que faltar vira NULL). */
function tratarPut(ConsultaModel $model): void
{
    $id    = lerId($_GET['id'] ?? null);
    $corpo = Resposta::corpoJson();

    if ($model->buscarPorId($id) === null) {
        Resposta::erro(404, "Nenhum registro salvo com o id $id.", 'nao_encontrado');
    }

    $apelido    = Validador::textoOpcional($corpo['apelido']    ?? null, 100);
    $observacao = Validador::textoOpcional($corpo['observacao'] ?? null, 255);

    $model->substituir($id, $apelido, $observacao);

    Resposta::json(200, [
        'mensagem' => 'Registro substituido (campos nao enviados ficaram vazios).',
        'dados'    => $model->buscarPorId($id),
    ]);
}

/** DELETE - remove o registro salvo. */
function tratarDelete(ConsultaModel $model): void
{
    $id = lerId($_GET['id'] ?? null);

    if (!$model->excluir($id)) {
        Resposta::erro(404, "Nenhum registro salvo com o id $id.", 'nao_encontrado');
    }

    Resposta::json(204, []);
}

// ---------------------------------------------------------------------------
// Apoio
// ---------------------------------------------------------------------------

/** Converte e valida o id recebido pela query string. */
function lerId($valor): int
{
    if (!is_string($valor) || preg_match('/^\d+$/', $valor) !== 1) {
        Resposta::erro(400, 'Informe um id numerico na URL, por exemplo ?id=1', 'id_invalido');
    }

    $id = (int) $valor;

    if ($id <= 0) {
        Resposta::erro(400, 'O id precisa ser maior que zero.', 'id_invalido');
    }

    return $id;
}
