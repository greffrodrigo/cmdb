# CMDB - Proxmox 

Módulo WEb desenvolvido para realizar levantamento de ativos virtuais em ambiente Proxmox via API, guardando em banco de dados, cria os itens
(Cluster, NODE, VM/CT) com um identificador único "ICONF" e guarda o histórico de alterações de recursos. 

Nenhum item pode ser  removido para fins de histórico completo.

## Instalação
O sistema host deve ter os seguintes pacotes instalados:
nginx, php-fpm, mariadb, php-mysql, openssl, jq e curl.

No sistema atual:
curl 8.14.1-2
jq 1.7.1-6
mariadb-server 1:11.8.6-0
nginx 1.26.3-3
openssl 3.5.7-1~deb13u3
php-fpm 2:8.4+96
php-mysql 2:8.4+96
php8.4-fpm 8.4.28-1
php8.4-mbstring 8.4.28-1
php8.4-mysql 8.4.28-1

A estrutura é entregue em um arquivo .tar com os arquivos necessários.
Descompactar em /var/www/cmdb

Criar a estrutura do banco: /var/www/cmdb/sql/schema.sql
Criar o primeiro user Admin via `php bin/criar-usuario.php`
Crie os usuários conforme necessário e com o perfil desejado: ADMIN ou ANALISTA.
Cadastre os ambientes com o API Token (formato exibido na tela de cadastro)

## Coleta manual 

Na tela inicial é possível realizar uma coleta manual:
1- De todos ambientes clicando em "Atualizar Dados"
2- Ambiente individual, localizando o desejado e clicando em "Atualizar este ambiente" 

## Agendamento das coletas (30 em 30 minutos ou uma vez ao dia)

Na tela Ambientes localize "Agendamento"
Existem 3 opções -Desligado
			     -A cada 30 minutos(em :00 e :30) 
				 -Uma vez ao dia, às HH:MM*
A seleção cria uma linha no cron do sistema operacional, e as alterações do tipo de agendamento não precisam ser feitas no Cron; mas no CMDB.
Todos os ambientes cadastrados são coletados na sequencia, de acordo com o agendamento.
## Pesquisa de ICONF

No topo da tela CMDB está loalizado o campo de pesquisa, onde é possível listar e acessar diretamente um ítem e avaliar dados do mesmo em uma tela individual.


