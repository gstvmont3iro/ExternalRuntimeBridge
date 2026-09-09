<?php
namespace ExternalRuntimeBridge;

use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;

class Main extends PluginBase{

    public function onLoad(){
        $base = __DIR__;
        $files = array(
            $base . DIRECTORY_SEPARATOR . "ExternalPluginManager.php",
            $base . DIRECTORY_SEPARATOR . "External" . DIRECTORY_SEPARATOR . "ExternalPluginLoader.php",
            $base . DIRECTORY_SEPARATOR . "Lua" . DIRECTORY_SEPARATOR . "LuaPlugin.php",
            $base . DIRECTORY_SEPARATOR . "Lua" . DIRECTORY_SEPARATOR . "LuaRuntime.php",
            $base . DIRECTORY_SEPARATOR . "Lua" . DIRECTORY_SEPARATOR . "LuaTask.php",
            $base . DIRECTORY_SEPARATOR . "Lua" . DIRECTORY_SEPARATOR . "LuaEventListener.php",
            $base . DIRECTORY_SEPARATOR . "Lua" . DIRECTORY_SEPARATOR . "LuaEventExecutor.php",
            $base . DIRECTORY_SEPARATOR . "Python" . DIRECTORY_SEPARATOR . "PythonPlugin.php",
            $base . DIRECTORY_SEPARATOR . "Python" . DIRECTORY_SEPARATOR . "PythonRuntime.php",
            $base . DIRECTORY_SEPARATOR . "Python" . DIRECTORY_SEPARATOR . "PythonTask.php",
            $base . DIRECTORY_SEPARATOR . "Python" . DIRECTORY_SEPARATOR . "PythonEventListener.php",
            $base . DIRECTORY_SEPARATOR . "Python" . DIRECTORY_SEPARATOR . "PythonEventExecutor.php"
        );
        foreach($files as $file){
            if(is_file($file)){
                require_once $file;
            }
        }
    }

    /** @var ExternalPluginManager */
    private $manager;

    public function onEnable(){
        $this->saveDefaultConfig();
        $this->ensureRuntimeFiles();
        $config = $this->getConfig();

        $this->manager = new ExternalPluginManager($this, $config);
        $this->manager->discoverAndLoad();

        $this->getLogger()->info("ExternalRuntimeBridge habilitado.");
        $this->getLogger()->info("Diretório de plugins externos: " . $this->manager->getExternalDirectory());
    }

    public function onDisable(){
        if($this->manager !== null){
            $this->manager->disableAll();
            $this->manager = null;
        }
    }

    /**
     * @return ExternalPluginManager
     */
    public function getExternalPluginManager(){
        return $this->manager;
    }

    private function ensureRuntimeFiles(){
        $resources = [
            "runtimes/python/python_runtime.py",
            "runtimes/lua/external_lua_host.c",
            "runtimes/lua/build.sh",
            "runtimes/lua/external_lua_host",
            "runtimes/lua/lib/liblua5.4.so.0",
            "runtimes/lua/LUA-5.4-LICENSE.txt",
        ];
        foreach($resources as $resource){
            $target = rtrim($this->getDataFolder(), "\\/") . DIRECTORY_SEPARATOR . str_replace(["/", "\\"], DIRECTORY_SEPARATOR, $resource);
            $dir = dirname($target);
            if(!is_dir($dir)){
                @mkdir($dir, 0755, true);
            }
            if(!file_exists($target)){
                $this->saveResource($resource, false);
            }
            if(strpos($resource, "external_lua_host") !== false){
                @chmod($target, 0755);
            }
        }
    }
}
