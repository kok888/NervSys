<?php

/**
 * Operator Handler
 *
 * Copyright 2016-2019 Jerry Shaw <jerry-shaw@live.com>
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

namespace core\handler;

use core\parser\data;
use core\parser\trustzone;

class operator extends factory
{
    /**
     * Initialize operator
     */
    public static function init(): void
    {
        try {
            //Build initial dependency
            parent::build_dep(self::$init);

            //Run dependency
            foreach (self::$init as $item) {
                self::build_caller(...$item);
            }

            unset($item);
        } catch (\Throwable $throwable) {
            error::exception_handler(new \Exception($throwable->getMessage(), E_USER_ERROR));
            unset($throwable);
        }
    }

    /**
     * Run CGI process
     */
    public static function run_cgi(): void
    {
        //Process orders
        while (!is_null($item_list = array_shift(parent::$cmd_cgi))) {
            //Get name & class
            $class = parent::build_name($name = array_shift($item_list));

            try {
                //Process dependency
                if (false !== strpos($module = strtr($name, '\\', '/'), '/')) {
                    $module = strstr($module, '/', true);
                }

                if (isset(parent::$load[$module])) {
                    $dep_list = is_string(parent::$load[$module]) ? [parent::$load[$module]] : parent::$load[$module];

                    //Build dependency
                    parent::build_dep($dep_list);

                    //Call dependency
                    foreach ($dep_list as $dep) {
                        self::build_caller(...$dep);
                    }

                    unset(parent::$load[$module], $dep_list, $dep);
                }

                //Check & load class
                if (!class_exists($class, false) && !self::load_class($class)) {
                    //Class NOT exist
                    continue;
                }

                //Check TrustZone permission
                if (empty($tz_list = trustzone::init($class))) {
                    //TrustZone NOT open
                    continue;
                }

                //Get method list
                $method_list = empty($item_list) ? $tz_list : array_intersect($item_list, $tz_list);
                unset($module, $tz_list, $item_list);

                //Process target method
                foreach ($method_list as $method) {
                    //Get TrustZone data
                    $tz_dep = trustzone::get_dep($method);

                    //Build pre/post dependency
                    parent::build_dep($tz_dep['pre']);
                    parent::build_dep($tz_dep['post']);

                    //Call pre dependency
                    foreach ($tz_dep['pre'] as $tz_item) {
                        self::build_caller(...$tz_item);
                    }

                    //Verify TrustZone params
                    trustzone::verify($class, $method);

                    //Build target caller
                    self::build_caller($name, $class, $method);

                    //Call post dependency
                    foreach ($tz_dep['post'] as $tz_item) {
                        self::build_caller(...$tz_item);
                    }
                }
            } catch (\Throwable $throwable) {
                error::exception_handler($throwable);
                unset($throwable);
            }
        }

        unset($item_list, $class, $name, $method_list, $method, $tz_dep, $tz_item);
    }

    /**
     * Run CLI process
     */
    public static function run_cli(): void
    {
        //Process orders
        while (!is_null($item_list = array_shift(parent::$cmd_cli))) {
            try {
                //Prepare command
                $command = '"' . $item_list['cmd'] . '"';

                //Append arguments
                if (isset($item_list['argv'])) {
                    $command .= $item_list['argv'];
                }

                //Create process
                $process = proc_open(
                    platform::cmd_proc($command),
                    [
                        ['pipe', 'r'],
                        ['pipe', 'w'],
                        ['file', ROOT . 'logs' . DIRECTORY_SEPARATOR . 'error_cli_' . date('Y-m-d') . '.log', 'a']
                    ],
                    $pipes
                );

                if (!is_resource($process)) {
                    throw new \Exception($item_list['key'] . '=>' . $item_list['cmd'] . ': Access denied or command ERROR!', E_USER_WARNING);
                }

                //Send data via pipe
                if (isset($item_list['pipe'])) {
                    fwrite($pipes[0], $item_list['pipe']);
                }

                //Collect result
                if ($item_list['ret'] && '' !== $data = self::read_pipe([$process, $pipes[1]], $item_list['time'])) {
                    parent::$result[$item_list['key']] = &$data;
                    unset($data);
                }

                //Close pipes (ignore process)
                foreach ($pipes as $pipe) {
                    fclose($pipe);
                }
            } catch (\Throwable $throwable) {
                error::exception_handler($throwable);
                unset($throwable);
            }
        }

        unset($item_list, $command, $process, $pipes, $pipe);
    }

    /**
     * Load class file
     *
     * @param string $class
     *
     * @return bool
     */
    private static function load_class(string $class): bool
    {
        $load = false;
        $file = trim(strtr($class, '\\', DIRECTORY_SEPARATOR), DIRECTORY_SEPARATOR) . '.php';
        $list = false !== strpos($file, DIRECTORY_SEPARATOR) ? [ROOT] : parent::$path;

        foreach ($list as $path) {
            if (is_string($path = realpath($path . $file))) {
                //Require script file
                require $path;

                //Check class status
                if ($load = class_exists($class, false)) {
                    break;
                }
            }
        }

        unset($class, $file, $list, $path);
        return $load;
    }

    /**
     * Build mapped key
     *
     * @param string $class
     * @param string $method
     *
     * @return string
     */
    private static function build_key(string $class, string $method): string
    {
        $key = parent::$param_cgi[$class . '-' . $method] ?? (parent::$param_cgi[$class] ?? $class) . '/' . $method;

        unset($class, $method);
        return $key;
    }

    /**
     * Build method caller
     *
     * @param string $order
     * @param string $class
     * @param string $method
     *
     * @throws \ReflectionException
     */
    private static function build_caller(string $order, string $class, string $method): void
    {
        //Get reflection
        $reflect = parent::reflect($class, $method);

        //Call constructor
        if ('__construct' === $method) {
            parent::obtain($class, data::build_argv($reflect, parent::$data));
            unset($order, $class, $method, $reflect);
            return;
        }

        //Using class object
        if (!$reflect->isStatic()) {
            $class = method_exists($class, '__construct')
                ? parent::obtain($class, data::build_argv(parent::reflect($class, '__construct'), parent::$data))
                : parent::obtain($class);
        }

        //Call method (with params)
        $result = !empty($params = data::build_argv($reflect, parent::$data))
            ? forward_static_call_array([$class, $method], $params)
            : forward_static_call([$class, $method]);

        //Save result (Try mapping keys)
        if (isset($result)) {
            parent::$result[self::build_key($order, $method)] = &$result;
        }

        unset($order, $class, $method, $reflect, $params, $result);
    }

    /**
     * Get stream content
     *
     * @param array $process
     * @param int   $timeout
     *
     * @return string
     */
    private static function read_pipe(array $process, int $timeout): string
    {
        $timer  = 0;
        $result = '';

        while (0 === $timeout || $timer <= $timeout) {
            if (proc_get_status($process[0])['running']) {
                usleep(1000);
                $timer += 1000;
            } else {
                $result = trim(stream_get_contents($process[1]));
                break;
            }
        }

        unset($process, $timeout, $timer);
        return $result;
    }
}