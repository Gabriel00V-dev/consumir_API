<?php

declare(strict_types=1);

final class Validador
{
    public static function somenteDigitos(string $valor): string
    {
        return preg_replace('/\D+/', '', $valor) ?? '';
    }

    public static function cnpjValido(string $cnpj): bool
    {
        $cnpj = self::somenteDigitos($cnpj);

        if (strlen($cnpj) !== 14) {
            return false;
        }

        if (preg_match('/^(\d)\1{13}$/', $cnpj) === 1) {
            return false;
        }

        foreach ([12, 13] as $posicao) {
            $pesos = $posicao === 12
                ? [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]
                : [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

            $soma = 0;
            foreach ($pesos as $i => $peso) {
                $soma += (int) $cnpj[$i] * $peso;
            }

            $resto     = $soma % 11;
            $esperado  = $resto < 2 ? 0 : 11 - $resto;

            if ((int) $cnpj[$posicao] !== $esperado) {
                return false;
            }
        }

        return true;
    }

    public static function textoOpcional(mixed $valor, int $limite): ?string
    {
        if ($valor === null) {
            return null;
        }

        if (!is_string($valor) && !is_numeric($valor)) {
            return null;
        }

        $texto = trim((string) $valor);

        if ($texto === '') {
            return null;
        }

        return mb_substr($texto, 0, $limite);
    }

    public static function formatarCnpj(string $cnpj): string
    {
        $cnpj = self::somenteDigitos($cnpj);

        if (strlen($cnpj) !== 14) {
            return $cnpj;
        }

        return sprintf(
            '%s.%s.%s/%s-%s',
            substr($cnpj, 0, 2),
            substr($cnpj, 2, 3),
            substr($cnpj, 5, 3),
            substr($cnpj, 8, 4),
            substr($cnpj, 12, 2)
        );
    }
}
