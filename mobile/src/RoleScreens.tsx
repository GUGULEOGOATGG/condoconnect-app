import React, { useEffect, useState } from 'react';
import { Alert, Linking, Pressable, StyleSheet, Text, View } from 'react-native';
import { useApp } from './AppContext';
import { accessOf, displayDate, noticeStatusLabels, roleLabels, validateNotice } from './domain';
import type { Navigate } from './navigation';
import type { AdminOverview, AdminUser, NoticeStatus, Role } from './types';
import { Body, Button, Card, Chips, colors, Empty, Field, Icon, Label, s, Screen, Title, useAction } from './ui';
import type { IconName } from './ui';

// Telas de gestão (síndico responsável e administração) e de administração da plataforma.
// Esconder uma tela não é a proteção: o servidor confere o nível de acesso em cada operação.

export const roleTones: Record<Role, { bg: string; fg: string }> = {
  morador: { bg: '#EFF6FF', fg: '#2563EB' }, prestador: { bg: '#E8F8F0', fg: '#047857' },
  sindico: { bg: '#F1EAFE', fg: '#6D28D9' }, admin: { bg: '#FFF4DA', fg: '#B45309' },
};
export function RoleBadge({ role }: { role: Role }) {
  const tone = roleTones[role] || roleTones.morador;
  return <View style={[r.badge, { backgroundColor: tone.bg }]}><View style={[r.dot, { backgroundColor: tone.fg }]} /><Text style={[r.badgeText, { color: tone.fg }]}>{roleLabels[role] || role}</Text></View>;
}
export function Pill({ label, tone = 'neutral' }: { label: string; tone?: 'neutral' | 'good' | 'warn' | 'bad' | 'info' }) {
  const tones = { neutral: ['#F0F2F6', '#64748B'], good: ['#E8F8F0', '#047857'], warn: ['#FFF6DE', '#936200'], bad: ['#FEF3F2', '#B42318'], info: ['#E8F0FF', '#1D4ED8'] };
  return <View style={[r.badge, { backgroundColor: tones[tone][0] }]}><Text style={[r.badgeText, { color: tones[tone][1] }]}>{label}</Text></View>;
}
export function Restricted() {
  return <Screen><Empty icon="shield-checkmark-outline" title="Acesso restrito" description="Seu nível de acesso não inclui esta área." /></Screen>;
}
/** Só a administração escolhe o condomínio; as demais contas ficam no seu. */
export function CondominioPicker() {
  const app = useApp(), action = useAction();
  if (!app.data) return null;
  const access = accessOf(app.data);
  if (access.role !== 'admin' || access.condominios.length < 2) return null;
  const current = access.condominios.find(c => Number(c.id) === access.condominio_id);
  return <View style={{ gap: 8, opacity: action.busy ? .6 : 1 }}><Label>Condomínio em gestão</Label><Chips values={access.condominios.map(c => c.nome)} selected={current?.nome || ''} onSelect={name => action.run(() => app.selectCondominio(Number(access.condominios.find(c => c.nome === name)?.id || 0)))} /></View>;
}

export function NoticeEditorScreen({ id, onDone }: { id?: number; onDone: () => void }) {
  const app = useApp(), action = useAction();
  const current = id ? app.data?.notices.find(n => Number(n.id) === id) : undefined;
  const [title, setTitle] = useState(current?.titulo || ''), [content, setContent] = useState(current?.conteudo || ''), [status, setStatus] = useState<NoticeStatus>(current?.status || 'publicado');
  if (!app.data || !accessOf(app.data).manager) return <Restricted />;
  if (id && !current) return <Screen><Empty icon="megaphone-outline" title="Comunicado indisponível" description="Volte à lista e atualize os comunicados." /></Screen>;
  const labels = Object.values(noticeStatusLabels);
  return <Screen><Label>Gestão do condomínio</Label><Title>{current ? 'Editar comunicado' : 'Novo comunicado'}</Title><Body>Os moradores de {app.data.community?.nome || 'seu condomínio'} são notificados ao publicar.</Body><Card>
    <Field label="Título" value={title} onChangeText={setTitle} maxLength={190} placeholder="Ex.: Manutenção dos elevadores" />
    <Field label="Conteúdo" value={content} onChangeText={setContent} multiline maxLength={15000} placeholder="Escreva o aviso para os moradores..." />
    <Label>Situação</Label><Chips values={labels} selected={noticeStatusLabels[status]} onSelect={label => setStatus((Object.keys(noticeStatusLabels) as NoticeStatus[]).find(key => noticeStatusLabels[key] === label) || 'publicado')} />
    <Button title={current ? 'Salvar alterações' : 'Publicar comunicado'} icon="megaphone-outline" loading={action.busy} onPress={() => action.run(async () => {
      const input = { id, titulo: title, conteudo: content, status };
      const error = validateNotice(input); if (error) throw new Error(error);
      await app.saveNotice(input); Alert.alert('Comunicado salvo', status === 'publicado' ? 'Os moradores já podem ler o aviso.' : 'O comunicado não está visível para os moradores.'); onDone();
    })} /></Card></Screen>;
}

