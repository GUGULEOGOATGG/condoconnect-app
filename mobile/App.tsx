import React, { useEffect, useState } from 'react';
import { ActivityIndicator, AppState, BackHandler, Pressable, StyleSheet, Text, View } from 'react-native';
import { SafeAreaProvider, SafeAreaView } from 'react-native-safe-area-context';
import { StatusBar } from 'expo-status-bar';
import { AppProvider, useApp } from './src/AppContext';
import { AuthScreens } from './src/AuthScreens';
import { HomeScreen, NewTicketScreen, TicketDetail, TicketsScreen } from './src/TicketScreens';
import { ChatsScreen, ChatScreen } from './src/ChatScreens';
import { CommunityScreen, EditProfileScreen, NewReservationScreen, NoticeScreen, NoticesScreen, NotificationsScreen, ProfileScreen, ProvidersScreen, ReservationsScreen } from './src/CommunityScreens';
import { AdminUsersScreen, AdminVerificationsScreen, EditCommunityScreen, NoticeEditorScreen, RoleBadge } from './src/RoleScreens';
import type { Navigate, Route, ScreenName } from './src/navigation';
import { colors, Icon, IconButton } from './src/ui';
import type { IconName } from './src/ui';

const rootScreens = ['home', 'tickets', 'chats', 'profile', 'adminUsers'];
const titles: Record<ScreenName, string> = { home: 'Meu espaço', tickets: 'Chamados', ticket: 'Detalhes do chamado', newTicket: 'Novo chamado', chats: 'Conversas', chat: 'Conversa', profile: 'Meu perfil', editProfile: 'Editar perfil', notices: 'Comunicados', notice: 'Comunicado', notifications: 'Notificações', reservations: 'Reservas', newReservation: 'Nova reserva', providers: 'Prestadores', community: 'Condomínio', noticeEditor: 'Comunicado', editCommunity: 'Editar condomínio', adminUsers: 'Usuários', adminVerifications: 'Verificação de síndicos' };
export default function App() { return <SafeAreaProvider><AppProvider><Application /></AppProvider></SafeAreaProvider>; }
function Application() {
  const app = useApp(); const [stack, setStack] = useState<Route[]>([{ name: 'home' }]);
  const route = stack[stack.length - 1], isRoot = rootScreens.includes(route.name);
  const back = () => setStack(current => current.length > 1 ? current.slice(0, -1) : [{ name: 'home' }]);
  const navigate: Navigate = (name, id) => setStack(current => rootScreens.includes(name) ? [{ name }] : [...current, { name, id }]);
  useEffect(() => { setStack([{ name: 'home' }]); }, [app.mode]);
  useEffect(() => {
    const handler = BackHandler.addEventListener('hardwareBackPress', () => {
      if (!app.mode || (stack.length === 1 && route.name === 'home')) return false;
      back(); return true;
    });
    return () => handler.remove();
  }, [stack, app.mode]);
  useEffect(() => { if (app.mode === 'live' && isRoot) app.refresh(); }, [route.name, app.mode]);
  useEffect(() => {
    const subscription = AppState.addEventListener('change', state => { if (state === 'active' && app.mode === 'live') app.refresh(); });
    return () => subscription.remove();
  }, [app.mode]);
  const unreadNotifications = app.data?.notifications.filter(n => !Number(n.lida)).length || 0;
  const unreadChats = app.data?.chats.reduce((sum, chat) => sum + Number(chat.nao_lidas), 0) || 0;
  const chat = app.data?.chats.find(c => Number(c.id) === route.id);
  // As abas acompanham o nível de acesso da conta autenticada.
  const role = app.data?.user.tipo_usuario;
  type Tab = { name: ScreenName; label: string; icon: IconName; activeIcon: IconName };
  const tickets: Tab = { name: 'tickets', label: role === 'prestador' ? 'Atendimentos' : 'Chamados', icon: 'ticket-outline', activeIcon: 'ticket' };
  const chats: Tab = { name: 'chats', label: 'Conversas', icon: 'chatbubbles-outline', activeIcon: 'chatbubbles' }, profile: Tab = { name: 'profile', label: 'Perfil', icon: 'person-outline', activeIcon: 'person' };
  const tabs: Tab[] = role === 'admin'
    ? [{ name: 'home', label: 'Painel', icon: 'stats-chart-outline', activeIcon: 'stats-chart' }, tickets, { name: 'adminUsers', label: 'Usuários', icon: 'people-outline', activeIcon: 'people' }, chats, profile]
    : [{ name: 'home', label: 'Início', icon: 'home-outline', activeIcon: 'home' }, tickets, chats, profile];
  return <SafeAreaView style={a.safe} edges={['top', 'bottom']}><StatusBar style="dark" />
    {app.starting ? <View style={a.loading}><Icon name="business-outline" size={48} /><Text style={a.loadingText}>CondoConnect.</Text><ActivityIndicator color={colors.primary} /></View> : !app.mode || !app.data ? <AuthScreens /> : <>
      <View style={a.header}>{isRoot ? <View style={a.brand}><View style={a.brandIcon}><Icon name="business-outline" color="white" size={22} /></View><View style={{ flex: 1 }}><Text style={a.brandTitle} numberOfLines={1}>CondoConnect<Text style={{ color: colors.primary }}>.</Text></Text><View style={a.identity}><RoleBadge role={app.data.user.tipo_usuario} /><Text style={[a.community, { flex: 1 }]} numberOfLines={1}>{app.data.community?.nome || app.data.user.condominio}</Text></View></View></View> : <View style={a.brand}><IconButton name="arrow-back" label="Voltar" onPress={back} /><Text style={a.pageTitle} numberOfLines={1}>{route.name === 'chat' ? chat?.contato_nome || 'Conversa' : titles[route.name]}</Text></View>}{route.name !== 'chat' && <IconButton name="notifications-outline" label={`${unreadNotifications} notificações não lidas`} badge={unreadNotifications} onPress={() => navigate('notifications')} />}</View>
      {app.mode === 'demo' && route.name !== 'chat' && <View style={a.demo}><Icon name="sparkles-outline" size={12} color="#385A9B" /><Text style={a.demoText}>Demonstração · dados fictícios</Text></View>}
      <View style={{ flex: 1, backgroundColor: colors.bg }}>
        {route.name === 'home' && <HomeScreen navigate={navigate} />}
        {route.name === 'tickets' && <TicketsScreen navigate={navigate} />}
        {route.name === 'ticket' && <TicketDetail id={route.id || 0} navigate={navigate} />}
        {route.name === 'newTicket' && <NewTicketScreen onDone={() => navigate('tickets')} />}
        {route.name === 'chats' && <ChatsScreen navigate={navigate} />}
        {route.name === 'chat' && <ChatScreen key={route.id} id={route.id || 0} />}
        {route.name === 'notices' && <NoticesScreen navigate={navigate} />}
        {route.name === 'notice' && <NoticeScreen id={route.id || 0} navigate={navigate} onDone={back} />}
        {route.name === 'notifications' && <NotificationsScreen navigate={navigate} />}
        {route.name === 'reservations' && <ReservationsScreen navigate={navigate} />}
        {route.name === 'newReservation' && <NewReservationScreen id={route.id} onDone={() => { back(); app.refresh(); }} />}
        {route.name === 'providers' && <ProvidersScreen navigate={navigate} />}
        {route.name === 'profile' && <ProfileScreen navigate={navigate} />}
        {route.name === 'editProfile' && <EditProfileScreen onDone={back} />}
        {route.name === 'community' && <CommunityScreen navigate={navigate} />}
        {route.name === 'noticeEditor' && <NoticeEditorScreen key={route.id || 0} id={route.id} onDone={back} />}
        {route.name === 'editCommunity' && <EditCommunityScreen onDone={back} />}
        {route.name === 'adminUsers' && <AdminUsersScreen />}
        {route.name === 'adminVerifications' && <AdminVerificationsScreen />}
      </View>
      {isRoot && <View style={a.tabs}>{tabs.map(tab => <Pressable accessibilityRole="tab" accessibilityState={{ selected: route.name === tab.name }} accessibilityLabel={tab.label} key={tab.name} onPress={() => navigate(tab.name)} style={[a.tab, route.name === tab.name && { backgroundColor: colors.blue }]}><Icon name={route.name === tab.name ? tab.activeIcon : tab.icon} color={route.name === tab.name ? colors.primary : '#78859A'} size={22} /><Text style={[a.tabText, route.name === tab.name && { color: colors.primary }]}>{tab.label}</Text>{tab.name === 'chats' && unreadChats > 0 && <View style={a.chatBadge}><Text style={{ color: 'white', fontSize: 9, fontWeight: '700' }}>{unreadChats > 9 ? '9+' : unreadChats}</Text></View>}</Pressable>)}</View>}
    </>}
    {!!app.error && !app.starting && <Pressable accessibilityRole="button" accessibilityLabel="Fechar aviso" onPress={app.clearError} style={a.error}><Text style={a.errorText}>{app.error}</Text><Icon name="close" color="#92400E" size={18} /></Pressable>}
  </SafeAreaView>;
}
const a = StyleSheet.create({
  identity: { flexDirection: 'row', alignItems: 'center', gap: 8, marginTop: 2 },
  safe: { flex: 1, backgroundColor: 'white' }, header: { minHeight: 76, paddingHorizontal: 14, paddingVertical: 10, flexDirection: 'row', alignItems: 'center', gap: 8, backgroundColor: 'white', borderBottomWidth: 1, borderBottomColor: '#EDF0F6' },
  brand: { flex: 1, flexDirection: 'row', alignItems: 'center', gap: 10 }, brandIcon: { backgroundColor: colors.primary, borderRadius: 12, width: 40, height: 40, alignItems: 'center', justifyContent: 'center' }, brandTitle: { fontSize: 19, color: colors.navy, fontWeight: '800', letterSpacing: -.8 }, community: { color: colors.muted, fontSize: 10, marginTop: 3 }, pageTitle: { flex: 1, fontSize: 16, fontWeight: '700', color: colors.navy },
  demo: { flexDirection: 'row', justifyContent: 'center', alignItems: 'center', gap: 6, backgroundColor: '#EAF0FA', padding: 6 }, demoText: { color: '#385A9B', fontSize: 10, fontWeight: '500' },
  tabs: { flexDirection: 'row', paddingVertical: 8, paddingHorizontal: 12, gap: 6, borderTopWidth: 1, borderTopColor: colors.line, backgroundColor: 'white' }, tab: { flex: 1, minHeight: 58, alignItems: 'center', justifyContent: 'center', borderRadius: 14, gap: 4 }, tabText: { color: '#78859A', fontSize: 10, fontWeight: '600' }, chatBadge: { position: 'absolute', top: 3, right: 10, minWidth: 17, borderRadius: 10, padding: 3, backgroundColor: colors.primary, alignItems: 'center', borderWidth: 1, borderColor: 'white' },
  loading: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: 20, backgroundColor: colors.bg }, loadingText: { fontSize: 26, color: colors.navy, fontWeight: '800' },
  error: { position: 'absolute', left: 16, right: 16, bottom: 100, backgroundColor: '#FFF4DA', padding: 16, borderRadius: 14, borderColor: '#F4D997', borderWidth: 1, flexDirection: 'row', gap: 10, alignItems: 'center' }, errorText: { flex: 1, color: '#92400E', fontSize: 12, lineHeight: 18 },
});
