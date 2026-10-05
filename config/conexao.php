<?php
/**
 * Conexao com o MySQL do XAMPP.
 *
 * Este arquivo roda SOMENTE no servidor. As credenciais nunca sao enviadas
 * ao navegador: a interface fala com os endpoints em /api, e sao eles que
 * falam com o banco.
 */

declare(strict_types=1);

const DB_HOST = '127.0.0.1';
const DB_PORT = 3306;
const DB_USER = 'root';
const DB_PASS = '';          // XAMPP padrao: root sem senha
const DB_NAME = 'consumir_api';

/**
 * Devolve a conexao mysqli ja configurada em UTF-8.
 *
 * @throws RuntimeException quando o MySQL esta parado ou o banco nao existe.
 */
function conectar(): mysqli
{
    static $conexao = null;

    if ($conexao instanceof mysqli) {
        return $conexao;
    }

    // Desliga as excecoes automaticas para tratar o erro com mensagem propria.
    mysqli_report(MYSQLI_REPORT_OFF);

    $conexao = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);

    if ($conexao->connect_errno !== 0) {
        throw new RuntimeException(
            'Falha ao conectar no MySQL: ' . $conexao->connect_error
        );
    }

    $conexao->set_charset('utf8mb4');

    return $conexao;
}
