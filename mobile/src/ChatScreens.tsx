import React, { useEffect, useRef, useState } from 'react';
import { ActivityIndicator, AppState, FlatList, KeyboardAvoidingView, Modal, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useApp } from './AppContext';
import { displayDate, roleLabels } from './domain';
import type { Navigate } from './navigation';
import type { Message } from './types';
import { Button, Card, colors, Empty, Icon, IconButton, Label, s, Screen, Title, useAction } from './ui';

export function ChatsScreen({ navigate }: { navigate: Navigate }) {
  const app = useApp(), action = useAction(), [contacts, setContacts] = useState(false);
  return <><Screen refresh><Label>Boas conversas, melhores soluções</Label><Title>Conversas</Title><Button title="Nova conversa" icon="add" onPress={() => setContacts(true)} />{app.data?.chats.map(chat => <Pressable accessibilityRole="button" key={chat.id} onPress={() => navigate('chat', Number(chat.id))}><Card style={{ padding: 18 }}><View style={s.row}><View style={c.avatar}><Text style={c.initial}>{chat.contato_nome.charAt(0)}</Text></View><View style={{ flex: 1, gap: 4 }}><Text style={{ color: colors.navy, fontSize: 15, fontWeight: '700' }}>{chat.contato_nome}</Text><Text style={{ color: colors.primary, fontSize: 11 }}>{roleLabels[chat.contato_tipo]}</Text><Text style={{ color: colors.muted, fontSize: 12, lineHeight: 18 }} numberOfLines={1}>{chat.ultimo_resumo || 'Comece uma boa conversa.'}</Text></View>{Number(chat.nao_lidas) > 0 ? <View style={c.unread}><Text style={{ color: 'white', fontSize: 11, fontWeight: '700' }}>{Number(chat.nao_lidas) > 99 ? '99+' : chat.nao_lidas}</Text></View> : <Icon name="chevron-forward" color={colors.muted} size={17} />}</View></Card></Pressable>)}{!app.data?.chats.length && <Empty icon="chatbubbles-outline" title="Uma boa conversa começa aqui" description="Toque em nova conversa e escolha um contato." />}</Screen><Modal visible={contacts} animationType="slide" onRequestClose={() => setContacts(false)}><SafeAreaView style={{ flex: 1, backgroundColor: colors.bg }}><View style={[s.row, { padding: 12, backgroundColor: 'white' }]}><IconButton name="close" label="Fechar contatos" onPress={() => setContacts(false)} /><Text style={s.cardTitle}>Nova conversa</Text></View><ScrollView contentContainerStyle={{ padding: 20, gap: 12 }}>{app.data?.contacts.map(person => <Button key={person.id} title={`${person.nome} · ${roleLabels[person.tipo_usuario]}`} secondary disabled={action.busy} onPress={() => action.run(async () => { const id = await app.openChat(Number(person.id)); setContacts(false); navigate('chat', id); })} />)}{!app.data?.contacts.length && <Empty icon="people-outline" title="Nenhum contato disponível" description="Os contatos liberados para sua conta aparecerão aqui." />}</ScrollView></SafeAreaView></Modal></>;
}
export function ChatScreen({ id }: { id: number }) {
  const app = useApp(), latest = useRef(app); latest.current = app;
  const action = useAction(), [messages, setMessages] = useState<Message[]>([]), [draft, setDraft] = useState(''), [error, setError] = useState(''), [loading, setLoading] = useState(true);
  const list = useRef<FlatList<Message>>(null), atBottom = useRef(true), active = useRef(true), fetching = useRef(false);
  const fetchMessages = async () => {
    if (fetching.current || (AppState.currentState && AppState.currentState !== 'active')) return;
    fetching.current = true;
    try { const rows = await latest.current.messages(id); if (active.current) { setMessages(rows); setError(''); } }
    catch (failure) { if (active.current) setError((failure as Error).message); }
    finally { fetching.current = false; if (active.current) setLoading(false); }
  };
  useEffect(() => {
    active.current = true; fetchMessages();
    const timer = setInterval(fetchMessages, 5000);
    const listener = AppState.addEventListener('change', state => { if (state === 'active') fetchMessages(); });
    return () => { active.current = false; clearInterval(timer); listener.remove(); };
  }, [id]);
  return <KeyboardAvoidingView style={{ flex: 1 }} behavior="height">
    {error && <Pressable onPress={fetchMessages} accessibilityRole="button" style={c.error}><Text style={{ color: colors.red, fontSize: 12 }}>{error} Toque para tentar novamente.</Text></Pressable>}
    {loading ? <ActivityIndicator style={{ flex: 1 }} color={colors.primary} /> : <FlatList ref={list} data={messages} keyExtractor={message => String(message.id)} contentContainerStyle={c.messages} showsVerticalScrollIndicator={false}
      keyboardShouldPersistTaps="handled" onScroll={event => { const e = event.nativeEvent; atBottom.current = e.contentOffset.y + e.layoutMeasurement.height >= e.contentSize.height - 80; }} scrollEventThrottle={100}
      onContentSizeChange={() => { if (atBottom.current) list.current?.scrollToEnd({ animated: false }); }}
      ListEmptyComponent={<View style={{ marginTop: 24 }}><Empty icon="chatbubble-outline" title="Diga um olá" description="Envie a primeira mensagem desta conversa." /></View>}
      renderItem={({ item }) => {
        const mine = Number(item.remetente_id) === Number(app.data?.user.id);
        return <View style={[c.message, mine ? c.mine : c.theirs]}><Text style={[c.sender, mine && { color: '#DBEAFE' }]}>{mine ? 'Você' : item.remetente_nome}</Text><Text style={[c.text, mine && { color: 'white' }]}>{item.mensagem}</Text><Text style={[c.time, mine && { color: '#DBEAFE' }]}>{displayDate(item.created_at, true)}{mine ? Number(item.lida_por_todos) ? ' · Lida' : ' · Enviada' : ''}</Text></View>;
      }} />}
    <View style={c.composer}><TextInput value={draft} onChangeText={setDraft} accessibilityLabel="Sua mensagem" placeholder="Escreva sua mensagem..." placeholderTextColor="#8492A6" maxLength={4000} multiline editable={!action.busy} style={c.input} /><Pressable accessibilityRole="button" accessibilityLabel="Enviar mensagem" accessibilityState={{ disabled: action.busy || !draft.trim(), busy: action.busy }} disabled={action.busy || !draft.trim()} onPress={() => action.run(async () => {
      await app.sendMessage(id, draft.trim()); setDraft(''); atBottom.current = true; await fetchMessages();
    })} style={({ pressed }) => [c.send, (pressed || action.busy || !draft.trim()) && { opacity: .55 }]}>{action.busy ? <ActivityIndicator color="white" /> : <Icon name="send" color="white" size={21} />}</Pressable></View>
  </KeyboardAvoidingView>;
}
const c = StyleSheet.create({
  avatar: { width: 50, height: 50, borderRadius: 25, backgroundColor: '#DCE9FF', alignItems: 'center', justifyContent: 'center' }, initial: { fontSize: 21, color: colors.primary, fontWeight: '700' },
  unread: { minWidth: 24, minHeight: 24, padding: 5, backgroundColor: colors.primary, borderRadius: 12, alignItems: 'center', justifyContent: 'center' },
  error: { padding: 12, margin: 12, borderRadius: 10, backgroundColor: '#FFF0EF' },
  messages: { padding: 18, gap: 12, flexGrow: 1 },
  message: { maxWidth: '87%', borderRadius: 18, padding: 14, gap: 6 }, mine: { alignSelf: 'flex-end', backgroundColor: colors.primary, borderBottomRightRadius: 4 }, theirs: { alignSelf: 'flex-start', backgroundColor: 'white', borderBottomLeftRadius: 4, borderWidth: 1, borderColor: colors.line },
  sender: { fontSize: 11, color: colors.primary, fontWeight: '700' }, text: { fontSize: 15, lineHeight: 23, color: colors.navy }, time: { fontSize: 9, color: colors.muted, textAlign: 'right' },
  composer: { flexDirection: 'row', alignItems: 'flex-end', gap: 10, padding: 14, backgroundColor: 'white', borderTopColor: colors.line, borderTopWidth: 1 }, input: { flex: 1, maxHeight: 120, minHeight: 50, padding: 14, fontSize: 16, color: colors.navy, backgroundColor: colors.bg, borderRadius: 14 }, send: { width: 50, height: 50, backgroundColor: colors.primary, borderRadius: 15, alignItems: 'center', justifyContent: 'center' },
});
