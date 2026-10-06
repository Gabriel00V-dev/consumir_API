<?php

declare(strict_types=1);

final class ConsultaModel
{
    public function __construct(private mysqli $db)
    {
    }

    private const CAMPOS = 'id, cnpj, razao_social, nome_fantasia, situacao_cadastral,
                            municipio, uf, cnae_principal, apelido, observacao,
                            criado_em, atualizado_em';

    public function listar(?string $busca = null): array
    {
        if ($busca === null || $busca === '') {
            $sql  = 'SELECT ' . self::CAMPOS . ' FROM consultas ORDER BY atualizado_em DESC';
            $stmt = $this->preparar($sql);
        } else {
            $curingaNome  = '%' . $busca . '%';
            $digitos      = Validador::somenteDigitos($busca);

            if ($digitos === '') {
                $sql  = 'SELECT ' . self::CAMPOS . ' FROM consultas
                         WHERE razao_social LIKE ? OR nome_fantasia LIKE ?
                         ORDER BY atualizado_em DESC';
                $stmt = $this->preparar($sql);
                $stmt->bind_param('ss', $curingaNome, $curingaNome);
            } else {
                $curingaCnpj = '%' . $digitos . '%';
                $sql  = 'SELECT ' . self::CAMPOS . ' FROM consultas
                         WHERE cnpj LIKE ? OR razao_social LIKE ? OR nome_fantasia LIKE ?
                         ORDER BY atualizado_em DESC';
                $stmt = $this->preparar($sql);
                $stmt->bind_param('sss', $curingaCnpj, $curingaNome, $curingaNome);
            }
        }

        $stmt->execute();
        $linhas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return array_map([$this, 'formatar'], $linhas);
    }

    public function buscarPorId(int $id): ?array
    {
        $stmt = $this->preparar('SELECT ' . self::CAMPOS . ' FROM consultas WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $linha = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $linha === null ? null : $this->formatar($linha);
    }

    public function buscarPorCnpj(string $cnpj): ?array
    {
        $stmt = $this->preparar('SELECT ' . self::CAMPOS . ' FROM consultas WHERE cnpj = ?');
        $stmt->bind_param('s', $cnpj);
        $stmt->execute();
        $linha = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $linha === null ? null : $this->formatar($linha);
    }

    public function inserir(string $cnpj, array $dados, ?string $apelido, ?string $observacao): int
    {
        $stmt = $this->preparar(
            'INSERT INTO consultas
                (cnpj, razao_social, nome_fantasia, situacao_cadastral,
                 municipio, uf, cnae_principal, apelido, observacao)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $stmt->bind_param(
            'sssssssss',
            $cnpj,
            $dados['razao_social'],
            $dados['nome_fantasia'],
            $dados['situacao_cadastral'],
            $dados['municipio'],
            $dados['uf'],
            $dados['cnae_principal'],
            $apelido,
            $observacao
        );

        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();

        return $id;
    }

    public function atualizarDadosDaApi(int $id, array $dados): void
    {
        $stmt = $this->preparar(
            'UPDATE consultas
                SET razao_social = ?, nome_fantasia = ?, situacao_cadastral = ?,
                    municipio = ?, uf = ?, cnae_principal = ?
              WHERE id = ?'
        );

        $stmt->bind_param(
            'ssssssi',
            $dados['razao_social'],
            $dados['nome_fantasia'],
            $dados['situacao_cadastral'],
            $dados['municipio'],
            $dados['uf'],
            $dados['cnae_principal'],
            $id
        );

        $stmt->execute();
        $stmt->close();
    }

    public function atualizarParcial(int $id, array $campos): void
    {
        if ($campos === []) {
            return;
        }

        $permitidos = ['apelido', 'observacao'];
        $pedacos    = [];
        $tipos      = '';
        $valores    = [];

        foreach ($campos as $coluna => $valor) {
            if (!in_array($coluna, $permitidos, true)) {
                continue;
            }
            $pedacos[] = "$coluna = ?";
            $tipos    .= 's';
            $valores[] = $valor;
        }

        if ($pedacos === []) {
            return;
        }

        $sql  = 'UPDATE consultas SET ' . implode(', ', $pedacos) . ' WHERE id = ?';
        $stmt = $this->preparar($sql);

        $tipos    .= 'i';
        $valores[] = $id;

        $stmt->bind_param($tipos, ...$valores);
        $stmt->execute();
        $stmt->close();
    }

    public function substituir(int $id, ?string $apelido, ?string $observacao): void
    {
        $stmt = $this->preparar(
            'UPDATE consultas SET apelido = ?, observacao = ? WHERE id = ?'
        );
        $stmt->bind_param('ssi', $apelido, $observacao, $id);
        $stmt->execute();
        $stmt->close();
    }

    public function excluir(int $id): bool
    {
        $stmt = $this->preparar('DELETE FROM consultas WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $afetadas = $stmt->affected_rows;
        $stmt->close();

        return $afetadas > 0;
    }

    private function preparar(string $sql): mysqli_stmt
    {
        $stmt = $this->db->prepare($sql);

        if ($stmt === false) {
            throw new RuntimeException('Erro ao preparar SQL: ' . $this->db->error);
        }

        return $stmt;
    }

    private function formatar(array $linha): array
    {
        $linha['id']               = (int) $linha['id'];
        $linha['cnpj_formatado']   = Validador::formatarCnpj((string) $linha['cnpj']);

        return $linha;
    }
}
