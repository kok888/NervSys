<?php

/**
 * Setting Pool
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

namespace core\pool;

class setting extends process
{
    //Output format
    public static $out = '';

    //Runtime values
    public static $is_cli   = true;
    public static $is_https = true;

    //Error reporting
    protected static $err = 0;

    //System settings
    protected static $log  = [];
    protected static $cgi  = [];
    protected static $cli  = [];
    protected static $cors = [];
    protected static $init = [];
    protected static $load = [];
    protected static $path = [];
}