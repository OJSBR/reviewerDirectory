# Reviewer Directory — OJS and OMP plugin

[![OJS](https://img.shields.io/badge/OJS-3.5-brightgreen)](https://pkp.sfu.ca/ojs/)
[![OMP](https://img.shields.io/badge/OMP-3.5-brightgreen)](https://pkp.sfu.ca/omp/)
[![Version](https://img.shields.io/badge/version-5.0.1.0-blue)](version.xml)
[![License](https://img.shields.io/badge/license-GPL--3.0-lightgrey)](LICENSE)

**⬇️ Install package:** [OJS / OMP 3.5](https://github.com/OJSBR/reviewerDirectory/releases/download/5.0.1.0/reviewerDirectory-5.0.1.0.tar.gz) — or browse all [Releases](../../releases).

> **Built on the work of others.** The reviewer statistics are the ones OJS and OMP show when an
> editor chooses a reviewer — the reviewer selection panel of the
> [Public Knowledge Project](https://pkp.sfu.ca), from the same core query
> (`Repo::user()->getCollector()->includeReviewerData()`). The page is made of PKP's own
> components and follows the layout of OJSBR's
> [Submission Queue Report](https://github.com/OJSBR/ojsbrSubmissionQueueReport), its sibling
> plugin. See [Credits](#credits--authorship).

A generic plugin for **Open Journal Systems (OJS)** and **Open Monograph Press (OMP)** that gives
editors an **internal directory of the reviewer pool** — every account with the *Reviewer* role,
with profile, indices, a summary of their situation, filters and an export — plus the
**reviewer roster (nominata)** of a period or issue, ready to publish as an acknowledgement.

> **Developed and maintained by [OJSBR](https://ojsbr.com).**

## Compatibility & branches

| Application | Branch | Plugin release |
|-------------|--------|----------------|
| OJS 3.5.x and OMP 3.5.x | [`stable-3_5_0`](../../tree/stable-3_5_0) *(default)* | 5.0.1.0 |

The first number of the version names the application line (5 = 3.5). The same package serves
OJS and OMP; the roster filter by issue is shown only in OJS (a press has no issues).

## What the editor gets

**Reviewer Directory** in the side menu, for **managers, section editors and administrators**:

- **The reviewer pool** — tiles with the number of reviewers in each group: all, *available now*
  (no review in progress), *reviewing now*, *with overdue reviews*, *reviewed in the last 12 months*,
  *never reviewed for the journal*, *without ORCID*. A click lists the group.
- **Indices of the listed reviewers in this journal** — acceptance of review requests, reviews
  delivered on time, average days from request to review, reviews in progress and reviews in the
  last 12 months. They follow the filters, so they describe the group on the screen.
- **Situation** — one per reviewer, the first that applies: *overdue*, *busy* (3 or more reviews in
  progress), *reviewing*, *available and active* (reviewed in the last 12 months), *available and
  idle*, *never reviewed*. A coloured bar and a legend show how many are in each one and **add up
  to the total**; a click lists only those reviewers.
- **Filters** — situation, country, interest, ORCID (any, with, without, verified), minimum reviews
  completed for the journal, months without completing a review, minimum rating, and a search by
  name, e-mail, username, affiliation, country, interest, ORCID or submission number (`#123`).
  Everything lives in the address of the page, so a filtered directory can be shared as a link.
- **Table** — 50 per page, sortable, with the columns each editor chooses (remembered in the
  browser): name and e-mail, affiliation, country, ORCID (verified mark), username, interests,
  situation, reviews in progress with links to their submissions, overdue, completed, last 12
  months, requests, acceptance and on-time bars, average days, last completion (highlighted after
  24 months), the numbers OJS shows when choosing a reviewer, rating and last assignment. Personal
  data is hidden until the editor shows it.
- **Export** — CSV for Excel (UTF-8 with BOM, `;`) or an Excel workbook (XLSX, frozen header with
  filter buttons), with exactly the reviewers filtered; the first line says which journal, when, by
  whom and with which filters.
- **Roster (nominata)** — a period of review completion and, in OJS, an issue: tiles with reviewers,
  reviews, submissions and countries; the table; the export; and **the list ready to publish**, one
  reviewer per line in alphabetical order with affiliation and country, copied with one click.

### Two families of numbers

- **As OJS/OMP shows them** when an editor chooses a reviewer — completed, declined, average days,
  rating, last assignment — taken from the same core query, so they are the numbers of that screen.
  Unless the site administrator turned the sharing off, they cover every journal of the site.
- **Of this journal or press only** — reviews in progress, overdue, completed, last 12 months,
  requests, acceptance, on time, average days, last completion. The tiles, the indices and the
  situations come from these.

A review is **in progress** as OJS sees it when it sends the reminders: notified, neither completed nor
declined nor cancelled, in the last round of the stage the submission is in, and the submission still
in the workflow. A review left open on a submission that was later published or declined does not
count. It is **overdue** when the response or the review was due before today ended (the core's rule).

## Installation

Upload the package in **Settings → Website → Plugins → Upload A New Plugin**, or extract the folder
into `plugins/generic/` (giving `plugins/generic/reviewerDirectory/`). Then enable **Reviewer
Directory** in the *Generic* plugins list. It opens from the side menu, from the *Open directory*
action of the plugins list, or at `/<journal>/reviewerdirectory`. Links to the former page keep
working.

## How it works (technical)

- One page (`reviewerdirectory`, through `LoadHandler`) with the operations `index`, `data`,
  `export`, `roster` and `rosterExport`; `ContextAccessPolicy` with the manager, section editor and
  administrator roles on every one of them.
- Reviewers and the OJS numbers from `Repo::user()->getCollector()->filterByRoleIds([Reviewer])
  ->includeReviewerData()`; interests from `preloadInterests()`; the journal's numbers from one
  grouped query on its review assignments (cancelled ones left out); users made from their rows the
  way core's `EntityDAO::fromRow()` does, with one query of settings for all of them. A few queries
  in all, whatever the number of reviewers.
- The page is a Vue component registered with `pkp.registry`, made of the core's own components
  (table, form fields, search, pagination, buttons) and `useFiltersForm`, in the layout shared with
  the Submission Queue Report.
- MySQL/MariaDB and PostgreSQL. No core file is patched, no template is replaced, nothing is stored.

## Tests

- **PHPUnit** (`tests/`): who may open the page and its data; the roster grouping; dates; the
  columns (personal data hidden by default); the filters, the situations, the indices and the
  sorting; CSV and XLSX; and, against the database, that the reviewers and their OJS numbers are the
  core's and that the journal's numbers and the roster match independent counts. The translations.
- **Cypress** (`cypress/tests/functional/ReviewerDirectory.cy.js`, run by
  [pkp-github-actions](https://github.com/pkp/pkp-github-actions) on OJS and OMP, MySQL and
  PostgreSQL): the menu; the login form for a visitor; for every reviewer, the numbers of the
  reviewer selection API; the situations adding up and a group listed; the roster with its text; the
  CSV with the rows of the screen; and a reviewer getting nothing.
- Checked in the browser on OJS and OMP 3.5: every reviewer against `api/v1/users/reviewers`,
  filters, search, sorting, the roster, the exports, and the access of each role.

Tests are kept in the repository and are not part of the release package.

## Languages

38 languages, every locale of OJS/OMP 3.5. English, Portuguese (Brazil and Portugal) and Spanish are
reviewed by the team; the others follow the terms of OJS itself in each language and are marked
*fuzzy* until a speaker reviews them (OJS shows them all the same). Corrections are welcome.

## Credits & authorship

- **Developed and maintained by** [OJSBR](https://ojsbr.com).
- **[Public Knowledge Project](https://pkp.sfu.ca)** — the reviewer selection panel of OJS and OMP
  and the core query behind its statistics, which the directory reuses; the core components of the
  page.
- **OJSBR's [Submission Queue Report](https://github.com/OJSBR/ojsbrSubmissionQueueReport)** — the
  layout of the page (tiles, a summary that adds up, filters, export).
- Distributed under the **GNU GPL v3**.

## AI use

Generative AI (Claude, by Anthropic) was used to write and run tests, improve the code and bring it
in line with PKP standards. Every change is reviewed and tested by OJSBR, which is responsible for
the published releases.

## Contributing

Issues and pull requests are welcome. See [`CONTRIBUTING.md`](CONTRIBUTING.md).

## License

Distributed under the **GNU GPL v3**. See [`LICENSE`](LICENSE) and `docs/COPYING`.

---

## 🇧🇷 Português

> **Feito sobre o trabalho de outros.** As estatísticas dos avaliadores são as que o OJS e o OMP
> mostram ao editor na escolha do avaliador — o painel de seleção de avaliadores do
> [Public Knowledge Project](https://pkp.sfu.ca), vindas da mesma consulta do núcleo
> (`Repo::user()->getCollector()->includeReviewerData()`). A página é feita com os componentes da
> própria PKP e segue o layout do
> [Relatório de submissões](https://github.com/OJSBR/ojsbrSubmissionQueueReport) da OJSBR, o plugin
> irmão. Veja [Créditos](#créditos-e-autoria).

Plugin genérico para o **Open Journal Systems (OJS)** e o **Open Monograph Press (OMP)** que dá aos
editores um **diretório interno do quadro de avaliadores** — todas as contas com o papel de
*Avaliador*, com perfil, índices, resumo da situação, filtros e exportação — e a **nominata** de um
período ou edição, pronta para publicar como agradecimento.

> **Desenvolvido e mantido pela [OJSBR](https://ojsbr.com).**

### Compatibilidade e branches

| Aplicação | Branch | Release do plugin |
|-----------|--------|-------------------|
| OJS 3.5.x e OMP 3.5.x | `stable-3_5_0` *(padrão)* | 5.0.1.0 |

O primeiro número da versão indica a linha da aplicação (5 = 3.5). O mesmo pacote atende OJS e OMP;
o filtro da nominata por edição aparece só no OJS (editora não tem edições).

### O que o editor ganha

**Diretório de Avaliadores** no menu lateral, para **gestores, editores de seção e administradores**:

- **O quadro de avaliadores** — quadros com quantos avaliadores há em cada grupo: todos, *livres
  agora* (sem avaliação em andamento), *avaliando agora*, *com avaliação atrasada*, *avaliaram nos
  últimos 12 meses*, *nunca avaliaram para a revista*, *sem ORCID*. O clique lista o grupo.
- **Índices dos avaliadores listados nesta revista** — aceite dos convites, pareceres no prazo,
  média de dias do convite ao parecer, avaliações em andamento e avaliações nos últimos 12 meses.
  Seguem os filtros, descrevendo o grupo que está na tela.
- **Situação** — uma por avaliador, a primeira que servir: *em atraso*, *sobrecarregados* (3 ou mais
  avaliações em andamento), *avaliando*, *livres e em atividade* (avaliaram nos últimos 12 meses),
  *livres e parados*, *nunca avaliaram*. Uma barra colorida e a legenda mostram quantos há em cada
  uma e **fecham com o total**; o clique lista só esses avaliadores.
- **Filtros** — situação, país, interesse, ORCID (qualquer, com, sem, autenticado), mínimo de
  avaliações concluídas para a revista, meses sem concluir avaliação, nota mínima e busca por nome,
  e-mail, usuário, afiliação, país, interesse, ORCID ou número de submissão (`#123`). Tudo fica no
  endereço da página: o diretório filtrado pode ser compartilhado como link.
- **Tabela** — 50 por página, ordenável, com as colunas que cada editor escolhe (guardadas no
  navegador): nome e e-mail, afiliação, país, ORCID (com marca de autenticado), usuário, interesses,
  situação, avaliações em andamento com link para as submissões, atrasadas, concluídas, últimos 12
  meses, convites, barras de aceite e de prazo, média de dias, última conclusão (em destaque depois
  de 24 meses), os números que o OJS mostra na escolha do avaliador, nota e última designação. Dados
  pessoais ficam ocultos até o editor mostrar.
- **Exportação** — CSV para o Excel (UTF-8 com BOM, `;`) ou planilha do Excel (XLSX, cabeçalho
  congelado com botões de filtro), com exatamente os avaliadores filtrados; a primeira linha diz a
  revista, quando, quem gerou e com quais filtros.
- **Nominata** — período de conclusão das avaliações e, no OJS, edição: quadros com avaliadores,
  avaliações, submissões e países; a tabela; a exportação; e **a lista pronta para publicar**, um
  avaliador por linha em ordem alfabética com afiliação e país, copiada com um clique.

### Duas famílias de números

- **Como o OJS/OMP mostra** na escolha do avaliador — concluídas, recusadas, média de dias, nota,
  última designação — da mesma consulta do núcleo: são os números daquela tela. A menos que o
  administrador do site tenha desligado o compartilhamento, somam todas as revistas do site.
- **Só desta revista ou editora** — em andamento, atrasadas, concluídas, últimos 12 meses, convites,
  aceite, no prazo, média de dias, última conclusão. Os quadros, os índices e as situações vêm daqui.

Uma avaliação está **em andamento** do jeito que o OJS a vê ao mandar os lembretes: convite enviado,
sem conclusão, recusa ou cancelamento, na última rodada da etapa em que a submissão está, e a
submissão ainda no fluxo. Avaliação esquecida aberta numa submissão depois publicada ou rejeitada não
conta. Está **atrasada** quando a resposta ou o parecer venciam até hoje (regra do núcleo).

### Instalação

Envie o pacote em **Configurações → Website → Plugins → Enviar um novo plugin**, ou extraia a pasta em
`plugins/generic/` (ficando `plugins/generic/reviewerDirectory/`). Depois ative o **Diretório de
Avaliadores** na lista de plugins *Genéricos*. Abre pelo menu lateral, pela ação *Abrir diretório* da
lista de plugins ou em `/<revista>/reviewerdirectory`. Links para a página anterior continuam
funcionando.

### Testes

PHPUnit e Cypress no CI da PKP (OJS e OMP, MySQL e PostgreSQL): quem abre a página e os dados;
agrupamento da nominata; datas; colunas (dados pessoais ocultos); filtros, situações, índices e
ordenação; CSV e XLSX; e, contra o banco, avaliadores e números do OJS iguais aos do núcleo, números
da revista e nominata iguais a contagens independentes; traduções. No navegador: para cada
avaliador, os números da API de seleção de avaliadores; situações fechando; nominata com o texto;
CSV com as linhas da tela; avaliador barrado. Conferido no OJS e no OMP 3.5.

### Idiomas

38 idiomas, todos os do OJS/OMP 3.5. Inglês, português (Brasil e Portugal) e espanhol são revisados
pela equipe; os demais seguem os termos do próprio OJS em cada idioma e ficam marcados como *fuzzy*
até um falante revisar (o OJS mostra do mesmo jeito). Correções são bem-vindas.

### Créditos e autoria

- **Desenvolvido e mantido pela** [OJSBR](https://ojsbr.com).
- **[Public Knowledge Project](https://pkp.sfu.ca)** — o painel de seleção de avaliadores do OJS e do
  OMP e a consulta do núcleo por trás das estatísticas dele, que o diretório reaproveita; os
  componentes do núcleo usados na página.
- **O [Relatório de submissões](https://github.com/OJSBR/ojsbrSubmissionQueueReport) da OJSBR** — o
  layout da página (quadros, resumo que fecha, filtros, exportação).
- Distribuído sob a **GNU GPL v3**.

### Uso de IA

Foi usada IA generativa (Claude, da Anthropic) para escrever e rodar testes, melhorar o código e
alinhá-lo aos padrões da PKP. Toda mudança é revisada e testada pela OJSBR, que responde pelas
releases publicadas.

### Licença

Distribuído sob a **GNU GPL v3**. Veja [`LICENSE`](LICENSE) e `docs/COPYING`.
