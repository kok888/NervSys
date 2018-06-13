<?php

/**
 * Redis Queue Extension
 *
 * Copyright 2016-2018 秋水之冰 <27206617@qq.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace ext;

use core\handler\observer;
use core\handler\operator;
use core\handler\platform;

use core\helper\log;

use core\parser\data;

use core\pool\config;

class redis_queue extends redis
{
    //Expose "start" & "process"
    public static $tz = [
        'start'   => ['pre' => ['ext/redis_queue-chk_cli']],
        'process' => ['pre' => ['ext/redis_queue-chk_cli']]
    ];

    //Queue failed key (list)
    public static $key_fail = 'queue:fail';

    //Queue list prefix (list)
    public static $prefix_list = 'queue:list:';

    //Queue process prefix (kv)
    public static $prefix_process = 'queue:process:';

    //Queue watch keys (hash)
    public static $key_watch_list    = 'queue:watch:list';
    public static $key_watch_process = 'queue:watch:process';

    //Max child processes
    public static $max_runs = 5;

    //Max executed counts
    public static $max_execute = 200;

    //Process idle wait (in seconds)
    public static $idle_wait = 3;

    //Queue scan wait (in seconds)
    public static $scan_wait = 60;

    //Process command
    private static $command = '';

    /**
     * Add queue
     * Caution: Do NOT expose "add" to TrustZone directly
     *
     * @param string $key
     * @param string $cmd
     * @param array  $data
     *
     * @return int
     * @throws \Exception
     */
    public static function add(string $key, string $cmd, array $data): int
    {
        //Add command
        $data['cmd'] = &$cmd;

        //Build list key
        $list = self::$prefix_list . $key;

        //Add to watch list & queue list
        self::connect()->hSet(self::$key_watch_list, $list, time());
        $add = (int)self::connect()->lPush($list, json_encode($data));

        unset($key, $cmd, $data, $list);
        return $add;
    }

    /**
     * Stop queue
     * Caution: Do NOT expose "stop" to TrustZone directly
     *
     * @param string $key
     *
     * @return int
     * @throws \Exception
     */
    public static function stop(string $key = ''): int
    {
        $process = '' === $key ? array_keys(self::show_process()) : [self::$prefix_process . $key];

        if (empty($process)) {
            return 0;
        }

        $result = call_user_func_array([self::connect(), 'del'], $process);

        array_unshift($process, self::$key_watch_process);
        call_user_func_array([self::connect(), 'hDel'], $process);

        unset($key, $process);
        return $result;
    }

    /**
     * Show fail list
     *
     * @param int $start
     * @param int $end
     *
     * @return array
     * @throws \Exception
     */
    public static function show_fail(int $start = 0, int $end = -1): array
    {
        $list = [];

        $list['len']  = self::connect()->lLen(self::$key_fail);
        $list['data'] = self::connect()->lRange(self::$key_fail, $start, $end);

        unset($start, $end);
        return $list;
    }

    /**
     * Show queue list
     *
     * @return array
     * @throws \Exception
     */
    public static function show_queue(): array
    {
        return self::get_keys(self::$key_watch_list);
    }

    /**
     * Show process list
     *
     * @return array
     * @throws \Exception
     */
    public static function show_process(): array
    {
        return self::get_keys(self::$key_watch_process);
    }

    /**
     * Check CLI mode
     */
    public static function chk_cli(): void
    {
        //Only support CLI
        if (config::$IS_CGI) {
            log::info('Redis queue only support CLI!');
            observer::send(1);
        }
    }

    /**
     * Start root process
     *
     * @throws \Exception
     */
    public static function start(): void
    {
        //Root process key
        $root_key = self::$prefix_process . 'root';

        //Exit when root process is running
        if (self::connect()->exists($root_key)) {
            exit;
        }

        //Set lifetime
        $time_wait = (int)(self::$scan_wait / 2);
        self::connect()->set($root_key, time(), self::$scan_wait);

        //Add to watch list
        self::connect()->hSet(self::$key_watch_process, $root_key, time());

        //Stop on shutdown
        register_shutdown_function([__CLASS__, 'stop']);

        //Build process command
        $command = [
            platform::sys_path(),
            ROOT . 'api.php',
            '--cmd "' . strtr(__CLASS__, '\\', '/') . '-process"',
            '--ret'
        ];

        self::$command = platform::cmd_bg(implode(' ', $command));

        unset($command);

        do {
            //Read list
            $list = self::show_queue();

            //Read process
            $runs = count(self::show_process());

            //Idle wait on no job or process runs
            if (empty($list) || 1 < $runs) {
                //Renew root process
                $renew = self::connect()->expire($root_key, self::$scan_wait);
                sleep(self::$idle_wait);
                continue;
            }

            //Listen
            $queue = self::connect()->brPop(array_keys($list), $time_wait);

            if (empty($queue)) {
                //Renew root process
                $renew = self::connect()->expire($root_key, self::$scan_wait);
                sleep(self::$idle_wait);
                continue;
            }

            //Call process
            self::call_process();

            //Re-add queue
            self::connect()->rPush($queue[0], $queue[1]);

            //Renew root process
            $renew = self::connect()->expire($root_key, self::$scan_wait);
        } while ($renew);

        //On exit
        self::stop();

        unset($root_key, $time_wait, $list, $runs, $renew, $queue);
    }

    /**
     * Start child process
     *
     * @throws \Exception
     */
    public static function process(): void
    {
        //Process Hash & Key
        $process_hash = hash('md5', uniqid(mt_rand(), true));
        $process_key  = self::$prefix_process . $process_hash;

        //Set timeout & lifetime
        $time_wait = (int)(self::$scan_wait / 2);
        self::connect()->set($process_key, 0, self::$scan_wait);

        //Add to watch list
        self::connect()->hSet(self::$key_watch_process, $process_key, time());

        //Stop on shutdown
        register_shutdown_function([__CLASS__, 'stop'], $process_hash);

        do {
            //Get queue list
            $list = self::show_queue();

            //Exit on no job
            if (empty($list)) {
                break;
            }

            //Listen
            $queue = self::connect()->brPop(array_keys($list), $time_wait);

            //Execute job
            if (!empty($queue)) {
                self::exec_queue($queue[1]);
                self::connect()->lRem($queue[0], $queue[1]);
            }

            //Check status & renew process
            $exist = self::connect()->exists($process_key);
            $renew = self::connect()->expire($process_key, self::$scan_wait);

            //Count executed jobs
            $executed = $exist ? self::connect()->incr($process_key) : self::$max_execute;
        } while ($exist && $renew && $executed < self::$max_execute);

        //On exit
        self::stop($process_hash);

        unset($process_hash, $process_key, $time_wait, $list, $queue, $exist, $renew, $executed);
    }

    /**
     * Get active keys
     *
     * @param string $key
     *
     * @return array
     * @throws \Exception
     */
    private static function get_keys(string $key): array
    {
        if (!self::connect()->exists($key)) {
            return [];
        }

        $keys = self::connect()->hGetAll($key);

        if (empty($keys)) {
            return [];
        }

        foreach ($keys as $k => $v) {
            if (!self::connect()->exists($k)) {
                self::connect()->hDel($key, $k);
                unset($keys[$k]);
            }
        }

        unset($key, $k, $v);
        return $keys;
    }

    /**
     * Execute queue
     *
     * @param string $data
     *
     * @throws \Exception
     */
    private static function exec_queue(string $data): void
    {
        //Decode data in JSON
        $input = json_decode($data, true);

        if (!is_array($input)) {
            self::connect()->lPush(self::$key_fail, json_encode(['data' => &$data, 'return' => 'Data ERROR!']));

            return;
        }

        //Get order & class & method
        list($order, $method) = explode('-', $input['cmd'], 2);
        $class = '\\' . ltrim(strtr($order, '/', '\\'), '\\');

        //Call LOAD commands
        $load_name = strstr($order, '/', true);

        if (isset(config::$LOAD[$load_name])) {
            operator::init_load(is_string(config::$LOAD[$load_name]) ? [config::$LOAD[$load_name]] : config::$LOAD[$load_name]);
        }

        try {
            //Reflection method
            $reflect = new \ReflectionMethod($class, $method);

            //Not public
            if (!$reflect->isPublic()) {
                self::connect()->lPush(self::$key_fail, json_encode(['data' => &$data, 'return' => 'NOT for public!']));

                return;
            }

            //Build arguments
            $params = data::build_argv($reflect, $input);

            //Create object
            if (!$reflect->isStatic()) {
                $class = unit::$object[$order] ?? unit::$object[$order] = new $class;
            }

            //Call method (with params)
            $result = empty($params) ? forward_static_call([$class, $method]) : forward_static_call_array([$class, $method], $params);

            //Check result
            self::chk_queue($data, json_encode($result));
        } catch (\Throwable $throwable) {
            self::connect()->lPush(self::$key_fail, json_encode(['data' => &$data, 'return' => $throwable->getMessage()]));
            unset($throwable);
        }

        unset($data, $input, $order, $method, $class, $load_name, $reflect, $params, $result);
    }

    /**
     * Check queue
     * Only empty & true are considerable
     *
     * @param string $data
     * @param string $result
     *
     * @throws \Exception
     */
    private static function chk_queue(string $data, string $result): void
    {
        //Decode result
        $json = json_decode($result, true);

        //Accept empty & true
        if (is_null($json) || true === $json) {
            return;
        }

        //Save fail list
        self::connect()->lPush(self::$key_fail, json_encode(['data' => &$data, 'return' => &$result]));

        unset($data, $result, $json);
    }

    /**
     * Call processes
     *
     * @throws \Exception
     */
    private static function call_process(): void
    {
        //Count running processes
        $runs = count(self::show_process());
        $left = self::$max_runs - $runs + 1;

        if (0 >= $left) {
            return;
        }

        //Read queue list
        $queue = self::show_queue();

        //Count jobs
        $jobs = 0;
        foreach ($queue as $key => $value) {
            $jobs += self::connect()->lLen($key);
        }

        if (0 === $jobs) {
            return;
        }

        //Count need processes
        $need = (int)(ceil($jobs / self::$max_execute) - $runs + 1);

        if ($need > $left) {
            $need = &$left;
        }

        //Call child processes
        for ($i = 0; $i < $need; ++$i) {
            pclose(popen(self::$command, 'r'));
        }

        unset($runs, $left, $queue, $jobs, $key, $value, $need, $i);
    }
}