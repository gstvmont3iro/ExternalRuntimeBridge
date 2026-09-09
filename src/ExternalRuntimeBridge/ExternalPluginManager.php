<?php
namespace ExternalRuntimeBridge;

use ExternalRuntimeBridge\External\ExternalPluginLoader;
use pocketmine\event\HandlerList;
use pocketmine\event\plugin\PluginEnableEvent;
use pocketmine\event\plugin\PluginDisableEvent;
use pocketmine\permission\Permission;
use pocketmine\plugin\Plugin;
use pocketmine\Server;
use pocketmine\utils\Config;
use pocketmine\utils\PluginException;

class ExternalPluginManager{

    private $owner;
    private $server;
    private $config;
    private $plugins = [];
    private $loadOrder = [];
    private $loaders = [];
    private $directory;
    private $addedPermissions = [];

    public function __construct(Main $owner, Config $config){
        $this->owner = $owner;
        $this->server = $owner->getServer();
        $this->config = $config;

        $configured = (string) $config->get("external-plugin-directory", "plugins/external");
        $configured = str_replace(["\\", "/"], DIRECTORY_SEPARATOR, trim($configured));
        if($configured === ""){
            $configured = "plugins" . DIRECTORY_SEPARATOR . "external";
        }

        if($this->isAbsolutePath($configured)){
            $this->directory = rtrim($configured, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        }else{
            $this->directory = rtrim($this->server->getDataPath(), DIRECTORY_SEPARATOR) .
                DIRECTORY_SEPARATOR . ltrim($configured, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        }

        if(!is_dir($this->directory)){
            @mkdir($this->directory, 0755, true);
        }

        $this->loaders["lua"] = new ExternalPluginLoader($this, "lua");
        $this->loaders["python"] = new ExternalPluginLoader($this, "python");
    }

    public function getOwner(){
        return $this->owner;
    }

    public function getExternalDirectory(){
        return $this->directory;
    }

    public function getPlugins(){
        return array_values($this->plugins);
    }

    public function getPlugin($name){
        foreach($this->plugins as $plugin){
            if(strcasecmp($plugin->getName(), $name) === 0){
                return $plugin;
            }
        }
        return null;
    }

    public function discoverAndLoad(){
        $entries = [];
        try{
            foreach(new \DirectoryIterator($this->directory) as $entry){
                if($entry->isDot() || !$entry->isDir()){
                    continue;
                }
                $entries[] = $entry->getPathname();
            }
        }catch(\Throwable $e){
            $this->owner->getLogger()->error("Não foi possível descobrir plugins externos: " . $e->getMessage());
            return;
        }

        sort($entries, SORT_NATURAL | SORT_FLAG_CASE);

        $pending = [];
        foreach($entries as $path){
            try{
                $raw = $this->readManifest($path);
                if($raw === null){
                    continue;
                }
                $language = strtolower(trim((string)($raw["language"] ?? "")));
                if(!isset($this->loaders[$language])){
                    $this->owner->getLogger()->error("Ignorando '" . basename($path) . "': language deve ser 'lua' ou 'python'.");
                    continue;
                }

                $manifest = $this->validateManifest($raw, $path);
                if($this->server->getPluginManager()->getPlugin($manifest["name"]) instanceof Plugin){
                    throw new PluginException("Já existe um plugin interno com o nome '{$manifest["name"]}'.");
                }
                if(isset($pending[$manifest["name"]])){
                    throw new PluginException("Plugin duplicado: " . $manifest["name"]);
                }
                $pending[$manifest["name"]] = [
                    "path" => $path,
                    "manifest" => $manifest,
                    "language" => $language,
                ];
            }catch(\Throwable $e){
                $this->owner->getLogger()->error("Falha ao validar plugin externo em '" . $path . "': " . $e->getMessage());
            }
        }

        $loaded = [];
        $remaining = $pending;
        while(count($remaining) > 0){
            $progress = false;

            foreach($remaining as $name => $item){
                $depends = isset($item["manifest"]["depend"]) ? (array)$item["manifest"]["depend"] : [];
                $ready = true;
                foreach($depends as $dependency){
                    $dependency = (string)$dependency;
                    if($this->getPlugin($dependency) !== null || $this->server->getPluginManager()->getPlugin($dependency) instanceof Plugin){
                        continue;
                    }
                    if(isset($remaining[$dependency])){
                        $ready = false;
                        break;
                    }
                    $this->owner->getLogger()->error("Plugin '{$name}' não carregado: dependência obrigatória ausente '{$dependency}'.");
                    $ready = false;
                    $item["_blocked"] = true;
                    break;
                }

                if(!$ready){
                    if(!isset($item["_blocked"])){
                        continue;
                    }
                    unset($remaining[$name]);
                    $progress = true;
                    continue;
                }

                foreach((array)($item["manifest"]["softdepend"] ?? []) as $dependency){
                    if(isset($remaining[$dependency]) && $this->getPlugin($dependency) === null){
                        $ready = false;
                        break;
                    }
                }
                if(!$ready){
                    continue;
                }

                try{
                    $plugin = $this->loadItem($item);
                    if($plugin instanceof Plugin){
                        $this->plugins[$plugin->getName()] = $plugin;
                        $loaded[] = $plugin->getName();
                        unset($remaining[$name]);
                        $progress = true;
                    }else{
                        unset($remaining[$name]);
                        $progress = true;
                    }
                }catch(\Throwable $e){
                    $this->owner->getLogger()->error("Falha ao carregar '{$name}': " . $e->getMessage());
                    $this->server->getLogger()->logException($e);
                    unset($remaining[$name]);
                    $progress = true;
                }
            }

            if(!$progress){
                foreach($remaining as $name => $item){
                    $this->owner->getLogger()->error("Dependência circular ou ordem impossível detectada envolvendo '{$name}'.");
                }
                break;
            }
        }

        foreach($loaded as $name){
            $plugin = $this->getPlugin($name);
            if($plugin === null){
                continue;
            }
            try{
                $this->enablePlugin($plugin);
            }catch(\Throwable $e){
                $this->owner->getLogger()->error("Falha ao habilitar '{$name}': " . $e->getMessage());
                $this->disablePlugin($plugin);
            }
        }
    }

    private function loadItem(array $item){
        $loader = $this->loaders[$item["language"]];
        return $loader->loadPlugin($item["path"], $item["manifest"]);
    }

    public function enablePlugin(Plugin $plugin){
        if(!$plugin->isEnabled()){
            foreach($plugin->getDescription()->getPermissions() as $permission){
                if($this->server->getPluginManager()->addPermission($permission)){
                    $this->addedPermissions[$permission->getName()] = true;
                }
            }
            $plugin->setEnabled(true);
            $this->server->getPluginManager()->callEvent(new PluginEnableEvent($plugin));
            $this->owner->getLogger()->info("Plugin externo habilitado: " . $plugin->getName() . " v" . $plugin->getDescription()->getVersion());
        }
    }

    public function disablePlugin(Plugin $plugin){
        if(!$plugin->isEnabled()){
            return;
        }
        try{
            $this->server->getPluginManager()->callEvent(new PluginDisableEvent($plugin));
            $plugin->setEnabled(false);
        }catch(\Throwable $e){
            $this->owner->getLogger()->error("Erro no onDisable de '" . $plugin->getName() . "': " . $e->getMessage());
            $this->server->getLogger()->logException($e);
        }

        $this->server->getScheduler()->cancelTasks($plugin);
        HandlerList::unregisterAll($plugin);

        foreach($plugin->getDescription()->getPermissions() as $permission){
            $name = $permission->getName();
            if(isset($this->addedPermissions[$name])){
                $this->server->getPluginManager()->removePermission($name);
                unset($this->addedPermissions[$name]);
            }
        }
    }

    public function disableAll(){
        $plugins = array_reverse(array_values($this->plugins));
        foreach($plugins as $plugin){
            $this->disablePlugin($plugin);
        }
        $this->plugins = [];
    }

    private function readManifest($path){
        $manifestFile = rtrim($path, "\\/") . DIRECTORY_SEPARATOR . "plugin.yml";
        if(!is_file($manifestFile)){
            return null;
        }
        $data = yaml_parse(file_get_contents($manifestFile));
        if(!is_array($data)){
            throw new PluginException("plugin.yml inválido.");
        }
        return $data;
    }

    private function validateManifest(array $data, $path){
        $required = ["name", "version", "main", "language", "api"];
        foreach($required as $key){
            if(!array_key_exists($key, $data)){
                throw new PluginException("plugin.yml sem campo obrigatório '{$key}'.");
            }
        }

        $name = preg_replace("/[^A-Za-z0-9 _.-]/", "", (string)$data["name"]);
        $name = str_replace(" ", "_", trim($name));
        if($name === ""){
            throw new PluginException("Nome de plugin externo inválido.");
        }

        $language = strtolower(trim((string)$data["language"]));
        if(!in_array($language, ["lua", "python"], true)){
            throw new PluginException("Linguagem não suportada: " . $language);
        }

        $main = str_replace(["\\", "/"], DIRECTORY_SEPARATOR, trim((string)$data["main"]));
        if($main === "" || strpos($main, "..") !== false || $main[0] === DIRECTORY_SEPARATOR || preg_match('/^[A-Za-z]:/', $main)){
            throw new PluginException("Campo main contém caminho inválido.");
        }

        $extension = strtolower(pathinfo($main, PATHINFO_EXTENSION));
        if($language === "lua" && $extension !== "lua"){
            throw new PluginException("Plugin Lua deve apontar para um arquivo .lua.");
        }
        if($language === "python" && $extension !== "py"){
            throw new PluginException("Plugin Python deve apontar para um arquivo .py.");
        }

        $api = is_array($data["api"]) ? $data["api"] : [$data["api"]];
        if(!$this->isApiCompatible($api, $this->server->getApiVersion())){
            throw new PluginException("API incompatível. Servidor: " . $this->server->getApiVersion());
        }

        if(isset($data["commands"]) && !is_array($data["commands"])){
            throw new PluginException("commands deve ser um mapa YAML.");
        }

        $data["name"] = $name;
        $data["version"] = (string)$data["version"];
        $data["main"] = $main;
        $data["language"] = $language;
        $data["api"] = $api;
        $data["depend"] = array_values((array)($data["depend"] ?? []));
        $data["softdepend"] = array_values((array)($data["softdepend"] ?? []));
        return $data;
    }

    private function isApiCompatible(array $supported, $serverApi){
        $sv = array_map("intval", explode(".", (string)$serverApi));
        $serverMajor = isset($sv[0]) ? $sv[0] : 0;
        $serverMinor = isset($sv[1]) ? $sv[1] : 0;

        foreach($supported as $version){
            $parts = array_map("intval", explode(".", (string)$version));
            $major = isset($parts[0]) ? $parts[0] : 0;
            $minor = isset($parts[1]) ? $parts[1] : 0;
            if($major < $serverMajor){
                continue;
            }
            if($major > $serverMajor){
                continue;
            }
            if($minor > $serverMinor){
                continue;
            }
            return true;
        }
        return false;
    }

    private function isAbsolutePath($path){
        return DIRECTORY_SEPARATOR === "\\" ?
            preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1 || strpos($path, "\\\\") === 0 :
            strpos($path, "/") === 0;
    }
}
