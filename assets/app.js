'use strict';

const ENDPOINT = 'api/consultas.php';

const $ = (seletor) => document.querySelector(seletor);

function mostrarAviso(elemento, mensagem, tipo = 'info') {
    elemento.textContent = mensagem;
    elemento.className = `aviso aviso--${tipo}`;
}

function esconder(elemento) {
    elemento.className = 'aviso oculto';
}

function escapar(texto) {
    if (texto === null || texto === undefined || texto === '') {
        return '—';
    }
    return String(texto)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function mascaraCnpj(valor) {
    const d = valor.replace(/\D/g, '').slice(0, 14);

    return d
        .replace(/^(\d{2})(\d)/, '$1.$2')
        .replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3')
        .replace(/\.(\d{3})(\d)/, '.$1/$2')
        .replace(/(\d{4})(\d)/, '$1-$2');
}

function cnpjValido(valor) {
    const cnpj = valor.replace(/\D/g, '');

    if (cnpj.length !== 14 || /^(\d)\1{13}$/.test(cnpj)) {
        return false;
    }

    for (const posicao of [12, 13]) {
        const pesos = posicao === 12
            ? [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]
            : [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

        const soma = pesos.reduce((acc, peso, i) => acc + Number(cnpj[i]) * peso, 0);
        const resto = soma % 11;
        const esperado = resto < 2 ? 0 : 11 - resto;

        if (Number(cnpj[posicao]) !== esperado) {
            return false;
        }
    }

    return true;
}

async function requisitar(url, opcoes = {}) {
    let resposta;

    try {
        resposta = await fetch(url, opcoes);
    } catch (falha) {
        return {
            ok: false,
            status: 0,
            corpo: { erro: { mensagem: 'Nao foi possivel falar com o servidor. O Apache do XAMPP esta ligado?' } }
        };
    }

    if (resposta.status === 204) {
        return { ok: true, status: 204, corpo: null };
    }

    let corpo = null;
    try {
        corpo = await resposta.json();
    } catch (falha) {
        corpo = { erro: { mensagem: 'O servidor respondeu algo que nao e JSON (status ' + resposta.status + ').' } };
    }

    return { ok: resposta.ok, status: resposta.status, corpo };
}

function mensagemDeErro(resultado) {
    const msg = resultado.corpo && resultado.corpo.erro && resultado.corpo.erro.mensagem;
    return msg ? `${msg} (HTTP ${resultado.status})` : `Falha na requisicao (HTTP ${resultado.status}).`;
}

const formConsulta = $('#form-consulta');
const campoCnpj = $('#cnpj');
const btnConsultar = $('#btn-consultar');
const avisoTopo = $('#aviso');
const painelResultado = $('#resultado');

campoCnpj.addEventListener('input', () => {
    campoCnpj.value = mascaraCnpj(campoCnpj.value);
});

formConsulta.addEventListener('submit', async (evento) => {
    evento.preventDefault();

    const cnpj = campoCnpj.value.trim();

    if (!cnpjValido(cnpj)) {
        painelResultado.className = 'resultado oculto';
        mostrarAviso(avisoTopo, 'CNPJ invalido: confira os 14 digitos.', 'erro');
        return;
    }

    btnConsultar.disabled = true;
    btnConsultar.textContent = 'Consultando…';
    mostrarAviso(avisoTopo, 'Consultando a BrasilAPI pelo servidor…', 'info');
    painelResultado.className = 'resultado oculto';

    const resultado = await requisitar(ENDPOINT, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            cnpj,
            apelido: $('#apelido').value.trim(),
            observacao: $('#observacao').value.trim()
        })
    });

    btnConsultar.disabled = false;
    btnConsultar.textContent = 'Consultar e salvar';

    if (!resultado.ok) {
        mostrarAviso(avisoTopo, mensagemDeErro(resultado), 'erro');
        return;
    }

    const criado = resultado.status === 201;
    mostrarAviso(
        avisoTopo,
        `${resultado.corpo.mensagem} (HTTP ${resultado.status} ${criado ? 'Created' : 'OK'})`,
        'sucesso'
    );

    desenharResultado(resultado.corpo.dados);
    formConsulta.reset();
    carregarLista();
});

function desenharResultado(registro) {
    painelResultado.className = 'resultado';
    painelResultado.innerHTML = `
        <h3>${escapar(registro.razao_social)}</h3>
        <dl>
            <div><dt>CNPJ</dt><dd>${escapar(registro.cnpj_formatado)}</dd></div>
            <div><dt>Nome fantasia</dt><dd>${escapar(registro.nome_fantasia)}</dd></div>
            <div><dt>Situação</dt><dd>${escapar(registro.situacao_cadastral)}</dd></div>
            <div><dt>Município / UF</dt><dd>${escapar(registro.municipio)} / ${escapar(registro.uf)}</dd></div>
            <div><dt>CNAE principal</dt><dd>${escapar(registro.cnae_principal)}</dd></div>
        </dl>`;
}