export function EditCommunityScreen({ onDone }: { onDone: () => void }) {
  const app = useApp(), action = useAction(), community = app.data?.community;
  const [about, setAbout] = useState(community?.descricao || ''), [rules, setRules] = useState(community?.regras || ''), [contact, setContact] = useState(community?.contato || '');
  if (!app.data || !community || !accessOf(app.data).manager) return <Restricted />;
  return <Screen><Label>Gestão do condomínio</Label><Title>{community.nome}</Title><Body>Essas informações aparecem para todos os moradores.</Body><Card>
    <Field label="Apresentação" value={about} onChangeText={setAbout} multiline maxLength={15000} />
    <Field label="Regras de convivência" value={rules} onChangeText={setRules} multiline maxLength={15000} />
    <Field label="Contato da administração" value={contact} onChangeText={setContact} maxLength={190} placeholder="Telefone, e-mail ou horário de atendimento" />
    <Button title="Salvar informações" icon="checkmark-circle-outline" loading={action.busy} onPress={() => action.run(async () => { await app.saveCommunity({ descricao: about, regras: rules, contato: contact }); onDone(); })} /></Card></Screen>;
}

function useLoader<T>(load: () => Promise<T>, dependencies: unknown[]) {
  const [value, setValue] = useState<T | null>(null), [error, setError] = useState(''), [attempt, setAttempt] = useState(0);
  useEffect(() => {
    let active = true;
    load().then(result => { if (active) { setValue(result); setError(''); } }).catch(failure => { if (active) setError((failure as Error).message); });
    return () => { active = false; };
  }, [...dependencies, attempt]);
  return { value, error, retry: () => setAttempt(current => current + 1) };
}
function Tile({ title, subtitle, icon, onPress, badge }: { title: string; subtitle: string; icon: IconName; onPress: () => void; badge?: number }) {
  return <Pressable accessibilityRole="button" onPress={onPress} style={({ pressed }) => [r.tile, pressed && { backgroundColor: colors.blue }]}><View style={s.iconTile}><Icon name={icon} /></View><Text style={r.tileTitle}>{title}</Text><Text style={r.tileSubtitle}>{subtitle}</Text>{badge ? <View style={r.counter}><Text style={r.counterText}>{badge > 9 ? '9+' : badge}</Text></View> : null}</Pressable>;
}
export function TileGrid({ tiles }: { tiles: { title: string; subtitle: string; icon: IconName; onPress: () => void; badge?: number }[] }) {
  return <View style={r.grid}>{tiles.map(tile => <Tile key={tile.title} {...tile} />)}</View>;
}

