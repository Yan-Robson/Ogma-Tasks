# Ogma Tasks para GLPI ⚙️

O **Ogma Tasks** é um plugin avançado para o sistema GLPI projetado para gerir, padronizar e automatizar fluxos de trabalho. Ele transforma o gerenciamento básico de tarefas em uma central de processos autônomos e visuais.

Inspirado em *Ogma*, o deus celta da eloquência, criação e estruturação da informação, este plugin organiza o fluxo de dados do seu ambiente de TI, garantindo que as requisições sigam padrões precisos e executem ações automáticas de forma inteligente.

## ✨ Funcionalidades Principais

* **🤖 Automação Inteligente:** Crie regras de negócios robustas. O sistema executa ações autônomas baseadas em gatilhos (hooks) e condições pré-definidas.
* **📋 Modelos e Padronização:** Utilize modelos (templates) e formulários dinâmicos para agilizar a criação e a estruturação de tarefas, garantindo consistência no atendimento.
* **⏱️ Acompanhamento via Timeline:** Monitorização visual e cronológica. Acompanhe o ciclo de vida e o progresso de cada atividade através de uma linha do tempo (timeline) interativa e integrada.
* **📂 Integração Nativa:** Comunicação perfeita com documentos, localidades e outros elementos centrais da arquitetura do GLPI.

## 🛠️ Requisitos

* **GLPI:** Versão 10.0 ou superior (ajuste conforme a compatibilidade do seu código)
* **PHP:** 7.4, 8.1 ou superior

## 🚀 Instalação

1. Faça o download da versão mais recente na página de *Releases*.
2. Descompacte o arquivo zipado.
3. Mova a pasta extraída (certifique-se de que ela se chama `ogmatasks` ou o nome de diretório definido no seu `setup.php`) para o diretório de plugins do seu servidor GLPI:
   ```bash
   /var/www/html/glpi/plugins/
