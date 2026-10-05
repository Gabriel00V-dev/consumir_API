<?php
/**
 * Camada de dados (Model) da tabela `consultas`.
 *
 * Todo comando que recebe dado vindo de fora usa PREPARED STATEMENT:
 * o SQL vai separado dos valores, o que elimina a injecao de SQL.
 */

declare(strict_types=1);

final class ConsultaModel
{
    public function __construct(private mysqli $db)
    {
    }

    /** Colunas devolvidas pela API (nunca "SELECT *" solto na resposta). */
    private const CAMPOS = 'id, cnpj, razao_social, nome_fantasia, situacao_cadastral,
                            municipio, uf, cnae_principal, apelido, observacao,
                            criado_em, atualizado_em';

    /**
     * Lista os registros salvos, com busca opcional por NOME ou CNPJ.
     *
     * O termo de busca e comparado de duas formas ao mesmo tempo:
     *   - como texto, contra razao_social e nome_fantasia (busca por nome);
     *   - so com os digitos, contra a coluna cnpj (busca por CNPJ), o que
     *     permite digitar o CNPJ com ou sem pontuacao (00.000.000/0001-91
     *     ou 00000000000191 encontram o mesmo registro).
     *
     * @return array<int,array<string,mixed>>
     */
    public function listar(?string $busca = null): array
    {
        if ($busca === null || $busca === '') {
            $sql  = 'SELECT ' . self::CAMPOS . ' FROM consultas ORDER BY atualizado_em DESC';
            $stmt = $this->preparar($sql);
        } else {
            $curingaNome  = '%' . $busca . '%';
            $digitos      = Validador::somenteDigitos($busca);

            if ($digitos === '') {
                // O usuario digitou so letras: nao faz sentido comparar com o CNPJ.
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

    /** Busca um registro pelo id. */
    public function buscarPorId(int $id): ?array
    {
        $stmt = $this->preparar('SELECT ' . self::CAMPOS . ' FROM consultas WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $linha = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $linha === null ? null : $this->formatar($linha);
    }

    /** Busca um registro pelo CNPJ (somente digitos). */
    public function buscarPorCnpj(string $cnpj): ?array
    {
        $stmt = $this->preparar('SELECT ' . self::CAMPOS . ' FROM consultas WHERE cnpj = ?');
        $stmt->bind_param('s', $cnpj);
        $stmt->execute();
        $linha = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $linha === null ? null : $this->formatar($linha);
    }

    /**
     * Grava uma consulta nova.
     *
     * @param  array<string,string|null> $dados
     * @return int id gerado
     */
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

    /**
     * Atualiza os campos vindos da API em um registro ja existente
     * (usado quando o mesmo CNPJ e consultado de novo).
     *
     * @param array<string,string|null> $dados
     */
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

    /**
     * PATCH: atualizacao PARCIAL. Monta o SET so com os campos enviados.
     *
     * @param array<string,string|null> $campos ex.: ['apelido' => 'Cliente X']
     */
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
                continue;                         // lista branca: nada de coluna arbitraria
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

    /**
     * PUT: substitui TODOS os campos editaveis. O que nao vier no corpo
     * e gravado como NULL - e essa a diferenca para o PATCH.
     */
    public function substituir(int $id, ?string $apelido, ?string $observacao): void
    {
        $stmt = $this->preparar(
            'UPDATE consultas SET apelido = ?, observacao = ? WHERE id = ?'
        );
        $stmt->bind_param('ssi', $apelido, $observacao, $id);
        $stmt->execute();
        $stmt->close();
    }

    /** DELETE: remove o registro. Devolve false se o id nao existia. */
    public function excluir(int $id): bool
    {
        $stmt = $this->preparar('DELETE FROM consultas WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $afetadas = $stmt->affected_rows;
        $stmt->close();

        return $afetadas > 0;
    }

    /** Prepara o statement e falha alto caso o SQL esteja errado. */
    private function preparar(string $sql): mysqli_stmt
    {
        $stmt = $this->db->prepare($sql);

        if ($stmt === false) {
            throw new RuntimeException('Erro ao preparar SQL: ' . $this->db->error);
        }

        return $stmt;
    }

    /** Ajusta os tipos para o JSON de saida. */
    private function formatar(array $linha): array
    {
        $linha['id']               = (int) $linha['id'];
        $linha['cnpj_formatado']   = Validador::formatarCnpj((string) $linha['cnpj']);

        return $linha;
    }
}
