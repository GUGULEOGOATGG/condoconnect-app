import React, { useState } from 'react';
import { Alert, Pressable, StyleSheet, Text, View } from 'react-native';
import { useApp } from './AppContext';
import { accessOf, categories, displayDate, localDate, priorities, roleLabels, statusLabels, ticketStats, validateTicket } from './domain';
import type { Navigate } from './navigation';
import type { Ticket } from './types';
import { AdminHome, TileGrid } from './RoleScreens';
import { Body, Button, Card, Chips, colors, DateField, Empty, Field, Icon, Label, s, Screen, Title, useAction } from './ui';
import type { IconName } from './ui';

export function TicketBadge({ status }: { status: Ticket['status'] }) {
  const tones = { aberto: ['#FFF6DE', '#936200'], andamento: ['#E8F0FF', '#1D4ED8'], concluido: ['#E8F8F0', '#047857'], cancelado: ['#F0F2F6', '#64748B'] };
  const tone = tones[status] || tones.aberto;
  return <View style={[t.badge, { backgroundColor: tone[0] }]}><View style={[t.dot, { backgroundColor: tone[1] }]} /><Text style={[t.badgeText, { color: tone[1] }]}>{statusLabels[status] || status}</Text></View>;
}
export function TicketCard({ ticket, onPress }: { ticket: Ticket; onPress: () => void }) {
  return <Pressable accessibilityRole="button" onPress={onPress} style={({ pressed }) => pressed && { opacity: .7 }}><Card><View style={[s.row, { justifyContent: 'space-between', flexWrap: 'wrap' }]}><Text style={t.category}>{ticket.categoria}</Text><TicketBadge status={ticket.status} /></View><Text style={s.cardTitle}>{ticket.titulo}</Text><View style={s.row}><Icon name="location-outline" color={colors.muted} size={15} /><Body style={{ fontSize: 12, flex: 1 }}>{ticket.local_problema}</Body><Icon name="chevron-forward" size={18} /></View><View style={t.cardFooter}><Text style={t.small}>{ticket.protocolo}</Text><Text style={t.small}>{displayDate(ticket.criado_em)}</Text></View></Card></Pressable>;
}
export function HomeScreen({ navigate }: { navigate: Navigate }) {
  const app = useApp(), { data } = app;
  if (!data) return null;
  const access = accessOf(data), role = data.user.tipo_usuario, me = Number(data.user.id), firstName = data.user.nome.split(' ')[0];
  const stats = ticketStats(data.tickets), confirmed = Number(data.user.email_verificado) === 1;
  const greeting = <View style={{ gap: 6 }}><Label>{role === 'admin' ? 'Administração' : 'Meu espaço'} · {roleLabels[role]}</Label><Title>Olá, {firstName}!</Title></View>;
  if (!confirmed) return <Screen refresh>{greeting}<Card style={{ borderColor: '#F5D77E', backgroundColor: '#FFF9E8' }}><Text style={s.cardTitle}>Confirme seu e-mail</Text><Body>Abra o link enviado para {data.user.email}. Depois, puxe a tela para atualizar e liberar os recursos.</Body><Button title="Reenviar confirmação" secondary onPress={() => app.resendVerification().then(message => Alert.alert('Confirmação', message)).catch(error => Alert.alert('Não foi possível enviar', error.message))} /></Card></Screen>;
  // Cada nível de acesso tem seu próprio início.
  if (role === 'admin') return <Screen refresh>{greeting}<Body>Indicadores reais para acompanhar pessoas, atendimentos e espaços.</Body><AdminHome navigate={navigate} /></Screen>;
  if (role === 'sindico' && !access.manager) return <Screen refresh>{greeting}<Card style={{ borderColor: '#F5D77E', backgroundColor: '#FFF9E8' }}><Text style={s.cardTitle}>{access.legacy ? 'Atualização necessária' : 'Verificação pendente'}</Text><Body>{access.legacy ? 'O servidor ainda usa a versão anterior da API do aplicativo. Peça a atualização do arquivo api/mobile.php para liberar a gestão.' : 'Chamados e recursos de gestão serão liberados quando a administração aprovar seu certificado de síndico.'}</Body></Card><TileGrid tiles={[{ title: 'Comunicados', subtitle: 'Avisos do condomínio', icon: 'megaphone-outline', onPress: () => navigate('notices') }, { title: 'Condomínio', subtitle: 'Regras e contato', icon: 'business-outline', onPress: () => navigate('community') }]} /></Screen>;
  const available = data.tickets.filter(ticket => ticket.status === 'aberto' && !ticket.prestador_id), mine = data.tickets.filter(ticket => Number(ticket.prestador_id) === me);
  const amber = { color: '#B7790C', bg: '#FFF5DD' }, blue = { color: colors.primary, bg: colors.blue }, green = { color: colors.green, bg: '#E8F8F0' };
  type Stat = { label: string; value: number; icon: IconName; color: string; bg: string };
  const views: Record<'morador' | 'prestador' | 'sindico', { title: string; body: string; stats: Stat[]; heading: string; tiles: { title: string; subtitle: string; icon: IconName; onPress: () => void; badge?: number }[]; recentTitle: string; recent: Ticket[]; emptyTitle: string; emptyBody: string }> = {
    morador: { title: 'Uma rotina mais leve\ncomeça aqui.', body: 'Seu condomínio inteiro,\nem um só lugar.', heading: 'Como podemos ajudar?', recentTitle: 'Chamados recentes', recent: data.tickets, emptyTitle: 'Tudo tranquilo por aqui', emptyBody: 'Se algo precisar de atenção, abra seu primeiro chamado.',
      stats: [{ label: 'Aguardando', value: stats.open, icon: 'time-outline', ...amber }, { label: 'Em andamento', value: stats.active, icon: 'pulse-outline', ...blue }],
      tiles: [{ title: 'Chamados', subtitle: 'Solicite e acompanhe', icon: 'construct-outline', onPress: () => navigate('tickets') }, { title: 'Reservas', subtitle: 'Bons momentos', icon: 'calendar-outline', onPress: () => navigate('reservations') }, { title: 'Comunicados', subtitle: 'Fique por dentro', icon: 'megaphone-outline', onPress: () => navigate('notices'), badge: data.notices.filter(notice => !notice.lido_em).length }, { title: 'Prestadores', subtitle: 'Encontre quem ajuda', icon: 'people-outline', onPress: () => navigate('providers') }] },
    prestador: { title: 'Seu trabalho transforma\no dia de alguém.', body: 'Aceite chamados e acompanhe\nseus atendimentos.', heading: 'Seu dia de trabalho', recentTitle: 'Chamados disponíveis', recent: available, emptyTitle: 'Nenhum chamado disponível', emptyBody: 'Novos pedidos de atendimento aparecerão aqui.',
      stats: [{ label: 'Disponíveis', value: available.length, icon: 'time-outline', ...amber }, { label: 'Em atendimento', value: mine.filter(ticket => ticket.status === 'andamento').length, icon: 'pulse-outline', ...blue }, { label: 'Concluídos', value: mine.filter(ticket => ticket.status === 'concluido').length, icon: 'checkmark-done-outline', ...green }],
      tiles: [{ title: 'Atendimentos', subtitle: 'Disponíveis e seus', icon: 'construct-outline', onPress: () => navigate('tickets') }, { title: 'Conversas', subtitle: 'Fale com moradores', icon: 'chatbubbles-outline', onPress: () => navigate('chats') }, { title: 'Comunicados', subtitle: 'Avisos do condomínio', icon: 'megaphone-outline', onPress: () => navigate('notices') }, { title: 'Condomínio', subtitle: 'Regras e contato', icon: 'business-outline', onPress: () => navigate('community') }] },
    sindico: { title: 'O condomínio\nem boas mãos.', body: data.community?.nome || data.user.condominio || 'Gestão do seu condomínio.', heading: 'Gestão do condomínio', recentTitle: 'Chamados do condomínio', recent: data.tickets, emptyTitle: 'Nenhum chamado no condomínio', emptyBody: 'Os pedidos dos moradores aparecerão aqui.',
      stats: [{ label: 'Abertos', value: stats.open, icon: 'time-outline', ...amber }, { label: 'Em andamento', value: stats.active, icon: 'pulse-outline', ...blue }, { label: 'Concluídos', value: stats.done, icon: 'checkmark-done-outline', ...green }],
      tiles: [{ title: 'Publicar comunicado', subtitle: 'Avise os moradores', icon: 'megaphone-outline', onPress: () => navigate('noticeEditor') }, { title: 'Chamados', subtitle: 'Todos do condomínio', icon: 'construct-outline', onPress: () => navigate('tickets') }, { title: 'Reservas', subtitle: 'Agenda dos espaços', icon: 'calendar-outline', onPress: () => navigate('reservations') }, { title: 'Condomínio', subtitle: 'Editar regras e contato', icon: 'create-outline', onPress: () => navigate('editCommunity') }] },
  };
  const view = views[role === 'prestador' || role === 'sindico' ? role : 'morador'];
  return <Screen refresh>{greeting}
    <View style={t.banner}><View style={{ flex: 1, gap: 8 }}><Text style={t.bannerLabel}>CADA CONEXÃO FAZ A DIFERENÇA</Text><Text style={t.bannerTitle}>{view.title}</Text><Text style={t.bannerBody}>{view.body}</Text></View><View style={t.bannerIcon}><Icon name="business-outline" size={47} color="#BFD4FC" /></View></View>
    <View style={t.stats}>{view.stats.map(item => <View key={item.label} style={[t.stat, view.stats.length > 2 && { padding: 13 }]}><View style={[t.statIcon, { backgroundColor: item.bg }]}><Icon name={item.icon} color={item.color} size={18} /></View><Text style={[t.statValue, view.stats.length > 2 && { fontSize: 26 }]}>{item.value.toString().padStart(2, '0')}</Text><Text style={t.statLabel}>{item.label}</Text></View>)}</View>
    <View style={{ gap: 14 }}><Text style={s.cardTitle}>{view.heading}</Text><TileGrid tiles={view.tiles} /></View>
    <View style={[s.row, { justifyContent: 'space-between' }]}><Text style={s.cardTitle}>{view.recentTitle}</Text><Pressable onPress={() => navigate('tickets')} accessibilityRole="button" style={{ minHeight: 44, justifyContent: 'center' }}><Text style={t.link}>Ver todos →</Text></Pressable></View>
    {view.recent.length ? view.recent.slice(0, 2).map(ticket => <TicketCard key={ticket.id} ticket={ticket} onPress={() => navigate('ticket', Number(ticket.id))} />) : <Empty title={view.emptyTitle} description={view.emptyBody} />}
    {role === 'morador' && <Button title="Abrir um chamado" icon="add-circle-outline" onPress={() => navigate('newTicket')} />}
  </Screen>;
}
export function TicketsScreen({ navigate }: { navigate: Navigate }) {
  const { data } = useApp(), [filter, setFilter] = useState('Todos'), [query, setQuery] = useState('');
  if (!data) return null;
  const tickets = data.tickets.filter(ticket => (filter === 'Todos' || statusLabels[ticket.status] === filter) && `${ticket.titulo} ${ticket.categoria} ${ticket.protocolo}`.toLocaleLowerCase('pt-BR').includes(query.toLocaleLowerCase('pt-BR')));
  return <Screen refresh><Label>Do pedido à solução</Label><Title>{{ morador: 'Meus chamados', prestador: 'Atendimentos', sindico: 'Chamados do condomínio', admin: 'Todos os chamados' }[data.user.tipo_usuario] || 'Chamados'}</Title><Body>Acompanhe cada etapa do atendimento.</Body>{data.user.tipo_usuario === 'morador' && <Button title="Novo chamado" icon="add" onPress={() => navigate('newTicket')} />}<Field label="Buscar chamado" placeholder="Título, categoria ou protocolo" value={query} onChangeText={setQuery} /><Chips values={['Todos', ...Object.values(statusLabels)]} selected={filter} onSelect={setFilter} />{tickets.map(ticket => <TicketCard key={ticket.id} ticket={ticket} onPress={() => navigate('ticket', Number(ticket.id))} />)}{!tickets.length && <Empty title="Nenhum chamado encontrado" description="Seus chamados aparecerão aqui. Você também pode ajustar os filtros." />}</Screen>;
}
export function TicketDetail({ id, navigate }: { id: number; navigate: Navigate }) {
  const app = useApp(), action = useAction(); const ticket = app.data?.tickets.find(t => Number(t.id) === id), user = app.data?.user;
  if (!ticket || !user || !app.data) return <Screen><Empty title="Chamado indisponível" description="Atualize a lista e tente novamente." /></Screen>;
  // O síndico responsável acompanha, cancela e entra na conversa dos chamados do seu condomínio.
  const syndic = user.tipo_usuario === 'sindico' && accessOf(app.data).manager;
  return <Screen refresh><Label>{ticket.protocolo}</Label><Title>{ticket.titulo}</Title><View style={{ alignSelf: 'flex-start' }}><TicketBadge status={ticket.status} /></View><Card><Text style={s.cardTitle}>O que precisa de atenção</Text><Body>{ticket.descricao}</Body><View style={t.divider} />{[['Categoria', ticket.categoria], ['Prioridade', ticket.prioridade], ['Local', ticket.local_problema], ['Data desejada', displayDate(ticket.data_servico)], ['Abertura', displayDate(ticket.criado_em, true)]].map(([label, value]) => <View key={label} style={t.detail}><Text style={t.detailLabel}>{label}</Text><Text style={t.detailValue}>{value}</Text></View>)}</Card>
    {(Number(ticket.usuario_id) === Number(user.id) || syndic) && ['aberto', 'andamento'].includes(ticket.status) && <Button title="Cancelar chamado" secondary loading={action.busy} onPress={() => Alert.alert('Cancelar chamado?', 'O atendimento será encerrado como cancelado.', [{ text: 'Manter chamado', style: 'cancel' }, { text: 'Cancelar chamado', style: 'destructive', onPress: () => action.run(() => app.changeTicket(id, 'cancelado')) }])} />}
    {user.tipo_usuario === 'prestador' && ticket.status === 'aberto' && !ticket.prestador_id && <Button title="Aceitar atendimento" loading={action.busy} onPress={() => action.run(async () => { const chat = await app.acceptTicket(id); navigate('chat', chat); })} />}
    {user.tipo_usuario === 'prestador' && Number(ticket.prestador_id) === Number(user.id) && ticket.status === 'andamento' && <Button title="Concluir atendimento" loading={action.busy} onPress={() => Alert.alert('Concluir atendimento?', 'Confirme que o serviço foi finalizado.', [{ text: 'Voltar', style: 'cancel' }, { text: 'Concluir', onPress: () => action.run(() => app.changeTicket(id, 'concluido')) }])} />}
    {syndic && !!ticket.prestador_id && ['andamento', 'concluido'].includes(ticket.status) && <Button title="Entrar na conversa do chamado" secondary icon="chatbubbles-outline" loading={action.busy} onPress={() => action.run(async () => { const chat = await app.joinTicketChat(id); navigate('chat', chat); })} />}
    {user.tipo_usuario === 'admin' ? <Body style={{ fontSize: 12, textAlign: 'center' }}>A administração acompanha os chamados em modo de consulta.</Body> : <Button title="Ir para minhas conversas" secondary icon="chatbubble-ellipses-outline" onPress={() => navigate('chats')} />}
  </Screen>;
}
export function NewTicketScreen({ onDone }: { onDone: () => void }) {
  const app = useApp(), action = useAction();
  const [category, setCategory] = useState(categories[0]), [priority, setPriority] = useState('Média'), [title, setTitle] = useState(''), [description, setDescription] = useState(''), [place, setPlace] = useState(''), [date, setDate] = useState(new Date());
  return <Screen><Label>Vamos cuidar disso</Label><Title>Novo chamado</Title><Body>Conte o que aconteceu. Quanto mais detalhes, melhor podemos ajudar.</Body><Card><Label>Categoria</Label><Chips values={categories} selected={category} onSelect={setCategory} /><Field label="Título do problema" value={title} onChangeText={setTitle} maxLength={190} placeholder="Ex.: Lâmpada do corredor piscando" /><Field label="Descrição" value={description} onChangeText={setDescription} multiline maxLength={10000} placeholder="Descreva o que precisa de atenção..." /><Field label="Local do problema" value={place} onChangeText={setPlace} maxLength={255} placeholder="Ex.: Bloco A, 2º andar" /><Label>Prioridade</Label><Chips values={priorities} selected={priority} onSelect={setPriority} /><DateField label="Data desejada" value={date} onChange={setDate} minimumDate={new Date()} /><Button title="Enviar chamado" icon="arrow-forward" loading={action.busy} onPress={() => action.run(async () => {
    const input = { titulo: title.trim(), descricao: description.trim(), local_problema: place.trim(), categoria: category, prioridade: priority, data_servico: localDate(date) };
    const error = validateTicket(input); if (error) throw new Error(error);
    await app.createTicket(input); Alert.alert('Chamado enviado', 'Você pode acompanhar o atendimento na lista de chamados.'); onDone();
  })} /></Card></Screen>;
}
const t = StyleSheet.create({
  badge: { flexDirection: 'row', alignItems: 'center', alignSelf: 'flex-start', borderRadius: 8, paddingHorizontal: 9, paddingVertical: 6, gap: 5 },
  dot: { width: 5, height: 5, borderRadius: 3 }, badgeText: { fontSize: 11, fontWeight: '700' },
  category: { fontSize: 11, color: colors.primary, fontWeight: '700' }, cardFooter: { flexDirection: 'row', gap: 10, justifyContent: 'space-between', borderTopWidth: 1, borderTopColor: '#F0F3F8', paddingTop: 12 },
  small: { fontSize: 10, color: colors.muted, flexShrink: 1 },
  banner: { flexDirection: 'row', gap: 8, alignItems: 'center', padding: 24, backgroundColor: colors.navy, borderRadius: 22 },
  bannerLabel: { color: '#ABC7FA', fontSize: 8, fontWeight: '700', letterSpacing: 1 }, bannerTitle: { color: 'white', fontSize: 22, lineHeight: 28, fontWeight: '700', letterSpacing: -.5 }, bannerBody: { color: '#B7C7DB', fontSize: 12, lineHeight: 18 },
  bannerIcon: { width: 70, height: 70, borderRadius: 35, borderWidth: 1, borderColor: '#FFFFFF26', alignItems: 'center', justifyContent: 'center', backgroundColor: '#FFFFFF08' },
  stats: { flexDirection: 'row', gap: 12 }, stat: { flex: 1, borderWidth: 1, borderColor: colors.line, borderRadius: 18, backgroundColor: 'white', padding: 18, gap: 10 },
  statIcon: { width: 33, height: 33, borderRadius: 10, alignItems: 'center', justifyContent: 'center' }, statValue: { fontSize: 34, fontWeight: '700', color: colors.navy, letterSpacing: -1 }, statLabel: { color: colors.muted, fontSize: 12, fontWeight: '500' },
  quickGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: 12 }, quick: { flexBasis: '45%', flexGrow: 1, backgroundColor: 'white', borderWidth: 1, borderColor: colors.line, borderRadius: 18, padding: 17, gap: 9 }, quickTitle: { color: colors.navy, fontSize: 15, fontWeight: '700' }, quickSubtitle: { color: colors.muted, fontSize: 11 },
  link: { color: colors.primary, fontSize: 12, fontWeight: '600' }, divider: { height: 1, backgroundColor: colors.line, marginVertical: 4 },
  detail: { flexDirection: 'row', alignItems: 'flex-start', gap: 18, paddingVertical: 5 }, detailLabel: { color: colors.muted, fontSize: 13, width: 100 }, detailValue: { color: colors.navy, fontSize: 14, flex: 1, textAlign: 'right' },
});
