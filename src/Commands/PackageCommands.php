<?php

namespace Abnermouke\LaravelBuilder\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Laravel Builder to package
 * Class PackageCommands
 * @package Abnermouke\LaravelBuilder\Commands
 */
class PackageCommands extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'builder:package {name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Laravel builder power by Abnermouke';

    /**
     * 初始化模版参数
     * @var array
     */
    private $tplParams = [
        '__NAME__' => '',
        '__TABLE_NAME__' => '',
        '__CASE_NAME__' => '',
        '__DATA_NAME__' => '',
        '__LOWER_CASE_NAME__' => '',
        '__AUTHOR__' => '',
        '__AUTHOR_CONTACT_EMAIL' => '',
        '__ORIGINATE__' => '',
        '__DATE__' => '',
        '__TIME__' => '',
        '__CHARSET__' => 'utf8mb4',
        '__ENGINE__' => 'innodb',
        '__DB_PREFIX__' => '',
        '__DATA_CACHE_NAME__' => '',
        '__DATA_CACHE_EXPIRE_SECOND__' => 0,
        '__DB_CONNECTION__' => '',
        '__DATA_CACHE_DRIVER__' => '',
        '__DICTIONARY__' => ''
    ];

    public function __construct($config)
    {
        //引入父级构造
        parent::__construct();
        //初始化基本配置
        $default_params = [
            '__DATE__' => date('Y-m-d'),
            '__TIME__' => date('H:i:s'),
            '__AUTHOR__' => data_get($config, 'author', 'Abnermouke'),
            '__AUTHOR_CONTACT_EMAIL' => data_get($config, 'author_email', 'abnermouke@gmail.com'),
            '__ORIGINATE__' => data_get($config, 'original', 'Yunni Technology Co Ltd.'),
            '__DB_PREFIX__' => data_get($config, 'database_prefix', ''),
            '__DB_CONNECTION__' => data_get($config, 'database_connection', 'mysql'),
            '__DATA_CACHE_DRIVER__' => data_get($config, 'cache_driver', 'file'),
        ];
        //初始化配置
        $this->tplParams = array_merge($this->tplParams, $default_params);
    }

    public function handle()
    {
        //获取生成文件包统一名称
        $this->tplParams['__NAME__'] = $this->tplParams['__MIGRATION_NAME_'] = $name = $this->argument('name');
        //提示输入表名
        $this->tplParams['__TABLE_NAME__'] = $tableName = $this->output->ask('请输入当前表注释名称并以"表"字结尾，例如：用户基本信息表');
        //提示获取目录结构
        $this->tplParams['__DICTIONARY__'] = $dictionary =  (string)$this->output->ask('多项目部署时如需对各服务文件进行分开部署请输入目录名称，例如：www');
        //检测是否存在数据库表前缀
        if (empty($this->tplParams['__DB_PREFIX__'])) {
            //提示设置数据库前缀
            $this->tplParams['__DB_PREFIX__'] = $dbPrefix = (string)$this->output->ask('检测到并未设置数据库表前缀，如数据库表存在统一前缀，请输入前缀，例如：system_');
            //初始化数据库前缀信息
            $this->tplParams['__DB_PREFIX__'] = Str::finish($dbPrefix, '_');
        }
        //整理驼峰名称
        $this->tplParams['__CASE_NAME__'] = $this->tplParams['__MIGRATION_CASE_NAME_'] = $caseName = Str::studly($name);
        //整理驼峰名称（小写）
        $this->tplParams['__LOWER_CASE_NAME__'] = Str::singular($caseName);
        //整理驼峰目录名称
        $this->tplParams['__DICTIONARY__'] = data_get($this->tplParams, '__DICTIONARY__', false) ? Str::start(Str::studly($this->tplParams['__DICTIONARY__']), '\\') : '';
        //匹配数据名
        $ret = preg_match('~(.*)表~Uuis', $tableName, $matched);
        //初始化数据名
        $this->tplParams['__DATA_NAME__'] = $dataName = intval($ret) >= 1 ? $matched[1] : $tableName;
        //询问获取数据库链接信息
        $this->tplParams['__DB_CONNECTION__'] = $this->choice('请选择当前数据查询时使用的表链接信息！', array_keys(config('database.connections')), config('database.default'));
        //生成model
        $this->makeModel();
        //生成数据仓库
        $this->makeRepository();
        //询问是否生成迁移文件Migration
        if ($this->confirm('是否生成数据迁移（Migration）文件？', true)) {
            //生成migration
            $this->makeMigration();
        }
        //生成服务容器
        $this->makeService();
        //询问是否生成数据缓存
        if ($this->confirm('是否生成数据缓存文件？', true)) {
            //设置基础缓存名
            $this->tplParams['__DATA_CACHE_NAME__'] = $this->ask('您可以自定义当前数据缓存名，默认为：[ '. (($dictionary && !empty($dictionary) ? strtolower($dictionary).'_' : '').$name.'_data_cache').' ]，如需更改，请输入您要使用的缓存名！', (($dictionary && !empty($dictionary) ? strtolower($dictionary).'_' : '').$name.'_data_cache'));
            //设置缓存过期时间，随机1小时-一天
            $this->tplParams['__DATA_CACHE_EXPIRE_SECOND__'] = $this->ask('您可以自定义数据缓存过期时间（单位：s）,系统将默认设定为 1 小时至一天的随机时间过期，您也可以自定义，0 为永远不过期，请输入当前数据缓存的过期时间！', rand(3600, 86400));
            //生成数据缓存文件
            $this->makeDataCache();
        }
        //询问是否生成控制器
        if ($this->confirm('是否生成控制器文件？', true)) {
            //生成控制器文件
            $this->makeController();
        }
        //输出信息
        $this->output->write('Laravel Builder Packages Create Success, Make it awesome!', true);

    }

    /**
     * 创建迁移文件
     * @Author Abnermouke <abnermouke@gmail.com>
     * @Originate in Company Yunnitec.
     * @Time 2020-04-27 20:05:36
     * @return bool
     */
    private function makeMigration()
    {
        //判断是否存在目录信息
        if ($this->tplParams['__DICTIONARY__'] && !empty($this->tplParams['__DICTIONARY__'])) {
            //整理迁移名称
            $this->tplParams['__MIGRATION_NAME_'] = strtolower(Str::after($this->tplParams['__DICTIONARY__'], '\\')).'_'.$this->tplParams['__NAME__'];
            //设置迁移显示类名称
            $this->tplParams['__MIGRATION_CASE_NAME_'] = Str::studly($this->tplParams['__MIGRATION_NAME_']);
        }
        try {
            //执行命令
            Artisan::call('make:migration', ['name' => 'create_'.$this->tplParams['__MIGRATION_NAME_'].'_table']);
        } catch (\Exception $exception) {
            //输出错误
            $this->output->warning($exception->getMessage());
        }
        //获取执行结果
        $output = Artisan::output();
        //匹配文件名
        $ret = preg_match('~Created Migration: (.*)\n~Uuis', $output, $matched);
        //判断是否匹配成功
        if ($ret <= 0) {
            //输出错误
            $this->output->warning('Migration出现未知错误');
            return true;
        }
        //初始化文件名
        $migrationName = $matched[1];
        //询问获取数据库配置信息
        $this->tplParams['__CHARSET__'] = $this->choice('请选择当前数据库表使用的字符集！', ['utf8', 'utf8mb4'], $this->tplParams['__CHARSET__']);
        $this->tplParams['__ENGINE__'] = $this->choice('请输入当前数据库表使用的储存引擎！', ['myisam', 'innodb'], $this->tplParams['__ENGINE__']);
        //获取模版内容
        $content = $this->getTplContent('migration');
        //内容存在
        if ($content && !empty($content)) {
            //整理路径
            $migrationPath = database_path('migrations/'.$migrationName.'.php');
            //设置内容
            $this->putContent($migrationPath, $content);
        }
        return true;
    }

    /**
     * 创建模型文件
     * @Author Abnermouke <abnermouke@gmail.com>
     * @Originate in Company Yunnitec.
     * @Time 2020-04-27 20:06:56
     * @return bool
     */
    private function makeModel()
    {
        //整理目录
        $modelDirectory = app_path('Model'.str_replace('\\', '/', $this->tplParams['__DICTIONARY__']));
        //判断目录是否存在
        if (!File::isDirectory($modelDirectory)) {
            //创建目录
            File::makeDirectory($modelDirectory, 0777, true, true);
        }
        //整理路径
        $modelPath = app_path('Model'.str_replace('\\', '/', $this->tplParams['__DICTIONARY__']).'/'.$this->tplParams['__CASE_NAME__'].'.php');
        //判断文件地址
        if (file_exists($modelPath) && !$this->confirm('数据模型 ['.$modelPath.'] 已存在，是否覆盖写入？')) {
            //直接返回
            return true;
        }
        //获取模版内容
        $content = $this->getTplContent('model');
        //内容存在
        if ($content && !empty($content)) {
            //设置内容
            $this->putContent($modelPath, $content);
        }
        return true;
    }

    /**
     * 创建服务容器
     * @Author Abnermouke <abnermouke@gmail.com>
     * @Originate in Company Yunnitec.
     * @Time 2020-04-27 20:12:43
     * @return bool
     */
    private function makeService()
    {
        //整理目录
        $serviceDirectory = app_path('Services'.str_replace('\\', '/', $this->tplParams['__DICTIONARY__']));
        //判断目录是否存在
        if (!File::isDirectory($serviceDirectory)) {
            //创建目录
            File::makeDirectory($serviceDirectory, 0777, true, true);
        }
        //整理路径
        $servicePath = app_path('Services'.str_replace('\\', '/', $this->tplParams['__DICTIONARY__']).'/'.$this->tplParams['__LOWER_CASE_NAME__'].'Service.php');
        //判断文件地址
        if (file_exists($servicePath) && !$this->confirm('服务容器 ['.$servicePath.'] 已存在，是否覆盖写入？')) {
            //直接返回
            return true;
        }
        //获取模版内容
        $content = $this->getTplContent('service');
        //内容存在
        if ($content && !empty($content)) {
            //设置内容
            $this->putContent($servicePath, $content);
        }
        return true;
    }

    /**
     * 创建控制器
     * @Author Abnermouke <abnermouke@gmail.com>
     * @Originate in Company Yunnitec.
     * @Time 2020-04-27 20:12:43
     * @return bool
     */
    private function makeController()
    {
        //整理目录
        $controllerDirectory = app_path('Http/Controllers'.str_replace('\\', '/', $this->tplParams['__DICTIONARY__']));
        //判断目录是否存在
        if (!File::isDirectory($controllerDirectory)) {
            //创建目录
            File::makeDirectory($controllerDirectory, 0777, true, true);
        }
        //整理路径
        $controllerPath = app_path('Http/Controllers'.str_replace('\\', '/', $this->tplParams['__DICTIONARY__']).'/'.$this->tplParams['__LOWER_CASE_NAME__'].'Controller.php');
        //判断文件地址
        if (file_exists($controllerPath) && !$this->confirm('控制器 ['.$controllerPath.'] 已存在，是否覆盖写入？')) {
            //直接返回
            return true;
        }
        //获取模版内容
        $content = $this->getTplContent('controller');
        //内容存在
        if ($content && !empty($content)) {
            //设置内容
            $this->putContent($controllerPath, $content);
        }
        return true;
    }

    /**
     * 创建数据缓存
     * @Author Abnermouke <abnermouke@gmail.com>
     * @Originate in Company Yunnitec.
     * @Time 2020-04-27 20:12:43
     * @return bool
     */
    private function makeDataCache()
    {
        //询问获取缓存链接信息
        $this->tplParams['__DATA_CACHE_DRIVER__'] = $this->choice('请选择当前数据缓存储存驱动！', array_keys(config('cache.stores')), config('cache.default'));
        //整理目录
        $dataCacheDirectory = app_path('Handler/Cache/Data'.str_replace('\\', '/', $this->tplParams['__DICTIONARY__']));
        //判断目录是否存在
        if (!File::isDirectory($dataCacheDirectory)) {
            //创建目录
            File::makeDirectory($dataCacheDirectory, 0777, true, true);
        }
        //整理路径
        $dataCachePath = app_path('Handler/Cache/Data'.str_replace('\\', '/', $this->tplParams['__DICTIONARY__']).'/'.$this->tplParams['__LOWER_CASE_NAME__'].'CacheHandler.php');
        //判断文件地址
        if (file_exists($dataCachePath) && !$this->confirm('数据缓存 ['.$dataCachePath.'] 已存在，是否覆盖写入？')) {
            //直接返回
            return true;
        }
        //获取模版内容
        $content = $this->getTplContent('data_cache');
        //内容存在
        if ($content && !empty($content)) {
            //设置内容
            $this->putContent($dataCachePath, $content);
        }
        return true;
    }

    /**
     * 创建数据仓库
     * @Author Abnermouke <abnermouke@gmail.com>
     * @Originate in Company Yunnitec.
     * @Time 2020-04-27 20:12:52
     * @return bool
     */
    private function makeRepository()
    {
        //整理目录
        $repositoryDirectory = app_path('Repository'.str_replace('\\', '/', $this->tplParams['__DICTIONARY__']));
        //判断目录是否存在
        if (!File::isDirectory($repositoryDirectory)) {
            //创建目录
            File::makeDirectory($repositoryDirectory, 0777, true, true);
        }
        //整理路径
        $repositoryPath = app_path('Repository'.str_replace('\\', '/', $this->tplParams['__DICTIONARY__']).'/'.$this->tplParams['__LOWER_CASE_NAME__'].'Repository.php');
        //判断文件地址
        if (file_exists($repositoryPath) && !$this->confirm('数据仓库 ['.$repositoryPath.'] 已存在，是否覆盖写入？')) {
            //直接返回
            return true;
        }
        //获取模版内容
        $content = $this->getTplContent('repository');
        //内容存在
        if ($content && !empty($content)) {
            //设置内容
            $this->putContent($repositoryPath, $content);
        }
        return true;
    }

    /**
     * 设置文件内容
     * @Author Abnermouke <abnermouke@gmail.com>
     * @Originate in Company Yunnitec.
     * @Time 2020-04-27 20:03:54
     * @param $path string 文件绝对路径
     * @param $content string 文件内容
     * @return bool
     */
    private function putContent($path, $content)
    {
        //打开文件
        $fp = fopen($path, 'w+');
        //写入文件
        fwrite($fp, $content);
        //关闭写入事件
        fclose($fp);
        //判断文件内容
        if (!file_exists($path)) {
            //抛出错误
            $this->output->warning('文件写入失败：'.$path);
        }
        //返回成功
        return true;
    }

    /**
     * 根据内容渲染模版获取详细模版内容
     * @Author Abnermouke <abnermouke@gmail.com>
     * @Originate in Company Yunnitec.
     * @Time 2020-04-27 20:01:14
     * @param string $mode 模版类型
     * @return bool|false|mixed|string
     */
    private function getTplContent($mode = 'migration')
    {
        //获取TPL文件信息
        $tplPath = __DIR__.'/../../tpl/'.strtolower($mode).'.tpl';
        //判断模版信息是否存在
        if (!file_exists($tplPath)) {
            //抛出错误
            $this->output->warning('缺少TPL：'.$tplPath);
            //直接返回
            return false;
        }
        //获取内容
        $content = file_get_contents($tplPath);
        //循环参数
        foreach ($this->tplParams as $item => $value) {
            //替换参数
            $content = str_replace('{'.$item.'}', $value, $content);
        }
        //返回模版内容
        return $content;
    }

}