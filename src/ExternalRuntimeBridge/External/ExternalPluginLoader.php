<?php
namespace ExternalRuntimeBridge\External;

use ExternalRuntimeBridge\ExternalPluginManager;
use ExternalRuntimeBridge\Lua\LuaPlugin;
use ExternalRuntimeBridge\Lua\LuaRuntime;
use ExternalRuntimeBridge\Python\PythonPlugin;
use ExternalRuntimeBridge\Python\PythonRuntime;
use pocketmine\plugin\PluginDescription;
use pocketmine\plugin\PluginLoader;
use pocketmine\plugin\Plugin;

class ExternalPluginLoader implements PluginLoader{

    private $manager;
    private $language;

    public function __construct(ExternalPluginManager $manager, $language){
        $this->manager = $manager;
        $this->language = $language;
    }

    public function loadPlugin($file){
        $manifest = $this->getPluginDescription($file);
        if(!is_array($manifest)){
            return null;
        }

        $root = rtrim($file, "\\/");
        $mainFile = $root . DIRECTORY_SEPARATOR . str_replace(["/", "\\"], DIRECTORY_SEPARATOR, $manifest["main"]);
        if(!is_file($mainFile)){
            throw new \RuntimeException("Arquivo principal não encontrado: " . $mainFile);
        }

        $descriptionData = $manifest;
        $descriptionData["type"] = "php";
        $description = new PluginDescription($descriptionData);

        if($this->language === "lua"){
            $plugin = new LuaPlugin();
            $plugin->init($this, $this->manager->getOwner()->getServer(), $description, $root, $root);
            $runtimePath = $this->manager->getOwner()->getDataFolder() . "runtimes" . DIRECTORY_SEPARATOR . "lua" . DIRECTORY_SEPARATOR . "external_lua_host";
            $configured = $this->manager->getOwner()->getConfig()->get("lua-executable", "");
            if(trim((string)$configured) !== ""){
                $runtimePath = $this->resolveConfiguredPath((string)$configured);
            }
            $runtime = new LuaRuntime($plugin, $mainFile, $runtimePath);
            $plugin->initLua($runtime, $mainFile);
            $plugin->initExternalManager($this->manager);
        }else{
            $plugin = new PythonPlugin();
            $plugin->init($this, $this->manager->getOwner()->getServer(), $description, $root, $root);
            $runtimeScript = $this->manager->getOwner()->getDataFolder() . "runtimes" . DIRECTORY_SEPARATOR . "python" . DIRECTORY_SEPARATOR . "python_runtime.py";
            $configuredScript = $this->manager->getOwner()->getConfig()->get("python-runtime-script", "");
            if(trim((string)$configuredScript) !== ""){
                $runtimeScript = $this->resolveConfiguredPath((string)$configuredScript);
            }
            $configured = $this->manager->getOwner()->getConfig()->get("python-executable", "");
            $executable = trim((string)$configured) !== "" ? $this->resolveConfiguredPath((string)$configured) : null;
            $runtime = new PythonRuntime($plugin, $mainFile, $runtimeScript, $executable);
            $plugin->initPython($runtime, $mainFile);
            $plugin->initExternalManager($this->manager);
        }

        $plugin->onLoad();
        return $plugin;
    }

    public function getPluginDescription($file){
        if(!is_dir($file)){
            return null;
        }
        $manifest = rtrim($file, "\\/") . DIRECTORY_SEPARATOR . "plugin.yml";
        if(!is_file($manifest)){
            return null;
        }

        $data = yaml_parse(file_get_contents($manifest));
        if(!is_array($data)){
            return null;
        }
        $language = strtolower(trim((string)($data["language"] ?? "")));
        return $language === $this->language ? $data : null;
    }

    public function getPluginFilters(){
        return $this->language === "lua" ? "/\\.lua$/i" : "/\\.py$/i";
    }

    public function enablePlugin(Plugin $plugin){
        if(!$plugin->isEnabled()){
            $plugin->setEnabled(true);
        }
    }

    public function disablePlugin(Plugin $plugin){
        if($plugin->isEnabled()){
            $plugin->setEnabled(false);
        }
    }

    public function getOwner(){
        return $this->manager->getOwner();
    }

    private function resolveConfiguredPath($path){
        if($path === ""){
            return $path;
        }
        if($path[0] === DIRECTORY_SEPARATOR || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path)){
            return $path;
        }
        return $this->manager->getOwner()->getDataFolder() . ltrim(str_replace(["\\", "/"], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    }
}
