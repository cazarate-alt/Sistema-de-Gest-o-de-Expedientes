# Sistema GED — Gestão Eletrónica de Documentos

**Versão:** 1.0.0
**Autor:** Clementino Azarate
**Email:** cazarate@estudantes.unisced.edu.mz
**Data:** `<?= date('Y') ?>`

---

## 1. Descrição

O GED é um sistema web de gestão e tramitação eletrónica de documentos académicos, desenvolvido em **PHP + MySQL**. Permite que estudantes submetam requerimentos (declarações, certificados, reclamações, notas de curso) e que estes sejam encaminhados automaticamente por um fluxo de trabalho definido, passando pela Secretaria, Docentes e Direção, até à entrega final ao Estudante.

O sistema é multiusuário, com controlo de acesso baseado em perfis (RBAC), auditoria completa das ações, notificações em tempo real, geração de PDF e dashboards personalizadas por perfil.

---

## 2. Funcionalidades Principais

- [x] Autenticação com sessões seguras
- [x] Controlo de acesso baseado em perfis e permissões (RBAC)
- [x] Gestão de utilizadores, perfis, cargos, módulos e ações
- [x] Submissão de requerimentos pelos Estudantes
- [x] Tramitação por fluxos configuráveis (Fluxo → Passos → Perfil)
- [x] Notificações em tempo real (sino no navbar)
- [x] Histórico completo de alterações de estado
- [x] Comentários e anexos por documento
- [x] Dashboards com gráficos (Chart.js) por perfil
- [x] Exportação para PDF (FPDF)
- [x] Auditoria imutável (LOG bloqueado a UPDATE/DELETE)
- [x] Validação de transição de estado via triggers MySQL
- [x] Geração automática de códigos e passwords (data de nascimento)

---

## 3. Perfis de Utilizador

| Código | Perfil | Descrição |
|--------|--------|-----------|
| `SUPE` | Super Administrador | Acesso total **EXCETO** Documentos/Tramitação (administrador puro). |
| `ADMI` | Administrador | Gestão do sistema: utilizadores, perfis, cargos, módulos, ações, permissões, auditoria e relatórios. |
| `DIRE` | Director | Visualiza todos os documentos, homologa e devolve à Secretaria. Único perfil (com SUPE) que pode **REJEITAR** documentos. |
| `SECR` | Secretaria | Recebe, responde, reencaminha e entrega documentos ao Estudante. Perfil final de todos os fluxos (entrega ao Estudante). |
| `DOCE` | Docente | Vê apenas documentos atribuídos a si, emite parecer e devolve. |
| `ESTU` | Estudante | Submete requerimentos e acompanha o estado dos seus pedidos. |

---

## 4. Requisitos do Sistema

### Servidor Web
- Apache 2.4+ (ou Nginx)
- PHP 7.4+ (recomendado PHP 8.1+)
- Extensões PHP: `pdo`, `pdo_mysql`, `mysqli`, `mbstring`, `fileinfo`, `json`, `gd`

### Base de Dados
- MySQL 5.7+ **OU** MariaDB 10.3+

### Navegador (cliente)
- Chrome, Firefox, Edge ou Safari (versões recentes)
- JavaScript ativado

---

## 5. Instalação

### Passo 1 — Copiar os ficheiros

Copiar todo o projeto para a pasta do servidor web:

