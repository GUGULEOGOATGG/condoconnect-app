import React, { useEffect, useState } from 'react';
import { Alert, BackHandler, ImageBackground, Linking, Pressable, StyleSheet, Text, View } from 'react-native';
import { useApp } from './AppContext';
import { Body, Button, Card, Chips, colors, Field, Icon, IconButton, Label, PasswordField, s, Screen, Title, useAction } from './ui';
import type { Registration } from './types';

type Step = 'welcome' | 'setup' | 'login' | 'register' | 'forgot';
export function AuthScreens() {
  const app = useApp();
  const [step, setStep] = useState<Step>('welcome');
  useEffect(() => {
    const subscription = BackHandler.addEventListener('hardwareBackPress', () => {
      if (step === 'welcome') return false;
      setStep(step === 'register' || step === 'forgot' ? 'login' : 'welcome');
      return true;
    });
    return () => subscription.remove();
  }, [step]);
  return <View style={{ flex: 1, backgroundColor: colors.bg }}>
    {step !== 'welcome' && <View style={a.top}><IconButton name="arrow-back" label="Voltar" onPress={() => setStep(step === 'register' || step === 'forgot' ? 'login' : 'welcome')} /><Text style={a.brand}>CondoConnect<Text style={{ color: colors.primary }}>.</Text></Text></View>}
    {step === 'welcome' ? <Screen>
      <View style={a.wordmark}><View style={[s.iconTile, { backgroundColor: colors.primary }]}><Icon name="business-outline" color="white" size={27} /></View><Text style={a.brand}>CondoConnect<Text style={{ color: colors.primary }}>.</Text></Text></View>
      <ImageBackground source={require('../assets/condominio.jpg')} imageStyle={{ borderRadius: 26 }} style={a.hero}>
        <View style={a.heroShade}><View style={a.heroPill}><View style={a.dot} /><Text style={a.heroPillText}>MAIS PERTO. MAIS SIMPLES.</Text></View><Text style={a.heroTitle}>Seu condomínio.{ '\n' }Na palma{ '\n' }da mão.</Text><Text style={a.heroText}>Mais conexão. Mais tempo para viver bem.</Text></View>
      </ImageBackground>
      <View style={{ gap: 8 }}><Label>Viver bem começa aqui</Label><Text style={[s.cardTitle, { fontSize: 23, lineHeight: 30 }]}>Tudo o que importa,{ '\n' }bem perto de você.</Text><Body>Abra chamados, converse com a comunidade e reserve os espaços do seu condomínio.</Body></View>
      <View style={{ gap: 12 }}><Button title="Entrar na minha conta" icon="arrow-forward" onPress={() => setStep(app.serverUrl ? 'login' : 'setup')} /><Button title="Explorar demonstração" secondary icon="sparkles-outline" onPress={app.startDemo} /><Text style={a.hint}>A demonstração usa dados fictícios e funciona sem servidor.</Text></View>
      <Pressable onPress={() => setStep('setup')} accessibilityRole="button" style={{ minHeight: 44, justifyContent: 'center' }}><Text style={a.link}>{app.serverUrl ? 'Alterar o endereço do condomínio' : 'Conectar ao meu condomínio'}</Text></Pressable>
    </Screen> : step === 'setup' ? <Setup onDone={() => setStep('login')} /> : step === 'login' ? <Login onRegister={() => setStep('register')} onForgot={() => setStep('forgot')} onSetup={() => setStep('setup')} /> : step === 'register' ? <Register /> : <Forgot />}
  </View>;
}
function Setup({ onDone }: { onDone: () => void }) {
  const app = useApp(), action = useAction();
  const [url, setUrl] = useState(app.serverUrl);
  return <Screen><View style={{ gap: 10 }}><View style={s.iconTile}><Icon name="link-outline" size={26} /></View><Label>Seu espaço</Label><Title>Vamos conectar{ '\n' }seu condomínio.</Title><Body>Informe o endereço usado para acessar o CondoConnect. A administração do condomínio pode fornecer esse endereço.</Body></View><Card><Field label="Endereço do condomínio" value={url} onChangeText={setUrl} autoCapitalize="none" keyboardType="url" autoCorrect={false} placeholder="https://seu-condominio.com.br" /><Body style={{ fontSize: 12 }}>Se o sistema estiver em uma subpasta, inclua-a no endereço. A conexão precisa usar HTTPS.</Body><Button title="Verificar e conectar" loading={action.busy} icon="checkmark-circle-outline" onPress={() => action.run(async () => { await app.configure(url); onDone(); })} /></Card><Body>Você também pode voltar e explorar a demonstração enquanto o servidor é configurado.</Body></Screen>;
}
function Login({ onRegister, onForgot, onSetup }: { onRegister: () => void; onForgot: () => void; onSetup: () => void }) {
  const app = useApp(), action = useAction();
  const [email, setEmail] = useState(''), [password, setPassword] = useState(''), [code, setCode] = useState(''), [requiresCode, setRequiresCode] = useState(false);
  return <Screen><View style={{ gap: 10 }}><Label>Que bom ter você por aqui</Label><Title>{requiresCode ? 'Só mais um passo.' : 'Seu espaço\ncomeça aqui.'}</Title><Body>{requiresCode ? 'Enviamos um código de 6 dígitos para seu e-mail. Ele expira em 10 minutos.' : 'Entre para acompanhar o que acontece no seu condomínio.'}</Body></View><Card>
    {requiresCode ? <Field label="Código de verificação" value={code} onChangeText={setCode} keyboardType="number-pad" autoComplete="sms-otp" maxLength={6} placeholder="000000" /> : <><Field label="E-mail" value={email} onChangeText={setEmail} autoCapitalize="none" autoCorrect={false} keyboardType="email-address" autoComplete="email" placeholder="seu@email.com" /><PasswordField value={password} onChangeText={setPassword} /></>}
    <Button title={requiresCode ? 'Verificar e entrar' : 'Entrar na minha conta'} loading={action.busy} icon="arrow-forward" onPress={() => action.run(async () => {
      if (!email.trim() || !password) throw new Error('Informe seu e-mail e senha.');
      if (requiresCode && !/^\d{6}$/.test(code)) throw new Error('Informe o código de 6 dígitos.');
      const done = await app.login(email.trim().toLowerCase(), password, requiresCode ? code : undefined);
      if (done) { setPassword(''); setCode(''); } else setRequiresCode(true);
    })} />
    {requiresCode ? <Button title="Voltar ao login" secondary onPress={() => { setRequiresCode(false); setCode(''); setPassword(''); }} /> : <Pressable style={a.textButton} onPress={onForgot} accessibilityRole="button"><Text style={a.link}>Esqueci minha senha</Text></Pressable>}
  </Card><Button title="Ainda não tenho uma conta" secondary onPress={onRegister} /><View style={[s.row, { justifyContent: 'center' }]}><Icon name="shield-checkmark-outline" size={16} /><Text style={a.hint}>Sua conexão com o condomínio é protegida.</Text></View><Pressable accessibilityRole="button" style={a.textButton} onPress={onSetup}><Text style={a.link}>Alterar endereço do condomínio</Text></Pressable></Screen>;
}
function Register() {
  const app = useApp(), action = useAction();
  const [form, setForm] = useState<Registration>({ nome: '', email: '', cpf: '', telefone: '', senha: '', confirmar_senha: '', tipo_usuario: 'morador', condominio: '', unidade: '' });
  const change = (key: keyof Registration, value: string) => setForm(current => ({ ...current, [key]: value }));
  return <Screen><Label>Uma nova conexão</Label><Title>Faça parte{ '\n' }da comunidade.</Title><Body>Crie sua conta para começar. Vamos enviar um link de confirmação para seu e-mail.</Body><Card><Label>Seu perfil</Label><Chips values={['Morador', 'Prestador']} selected={form.tipo_usuario === 'morador' ? 'Morador' : 'Prestador'} onSelect={v => change('tipo_usuario', v === 'Morador' ? 'morador' : 'prestador')} /><Field label="Nome completo" value={form.nome} onChangeText={v => change('nome', v)} autoComplete="name" maxLength={190} /><Field label="CPF" value={form.cpf} onChangeText={v => change('cpf', v.replace(/\D/g, ''))} keyboardType="number-pad" maxLength={11} placeholder="Somente números" /><Field label="E-mail" value={form.email} onChangeText={v => change('email', v)} autoCapitalize="none" autoCorrect={false} keyboardType="email-address" autoComplete="email" maxLength={190} /><Field label="Telefone com DDD" value={form.telefone} onChangeText={v => change('telefone', v)} keyboardType="phone-pad" autoComplete="tel" maxLength={20} /><Field label="Nome do condomínio" value={form.condominio} onChangeText={v => change('condominio', v)} maxLength={190} /><Field label="Bloco e unidade" value={form.unidade} onChangeText={v => change('unidade', v)} placeholder="Ex.: Bloco A, 204" maxLength={190} /><PasswordField label="Crie uma senha" newPassword value={form.senha} onChangeText={v => change('senha', v)} /><Body style={{ fontSize: 12 }}>Use pelo menos 8 caracteres.</Body><PasswordField label="Repita sua senha" newPassword value={form.confirmar_senha} onChangeText={v => change('confirmar_senha', v)} /><Button title="Criar minha conta" loading={action.busy} onPress={() => action.run(async () => {
    if (Object.values(form).some(v => !v.trim())) throw new Error('Preencha todos os campos.');
    if (!/^\d{11}$/.test(form.cpf)) throw new Error('Informe um CPF com 11 números.');
    if (form.senha.length < 8 || form.senha !== form.confirmar_senha) throw new Error('Use uma senha de pelo menos 8 caracteres e repita a mesma senha.');
    await app.register({ ...form, email: form.email.trim().toLowerCase() });
  })} /></Card><Body style={{ fontSize: 12 }}>O cadastro de síndicos, que exige certificado, é feito pelo site. Síndicos com conta existente podem entrar normalmente no app.</Body>{app.serverUrl && <Button title="Abrir cadastro de síndico no site" secondary onPress={() => Linking.openURL(`${app.serverUrl}/pages/cadastro.php`).catch(() => Alert.alert('Não foi possível abrir o site.'))} />}</Screen>;
}
function Forgot() {
  const app = useApp(), action = useAction(); const [email, setEmail] = useState('');
  return <Screen><Label>Vamos ajudar</Label><Title>Recupere{ '\n' }seu acesso.</Title><Body>Informe seu e-mail para receber um link de redefinição de senha.</Body><Card><Field label="E-mail da conta" value={email} onChangeText={setEmail} keyboardType="email-address" autoCapitalize="none" autoComplete="email" /><Button title="Enviar link de recuperação" loading={action.busy} onPress={() => action.run(async () => { const message = await app.forgotPassword(email.trim().toLowerCase()); Alert.alert('Recuperação de acesso', message); })} /></Card></Screen>;
}
const a = StyleSheet.create({
  top: { paddingHorizontal: 12, paddingVertical: 8, flexDirection: 'row', alignItems: 'center', gap: 8, backgroundColor: 'white' },
  brand: { color: colors.navy, fontSize: 23, fontWeight: '800', letterSpacing: -.9 },
  wordmark: { flexDirection: 'row', alignItems: 'center', gap: 12, marginBottom: 4, marginTop: 8 },
  hero: { height: 330, borderRadius: 26, overflow: 'hidden', backgroundColor: colors.navy },
  heroShade: { flex: 1, backgroundColor: '#0F2A4759', padding: 26, justifyContent: 'flex-end', gap: 14 },
  heroPill: { flexDirection: 'row', alignItems: 'center', gap: 7, alignSelf: 'flex-start', borderRadius: 20, backgroundColor: '#FFFFFFE8', paddingVertical: 8, paddingHorizontal: 10 },
  dot: { width: 6, height: 6, borderRadius: 4, backgroundColor: colors.primary },
  heroPillText: { fontSize: 9, letterSpacing: 1, fontWeight: '700', color: colors.primary },
  heroTitle: { color: 'white', fontSize: 38, lineHeight: 43, fontWeight: '800', letterSpacing: -1.3 },
  heroText: { color: '#E5EEFC', fontSize: 13, lineHeight: 20 },
  hint: { color: colors.muted, fontSize: 11, textAlign: 'center', lineHeight: 17, flexShrink: 1 },
  link: { color: colors.primary, fontWeight: '600', fontSize: 13, textAlign: 'center' },
  textButton: { minHeight: 48, justifyContent: 'center' },
});
