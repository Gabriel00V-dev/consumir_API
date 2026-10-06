<?php

declare(strict_types=1);

const DB_HOST = '127.0.0.1';
const DB_PORT = 3306;
const DB_USER = 'root';
const DB_PASS = '';
const DB_NAME = 'consumir_api';

function conectar(): mysqli
{
    static $conexao = null;

    if ($conexao instanceof mysqli) {
        return $conexao;
    }

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
