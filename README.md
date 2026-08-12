# Recommend Articles by Author — OJS plugin

[![OJS](https://img.shields.io/badge/OJS-3.5-brightgreen)](https://pkp.sfu.ca/ojs/)
[![Version](https://img.shields.io/badge/version-2.0.0.1-blue)](version.xml)
[![License](https://img.shields.io/badge/license-GPL--3.0-lightgrey)](LICENSE)

**⬇️ Install package:** [OJS 3.5](https://github.com/OJSBR/recommendByAuthor/releases/download/2.0.0.1/recommendByAuthor-2.0.0.1.tar.gz) — or browse all [Releases](../../releases).

The **"Most read articles by the same author(s)"** section on the article page — the same
feature journals already know, rebuilt so that it is **read from a cache instead of computed
while a reader waits**.

> **Originally written by the [Public Knowledge Project](https://pkp.sfu.ca) — Simon Fraser
> University and John Willinsky — and distributed with OJS.** This is a rewrite of their
> plugin for OJS 3.5, keeping the feature and the markup and changing where the work happens;
> maintained by [OJSBR](https://ojsbr.com). Details in
> [Credits & authorship](#credits--authorship).

## Compatibility & branches

| OJS version | Branch | Plugin release |
|-------------|--------|----------------|
| OJS 3.5.x   | [`stable-3_5_0`](../../tree/stable-3_5_0) *(default)* | 2.0.0.1 |

## The problem

The original plugin asks, on **every single view of an article**: *"which authors are named
like this one?"*. Author names live in `author_settings`, whose only key is `author_id`, so
that question has no index to stand on — MySQL scans the whole settings table, **twice per
author** of the article being read.

Measured on a production journal with 4,823 published articles and 25,877 authors
(`EXPLAIN` reports `type=ALL` over 401,192 rows):

| Article | Authors | Time | Queries |
|---------|---------|------|---------|
| worst case found | 32 | **11.9 s** | 1,441 |
| second worst | 21 | 6.4 s | 410 |
| average of 25 | 4 | 0.8 s | 183 |

Every one of those seconds is spent on the most visited page of the site, and it gets worse
as the journal grows. That is why so many hosts simply delete this plugin.

## What it does

The same section, from a list that is already written down:

| | original | this plugin (cache miss) | this plugin (cache hit) |
|---|---|---|---|
| worst case above | 11.9 s / 1,441 queries | **16.6 ms / 49 queries** | **0.14 ms / 1 query** |
| whole journal rebuilt | — | 12.2 s for 4,823 articles | — |

It also **finds more than the original**, and the difference is not noise. Compared over 60
submissions of that journal, the new results are a **superset**: nothing the original found
was lost, and these were added —

- **names with stray or double spaces.** The original compares strings exactly, so
  `"Ana Carolina  Menezes"` and `"Ana Carolina Menezes"` are two different people.
  Real, common data.
- **ORCID.** An author who changed surname keeps their identifier. The original plugin's own
  source comments asked for exactly this ("until OJS allows users to consistently normalize
  authors … via ORCID"); OJS 3.5 has it, so it is used — as an *additional* key, never a
  replacement, so switching it on can only add articles.
- **the same name recorded in different locales.**

And it **stops recommending nonsense**: the original calls `filterByName('', '')` for authors
whose name is blank, which matches every other nameless author in the database. In that same
journal there are **222 nameless authors across 97 published articles**, and each of those
articles was being given about **3,285 irrelevant recommendations** at a cost of roughly
100 seconds per view.

## Installation

1. Download the package from [Releases](../../releases) and install it through
   **Settings → Website → Plugins → Upload A New Plugin**, or drop the folder into
   `plugins/generic/recommendByAuthor` and register it.
   **Do not rename the folder** — OJS derives the plugin's namespace from the directory name.
2. Enable it in **Settings → Website → Plugins**. Enabling creates its three tables.
3. Recommended on a large journal: build the cache once, deliberately, before the readers
   arrive — see below. Otherwise the scheduled task fills it in over a few hours, and articles
   simply show no section until their turn comes.

```bash
php plugins/generic/recommendByAuthor/tools/buildRecommendations.php --pause=100
```

Run it as the account that owns the files, never as root. `--help` lists the options
(`--context`, `--limit`, `--batch`, `--pause`, `--stale-only`, `--status`).

## Configuration

**Settings → Website → Plugins → Recommend Articles by Author → Settings.** The panel shows
how much of the journal is covered, and lets you set:

| Setting | Default | What it is for |
|---|---|---|
| Recommendations per page | 10 | What the reader sees at a time |
| Maximum stored per article | 50 | How deep paging can go |
| Order by | Most read | Views and downloads, or most recently published |
| Also match by ORCID | on | Adds articles the name alone would miss |
| Submissions refreshed per run | 250 | The size of each slice |
| Refresh older than | 7 days | How stale a list may get |
| Enrol at most | 0 (no limit) | Cap for trying the plugin out on part of a journal |
| Reuse reading figures for | 12 h | How often the ranking aggregate is recomputed |
| Keep the rendered section for | 168 h | Lifetime of the rendered HTML |
| Compute while the reader waits | off | See below |

**Leave "compute while the reader waits" off on any journal of size.** With it off, an article
that has not been computed yet shows no section at all and costs one indexed query — there is
no path from a page view to expensive work.

## How it works (technical)

Three layers, none of which lets the article page do real work:

1. **Rendered HTML** in the Laravel cache, keyed by submission, state version, settings stamp,
   locale and page. A hit is one primary-key read.
2. **`recommend_author_cache`** — the ordered list of recommended submission ids.
3. **`recommend_author_index`** — one row per (author identity, published submission), where
   the identity is a normalised name or an ORCID. This is what turns *"which articles does
   this author have?"* from a table scan into an index lookup.

`recommend_author_state` records what has been indexed and computed. **Indexing and computing
are deliberately separate**: an article can only be recommended once it is in the index, so
computing while the index is still being filled would store lists that are quietly incomplete
— and store them as finished. Indexing runs to completion first (1.3 s for the whole journal
above), and only then is anything computed.

The scheduled task (`RefreshRecommendations`, every 15 minutes) refreshes **a slice**: the
never-computed first, then the least recently computed, up to the batch size. Nothing ever
expires at the same moment, so the refresh is never a stampede — the window rolls forward.
Publishing, unpublishing or deleting an article queues it and everything that shares an author
with it, so new work appears without waiting for the cycle.

No OJS table is modified. Uninstalling is `DROP`.

## Tests

Three suites, all of them run against OJS 3.5.0.3 before this release
(details and the list of cases in [tests/CASES.md](tests/CASES.md)):

| Suite | What it covers | Result |
|---|---|---|
| [`tests/regression.php`](tests/regression.php) | author keys, the index, the store, and publishing through to the reader — against a real database | **62 cases, 62 passed** |
| [`tests/AuthorKeyTest.php`](tests/AuthorKeyTest.php) | the identity rules on their own (PHPUnit, no database) | **passed** |
| [`cypress/tests/functional/`](cypress/tests/functional) | enabling, the settings screen and the article page, in a browser | **7 tests, 7 passed** |

```bash
php plugins/generic/recommendByAuthor/tests/regression.php
cd lib/pkp/tests && ../lib/vendor/bin/phpunit -c phpunit.xml --testsuite ApplicationPlugins
npx cypress run --spec 'cypress/tests/**/RecommendByAuthor.cy.js'
```

The regression suite creates its own submissions and deletes them again; run it on a test
installation. The Cypress spec defaults to the PKP test data but runs against any journal
through `cypress.env.json` — it solves the Altcha proof of work where `captcha_on_login` is on,
and works whatever language the interface is in.

Beyond the suites, the correctness of the results was checked against the original plugin on a
production journal: **60 submissions compared item by item**, where the new results are a
superset with no legitimate result lost, and every extra match audited by hand.

## Credits & authorship

- **[Public Knowledge Project](https://pkp.sfu.ca), Simon Fraser University and John
  Willinsky** — authors of the original `recommendByAuthor` plugin, of the feature itself and
  of the template markup this plugin keeps. Copyright (c) 2014–2025 Simon Fraser University,
  (c) 2003–2025 John Willinsky.
- **[OJSBR](https://ojsbr.com)** — the 3.5 rewrite: author index, materialised cache, the
  scheduled slice-refresh, ORCID matching and the settings panel.

## Contributing

Issues and pull requests are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md).

## License

GNU GPL v3 — see [LICENSE](LICENSE), the same licence as OJS and as the original plugin.

---

## 🇧🇷 Português

A seção **"Artigos mais lidos pelo mesmo(s) autor(es)"** na página do artigo — a mesma
funcionalidade que as revistas já conhecem, reconstruída para ser **lida de um cache em vez de
calculada enquanto o leitor espera**.

> **Escrito originalmente pelo [Public Knowledge Project](https://pkp.sfu.ca) — Simon Fraser
> University e John Willinsky — e distribuído com o OJS.** Esta é uma reescrita do plugin
> deles para o OJS 3.5, que mantém a funcionalidade e a marcação e muda onde o trabalho
> acontece; mantida pela [OJSBR](https://ojsbr.com). Detalhes em
> [Créditos e autoria](#créditos-e-autoria).

### Compatibilidade e branches

| Versão do OJS | Branch | Versão do plugin |
|---------------|--------|------------------|
| OJS 3.5.x     | [`stable-3_5_0`](../../tree/stable-3_5_0) *(padrão)* | 2.0.0.1 |

### O que faz

O plugin original pergunta, **a cada visualização de artigo**: *"quais autores se chamam
assim?"*. Os nomes ficam em `author_settings`, cuja única chave é `author_id` — então a
pergunta não tem índice em que se apoiar e o MySQL varre a tabela inteira, **duas vezes por
autor** do artigo.

Medido numa revista de produção com 4.823 artigos publicados e 25.877 autores:

| | original | este plugin (sem cache de HTML) | este plugin (com cache) |
|---|---|---|---|
| pior caso (artigo com 32 autores) | 11,9 s / 1.441 consultas | **16,6 ms / 49 consultas** | **0,14 ms / 1 consulta** |
| revista inteira reconstruída | — | 12,2 s para 4.823 artigos | — |

E **encontra mais que o original**, sem perder nada: comparados 60 artigos item a item, o
resultado novo é um **superconjunto**. Ele acrescenta nomes com espaço sobrando ou duplo (o
original compara string exata), casamento por **ORCID** (o autor que mudou de sobrenome) e
nomes gravados em locales diferentes. E deixa de recomendar lixo: o original chama
`filterByName('', '')` para autores sem nome, casando com todos os outros autores sem nome do
banco — naquela revista são **222 autores sem nome em 97 artigos**, cada um recebendo cerca de
**3.285 recomendações irrelevantes** a um custo de uns 100 segundos por visualização.

### Instalação

1. Baixe o pacote em [Releases](../../releases) e instale por **Configurações → Website →
   Plugins → Enviar novo plugin**, ou copie a pasta para `plugins/generic/recommendByAuthor`.
   **Não renomeie a pasta** — o OJS deriva o namespace do nome do diretório.
2. Habilite em **Configurações → Website → Plugins**. Ao habilitar, as três tabelas são criadas.
3. Em revista grande, monte o cache de uma vez, fora do horário de pico:

```bash
php plugins/generic/recommendByAuthor/tools/buildRecommendations.php --pause=100
```

Rode como o dono dos arquivos, nunca como root. `--help` lista as opções.

### Configuração

Em **Configurações → Website → Plugins → Artigos recomendados por Autor → Configurações**. O
painel mostra a cobertura atual e permite ajustar recomendações por página, máximo armazenado
por artigo, ordenação (mais lidos ou mais recentes), casamento por ORCID, tamanho do lote por
execução, idade máxima antes de recalcular, teto de submissões inscritas e a duração dos caches.

**Deixe "calcular enquanto o leitor espera" desligado** em qualquer revista de porte: assim um
artigo ainda não calculado simplesmente não exibe a seção e custa uma consulta indexada — não
existe caminho entre uma visita e trabalho caro.

### Como funciona (técnico)

Três camadas: o HTML renderizado no cache do Laravel; a tabela `recommend_author_cache` com a
lista pronta de ids; e o índice `recommend_author_index`, uma linha por (identidade de autor,
artigo publicado), sendo a identidade um nome normalizado ou um ORCID — é ele que transforma a
varredura de tabela em consulta por chave.

**Indexar e calcular são fases separadas de propósito**: um artigo só pode ser recomendado
depois de estar no índice, então calcular com o índice pela metade gravaria listas incompletas
*como se fossem finais*. A indexação termina primeiro (1,3 s na revista inteira) e só então se
calcula.

A tarefa agendada roda a cada 15 minutos e atualiza **uma fatia**: primeiro os nunca
calculados, depois os mais antigos, até o tamanho do lote. Nada expira ao mesmo tempo, então a
renovação nunca vira uma avalanche. Publicar, despublicar ou excluir um artigo coloca na fila
ele e todos que compartilham autor com ele.

Nenhuma tabela do OJS é alterada. Desinstalar é `DROP`.

### Testes

Três suítes, todas executadas contra o OJS 3.5.0.3 antes desta versão (a lista de casos está em
[tests/CASES.md](tests/CASES.md)): a de regressão (`tests/regression.php`), que cobre as chaves
de autor, o índice, o armazenamento e o caminho da publicação até o leitor contra um banco real
— **62 casos, 62 passaram**; a unitária em PHPUnit (`tests/AuthorKeyTest.php`), que cobre as
regras de identidade sozinhas — **passou**; e a funcional em Cypress, que cobre habilitar o
plugin, a tela de configurações e a página do artigo no navegador — **7 testes, 7 passaram**.

A suíte de regressão cria e apaga as próprias submissões: rode em instalação de teste. O Cypress
usa por padrão a base de testes da PKP, mas roda contra qualquer revista via `cypress.env.json`
— ele resolve o desafio do Altcha onde `captcha_on_login` está ligado e funciona em qualquer
idioma de interface.

Além das suítes, a correção dos resultados foi conferida contra o plugin original numa revista
de produção: **60 artigos comparados item a item**, com o resultado novo sendo superconjunto, sem
perder nada legítimo, e cada casamento extra auditado à mão.

### Créditos e autoria

- **[Public Knowledge Project](https://pkp.sfu.ca), Simon Fraser University e John Willinsky**
  — autores do plugin `recommendByAuthor` original, da funcionalidade e da marcação do template
  que este plugin preserva. Copyright (c) 2014–2025 Simon Fraser University, (c) 2003–2025 John
  Willinsky.
- **[OJSBR](https://ojsbr.com)** — a reescrita para o 3.5: índice de autores, cache
  materializado, atualização em fatias pela tarefa agendada, casamento por ORCID e o painel de
  configurações.

### Licença

GNU GPL v3 — veja [LICENSE](LICENSE), a mesma licença do OJS e do plugin original.