/** Conteúdo do painel do administrador, exibido na aba inicial. */
export function AdminHome({ navigate }: { navigate: Navigate }) {
  const app = useApp();
  const { value: overview, error, retry } = useLoader<AdminOverview>(() => app.adminOverview(), [app.data]);
  if (app.data && accessOf(app.data).legacy) return <Card style={r.warn}><Text style={s.cardTitle}>Atualização necessária</Text><Body>O servidor ainda usa a versão anterior da API do aplicativo. Atualize o arquivo api/mobile.php para liberar o painel administrativo.</Body></Card>;
  if (!overview) return error ? <Card><Body>{error}</Body><Button title="Tentar novamente" secondary onPress={retry} /></Card> : <Body>Carregando indicadores...</Body>;
  const totals = overview.totals, pending = overview.pending.length;
  const stats: [keyof AdminOverview['totals'], string, IconName][] = [['total_usuarios', 'Usuários', 'people-outline'], ['moradores', 'Moradores', 'person-outline'], ['profissionais', 'Prestadores', 'construct-outline'], ['sindicos', 'Síndicos', 'shield-checkmark-outline'], ['condominios', 'Condomínios', 'business-outline'], ['chamados_abertos', 'Chamados abertos', 'time-outline'], ['chamados_concluidos', 'Serviços concluídos', 'checkmark-done-outline'], ['reservas', 'Reservas', 'calendar-outline']];
  return <>
    {pending > 0 && <Pressable accessibilityRole="button" onPress={() => navigate('adminVerifications')}><Card style={r.warn}><View style={[s.row, { justifyContent: 'space-between' }]}><Text style={[s.cardTitle, { flex: 1 }]}>{pending === 1 ? '1 verificação de síndico pendente' : `${pending} verificações de síndico pendentes`}</Text><Icon name="chevron-forward" size={18} color={colors.muted} /></View><Body>Aprove ou rejeite os certificados enviados.</Body></Card></Pressable>}
    <View style={r.grid}>{stats.map(([key, label, icon]) => <View key={key} style={r.stat}><View style={r.statIcon}><Icon name={icon} size={18} /></View><Text style={r.statValue}>{String(Number(totals[key]) || 0).padStart(2, '0')}</Text><Text style={r.statLabel}>{label}</Text></View>)}</View>
    <Text style={s.cardTitle}>Ações administrativas</Text>
    <TileGrid tiles={[{ title: 'Usuários', subtitle: 'Contas e níveis', icon: 'people-outline', onPress: () => navigate('adminUsers') }, { title: 'Verificações', subtitle: 'Certificados de síndico', icon: 'shield-checkmark-outline', onPress: () => navigate('adminVerifications'), badge: pending },
      { title: 'Comunicados', subtitle: 'Publicar e gerenciar', icon: 'megaphone-outline', onPress: () => navigate('notices') }, { title: 'Chamados', subtitle: 'Todos os condomínios', icon: 'construct-outline', onPress: () => navigate('tickets') }]} />
    <Card><View style={[s.row, { justifyContent: 'space-between', flexWrap: 'wrap' }]}><Text style={s.cardTitle}>Operação por condomínio</Text><Pill tone="info" label={`${Number(totals.comunicados) || 0} comunicados`} /></View>
      <View style={r.tableRow}><Text style={[r.cell, r.head, { flex: 1 }]}>Condomínio</Text><Text style={[r.cell, r.head, r.number]}>Pessoas</Text><Text style={[r.cell, r.head, r.number]}>Ativos</Text></View>
      {overview.by_condominio.map(row => <View key={row.id} style={r.tableRow}><Text style={[r.cell, { flex: 1 }]}>{row.nome}</Text><Text style={[r.cell, r.number]}>{Number(row.usuarios)}</Text><Text style={[r.cell, r.number]}>{Number(row.chamados)}</Text></View>)}</Card>
    <Card><Text style={s.cardTitle}>Atividades recentes</Text>{overview.activity.length ? overview.activity.map(event => <View key={event.id} style={r.event}><Text style={r.eventTitle}>{event.observacao || event.status_novo}</Text><Text style={r.eventMeta}>{event.titulo} · {event.usuario_nome || 'Sistema'} · {displayDate(event.criado_em, true)}</Text></View>) : <Body>Nenhuma atividade registrada.</Body>}</Card>
  </>;
}

