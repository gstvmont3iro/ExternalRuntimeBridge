# ExternalRuntimeBridge

Plugin de compatibilidade para servidores compatíveis com PocketMine-MP API 2.0.0, adicionando execução de plugins externos em Lua 5.4 e Python 3 sem alterar o núcleo do servidor.

## Recursos

- Descoberta de plugins em `plugins/external/<PluginName>/`.
- Manifesto independente com `language: lua` ou `language: python`.
- Um processo persistente por plugin externo.
- RPC com timeout e autenticação local para Python.
- Host nativo Lua 5.4 com API reduzida.
- Eventos, comandos, scheduler, jogadores, mundos, entidades, configuração e logging.
- Dependências obrigatórias/opcionais e detecção de ciclos.
- Falhas de um plugin externo são isoladas e registradas.
- Nenhum arquivo do núcleo precisa ser editado.

## Compatibilidade

- PHP 7.x.
- API 2.0.0.
- Linux é a plataforma principal.
- Lua: 5.4.
- Python: 3.x.
- Requer `proc_open`, sockets/streams e extensão YAML usada pelo servidor.

## Instalação

1. Copie a pasta do plugin para `plugins/ExternalRuntimeBridge`.
2. Inicie o servidor uma vez. O plugin criará `plugins/external/` e copiará os helpers para a pasta de dados do plugin.
3. Para Lua Linux x86_64, o pacote inclui um host pré-compilado. Em outra arquitetura, execute `runtimes/lua/build.sh` com a biblioteca de desenvolvimento/runtime Lua 5.4 disponível e substitua o binário.
4. Instale Python 3 para plugins Python. O executável pode ser definido em `config.yml`.
5. Copie um dos exemplos de `examples/lua` ou `examples/python` para `plugins/external`.
6. Reinicie o servidor e verifique o log.

## Manifest

```yaml
name: MeuPlugin
version: 1.0.0
main: main.lua
language: lua
api: 2.0.0
author: Autor
description: "Descrição"
depend:
  - OutroPlugin
softdepend:
  - PluginOpcional
commands:
  meucomando:
    description: "Descrição"
    usage: "/meucomando"
    aliases: ["mc"]
```

O campo `main` é sempre resolvido dentro da pasta do plugin. Caminhos absolutos e `..` são rejeitados.

## API Lua

A API disponibiliza globais `Server`, `Events`, `Commands`, `Scheduler`, `World`, `Entity`, `Plugin`, `Sender` e `Config`.

```lua
function joined(event)
    local player = event:getPlayer()
    if player then
        player:sendMessage("Olá!")
    end
end

function onEnable()
    Events.on("playerJoin", joined)
    Commands.register("ola", function(sender, args)
        sender:sendMessage("Olá do Lua!")
        return true
    end)
    Scheduler.runLater(20, function(tick)
        Server.log("Executou no tick " .. tick)
    end)
end
```

## API Python

O helper Python injeta `server`, `plugin` e `logger`.

```python
def joined(event):
    player = event.getPlayer()
    if player:
        player.sendMessage("Olá!")

def ola(sender, args):
    sender.sendMessage("Olá do Python!")
    return True

def onEnable():
    server.registerEvent("playerJoin", joined)
    server.registerCommand("ola", ola)
    server.scheduleDelayedTask(lambda tick: logger.info("Executou"), 20)
```

## Eventos

Os aliases iniciais são resolvidos somente quando a classe correspondente realmente existe no runtime do servidor. Exemplos:

`playerJoin`, `playerQuit`, `playerChat`, `playerInteract`, `playerMove`, `playerLogin`, `playerKick`, `playerDeath`, `playerCommandPreprocess`, `serverCommand`, `levelLoad`, `levelUnload`, `levelSave`, `weatherChange`, `blockBreak`, `blockPlace`, `signChange`, `entityDamage`, `entitySpawn`, `entityDeath`.

Também é possível passar o nome completo de uma classe de evento que exista e seja descendente de `pocketmine\event\Event`.

## Scheduler

Lua:

- `Scheduler.runLater(delay, callback)`
- `Scheduler.runRepeating(period, callback)`
- `Scheduler.runDelayedRepeating(delay, period, callback)`
- `Scheduler.cancel(taskId)`

Python:

- `server.scheduleDelayedTask(callback, delay)`
- `server.scheduleRepeatingTask(callback, period)`
- `server.scheduleDelayedRepeatingTask(callback, delay, period)`
- `server.cancelTask(taskId)`

Os callbacks são executados no scheduler principal. Operações lentas ainda podem prejudicar o tick e devem ser evitadas.

## Comunicação e segurança

Lua e Python não recebem objetos PHP. O bridge trabalha com IDs/opacos e valores serializados.

Python usa uma conexão TCP local em `127.0.0.1` com token aleatório por processo e timeout.

O host Lua remove APIs de sistema como `io`, `os`, `debug`, `package`, `require`, `dofile`, `loadfile` e `load`.

Isso **não é sandbox de segurança**. Código externo executado pelo processo do servidor deve ser considerado código confiável. Em PHP/OS, isolamento forte exige container, usuário de sistema ou mecanismo externo de sandbox.

## Limitações conhecidas

- O plugin precisa ser habilitado primeiro para que ele descubra e inicialize os plugins externos. Eles não participam do carregamento automático nativo do servidor.
- Plugins externos não aparecem como plugins internos no armazenamento privado do `PluginManager`; o gerenciador deste projeto mantém seu próprio registro.
- O bridge cobre a API de alto nível exposta pelos adaptadores, não toda a API PHP 2.0.0.
- O host Lua incluído é Linux x86_64. Outras arquiteturas precisam recompilar o C.
- O Python depende de uma instalação de Python 3 no host.

## Testes

Teste unitário mínimo:

```sh
php tests/ManifestSafetyTest.php
```

Teste de integração: inicie um servidor API 2.0.0 com apenas o plugin ponte e um dos exemplos. Verifique carregamento, evento de entrada, comando e scheduler. Remova o runtime Python/Lua deliberadamente para confirmar que um plugin incompatível gera erro sem derrubar os demais plugins.

## Estrutura

```text
ExternalRuntimeBridge/
├── plugin.yml
├── README.md
├── LICENSE
├── src/
│   └── ExternalRuntimeBridge/
│       ├── Main.php
│       ├── ExternalPluginManager.php
│       ├── External/
│       │   └── ExternalPluginLoader.php
│       ├── Lua/
│       └── Python/
├── resources/
│   ├── config.yml
│   └── runtimes/
├── docs/
│   ├── ARCHITECTURE.md
│   └── API.md
├── examples/
│   ├── lua/HelloLua/
│   └── python/HelloPython/
└── tests/
```
