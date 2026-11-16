import React, { useRef, useState } from 'react';
import { ActivityIndicator, Alert, KeyboardAvoidingView, Pressable, RefreshControl, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import type { TextInputProps, ViewStyle } from 'react-native';
import Ionicons from '@expo/vector-icons/Ionicons';
import DateTimePicker from '@react-native-community/datetimepicker';
import { displayDate } from './domain';
import { useApp } from './AppContext';

export const colors = { bg: '#F4F7FC', white: '#FFFFFF', navy: '#0F2A47', primary: '#2563EB', muted: '#62738B', line: '#E2E8F0', blue: '#EFF6FF', green: '#047857', red: '#B42318' };
export type IconName = keyof typeof Ionicons.glyphMap;
export function Icon({ name, size = 22, color = colors.primary }: { name: IconName; size?: number; color?: string }) { return <Ionicons name={name} size={size} color={color} />; }
export function Label({ children }: { children: React.ReactNode }) { return <Text style={s.label}>{children}</Text>; }
export function Title({ children }: { children: React.ReactNode }) { return <Text style={s.title}>{children}</Text>; }
export function Body({ children, style }: { children: React.ReactNode; style?: any }) { return <Text style={[s.body, style]}>{children}</Text>; }
export function Card({ children, style }: { children: React.ReactNode; style?: ViewStyle }) { return <View style={[s.card, style]}>{children}</View>; }
export function Button({ title, onPress, icon, secondary, danger, loading, disabled }: { title: string; onPress: () => void; icon?: IconName; secondary?: boolean; danger?: boolean; loading?: boolean; disabled?: boolean }) {
  return <Pressable accessibilityRole="button" accessibilityState={{ disabled: disabled || loading, busy: loading }} disabled={disabled || loading} onPress={onPress}
    style={({ pressed }) => [s.button, secondary && s.secondary, danger && s.danger, (pressed || disabled || loading) && { opacity: .65 }]}>
    {loading ? <ActivityIndicator color={secondary ? colors.primary : 'white'} /> : icon ? <Icon name={icon} color={secondary ? colors.primary : 'white'} size={20} /> : null}
    <Text style={[s.buttonText, secondary && { color: colors.primary }]}>{title}</Text>
  </Pressable>;
}
export function IconButton({ name, onPress, label, badge }: { name: IconName; onPress: () => void; label: string; badge?: number }) {
  return <Pressable onPress={onPress} accessibilityRole="button" accessibilityLabel={label} style={({ pressed }) => [s.iconButton, pressed && { opacity: .6 }]}>
    <Icon name={name} color={colors.navy} />{badge ? <View style={s.counter}><Text style={s.counterText}>{badge > 9 ? '9+' : badge}</Text></View> : null}
  </Pressable>;
}
export function Field({ label, ...props }: TextInputProps & { label: string }) {
  return <View style={s.field}><Text style={s.fieldLabel}>{label}</Text><TextInput accessibilityLabel={label} placeholderTextColor="#8492A6" {...props} style={[s.input, props.multiline && { minHeight: 120, textAlignVertical: 'top' }, props.style]} /></View>;
}
export function PasswordField({ label = 'Senha', value, onChangeText, newPassword }: { label?: string; value: string; onChangeText: (v: string) => void; newPassword?: boolean }) {
  const [show, setShow] = useState(false);
  return <View style={s.field}><Text style={s.fieldLabel}>{label}</Text><View style={s.password}>
    <TextInput accessibilityLabel={label} value={value} onChangeText={onChangeText} secureTextEntry={!show} autoCapitalize="none" autoComplete={newPassword ? 'new-password' : 'current-password'} style={[s.input, { flex: 1, borderWidth: 0 }]} />
    <IconButton name={show ? 'eye-off-outline' : 'eye-outline'} label={show ? 'Ocultar senha' : 'Mostrar senha'} onPress={() => setShow(!show)} />
  </View></View>;
}
export function Chips({ values, selected, onSelect }: { values: string[]; selected: string; onSelect: (v: string) => void }) {
  return <View style={s.chips}>{values.map(value => <Pressable key={value} accessibilityRole="button" accessibilityState={{ selected: value === selected }} onPress={() => onSelect(value)} style={[s.chip, value === selected && s.chipSelected]}><Text style={[s.chipText, value === selected && { color: colors.primary }]}>{value}</Text></Pressable>)}</View>;
}
export function DateField({ label, value, onChange, time, minimumDate }: { label: string; value: Date; onChange: (v: Date) => void; time?: boolean; minimumDate?: Date }) {
  const [picker, setPicker] = useState<'date' | 'time' | null>(null);
  return <View style={s.field}><Text style={s.fieldLabel}>{label}</Text><Pressable accessibilityRole="button" accessibilityLabel={`${label}: ${displayDate(value.toISOString(), time)}`} onPress={() => setPicker('date')} style={[s.input, s.row]}><Icon name="calendar-outline" size={20} /><Text style={s.dateText}>{displayDate(value.toISOString(), time)}</Text><Icon name="chevron-down" size={16} /></Pressable>
    {picker && <DateTimePicker value={value} mode={picker} minimumDate={picker === 'date' ? minimumDate : undefined} onChange={(event, chosen) => {
      const previousMode = picker; setPicker(null);
      if (event.type !== 'set' || !chosen) return;
      onChange(chosen); if (time && previousMode === 'date') setTimeout(() => setPicker('time'), 100);
    }} />}
  </View>;
}
export function Screen({ children, refresh = false }: { children: React.ReactNode; refresh?: boolean }) {
  const app = useApp();
  return <KeyboardAvoidingView style={{ flex: 1 }} behavior="height"><ScrollView contentContainerStyle={s.screen} keyboardShouldPersistTaps="handled" showsVerticalScrollIndicator={false}
    refreshControl={refresh ? <RefreshControl refreshing={app.refreshing} onRefresh={app.refresh} tintColor={colors.primary} colors={[colors.primary]} /> : undefined}>{children}</ScrollView></KeyboardAvoidingView>;
}
export function Empty({ icon = 'file-tray-outline', title, description }: { icon?: IconName; title: string; description: string }) {
  return <Card style={{ alignItems: 'center', gap: 12, paddingVertical: 32 }}><View style={s.iconTile}><Icon name={icon} size={28} /></View><Text style={s.cardTitle}>{title}</Text><Body style={{ textAlign: 'center' }}>{description}</Body></Card>;
}
export function RowLink({ title, subtitle, icon, onPress, badge }: { title: string; subtitle?: string; icon: IconName; onPress: () => void; badge?: string }) {
  return <Pressable onPress={onPress} accessibilityRole="button" style={({ pressed }) => [s.rowLink, pressed && { backgroundColor: colors.blue }]}><View style={s.iconTile}><Icon name={icon} /></View><View style={{ flex: 1 }}><Text style={s.rowTitle}>{title}</Text>{subtitle && <Body style={{ fontSize: 12, marginTop: 3 }}>{subtitle}</Body>}</View>{badge && <Text style={s.rowBadge}>{badge}</Text>}<Icon name="chevron-forward" size={18} color={colors.muted} /></Pressable>;
}
export function useAction() {
  const [busy, setBusy] = useState(false), lock = useRef(false);
  const run = async (action: () => Promise<void>) => {
    if (lock.current) return;
    lock.current = true; setBusy(true);
    try { await action(); } catch (error) { Alert.alert('Não foi possível concluir', (error as Error).message || 'Tente novamente.'); }
    finally { lock.current = false; setBusy(false); }
  };
  return { busy, run };
}
export const s = StyleSheet.create({
  screen: { padding: 20, paddingBottom: 32, gap: 20 },
  label: { color: colors.primary, fontSize: 11, fontWeight: '700', letterSpacing: 1.5, textTransform: 'uppercase' },
  title: { color: colors.navy, fontSize: 30, lineHeight: 36, fontWeight: '800', letterSpacing: -1 },
  body: { color: colors.muted, fontSize: 14, lineHeight: 22 },
  card: { backgroundColor: colors.white, borderRadius: 20, borderWidth: 1, borderColor: colors.line, padding: 20, gap: 12 },
  cardTitle: { fontSize: 17, color: colors.navy, fontWeight: '700', lineHeight: 23 },
  button: { backgroundColor: colors.primary, minHeight: 52, borderRadius: 14, paddingHorizontal: 20, paddingVertical: 14, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 10 },
  secondary: { backgroundColor: colors.blue, borderWidth: 1, borderColor: '#D6E5FF' },
  danger: { backgroundColor: colors.red },
  buttonText: { color: colors.white, fontSize: 15, fontWeight: '700', flexShrink: 1, textAlign: 'center' },
  iconButton: { minWidth: 48, minHeight: 48, alignItems: 'center', justifyContent: 'center', borderRadius: 14 },
  counter: { position: 'absolute', right: 2, top: 2, borderRadius: 20, minWidth: 18, paddingHorizontal: 4, backgroundColor: '#DC2626', borderWidth: 2, borderColor: 'white' },
  counterText: { color: 'white', fontSize: 10, fontWeight: '700', textAlign: 'center' },
  field: { gap: 8 }, fieldLabel: { fontSize: 13, color: colors.navy, fontWeight: '600' },
  input: { minHeight: 54, backgroundColor: 'white', borderWidth: 1, borderColor: colors.line, borderRadius: 13, paddingHorizontal: 16, paddingVertical: 14, color: colors.navy, fontSize: 16 },
  password: { flexDirection: 'row', alignItems: 'center', borderWidth: 1, borderColor: colors.line, borderRadius: 13, backgroundColor: 'white' },
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  chip: { minHeight: 44, borderWidth: 1, borderColor: colors.line, borderRadius: 12, paddingHorizontal: 14, paddingVertical: 11, justifyContent: 'center', backgroundColor: 'white' },
  chipSelected: { backgroundColor: colors.blue, borderColor: '#9BBFFF' },
  chipText: { fontSize: 13, color: colors.muted, fontWeight: '600' },
  row: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  dateText: { flex: 1, color: colors.navy, fontSize: 15 },
  iconTile: { width: 46, height: 46, backgroundColor: colors.blue, borderRadius: 14, alignItems: 'center', justifyContent: 'center' },
  rowLink: { flexDirection: 'row', alignItems: 'center', gap: 14, paddingVertical: 14, minHeight: 72, borderBottomColor: '#F0F3F8', borderBottomWidth: 1 },
  rowTitle: { color: colors.navy, fontSize: 15, fontWeight: '600' },
  rowBadge: { backgroundColor: colors.blue, color: colors.primary, borderRadius: 8, padding: 5, fontSize: 12 },
});
