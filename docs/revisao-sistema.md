# Revisão CondoConnect

## Mapa antes das alterações

- `pages/*.php` encaminha para `frontend/pages/*.php`; preservar essas rotas.
- `frontend/includes` encaminha autenticação e funções para `backend/includes`.
- `functions.php` concentra usuários, chamados, chat, notificações e avaliações.
- `auth.php` usa sessão PHP, CSRF e verificação de e-mail; APIs legadas usam Bearer.
- `condoconnect.css` e `main.js` são os recursos efetivamente carregados pelo layout.
- `db_sisc.sql` e `backend/database/db_sisc.sql` precisam representar o mesmo schema.
- Pesquisa exige categoria e filtra condomínio textual; perfil expõe contato de terceiros.
- Chat possui participantes e cursor de leitura; unicidade global por par mistura contextos.
- Chamados têm histórico e aceite; não há propostas, visitas ou orçamentos persistidos.
- Comunicados e reservas são placeholders; condomínio é associado apenas por nome.
- Administrador exibe mapa Leaflet; menu não possui controle de recolhimento.
- API aceita ID como token e cadastro aceita administrador: corrigir sem renomear endpoints.

## Decisões

- Preservar integralmente `backend/config/database.php` e os dados existentes.
- Migrar relações legadas de nome para IDs, mantendo campos de texto por compatibilidade.
- Separar chat direto e chat de chamado, com unicidade por par e contexto.
- Centralizar regras de condomínio, reservas, comunicados e atendimento em módulos incluídos
  por `functions.php`, sem duplicar controladores ou folhas de estilo.
- Dados fictícios identificados por domínio `demo.example.test`; carga repetível.
- Verificar instalação limpa, migração repetida, isolamento entre condomínios, fluxos HTTP
  autenticados, consultas reais e layout em diferentes larguras.
