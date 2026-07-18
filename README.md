# Reviewer Directory — OJS plugin

[![OJS](https://img.shields.io/badge/OJS-3.5-brightgreen)](https://pkp.sfu.ca/ojs/)
[![Version](https://img.shields.io/badge/version-1.0.0.0-blue)](version.xml)
[![License](https://img.shields.io/badge/license-GPL--3.0-lightgrey)](LICENSE)

**⬇️ Install package:** [OJS 3.5](https://github.com/OJSBR/reviewerDirectory/releases/download/1.0.0.0/reviewerDirectory-1.0.0.0.tar.gz) — or browse all [Releases](../../releases).

A generic plugin for **Open Journal Systems (OJS)** that adds an **internal, editor-only
directory of reviewers** — pulling the accounts that already hold the *Reviewer* role in the
journal, with their profiles and review statistics — plus a **reviewer roster (nominata)** for
a period or issue, ready to publish as an acknowledgement.

> **Developed and maintained by [OJSBR](https://ojsbr.com.br).** See the
> [Credits & authorship](#credits--authorship) section below.

## Compatibility & branches

| OJS version | Branch | Plugin release |
|-------------|--------|----------------|
| OJS 3.5.x   | [`stable-3_5_0`](../../tree/stable-3_5_0) *(default)* | 1.0.0.0 |

## What it does

- **Reviewer directory** — a backend page, restricted to **Managers, Section Editors and
  Administrators**, listing every user with the *Reviewer* role in the current journal.
- **Profile + review data per reviewer** — name, affiliation, country, review interests, ORCID
  (with a verified badge), plus statistics: reviews completed, in progress, declined, average
  days to complete, quality rating, last assignment date and **last completion date**.
- **Active reviews with submission IDs** — the *Active* column lists the submissions currently
  under review by each reviewer, each linking straight to its editorial workflow.
- **Instant search** across name, affiliation, country, interest, e-mail, username and active
  submission IDs, plus an **"only with ORCID"** filter.
- **Configurable columns** — a checkbox bar toggles columns on/off; the choice is remembered
  per browser. Affiliation, country, ORCID, username and e-mail are hidden by default.
- **Sort** by any column (numeric or text).
- **Export to Excel** — one click exports exactly what is filtered/visible to a UTF-8 CSV that
  Excel opens natively.
- **Reviewer roster (nominata)** — pick a **date range** (review completion date) and/or an
  **issue**, and get the reviewers who completed reviews within that scope, with a count and
  the submissions they reviewed — its own Excel export included.

## Installation

1. Download the release (or clone the matching branch).
2. Install via **Settings → Website → Plugins → Upload A New Plugin**, or extract the folder
   into `plugins/generic/` so you get `plugins/generic/reviewerDirectory/`.
3. Enable **Reviewer Directory** under the *Generic* plugins list.
4. Open it via the **"Open directory"** action on the plugins list, or go to
   `/<journal>/reviewerdirectory`.

## How it works (technical)

- Reviewers come straight from OJS accounts via `Repo::user()->getCollector()` with
  `includeReviewerData()` (statistics in a single query) and `preloadInterests()` (interests
  batched). Nothing is duplicated or stored by the plugin.
- Active submissions, last completion date and the roster are resolved with batched
  `review_assignments` queries (context-scoped through `submissions`; issues through
  `publications.issue_id`), mirroring OJS's own definitions of *incomplete* / *completed*.
- The page renders inside the OJS backend (Vue) using a `v-pre` wrapper so server-rendered
  content is untouched; search, column toggles, sorting and export are inline, event-delegated
  JavaScript. Access is enforced by `ContextAccessPolicy` with the manager/sub-editor roles.

## Credits & authorship

- **Developed and maintained by** [OJSBR](https://ojsbr.com.br) — original plugin.
- Distributed under the **GNU GPL v3**.

## Contributing

Issues and pull requests are welcome. Please target the branch matching the OJS version you
are working against. See [`CONTRIBUTING.md`](CONTRIBUTING.md).

## License

Distributed under the **GNU GPL v3**. See [`LICENSE`](LICENSE) and `docs/COPYING`.

---

## 🇧🇷 Português

Plugin genérico para o **Open Journal Systems (OJS)** que adiciona um **diretório interno de
avaliadores, restrito a editores** — puxando as contas que já têm o papel de *Avaliador* na
revista, com seus perfis e estatísticas de avaliação — além de uma **nominata de avaliadores**
por período ou edição, pronta para publicar como agradecimento.

> **Desenvolvido e mantido pela [OJSBR](https://ojsbr.com.br).** Veja a seção
> [Créditos e autoria](#créditos-e-autoria) abaixo.

### Compatibilidade e branches

| Versão do OJS | Branch | Release do plugin |
|---------------|--------|-------------------|
| OJS 3.5.x     | `stable-3_5_0` *(padrão)* | 1.0.0.0 |

### O que faz

- **Diretório de avaliadores** — uma página de backend, restrita a **Gerentes, Editores de
  seção e Administradores**, listando todos os usuários com papel de *Avaliador* na revista.
- **Perfil + dados de avaliação por avaliador** — nome, afiliação, país, interesses de
  avaliação, ORCID (com selo de autenticação) e estatísticas: avaliações concluídas, em
  andamento, recusadas, média de dias para concluir, nota de qualidade, data da última
  designação e **data da última conclusão**.
- **Avaliações ativas com IDs de submissão** — a coluna *Ativas* lista as submissões que cada
  avaliador está avaliando no momento, cada uma com link direto para o fluxo editorial.
- **Busca instantânea** por nome, afiliação, país, interesse, e-mail, usuário e IDs de
  submissão ativa, além do filtro **"somente com ORCID"**.
- **Colunas configuráveis** — uma barra de checkboxes liga/desliga colunas; a escolha é
  lembrada por navegador. Afiliação, país, ORCID, usuário e e-mail ficam ocultos por padrão.
- **Ordenação** por qualquer coluna (numérica ou texto).
- **Exportar para Excel** — um clique exporta exatamente o que está filtrado/visível para um
  CSV UTF-8 que o Excel abre nativamente.
- **Nominata de avaliadores** — escolha um **período** (data de conclusão da avaliação) e/ou
  uma **edição**, e obtenha os avaliadores que concluíram avaliações naquele escopo, com a
  contagem e as submissões avaliadas — com exportação própria para Excel.

### Instalação

Instale em **Configurações → Website → Plugins → Enviar um novo plugin**, ou extraia a pasta
em `plugins/generic/` (ficando `plugins/generic/reviewerDirectory/`). Depois ative o
**Reviewer Directory** na lista de plugins *Genéricos*. Acesse pela ação **"Abrir diretório"**
na lista de plugins, ou em `/<revista>/reviewerdirectory`.

### Créditos e autoria

- **Desenvolvido e mantido pela** [OJSBR](https://ojsbr.com.br) — plugin autoral.
- Distribuído sob a **GNU GPL v3**.

### Licença

Distribuído sob a **GNU GPL v3**. Veja [`LICENSE`](LICENSE) e `docs/COPYING`.