const corpoTabela = $('#corpo-tabela');
const avisoLista = $('#aviso-lista');
const campoBusca = $('#busca');

async function carregarLista() {
    corpoTabela.innerHTML = '<tr><td colspan="8" class="vazio">Carregando…</td></tr>';

    const filtro = campoBusca.value.trim();
    const url = filtro === '' ? ENDPOINT : `${ENDPOINT}?busca=${encodeURIComponent(filtro)}`;

    const resultado = await requisitar(url);

    if (!resultado.ok) {
        corpoTabela.innerHTML = '<tr><td colspan="8" class="vazio">Não foi possível carregar.</td></tr>';
        mostrarAviso(avisoLista, mensagemDeErro(resultado), 'erro');
        return;
    }

    esconder(avisoLista);
    desenharTabela(resultado.corpo.dados);
}

function desenharTabela(registros) {
    if (!registros || registros.length === 0) {
        corpoTabela.innerHTML =
            '<tr><td colspan="8" class="vazio">Nenhum registro salvo ainda.</td></tr>';
        return;
    }

    corpoTabela.innerHTML = registros.map((r) => `
        <tr>
            <td>${r.id}</td>
            <td class="mono">${escapar(r.cnpj_formatado)}</td>
            <td>${escapar(r.razao_social)}</td>
            <td>${escapar(r.municipio)} / ${escapar(r.uf)}</td>
            <td>${escapar(r.situacao_cadastral)}</td>
            <td>${escapar(r.apelido)}</td>
            <td>${escapar(r.observacao)}</td>
            <td class="acoes">
                <button type="button" class="mini" data-acao="editar" data-id="${r.id}">Editar</button>
                <button type="button" class="mini perigo" data-acao="excluir" data-id="${r.id}">Excluir</button>
            </td>
        </tr>`).join('');
}

$('#btn-buscar').addEventListener('click', carregarLista);
$('#btn-recarregar').addEventListener('click', () => {
    campoBusca.value = '';
    carregarLista();
});
campoBusca.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
        e.preventDefault();
        carregarLista();
    }
});

const modal = $('#modal-edicao');
const avisoModal = $('#aviso-modal');
let idEmEdicao = null;

corpoTabela.addEventListener('click', (evento) => {
    const botao = evento.target.closest('button[data-acao]');
    if (!botao) {
        return;
    }

    const id = Number(botao.dataset.id);

    if (botao.dataset.acao === 'editar') {
        abrirEdicao(id);
    } else {
        excluir(id);
    }
});

async function abrirEdicao(id) {
    const resultado = await requisitar(`${ENDPOINT}?id=${id}`);

    if (!resultado.ok) {
        mostrarAviso(avisoLista, mensagemDeErro(resultado), 'erro');
        return;
    }

    const registro = resultado.corpo.dados;
    idEmEdicao = id;

    $('#modal-id').textContent = `#${id}`;
    $('#modal-empresa').textContent = `${registro.cnpj_formatado} — ${registro.razao_social}`;
    $('#edit-apelido').value = registro.apelido || '';
    $('#edit-observacao').value = registro.observacao || '';
    $('#edit-metodo').value = 'PATCH';
    esconder(avisoModal);

    modal.showModal();
}

$('#btn-cancelar').addEventListener('click', () => modal.close());

$('#btn-salvar').addEventListener('click', async () => {
    const metodo = $('#edit-metodo').value;

    const resultado = await requisitar(`${ENDPOINT}?id=${idEmEdicao}`, {
        method: metodo,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            apelido: $('#edit-apelido').value.trim(),
            observacao: $('#edit-observacao').value.trim()
        })
    });

    if (!resultado.ok) {
        mostrarAviso(avisoModal, mensagemDeErro(resultado), 'erro');
        return;
    }

    modal.close();
    mostrarAviso(avisoLista, `${resultado.corpo.mensagem} (${metodo} → HTTP ${resultado.status})`, 'sucesso');
    carregarLista();
});

async function excluir(id) {
    if (!window.confirm(`Excluir o registro #${id}? Essa ação não pode ser desfeita.`)) {
        return;
    }

    const resultado = await requisitar(`${ENDPOINT}?id=${id}`, { method: 'DELETE' });

    if (!resultado.ok) {
        mostrarAviso(avisoLista, mensagemDeErro(resultado), 'erro');
        return;
    }

    mostrarAviso(avisoLista, `Registro #${id} excluído (DELETE → HTTP 204 No Content).`, 'sucesso');
    carregarLista();
}

carregarLista();