export function AdminUsersScreen() {
  const app = useApp();
  const [query, setQuery] = useState(''), [search, setSearch] = useState(''), [level, setLevel] = useState('Todos');
  useEffect(() => { const timer = setTimeout(() => setSearch(query.trim()), 350); return () => clearTimeout(timer); }, [query]);
  const role = (Object.keys(roleLabels) as Role[]).find(key => roleLabels[key] === level);
  // O servidor filtra por nome, e-mail, tipo ou status; o nível escolhido é aplicado aqui também.
  const { value, error, retry } = useLoader<AdminUser[]>(() => app.adminUsers(search || role || ''), [search, role]);
  if (!app.data || app.data.user.tipo_usuario !== 'admin') return <Restricted />;
  const users = (value || []).filter(user => !role || user.tipo_usuario === role);
  return <Screen><Label>Administração</Label><Title>Usuários</Title><Body>Consulte contas, níveis de acesso e situação de verificação.</Body>
    <Field label="Buscar por nome ou e-mail" value={query} onChangeText={setQuery} autoCapitalize="none" placeholder="Ex.: Maria ou maria@email.com" maxLength={190} />
    <Chips values={['Todos', ...Object.values(roleLabels)]} selected={level} onSelect={setLevel} />
    {error ? <Card><Body>{error}</Body><Button title="Tentar novamente" secondary onPress={retry} /></Card> : !value ? <Body>Buscando contas...</Body> : <Body style={{ fontSize: 12 }}>{users.length === 1 ? '1 conta' : `${users.length} contas`}</Body>}
    {!error && users.map(user => <Card key={user.id}><View style={s.row}><View style={r.avatar}><Text style={r.initial}>{user.nome.charAt(0).toUpperCase()}</Text></View><View style={{ flex: 1, gap: 3 }}><Text style={s.cardTitle}>{user.nome}</Text><Text style={r.eventMeta}>{user.email}</Text></View></View>
      <View style={r.tags}><RoleBadge role={user.tipo_usuario} /><Pill tone={user.status === 'ativo' ? 'good' : 'bad'} label={user.status === 'ativo' ? 'Ativa' : 'Bloqueada'} /><Pill tone={Number(user.email_verificado) === 1 ? 'good' : 'warn'} label={Number(user.email_verificado) === 1 ? 'E-mail confirmado' : 'E-mail pendente'} />{user.tipo_usuario === 'sindico' && <Pill tone={user.certificado_status === 'aprovado' ? 'good' : user.certificado_status === 'rejeitado' ? 'bad' : 'warn'} label={`Certificado ${user.certificado_status}`} />}</View></Card>)}
    {!error && !!value && !users.length && <Empty icon="people-outline" title="Nenhuma conta encontrada" description="Ajuste a busca ou o nível selecionado." />}
  </Screen>;
}

export function AdminVerificationsScreen() {
  const app = useApp(), action = useAction();
  const { value: overview, error, retry } = useLoader<AdminOverview>(() => app.adminOverview(), [app.data]);
  if (!app.data || app.data.user.tipo_usuario !== 'admin') return <Restricted />;
  const decide = (id: number, name: string, building: string, status: 'aprovado' | 'rejeitado') => Alert.alert(
    status === 'aprovado' ? 'Aprovar este síndico?' : 'Rejeitar este cadastro?',
    status === 'aprovado' ? `${name} passará a gerir o condomínio ${building}.` : `${name} não terá acesso à gestão do condomínio.`,
    [{ text: 'Voltar', style: 'cancel' }, { text: status === 'aprovado' ? 'Aprovar' : 'Rejeitar', style: status === 'aprovado' ? 'default' : 'destructive', onPress: () => action.run(() => app.verifySyndic(id, status)) }]);
  return <Screen refresh><Label>Administração</Label><Title>Verificação de síndicos</Title><Body>Aprove ou rejeite os certificados enviados para ativar a gestão do condomínio.</Body>
    <Button title="Ver certificados no site" secondary icon="open-outline" onPress={() => Linking.openURL(`${app.serverUrl}/pages/admin-verificacoes.php`).catch(() => Alert.alert('Não foi possível abrir o site.'))} />
    {error ? <Card><Body>{error}</Body><Button title="Tentar novamente" secondary onPress={retry} /></Card> : !overview ? <Body>Carregando verificações...</Body> : overview.pending.map(item => <Card key={item.id}>
      <View style={s.row}><View style={r.avatar}><Text style={r.initial}>{item.nome.charAt(0).toUpperCase()}</Text></View><View style={{ flex: 1, gap: 3 }}><Text style={s.cardTitle}>{item.nome}</Text><Text style={r.eventMeta}>{item.email}</Text></View></View>
      {[['Condomínio', item.condominio || '—'], ['Unidade', item.unidade || '—'], ['Certificado', Number(item.tem_certificado) ? 'Enviado (consulte no site)' : 'Não enviado'], ['Cadastro', displayDate(item.criado_em, true)]].map(([label, text]) => <View key={label} style={r.detail}><Text style={r.detailLabel}>{label}</Text><Text style={r.detailValue}>{text}</Text></View>)}
      <View style={[s.row, { gap: 10 }]}><View style={{ flex: 1 }}><Button title="Rejeitar" secondary loading={action.busy} onPress={() => decide(Number(item.id), item.nome, item.condominio, 'rejeitado')} /></View><View style={{ flex: 1 }}><Button title="Aprovar" icon="checkmark" loading={action.busy} onPress={() => decide(Number(item.id), item.nome, item.condominio, 'aprovado')} /></View></View>
    </Card>)}
    {!error && !!overview && !overview.pending.length && <Empty icon="shield-checkmark-outline" title="Nenhuma verificação pendente" description="Novos cadastros de síndico aparecerão aqui." />}
  </Screen>;
}

