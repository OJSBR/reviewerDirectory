# Rewrites locale/<code>/locale.po: keeps the translations of the keys still in use, drops
# the keys no longer used, and adds the new ones (en, pt_BR, es and pt reviewed; the other
# locales get the text of tools/translations/<code>.json marked fuzzy until a speaker reviews
# it — OJS shows fuzzy entries as any other).
# Usage: python3 tools/locales.py
import json
import os
import re

P = 'plugins.generic.reviewerDirectory.'
BASE = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'locale')

KEEP = ['displayName', 'description', 'openDirectory', 'searchPlaceholder', 'rating', 'lastAssigned', 'lastCompleted',
        'orcidVerified', 'columns', 'export', 'tabDirectory', 'tabNominata', 'nominataIntro', 'dateFrom', 'dateTo',
        'issue', 'allIssues', 'generate', 'nominataEmpty', 'reviewsInScope', 'submissions', 'firstDate', 'lastDate']

# key: (en, pt_BR, es, pt)
NEW = [
 ('situation.overdue', 'Overdue', 'Em atraso', 'Con retraso', 'Em atraso'),
 ('situation.overdue.description', 'Has a review whose response or review is past its due date.', 'Tem avaliação com resposta ou parecer fora do prazo.', 'Tiene una revisión con respuesta o informe fuera de plazo.', 'Tem uma revisão com resposta ou parecer fora do prazo.'),
 ('situation.busy', 'Busy', 'Sobrecarregados', 'Saturados', 'Sobrecarregados'),
 ('situation.busy.description', 'Three or more reviews in progress, none overdue.', 'Três ou mais avaliações em andamento, nenhuma atrasada.', 'Tres o más revisiones en curso, ninguna con retraso.', 'Três ou mais revisões em curso, nenhuma em atraso.'),
 ('situation.reviewing', 'Reviewing', 'Avaliando', 'Revisando', 'A rever'),
 ('situation.reviewing.description', 'One or two reviews in progress, none overdue.', 'Uma ou duas avaliações em andamento, nenhuma atrasada.', 'Una o dos revisiones en curso, ninguna con retraso.', 'Uma ou duas revisões em curso, nenhuma em atraso.'),
 ('situation.recent', 'Available, active', 'Livres, em atividade', 'Disponibles, activos', 'Livres, em atividade'),
 ('situation.recent.description', 'No review in progress; completed a review for the journal in the last 12 months.', 'Sem avaliação em andamento; concluiu avaliação para a revista nos últimos 12 meses.', 'Sin revisiones en curso; completó una revisión para la revista en los últimos 12 meses.', 'Sem revisões em curso; concluiu uma revisão para a revista nos últimos 12 meses.'),
 ('situation.idle', 'Available, idle', 'Livres, parados', 'Disponibles, inactivos', 'Livres, inativos'),
 ('situation.idle.description', 'No review in progress; their last review for the journal is more than 12 months old.', 'Sem avaliação em andamento; a última avaliação para a revista tem mais de 12 meses.', 'Sin revisiones en curso; su última revisión para la revista tiene más de 12 meses.', 'Sem revisões em curso; a última revisão para a revista tem mais de 12 meses.'),
 ('situation.new', 'Never reviewed', 'Nunca avaliaram', 'Nunca revisaron', 'Nunca reviram'),
 ('situation.new.description', 'Never completed a review for the journal and has none in progress.', 'Nunca concluíram avaliação para a revista e não têm nenhuma em andamento.', 'Nunca completaron una revisión para la revista y no tienen ninguna en curso.', 'Nunca concluíram uma revisão para a revista e não têm nenhuma em curso.'),
 ('group.available', 'Available now', 'Livres agora', 'Disponibles ahora', 'Livres agora'),
 ('group.available.description', 'No review in progress for the journal.', 'Nenhuma avaliação em andamento na revista.', 'Ninguna revisión en curso en la revista.', 'Nenhuma revisão em curso na revista.'),
 ('group.reviewing', 'Reviewing now', 'Avaliando agora', 'Revisando ahora', 'A rever agora'),
 ('group.reviewing.description', 'At least one review in progress for the journal.', 'Ao menos uma avaliação em andamento na revista.', 'Al menos una revisión en curso en la revista.', 'Pelo menos uma revisão em curso na revista.'),
 ('group.overdue', 'With overdue reviews', 'Com avaliação atrasada', 'Con revisiones vencidas', 'Com revisões em atraso'),
 ('group.overdue.description', 'A response or a review past its due date.', 'Resposta ou parecer fora do prazo.', 'Respuesta o informe fuera de plazo.', 'Resposta ou parecer fora do prazo.'),
 ('group.recent', 'Reviewed in the last 12 months', 'Avaliaram nos últimos 12 meses', 'Revisaron en los últimos 12 meses', 'Reviram nos últimos 12 meses'),
 ('group.recent.description', 'Completed at least one review for the journal in the last 12 months.', 'Concluíram ao menos uma avaliação para a revista nos últimos 12 meses.', 'Completaron al menos una revisión para la revista en los últimos 12 meses.', 'Concluíram pelo menos uma revisão para a revista nos últimos 12 meses.'),
 ('group.never', 'Never reviewed for the journal', 'Nunca avaliaram para a revista', 'Nunca revisaron para la revista', 'Nunca reviram para a revista'),
 ('group.never.description', 'No review completed for the journal yet.', 'Nenhuma avaliação concluída para a revista até agora.', 'Ninguna revisión completada para la revista hasta ahora.', 'Nenhuma revisão concluída para a revista até agora.'),
 ('group.withoutOrcid', 'Without ORCID', 'Sem ORCID', 'Sin ORCID', 'Sem ORCID'),
 ('group.withoutOrcid.description', 'No ORCID iD in the profile.', 'Sem ORCID iD no perfil.', 'Sin ORCID iD en el perfil.', 'Sem ORCID iD no perfil.'),
 ('column.situation', 'Situation', 'Situação', 'Situación', 'Situação'),
 ('column.active', 'In progress', 'Em andamento', 'En curso', 'Em curso'),
 ('column.active.description', 'Reviews in progress for the journal (requested and not yet completed, declined or cancelled), with their submissions.', 'Avaliações em andamento na revista (solicitadas e ainda não concluídas, recusadas ou canceladas), com as submissões.', 'Revisiones en curso en la revista (solicitadas y aún no completadas, rechazadas ni canceladas), con sus envíos.', 'Revisões em curso na revista (pedidas e ainda não concluídas, recusadas ou canceladas), com as submissões.'),
 ('column.overdue', 'Overdue', 'Atrasadas', 'Vencidas', 'Em atraso'),
 ('column.overdue.description', 'Reviews in progress with the response or the review past its due date.', 'Avaliações em andamento com a resposta ou o parecer fora do prazo.', 'Revisiones en curso con la respuesta o el informe fuera de plazo.', 'Revisões em curso com a resposta ou o parecer fora do prazo.'),
 ('column.completed', 'Completed', 'Concluídas', 'Completadas', 'Concluídas'),
 ('column.completed.description', 'Reviews completed for this journal.', 'Avaliações concluídas para esta revista.', 'Revisiones completadas para esta revista.', 'Revisões concluídas para esta revista.'),
 ('column.recent', 'Last 12 months', 'Últimos 12 meses', 'Últimos 12 meses', 'Últimos 12 meses'),
 ('column.recent.description', 'Reviews completed for this journal in the last 12 months.', 'Avaliações concluídas para esta revista nos últimos 12 meses.', 'Revisiones completadas para esta revista en los últimos 12 meses.', 'Revisões concluídas para esta revista nos últimos 12 meses.'),
 ('column.requests', 'Requests', 'Convites', 'Invitaciones', 'Convites'),
 ('column.requests.description', 'Review requests from this journal, cancelled ones left out.', 'Convites para avaliar recebidos desta revista, sem contar os cancelados.', 'Invitaciones a revisar recibidas de esta revista, sin contar las canceladas.', 'Convites para rever recebidos desta revista, sem contar os cancelados.'),
 ('column.acceptance', 'Acceptance', 'Aceite', 'Aceptación', 'Aceitação'),
 ('column.acceptance.description', 'Requests accepted out of the requests answered, in this journal.', 'Convites aceitos entre os convites respondidos, nesta revista.', 'Invitaciones aceptadas entre las respondidas, en esta revista.', 'Convites aceites entre os respondidos, nesta revista.'),
 ('column.onTime', 'On time', 'No prazo', 'A tiempo', 'No prazo'),
 ('column.onTime.description', 'Reviews completed by their due date, out of the reviews completed for this journal.', 'Pareceres entregues até o prazo, entre as avaliações concluídas para esta revista.', 'Informes entregados dentro del plazo, entre las revisiones completadas para esta revista.', 'Pareceres entregues dentro do prazo, entre as revisões concluídas para esta revista.'),
 ('column.averageDays', 'Average days', 'Média de dias', 'Promedio de días', 'Média de dias'),
 ('column.averageDays.description', 'Average days from the request to the review, in this journal.', 'Média de dias entre o convite e o parecer, nesta revista.', 'Promedio de días entre la invitación y el informe, en esta revista.', 'Média de dias entre o convite e o parecer, nesta revista.'),
 ('column.lastCompleted.description', 'Last review completed for this journal.', 'Última avaliação concluída para esta revista.', 'Última revisión completada para esta revista.', 'Última revisão concluída para esta revista.'),
 ('column.ojs', 'In OJS: completed · declined · average', 'No OJS: concluídas · recusadas · média', 'En OJS: completadas · rechazadas · promedio', 'No OJS: concluídas · recusadas · média'),
 ('column.ojs.description', 'The numbers OJS shows when an editor chooses a reviewer. Unless the site administrator turned the sharing off, they cover every journal of the site.', 'Os números que o OJS mostra ao editor na escolha do avaliador. A menos que o administrador do site tenha desligado o compartilhamento, eles somam todas as revistas do site.', 'Los números que OJS muestra al editor al elegir un revisor. Salvo que el administrador del sitio haya desactivado el uso compartido, suman todas las revistas del sitio.', 'Os números que o OJS mostra ao editor na escolha do revisor. A menos que o administrador do sítio tenha desligado a partilha, somam todas as revistas do sítio.'),
 ('column.rating.description', 'Average of the ratings editors gave the reviewer\'s reviews, as OJS shows it.', 'Média das notas que os editores deram aos pareceres do avaliador, como o OJS mostra.', 'Promedio de las calificaciones que los editores dieron a los informes del revisor, como lo muestra OJS.', 'Média das classificações que os editores deram aos pareceres do revisor, como o OJS mostra.'),
 ('column.lastAssigned.description', 'Last review request, as OJS shows it.', 'Último convite para avaliar, como o OJS mostra.', 'Última invitación a revisar, como la muestra OJS.', 'Último convite para rever, como o OJS mostra.'),
 ('column.activeSubmissions', 'Submissions in review', 'Submissões em avaliação', 'Envíos en revisión', 'Submissões em revisão'),
 ('column.ojsCompleted', 'Completed (OJS)', 'Concluídas (OJS)', 'Completadas (OJS)', 'Concluídas (OJS)'),
 ('column.ojsDeclined', 'Declined (OJS)', 'Recusadas (OJS)', 'Rechazadas (OJS)', 'Recusadas (OJS)'),
 ('column.ojsAverageDays', 'Average days (OJS)', 'Média de dias (OJS)', 'Promedio de días (OJS)', 'Média de dias (OJS)'),
 ('filter.interest.description', 'Reviewers with an interest that contains this text.', 'Avaliadores com algum interesse que contenha este texto.', 'Revisores con algún interés que contenga este texto.', 'Revisores com algum interesse que contenha este texto.'),
 ('filter.orcid.any', 'Any', 'Qualquer', 'Cualquiera', 'Qualquer'),
 ('filter.orcid.with', 'With ORCID', 'Com ORCID', 'Con ORCID', 'Com ORCID'),
 ('filter.orcid.without', 'Without ORCID', 'Sem ORCID', 'Sin ORCID', 'Sem ORCID'),
 ('filter.orcid.verified', 'Verified ORCID', 'ORCID autenticado', 'ORCID verificado', 'ORCID verificado'),
 ('filter.minCompleted', 'At least this many reviews completed for the journal', 'Mínimo de avaliações concluídas para a revista', 'Mínimo de revisiones completadas para la revista', 'Mínimo de revisões concluídas para a revista'),
 ('filter.monthsIdle', 'Months without completing a review', 'Meses sem concluir avaliação', 'Meses sin completar una revisión', 'Meses sem concluir uma revisão'),
 ('filter.monthsIdle.description', 'Reviewers whose last review for the journal is at least this old, or who never completed one.', 'Avaliadores cuja última avaliação para a revista tem pelo menos essa idade, ou que nunca concluíram uma.', 'Revisores cuya última revisión para la revista tiene al menos esa antigüedad, o que nunca completaron una.', 'Revisores cuja última revisão para a revista tem pelo menos essa idade, ou que nunca concluíram uma.'),
 ('export.csv', 'CSV (Excel, LibreOffice)', 'CSV (Excel, LibreOffice)', 'CSV (Excel, LibreOffice)', 'CSV (Excel, LibreOffice)'),
 ('export.xlsx', 'Excel workbook (XLSX)', 'Planilha do Excel (XLSX)', 'Libro de Excel (XLSX)', 'Folha de cálculo do Excel (XLSX)'),
 ('export.filePrefix', 'reviewers', 'avaliadores', 'revisores', 'revisores'),
 ('export.rosterFilePrefix', 'reviewer roster', 'nominata', 'nomina de revisores', 'nominata'),
 ('export.firstLine', '{$context} — generated on {$date} by {$user} — {$filters}', '{$context} — gerado em {$date} por {$user} — {$filters}', '{$context} — generado el {$date} por {$user} — {$filters}', '{$context} — gerado em {$date} por {$user} — {$filters}'),
 ('rosterPeriod', 'Reviews completed between {$from} and {$to}', 'Avaliações concluídas entre {$from} e {$to}', 'Revisiones completadas entre {$from} y {$to}', 'Revisões concluídas entre {$from} e {$to}'),
 ('issue.description', 'Only the reviews of the articles of this issue.', 'Só as avaliações dos artigos desta edição.', 'Solo las revisiones de los artículos de este número.', 'Só as revisões dos artigos desta edição.'),
 ('allReviewers', 'All reviewers', 'Todos os avaliadores', 'Todos los revisores', 'Todos os revisores'),
 ('totalCount', 'Total: {$n}', 'Total: {$n}', 'Total: {$n}', 'Total: {$n}'),
 ('generatedAt', 'Generated on {$date}', 'Gerado em {$date}', 'Generado el {$date}', 'Gerado em {$date}'),
 ('exportTitle', 'Export what is filtered', 'Exportar o que está filtrado', 'Exportar lo filtrado', 'Exportar o que está filtrado'),
 ('exportFormat', 'Format', 'Formato', 'Formato', 'Formato'),
 ('download', 'Download', 'Baixar', 'Descargar', 'Descarregar'),
 ('indicesHeading', 'The reviewer pool', 'O quadro de avaliadores', 'El equipo de revisores', 'O quadro de revisores'),
 ('indicesNote', 'Everyone with the Reviewer role in the journal. Choose a group to list its reviewers.', 'Todos com o papel de Avaliador na revista. Escolha um grupo para listar os avaliadores dele.', 'Todas las personas con el rol de Revisor en la revista. Elija un grupo para listar sus revisores.', 'Todos com o papel de Revisor na revista. Escolha um grupo para listar os revisores dele.'),
 ('indicesFiltered', 'With the filters in use.', 'Com os filtros em uso.', 'Con los filtros activos.', 'Com os filtros em uso.'),
 ('ratesHeading', 'Indices of the listed reviewers in this journal', 'Índices dos avaliadores listados nesta revista', 'Índices de los revisores listados en esta revista', 'Índices dos revisores listados nesta revista'),
 ('rate.acceptance', 'Acceptance', 'Aceite dos convites', 'Aceptación de invitaciones', 'Aceitação dos convites'),
 ('rate.acceptance.description', 'Requests accepted out of the requests answered.', 'Convites aceitos entre os convites respondidos.', 'Invitaciones aceptadas entre las respondidas.', 'Convites aceites entre os respondidos.'),
 ('rate.onTime', 'Reviews on time', 'Pareceres no prazo', 'Informes a tiempo', 'Pareceres no prazo'),
 ('rate.onTime.description', 'Reviews completed by their due date, out of all completed reviews.', 'Pareceres entregues até o prazo, entre todos os concluídos.', 'Informes entregados dentro del plazo, entre todos los completados.', 'Pareceres entregues dentro do prazo, entre todos os concluídos.'),
 ('rate.averageDays', 'From request to review', 'Do convite ao parecer', 'De la invitación al informe', 'Do convite ao parecer'),
 ('rate.averageDays.description', 'Average days from the request to the review, over all completed reviews.', 'Média de dias entre o convite e o parecer, em todas as avaliações concluídas.', 'Promedio de días entre la invitación y el informe, en todas las revisiones completadas.', 'Média de dias entre o convite e o parecer, em todas as revisões concluídas.'),
 ('rate.reviewsInProgress', 'Reviews in progress', 'Avaliações em andamento', 'Revisiones en curso', 'Revisões em curso'),
 ('rate.reviewsInProgress.description', 'Submissions in review with the listed reviewers now.', 'Submissões em avaliação com os avaliadores listados agora.', 'Envíos en revisión con los revisores listados ahora.', 'Submissões em revisão com os revisores listados agora.'),
 ('rate.reviewsRecent', 'Reviews in the last 12 months', 'Avaliações nos últimos 12 meses', 'Revisiones en los últimos 12 meses', 'Revisões nos últimos 12 meses'),
 ('rate.reviewsRecent.description', 'Reviews the listed reviewers completed for the journal in the last 12 months.', 'Avaliações que os avaliadores listados concluíram para a revista nos últimos 12 meses.', 'Revisiones que los revisores listados completaron para la revista en los últimos 12 meses.', 'Revisões que os revisores listados concluíram para a revista nos últimos 12 meses.'),
 ('summaryHeading', 'Situation', 'Situação', 'Situación', 'Situação'),
 ('summaryDescription', 'One situation per reviewer, the first that applies in this order, so the numbers add up. Choose one or more to list only their reviewers.', 'Uma situação por avaliador, a primeira que servir nesta ordem, para os números fecharem. Escolha uma ou mais para listar só esses avaliadores.', 'Una situación por revisor, la primera que corresponda en este orden, para que los números sumen. Elija una o más para listar solo esos revisores.', 'Uma situação por revisor, a primeira que se aplicar nesta ordem, para os números baterem certo. Escolha uma ou mais para listar só esses revisores.'),
 ('summaryClear', 'Show every situation', 'Mostrar todas as situações', 'Mostrar todas las situaciones', 'Mostrar todas as situações'),
 ('filtersShow', 'Show filters', 'Mostrar filtros', 'Mostrar filtros', 'Mostrar filtros'),
 ('filtersHide', 'Hide filters', 'Ocultar filtros', 'Ocultar filtros', 'Ocultar filtros'),
 ('activeFilters', 'Active filters', 'Filtros em uso', 'Filtros activos', 'Filtros em uso'),
 ('columnsReset', 'Default columns', 'Colunas padrão', 'Columnas predeterminadas', 'Colunas predefinidas'),
 ('loadError', 'The directory could not be loaded.', 'Não foi possível carregar o diretório.', 'No se pudo cargar el directorio.', 'Não foi possível carregar o diretório.'),
 ('retry', 'Try again', 'Tentar de novo', 'Intentar de nuevo', 'Tentar novamente'),
 ('pageOf', 'Page {$page} of {$lastPage}', 'Página {$page} de {$lastPage}', 'Página {$page} de {$lastPage}', 'Página {$page} de {$lastPage}'),
 ('percent', '{$percent}%', '{$percent}%', '{$percent}%', '{$percent}%'),
 ('days', '{$days} days', '{$days} dias', '{$days} días', '{$days} dias'),
 ('monthsAgo', '{$months} months ago', 'há {$months} meses', 'hace {$months} meses', 'há {$months} meses'),
 ('thisMonth', 'this month', 'neste mês', 'este mes', 'este mês'),
 ('none', 'None', 'Nenhuma', 'Ninguna', 'Nenhuma'),
 ('openSubmission', 'Open submission {$id} in a new tab', 'Abrir a submissão {$id} numa nova aba', 'Abrir el envío {$id} en una pestaña nueva', 'Abrir a submissão {$id} num novo separador'),
 ('mailto', 'Write to {$name}', 'Escrever para {$name}', 'Escribir a {$name}', 'Escrever para {$name}'),
 ('ojsNote', 'As OJS shows it when choosing a reviewer: completed · declined · average days', 'Como o OJS mostra na escolha do avaliador: concluídas · recusadas · média de dias', 'Como lo muestra OJS al elegir un revisor: completadas · rechazadas · promedio de días', 'Como o OJS mostra na escolha do revisor: concluídas · recusadas · média de dias'),
 ('ratingOf', 'Rated {$rating} out of 5', 'Nota {$rating} de 5', 'Calificación {$rating} de 5', 'Classificação {$rating} em 5'),
 ('roster.reviewers', 'Reviewers', 'Avaliadores', 'Revisores', 'Revisores'),
 ('roster.countries', 'Countries', 'Países', 'Países', 'Países'),
 ('roster.copy', 'Copy the list for publishing', 'Copiar a lista para publicar', 'Copiar la lista para publicar', 'Copiar a lista para publicar'),
 ('roster.copied', 'Copied.', 'Copiado.', 'Copiado.', 'Copiado.'),
 ('roster.copyDescription', 'One reviewer per line, in alphabetical order, with affiliation and country: ready to paste into the acknowledgement page or the issue.', 'Um avaliador por linha, em ordem alfabética, com afiliação e país: pronto para colar na página de agradecimento ou na edição.', 'Un revisor por línea, en orden alfabético, con afiliación y país: listo para pegar en la página de agradecimiento o en el número.', 'Um revisor por linha, por ordem alfabética, com afiliação e país: pronto a colar na página de agradecimento ou na edição.'),
]

