<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../src/Resposta.php';
require_once __DIR__ . '/../src/Validador.php';
require_once __DIR__ . '/../src/BrasilApi.php';
require_once __DIR__ . '/../src/ConsultaModel.php';

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
    error_log('[consumir_API] ' . $e->getMessage());
    Resposta::erro(
        500,
        'Erro interno no servidor. Verifique se o MySQL esta ativo e se o script sql/schema.sql foi executado.',
        'erro_servidor'
    );
}

// GET
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

// POST
function tratarPost(ConsultaModel $model): void
{
    $corpo = Resposta::corpoJson();

    if ($corpo === [] && $_POST !== []) {
        $corpo = $_POST;
    }

    $cnpjBruto = isset($corpo['cnpj']) ? (string) $corpo['cnpj'] : '';

    if (trim($cnpjBruto) === '') {
        Resposta::erro(400, 'Informe o campo "cnpj" no corpo da requisicao.', 'cnpj_ausente');
    }

    $cnpj = Validador::somenteDigitos($cnpjBruto);

    if (!Validador::cnpjValido($cnpj)) {
        Resposta::erro(
            400,
            'CNPJ invalido. Envie 14 digitos com digitos verificadores corretos (ex.: 11.222.333/0001-81).',
            'cnpj_invalido'
        );
    }

    $apelido    = Validador::textoOpcional($corpo['apelido']    ?? null, 100);
    $observacao = Validador::textoOpcional($corpo['observacao'] ?? null, 255);

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

    $existente = $model->buscarPorCnpj($cnpj);

    if ($existente !== null) {
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

// PATCH
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

// PUT
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

// DELETE
function tratarDelete(ConsultaModel $model): void
{
    $id = lerId($_GET['id'] ?? null);

    if (!$model->excluir($id)) {
        Resposta::erro(404, "Nenhum registro salvo com o id $id.", 'nao_encontrado');
    }

    Resposta::json(204, []);
}

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
