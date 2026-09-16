# Reviewer Directory — OJS and OMP plugin

[![OJS](https://img.shields.io/badge/OJS-3.5-brightgreen)](https://pkp.sfu.ca/ojs/)
[![OMP](https://img.shields.io/badge/OMP-3.5-brightgreen)](https://pkp.sfu.ca/omp/)
[![Version](https://img.shields.io/badge/version-1.0.2.0-blue)](version.xml)
[![License](https://img.shields.io/badge/license-GPL--3.0-lightgrey)](LICENSE)

**⬇️ Install package:** [OJS / OMP 3.5](https://github.com/OJSBR/reviewerDirectory/releases/download/1.0.2.0/reviewerDirectory-1.0.2.0.tar.gz) — or browse all [Releases](../../releases).

A generic plugin for **Open Journal Systems (OJS)** and **Open Monograph Press (OMP)** that
adds an **internal, editor-only directory of reviewers** — pulling the accounts that already
hold the *Reviewer* role in the journal or press, with their profiles and review statistics —
plus a **reviewer roster (nominata)** for a period (and, in OJS, for an issue), ready to
publish as an acknowledgement.

> **Developed and maintained by [OJSBR](https://ojsbr.com).** See the
> [Credits & authorship](#credits--authorship) section below.

## Compatibility & branches

| Application | Branch | Plugin release |
|-------------|--------|----------------|
| OJS 3.5.x and OMP 3.5.x | [`stable-3_5_0`](../../tree/stable-3_5_0) *(default)* | 1.0.2.0 |

> Since 1.0.2.0 the same package serves OJS and OMP. The former `reviewerDirectoryOmp`
> repository is archived; its releases stay available there. The roster filter by issue is
> shown only in OJS: a press has no issues, and there the roster is filtered by date.

## What it does

- **Reviewer directory** — a backend page, restricted to **Managers, Section Editors and
  Administrators**, listing every user with the *Reviewer* role in the current journal.
- **One-click shortcut in the dashboard menu** — once enabled, the plugin appends a
  **Reviewer Directory** entry to the end of the editorial sidebar menu, so editors reach the
  page directly instead of going through the plugins screen. It is shown only to the roles the
  page itself authorises (Managers, Section Editors and Administrators).
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
- Active submissions, last completion date and the roster start from the core collectors
  (`Repo::reviewAssignment()->getCollector()` scoped to the journal, and
  `Repo::publication()->getCollector()` for an issue), with the plugin's own conditions added:
  an active review is notified and not completed, declined or cancelled.
- The page renders inside the OJS backend (Vue) using a `v-pre` wrapper so server-rendered
  content is untouched; search, column toggles, sorting and export are in
  `js/reviewerDirectory.js` and the styles in `css/reviewerDirectory.css`, both added to backend
  pages only. Access is enforced by `ContextAccessPolicy` with the manager, section editor and
  administrator roles; the menu entry uses the same list.

## Tests

- **PHPUnit** (`tests/*Test.php`, on `PKP\tests\PKPTestCase`): the classes against the installed
  PKP, the plugin found by PKP's plugin registry, the roles that may open the page, the roster
  grouping (reviews, submissions, first and last dates), dates checked before they reach the
  queries, the columns (personal data hidden by default), static assets, queries built on the
  core collectors, the templates and the 38 translations. From the OJS root:

  ```bash
  lib/pkp/lib/vendor/bin/phpunit --configuration lib/pkp/tests/phpunit.xml --no-coverage "$PWD/plugins/generic/reviewerDirectory/tests"
  ```

- **Cypress** (`cypress/tests/functional/ReviewerDirectory.cy.js`, run by
  [pkp-github-actions](https://github.com/pkp/pkp-github-actions) on every push): enables the
  plugin; an anonymous visitor ends at the login form; as an editor, the page loads its
  stylesheet and script once, shows the menu shortcut, lists the reviewers, filters them by
  name (and to none), hides and shows a column, builds the roster of a period and ignores an
  impossible date. Each check fails with the part it covers removed.
- Verified on OJS 3.5.0.3 and OMP 3.5.0.3, each with the whole suite.

Tests are kept in the repository and are not part of the release package.

## Credits & authorship

- **Developed and maintained by** [OJSBR](https://ojsbr.com) — original plugin.
- Distributed under the **GNU GPL v3**.

## AI use

Generative AI (Claude, by Anthropic) was used to write and run tests, improve the code and bring
it in line with PKP standards. Every change is reviewed and tested by OJSBR, which is responsible
for the published releases.

## Contributing

Issues and pull requests are welcome. Please target the branch matching the OJS version you
are working against. See [`CONTRIBUTING.md`](CONTRIBUTING.md).

## License

Distributed under the **GNU GPL v3**. See [`LICENSE`](LICENSE) and `docs/COPYING`.

---

## 🇧🇷 Português

Plugin genérico para o **Open Journal Systems (OJS)** que adiciona um **diretório interno de
avaliadores, restrito a editores** — puxando as contas que já têm o papel de *Avaliador* na
revista ou na editora, com seus perfis e estatísticas de avaliação — além de uma **nominata de
avaliadores** por período (e, no OJS, por edição), pronta para publicar como agradecimento.

> **Desenvolvido e mantido pela [OJSBR](https://ojsbr.com).** Veja a seção
> [Créditos e autoria](#créditos-e-autoria) abaixo.

### Compatibilidade e branches

| Aplicação | Branch | Release do plugin |
|-----------|--------|-------------------|
| OJS 3.5.x e OMP 3.5.x | `stable-3_5_0` *(padrão)* | 1.0.2.0 |

> A partir da 1.0.2.0 o mesmo pacote serve OJS e OMP. O repositório `reviewerDirectoryOmp`
> está arquivado; as releases dele continuam disponíveis lá. O filtro da nominata por edição
> só aparece no OJS: uma editora não tem edições, e lá a nominata é filtrada por data.

### O que faz

- **Diretório de avaliadores** — uma página de backend, restrita a **Gerentes, Editores de
  seção e Administradores**, listando todos os usuários com papel de *Avaliador* na revista.
- **Atalho de um clique no menu do painel** — quando ativado, o plugin acrescenta um item
  **Diretório de Avaliadores** ao fim do menu lateral do painel editorial, para o editor abrir
  a página direto, sem passar pela tela de plugins. Aparece apenas para os papéis que a própria
  página autoriza (Gerentes, Editores de seção e Administradores).
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

### Testes

PHPUnit em `tests/` (sobre `PKP\tests\PKPTestCase`) e Cypress em `cypress/tests/functional/`
(rodado pelo [pkp-github-actions](https://github.com/pkp/pkp-github-actions) a cada push), com o
comando da seção em inglês. A suíte cobre as classes contra o PKP instalado, o plugin encontrado
pelo registro de plugins, os papéis que abrem a página, o agrupamento da nominata, datas validadas
antes das consultas, as colunas (dados pessoais ocultos por padrão), CSS e JS em arquivo,
consultas sobre os collectors do núcleo, os templates e as 38 traduções. O Cypress liga o plugin,
confere que o visitante anônimo cai no login e, como editor, que a página carrega CSS e JS uma
vez, mostra o atalho no menu, lista e filtra os avaliadores, oculta e mostra uma coluna, gera a
nominata de um período e ignora uma data impossível. Verificado no OJS 3.5.0.3 e no OMP 3.5.0.3, com a suíte inteira em cada um.

Os testes ficam no repositório e não fazem parte do pacote da release.

### Créditos e autoria

- **Desenvolvido e mantido pela** [OJSBR](https://ojsbr.com) — plugin autoral.
- Distribuído sob a **GNU GPL v3**.

### Uso de IA

Foi usada IA generativa (Claude, da Anthropic) para escrever e rodar testes, melhorar o código e
alinhá-lo aos padrões da PKP. Toda mudança é revisada e testada pela OJSBR, que responde pelas
releases publicadas.

### Licença

Distribuído sob a **GNU GPL v3**. Veja [`LICENSE`](LICENSE) e `docs/COPYING`.