REVIEWED = {'en': 0, 'pt_BR': 1, 'es': 2, 'pt': 3}


def q(text):
    return '"' + text.replace('\\', '\\\\').replace('"', '\\"') + '"'


def parse(path):
    text = open(path, encoding='utf-8').read()
    header = text[:text.index('\n\n') + 2] if text.startswith('msgid ""') else ''
    entries = {}
    for fuzzy, key, value in re.findall(r'(#, fuzzy\n)?msgid "' + re.escape(P) + r'([^"]+)"\nmsgstr "((?:[^"\\]|\\.)*)"', text):
        entries[key] = (value.encode('latin-1', 'backslashreplace').decode('unicode_escape') if False else value.replace('\\"', '"').replace('\\\\', '\\'), bool(fuzzy))
    return header, entries


for code in sorted(os.listdir(BASE)):
    path = os.path.join(BASE, code, 'locale.po')
    header, entries = parse(path)
    translated = {}
    if code not in REVIEWED:
        translated = json.load(open(os.path.join(os.path.dirname(os.path.abspath(__file__)), 'translations', code + '.json'), encoding='utf-8'))
        for row in NEW:
            found = sorted(re.findall(r'\{\$\w+\}', translated[row[0]]))
            assert translated[row[0]].strip() and found == sorted(re.findall(r'\{\$\w+\}', row[1])), code + ': ' + row[0]
    out = header.rstrip('\n') + '\n\n'
    for key in KEEP:
        value, fuzzy = entries[key]
        out += '\n' + ('#, fuzzy\n' if fuzzy else '') + 'msgid ' + q(P + key) + '\nmsgstr ' + q(value) + '\n'
    for row in NEW:
        key = row[0]
        if code in REVIEWED:
            out += '\nmsgid ' + q(P + key) + '\nmsgstr ' + q(row[1 + REVIEWED[code]]) + '\n'
        else:
            out += '\n#, fuzzy\nmsgid ' + q(P + key) + '\nmsgstr ' + q(translated[key]) + '\n'
    open(path, 'w', encoding='utf-8').write(out)
print(len(KEEP), 'kept,', len(NEW), 'new,', len(os.listdir(BASE)), 'locales')