| Ambiente | Caminho |
|----------|---------|
| XAMPP | `C:\xampp\htdocs\GED\` |
| WAMP | `C:\wamp64\www\GED\` |
| Linux | `/var/www/html/GED/` |

### Passo 2 — Configurar a ligação à BD

Editar os ficheiros PHP e ajustar as credenciais (se necessário):

```php
$host = 'localhost';
$db   = 'GED';
$user = 'root';
$pass = '';
```

### Passo 3 — Colocar o `DB.sql` na raiz do projeto

O ficheiro `DB.sql` deve estar na mesma pasta do `index.php`.

### Passo 4 — Abrir no navegador

```
http://localhost/GED/
```

O sistema irá:
1. Verificar se a base de dados `GED` existe.
2. Se **não** existir, executar automaticamente o `DB.sql`.
3. Se existir, verificar a sessão:
   - Logado → redireciona para `dashboard.php`
   - Não logado → redireciona para `login.php`

### Passo 5 — Primeiro acesso

- **Código:** `SUPER`
- **Password:** (ver no `DB.sql`, por defeito `Super@2025` — alterar depois!)

> **Importante:** Após o primeiro login, altere a password do Super Admin através da Gestão de Utilizadores.

---

## 6. Estrutura de Pastas

```
GED/
│
├── index.php                  Ponto de entrada (verifica BD + sessão)
├── login.php                  Página de autenticação
├── logout.php                 Termina a sessão
├── DB.sql                     Script completo de instalação da BD
├── README.md                  Este ficheiro
│
├── dashboard.php              Dashboard dinâmica por perfil
├── dashboard_pdf.php          Exportação da dashboard em PDF
│
├── documents.php              Lista de documentos
├── document_view.php          Detalhes e tramitação de um documento
├── document_process.php       Endpoint de ações (forward/return/resolve/reject)
├── document_types.php         Gestão de tipos de documento
│
├── flows.php                  Gestão de fluxos de tramitação
├── flow_steps.php             Gestão de passos dos fluxos
│
├── users.php                  Gestão de utilizadores
├── profiles.php               Gestão de perfis
├── positions.php              Gestão de cargos
├── modules.php                Gestão de módulos
├── actions.php                Gestão de ações
├── permissions.php            Matriz de permissões
├── profile_permissions.php    Atribuição de permissões ao perfil
│
├── logs.php                   Auditoria do sistema
├── logs_pdf.php                Exportação de auditoria em PDF
├── reports.php                Relatórios gerais
├── reports_pdf.php            Exportação de relatórios em PDF
├── export_pdf.php             Exportação rápida em PDF (por tipo)
│
├── uploads/
│   └── attachments/           Anexos dos documentos
│
├── includes/
│   ├── auth.php                    Funções de autenticação e permissões
│   ├── header.php                  Cabeçalho HTML
│   ├── navbar.php                  Barra de navegação + notificações
│   ├── sidebar.php                 Menu lateral dinâmico
│   ├── footer.php                  Rodapé
│   ├── modals.php                  Modais partilhados
│   ├── notification_helper.php     Funções de notificações
│   └── pdf_report.php              Helpers FPDF (GED)
│
├── libs/
│   └── fpdf/
│       └── fpdf.php            Biblioteca FPDF
│
└── assets/
    ├── css/
    │   ├── style.css           Estilos globais
    │   └── login.css           Estilos do login
    └── js/
        └── main.js             Scripts globais