const r = StyleSheet.create({
  badge: { flexDirection: 'row', alignItems: 'center', alignSelf: 'flex-start', gap: 5, borderRadius: 999, paddingHorizontal: 10, paddingVertical: 4 },
  dot: { width: 6, height: 6, borderRadius: 3 }, badgeText: { fontSize: 11, fontWeight: '700' },
  warn: { borderColor: '#F5D77E', backgroundColor: '#FFF9E8' },
  grid: { flexDirection: 'row', flexWrap: 'wrap', gap: 12 },
  tile: { flexBasis: '45%', flexGrow: 1, backgroundColor: 'white', borderWidth: 1, borderColor: colors.line, borderRadius: 18, padding: 17, gap: 9 },
  tileTitle: { color: colors.navy, fontSize: 15, fontWeight: '700' }, tileSubtitle: { color: colors.muted, fontSize: 11 },
  counter: { position: 'absolute', top: 12, right: 12, minWidth: 20, borderRadius: 10, paddingHorizontal: 5, backgroundColor: '#DC2626' }, counterText: { color: 'white', fontSize: 11, fontWeight: '700', textAlign: 'center' },
  stat: { flexBasis: '45%', flexGrow: 1, borderWidth: 1, borderColor: colors.line, borderRadius: 18, backgroundColor: 'white', padding: 16, gap: 8 },
  statIcon: { width: 33, height: 33, borderRadius: 10, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.blue },
  statValue: { fontSize: 30, fontWeight: '700', color: colors.navy, letterSpacing: -1 }, statLabel: { color: colors.muted, fontSize: 12, fontWeight: '500' },
  tableRow: { flexDirection: 'row', gap: 10, paddingVertical: 8, borderBottomWidth: 1, borderBottomColor: '#F0F3F8' },
  cell: { color: colors.navy, fontSize: 13 }, head: { color: colors.muted, fontSize: 11, fontWeight: '700' }, number: { width: 64, textAlign: 'right' },
  event: { paddingLeft: 12, borderLeftWidth: 2, borderLeftColor: '#D6E5FF', gap: 2 }, eventTitle: { color: colors.navy, fontSize: 13, fontWeight: '700' }, eventMeta: { color: colors.muted, fontSize: 12 },
  avatar: { width: 46, height: 46, borderRadius: 23, backgroundColor: '#DCE9FF', alignItems: 'center', justifyContent: 'center' }, initial: { color: colors.primary, fontSize: 19, fontWeight: '700' },
  tags: { flexDirection: 'row', flexWrap: 'wrap', gap: 6 },
  detail: { flexDirection: 'row', alignItems: 'flex-start', gap: 18, paddingVertical: 4 }, detailLabel: { color: colors.muted, fontSize: 13, width: 100 }, detailValue: { color: colors.navy, fontSize: 14, flex: 1, textAlign: 'right' },
});
