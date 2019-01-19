<?php

/**
 * Redis Queue Extension
 *
 * Copyright 2016-2019 秋水之冰 <27206617@qq.com>
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

use core\parser\data;

use core\handler\platform;

class redis_queue extends redis
{
    //Expose "root" & "child"
    public static $tz = 'root,child';

    //Child resources
    private $redis   = null;
    private $child   = '';
    private $reflect = [];

    //Process properties
    protected $runs = 10;
    protected $exec = 200;

    //Wait properties
    const WAIT_IDLE = 3;
    const WAIT_SCAN = 60;

    //Queue keys
    const KEY_FAILED       = 'RQ:fail';
    const KEY_WATCH_LIST   = 'RQ:watch:list';
    const KEY_WATCH_WORKER = 'RQ:watch:worker';

    //Queue key prefix
    const PREFIX_CMD    = 'RQ:cmd:';
    const PREFIX_LIST   = 'RQ:list:';
    const PREFIX_WORKER = 'RQ:worker:';

    /**
     * Add job
     * Caution: Do NOT expose "add" to TrustZone directly
     *
     * @param string $cmd
     * @param array  $data
     * @param string $group
     * @param int    $duration
     *
     * @return int
     * @throws \RedisException
     */
    public function add(string $cmd, array $data = [], string $group = '', int $duration = 0): int
    {
        //Add command
        $data['cmd'] = &$cmd;

        //Build connection
        $redis = parent::connect();

        //Check duration
        if (0 < $duration) {
            //Check job duration
            if (!$redis->setnx($cmd_key = self::PREFIX_CMD . hash('crc32b', $cmd), '')) {
                return 0;
            }

            //Set duration life
            $redis->expire($cmd_key, $duration);
            unset($cmd_key);
        }

        //Build group key & queue data
        $list  = self::PREFIX_LIST . ('' === $group ? 'main' : $group);
        $queue = json_encode($data);

        //Add watch list & queue list
        $redis->hSet(self::KEY_WATCH_LIST, $list, time());
        $result = 0 < $redis->lRem($list, $queue) ? $redis->rPush($list, $queue) : $redis->lPush($list, $queue);

        unset($cmd, $data, $group, $duration, $redis, $list, $queue);
        return (int)$result;
    }

    /**
     * Close process
     * Caution: Do NOT expose "close" to TrustZone directly
     *
     * @param string $key
     *
     * @return int
     * @throws \RedisException
     */
    public function close(string $key = ''): int
    {
        //Build connection
        $redis = parent::connect();

        //Get process list
        $process = '' === $key ? array_keys($this->show_process()) : [self::PREFIX_WORKER . $key];

        if (empty($process)) {
            return 0;
        }

        $result = call_user_func_array([$redis, 'del'], $process);

        array_unshift($process, self::KEY_WATCH_WORKER);
        call_user_func_array([$redis, 'hDel'], $process);

        unset($key, $redis, $process);
        return $result;
    }

    /**
     * Show fail list
     *
     * @param int $start
     * @param int $end
     *
     * @return array
     * @throws \RedisException
     */
    public function show_fail(int $start = 0, int $end = -1): array
    {
        $list = [];

        //Build connection
        $redis = parent::connect();

        //Read failed list
        $list['len']  = $redis->lLen(self::KEY_FAILED);
        $list['data'] = $redis->lRange(self::KEY_FAILED, $start, $end);

        unset($start, $end, $redis);
        return $list;
    }

    /**
     * Show queue list
     *
     * @return array
     * @throws \RedisException
     */
    public function show_queue(): array
    {
        return $this->get_keys(self::KEY_WATCH_LIST);
    }

    /**
     * Show process list
     *
     * @return array
     * @throws \RedisException
     */
    public function show_process(): array
    {
        return $this->get_keys(self::KEY_WATCH_WORKER);
    }

    /**
     * Start root process
     *
     * @param int $runs
     * @param int $exec
     *
     * @throws \RedisException
     */
    public function root(int $runs = 10, int $exec = 200): void
    {
        //Detect running mode
        if (!parent::$is_cli) {
            throw new \Exception('Redis queue only supports CLI!', E_USER_ERROR);
        }

        //Build connection
        $redis = parent::connect();

        //Build root process key
        $root_key = self::PREFIX_WORKER . 'root';

        //Exit when root process is running
        if (0 < $redis->exists($root_key)) {
            exit;
        }

        if (0 < $runs) {
            $this->runs = &$runs;
        }

        if (0 < $exec) {
            $this->exec = &$exec;
        }

        unset($runs, $exec);

        //Set process life
        $wait_time = (int)(self::WAIT_SCAN / 2);
        $root_hash = hash('crc32b', uniqid(mt_rand(), true));

        $redis->set($root_key, $root_hash, self::WAIT_SCAN);

        //Add to watch list
        $redis->hSet(self::KEY_WATCH_WORKER, $root_key, time());

        //Close on shutdown
        register_shutdown_function([$this, 'close']);

        //Build child command
        $this->child = platform::cmd_bg(
            platform::sys_path() . ' '
            . ROOT . 'api.php --ret --cmd "'
            . strtr(__CLASS__, '\\', '/') . '-child"'
        );

        do {
            //Get process status
            $valid   = $redis->get($root_key) === $root_hash;
            $running = $redis->expire($root_key, self::WAIT_SCAN);

            //Idle wait on no job or child process running
            if (empty($list = $this->show_queue()) || 1 < count($this->show_process())) {
                sleep(self::WAIT_IDLE);
                continue;
            }

            //Idle wait on no job
            if (empty($queue = $redis->brPop(array_keys($list), $wait_time))) {
                sleep(self::WAIT_IDLE);
                continue;
            }

            //Re-add queue job
            $redis->rPush($queue[0], $queue[1]);
            //Call child process
            $this->call_child();
        } while ($valid && $running);

        //On exit
        self::close();

        unset($redis, $root_key, $wait_time, $root_hash, $valid, $running, $list, $queue);
    }

    /**
     * Start child process
     *
     * @throws \RedisException
     */
    public function child(): void
    {
        //Detect running mode
        if (!parent::$is_cli) {
            throw new \Exception('Redis queue only supports CLI!', E_USER_ERROR);
        }

        //Build connection
        $this->redis = parent::connect();

        //Build Hash & Key
        $child_hash = hash('crc32b', uniqid(mt_rand(), true));
        $child_key  = self::PREFIX_WORKER . $child_hash;

        //Set process life
        $wait_time = (int)(self::WAIT_SCAN / 2);
        $this->redis->set($child_key, '', self::WAIT_SCAN);

        //Add to watch list
        $this->redis->hSet(self::KEY_WATCH_WORKER, $child_key, time());

        //Close on exit
        register_shutdown_function([$this, 'close'], $child_hash);

        $execute = 0;

        do {
            //Exit on no job
            if (empty($list = $this->show_queue())) {
                break;
            }

            //Execute job
            if (!empty($queue = $this->redis->brPop(array_keys($list), $wait_time))) {
                self::exec_job($queue[1]);
            }
        } while (0 < $this->redis->exists($child_key) && $this->redis->expire($child_key, self::WAIT_SCAN) && ++$execute < $this->exec);

        //On exit
        self::stop($child_hash);

        unset($child_hash, $child_key, $wait_time, $execute, $list, $queue);
    }

    /**
     * Get active keys
     *
     * @param string $key
     *
     * @return array
     * @throws \RedisException
     */
    private function get_keys(string $key): array
    {
        $redis = parent::connect();

        if (0 === $redis->exists($key)) {
            return [];
        }

        if (empty($keys = $redis->hGetAll($key))) {
            return [];
        }

        foreach ($keys as $k => $v) {
            if (0 === $redis->exists($k)) {
                $redis->hDel($key, $k);
                unset($keys[$k]);
            }
        }

        unset($key, $k, $v);
        return $keys;
    }

    /**
     * Execute job
     *
     * @param string $data
     */
    private function exec_job(string $data): void
    {
        try {
            //Decode data in JSON
            if (!is_array($input = json_decode($data, true))) {
                throw new \Exception('Data ERROR!', E_USER_WARNING);
            }

            //Check command
            if (false === strpos($input['cmd'], '-')) {
                throw new \Exception('Command [' . $input['cmd'] . '] ERROR!', E_USER_WARNING);
            }

            //Job list
            $job_list = [];

            //Get order & method
            list($order, $method) = explode('-', $input['cmd'], 2);

            //Process dependency
            if (false !== strpos($module = strtr($order, '\\', '/'), '/')) {
                $module = strstr($module, '/', true);
            }

            if (isset(parent::$load[$module])) {
                $dep_list = is_string(parent::$load[$module]) ? [parent::$load[$module]] : parent::$load[$module];

                //Build dependency
                parent::build_dep($dep_list);

                //Save to job list
                foreach ($dep_list as $dep) {
                    $job_list[] = [$dep[1], $dep[2]];
                }

                unset($dep_list, $dep);
            }

            //Save class & method
            $job_list[] = [parent::build_name($order), $method];

            //Execute jobs
            foreach ($job_list as $job) {
                //Get reflection
                $reflect = $this->reflect_method($job[0], $job[1]);

                //Call constructor
                if ('__construct' === $job[1]) {
                    parent::obtain($job[0], data::build_argv($reflect, $input));
                    continue;
                }

                //Using class object
                if (!$reflect->isStatic()) {
                    $job[0] = method_exists($job[0], '__construct')
                        ? parent::obtain($job[0], data::build_argv($this->reflect_method($job[0], '__construct'), $input))
                        : parent::obtain($job[0]);
                }

                //Call method (with params)
                $result = !empty($params = data::build_argv($reflect, $input))
                    ? forward_static_call_array([$job[0], $job[1]], $params)
                    : forward_static_call([$job[0], $job[1]]);

                //Check result
                self::check_job($data, json_encode($result));
            }
        } catch (\Throwable $throwable) {
            $this->redis->lPush(self::KEY_FAILED, json_encode(['data' => &$data, 'return' => $throwable->getMessage()]));
            unset($throwable);
            return;
        }

        unset($data, $input, $job_list, $order, $method, $module, $job, $reflect, $params, $result);
    }

    /**
     * Reflect method
     * Store for process
     *
     * @param string $class
     * @param string $method
     *
     * @return \ReflectionMethod
     * @throws \ReflectionException
     */
    private function reflect_method(string $class, string $method): \ReflectionMethod
    {
        //Generate hash key
        $hash_key = hash('crc32b', $command = $class . '::' . $method);

        //Return when exist
        if (isset($this->reflect[$hash_key])) {
            return $this->reflect[$hash_key];
        }

        //Get method reflection
        $reflect = new \ReflectionMethod($class, $method);

        //Check method visibility
        if (!$reflect->isPublic()) {
            throw new \ReflectionException($command . ': NOT for public!', E_USER_WARNING);
        }

        //Store for process
        $this->reflect[$hash_key] = &$reflect;

        unset($class, $method, $command, $hash_key);
        return $reflect;
    }

    /**
     * Check job
     * Only accept null & true
     *
     * @param string $data
     * @param string $result
     *
     * @throws \RedisException
     */
    private function check_job(string $data, string $result): void
    {
        //Decode result
        $json = json_decode($result, true);

        //Save to fail list
        if (!is_null($json) && true !== $json) {
            parent::connect()->lPush(self::KEY_FAILED, json_encode(['data' => &$data, 'return' => &$result]));
        }

        unset($data, $result, $json);
    }

    /**
     * Call child process
     *
     * @throws \RedisException
     */
    private function call_child(): void
    {
        //Count running processes
        $runs = count($this->show_process());

        if (0 >= $left = $this->runs - $runs + 1) {
            return;
        }

        //Read queue list
        $queue = $this->show_queue();

        //Count jobs
        $jobs  = 0;
        $redis = parent::connect();
        foreach ($queue as $key => $item) {
            $jobs += $redis->lLen($key);
        }

        //Exit on no job
        if (0 === $jobs) {
            return;
        }

        //Count need processes
        if ($left < $need = (ceil($jobs / $this->exec) - $runs + 1)) {
            $need = &$left;
        }

        //Call child processes
        for ($i = 0; $i < $need; ++$i) {
            pclose(popen($this->child, 'r'));
        }

        unset($runs, $left, $queue, $jobs, $redis, $key, $item, $need, $i);
    }
}