```

---

## 7. Base de Dados

**Nome:** `GED`

### Tabelas RBAC
- `PROFILE` — Perfis de utilizador
- `POSITION` — Cargos
- `ACTION` — Ações (VIEW, CREA, UPDT, DELE)
- `MODULE` — Módulos do sistema
- `USERS` — Utilizadores
- `PERMISSION` — Permissões (Perfil × Módulo × Ação)
- `SESSION` — Sessões de login
- `LOG` — Auditoria (imutável)

### Tabelas de Tramitação
- `DOCUMENT_TYPE` — Tipos de documento
- `FLOW` — Fluxos de tramitação
- `FLOW_STEP` — Passos dos fluxos
- `DOCUMENT` — Documentos
- `DOCUMENT_ATTACHMENT` — Anexos
- `PROCESSING` — Encaminhamentos
- `DOCUMENT_HISTORY` — Histórico de estados
- `DOCUMENT_COMMENT` — Comentários
- `NOTIFICATION` — Notificações

### Procedures
- `SP_SET_APP_CONTEXT` — Define o contexto (`@app_user_id`, `@app_profile_code`...)
- `SP_WRITE_LOG` — Escreve no LOG

### Triggers
- 22 triggers de auditoria, sessão, validação de estado e proteção do LOG

---

## 8. Fluxos de Tramitação

| Código | Fluxo |
|--------|-------|
| `DECL` | Declaração: Estudante → Secretaria → Docente → Director → Secretaria (entrega) → Estudante |
| `CERT` | Certificado: Estudante → Secretaria → Director → Secretaria (entrega) → Estudante |
| `RCLM` | Reclamação: Estudante → Secretaria → Docente → Director → Secretaria (entrega) → Estudante |
| `NOTA` | Nota de Curso: Estudante → Secretaria → Docente → Secretaria (entrega) → Estudante |

---

## 9. Regras de Negócio Importantes

- Todos os fluxos **terminam** na Secretaria (entrega ao Estudante).
- Apenas `SECR` (e `SUPE`) podem marcar documentos como `RESOLVED` ou `CLOSED`.
- Apenas `DIRE` (e `SUPE`) podem **rejeitar** documentos.
- O Super Administrador **não** tem acesso a Documentos nem Tramitação.
- A Secretaria **não** tem acesso à Auditoria nem cria documentos.
- O Docente só vê documentos atribuídos a si (`DOCUMENT_ASSIGNED_TO`).
- O Estudante só vê os seus próprios documentos (`USER_ID`).
- A password inicial de cada utilizador é a data de nascimento (`DDMMAAAA`).
- O LOG é **imutável** (não permite `UPDATE` nem `DELETE`).
- O contexto da aplicação (`@app_profile_code`) é essencial para que os triggers validem as transições de estado — deve ser definido no PHP através de `SP_SET_APP_CONTEXT` ou `SET @app_*` antes de qualquer `UPDATE` em `DOCUMENT`.

---

## 10. Segurança

- Todas as passwords são guardadas com `password_hash()` (bcrypt).
- Uso exclusivo de prepared statements (PDO) — proteção contra SQL Injection.
- Validação de sessão em todas as páginas (`requireLogin()`).
- Regeneração de `session_id` após login (`session_regenerate_id(true)`).
- Registo de tentativas de login (sucesso e falha) no LOG.
- Registo de início/fim de sessão na tabela `SESSION`.
- Controlo de acesso por permissão (`requirePermissionOrRedirect()`).

### Recomendações após instalação

1. Alterar a password do Super Administrador.
2. Bloquear o acesso direto a `DB.sql` (via `.htaccess` ou removê-lo após instalação bem-sucedida).
3. Ativar HTTPS em produção.
4. Configurar backups periódicos da base de dados `GED`.
5. Manter PHP e MySQL atualizados.

---

## 11. Utilização Rápida

### Estudante
1. Login com código/email + password.
2. Dashboard → "Criar Novo Requerimento".
3. Escolher tipo, escrever assunto e corpo.
4. Submeter → notificação chega à Secretaria.
5. Acompanhar estado na lista "Meus Pedidos".
6. Quando estiver `RESOLVED`/`CLOSED` → "Imprimir PDF".

### Secretaria
1. Login → vê requerimentos pendentes.
2. Abrir documento → escolher ação (Encaminhar / Resolver / Entregar).
3. Enviar para o próximo passo do fluxo.

### Docente
1. Login → vê documentos atribuídos.
2. Emitir parecer (Devolver à Secretaria com nota).

### Director
1. Login → vê todos os pendentes.
2. Aprovar (Encaminhar) ou Rejeitar.

### Administrador / Super Admin
1. Gestão de utilizadores, perfis, cargos, módulos, ações, permissões.
2. Consulta da auditoria e relatórios.
3. Exportação em PDF de todas as listas.

---

## 12. Resolução de Problemas

<details>
<summary><strong>Erro: "Erro ao ligar ao MySQL"</strong></summary>

Verifique se o MySQL está a correr e as credenciais em cada ficheiro.
</details>

<details>
<summary><strong>Erro: "Ficheiro DB.sql não encontrado"</strong></summary>

Coloque o `DB.sql` na raiz do projeto (mesma pasta do `index.php`).
</details>

<details>
<summary><strong>Erro: "Apenas a Secretaria pode entregar documentos ao Estudante."</strong></summary>

O contexto da aplicação não está definido. Verifique se o PHP executa `SET @app_profile_code = '<perfil>'` antes de `UPDATE` em `DOCUMENT`.
</details>

<details>
<summary><strong>Erro: "LOG imutável."</strong></summary>

Tentativa de `UPDATE`/`DELETE` na tabela `LOG` — comportamento intencional.
</details>

<details>
<summary><strong>Login falha com password correta</strong></summary>

Verifique se a password foi gerada com `password_hash()` e é compatível. Se importou o `DB.sql` antigo, o hash pode ser placeholder — regenere a password em `users.php` → "Reset Password".
</details>

<details>
<summary><strong>PDFs não abrem</strong></summary>

Confirme que `libs/fpdf/fpdf.php` existe e que `includes/pdf_report.php` faz o `require_once` correto.
</details>

---

## 13. Tecnologias Usadas

**Backend**
- PHP 7.4+ (PDO, sessões, `password_hash`)
- MySQL / MariaDB (InnoDB, procedures, triggers, views)

**Frontend**
- HTML5 + CSS3
- JavaScript (Vanilla)
- Bootstrap 5.3
- Font Awesome 6.4
- Chart.js 4.4

**Bibliotecas**
- FPDF (geração de PDF)

---

## 14. Licença e Créditos

Sistema desenvolvido por **Clementino Azarate**.
Uso interno / académico.

© `<?= date('Y') ?>` — Todos os direitos reservados.

---

## 15. Contacto

- **Email:** cazarate@estudantes.unisced.edu.mz
- **GitHub:** _(adicionar link do repositório, se aplicável)_
