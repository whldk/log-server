<?php
include "class/db.php";
include "class/dataController.php";
include "class/beepoolController.php";
include "class/walletlogController.php";
include "class/loginCountController.php";
include "config.php";

$server = new Swoole\Http\Server('0.0.0.0', $apiPort);


$server->set(array(
    'daemonize' => true,
));


$server->session = new Swoole\Table(2048);

$server->session->column('user', Swoole\Table::TYPE_STRING, 24);
$server->session->column('lasttime', Swoole\Table::TYPE_INT, 4);
$server->session->column('logintime', Swoole\Table::TYPE_INT, 4);

$server->session->create();

// 清理过期session
$process = new Swoole\Process(function ($process) use($server) {

    while (true) {
        $now = time();
        $sessionIdSet = array();

        foreach ($server->session as $fd => $v) {
            if ($v['logintime'] < ($now - 86400) || $v['lasttime'] < ($now - 3600)) {
                $sessionIdSet[] = $fd;
            }
        }

        foreach ($sessionIdSet as $fd) {

            $server->session->del($fd);
        }

        sleep(3600);
    }
});

$server->addProcess($process);

$server->on('WorkerStart', function (Swoole\Server $server, int $workerId) {
    global $logdb, $logdbuser, $logdbpwd, $logdbname,
           $coredb, $coredbuser, $coredbpwd, $coredbname,
           $beepooldb, $beepooldbuser, $beepooldbpwd, $beepooldbname;

    // 日志数据库
    $server->logdb = new db();
    $server->logdb->init($logdb, $logdbuser, $logdbpwd, $logdbname);

    // 核心数据库
    $server->coredb = new db();
    $server->coredb->init($coredb, $coredbuser, $coredbpwd, $coredbname);

    //beepool 数据库
    $server->beepooldb = new db();
    $server->beepooldb->init($beepooldb, $beepooldbuser, $beepooldbpwd, $beepooldbname);

    $server->dataController = new dataController($server->logdb, $server->coredb);

    $server->beepoolController = new beepoolController($server->beepooldb, $server->coredb);

    $server->walletlogController = new walletlogController($server->logdb);

    $server->loginCountController = new loginCountController($server->logdb);
});

$server->on('request', function ($request, $response) {
    global $server, $userList;

    $return = array();

    $response->header('Access-Control-Allow-Origin', 'http://log.otherpool.com');
    $response->header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
    $response->header('Access-Control-Allow-Credentials', 'true');
    $response->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');

    // 判断是否有cookie，检查是否登录

    $isLogin = false;

    $sessionId = false;

    $userName = false;

    $now = time();

    if (isset($request->cookie['session_id']) && strlen(trim($request->cookie['session_id'])) == 8) {

        $sessionId = strtolower(trim($request->cookie['session_id']));

        if ($server->session->exist($sessionId)) {

            $sessionUsername = strtolower(trim($request->cookie['username']));

            $sessionInfo = $server->session->get($sessionId);

            if ($sessionUsername == $sessionInfo['user']) {
                // check time
                if ($sessionInfo['lasttime'] > ($now - 3600) && $sessionInfo['logintime'] > ($now - 86400)) {

                    $isLogin = true;
                    $username = $sessionInfo['user'];

                    $server->session->set($sessionId, array(
                        'lasttime' => $now
                    ));
                } else {
                    $server->session->del($sessionId);
                }
            } else {
                $server->session->del($sessionId);
            }
        }
    }

    list ($controller, $action) = explode('/', trim($request->server['request_uri'], '/'));

    // 预判断

    if ($isLogin === false && $controller !== 'login') {
        // 直接返回错误
        $return = array(
            'code' => 300,
            'msg' => 'Authorization is necessary.',
            'result' => false
        );

        $response->end(json_encode($return));
        return;
    }

    switch ($controller) {
        case 'main':
            switch ($action) {
                case 'user':
                    $return = array(
                        'code' => 0,
                        'msg' => 'User Info.',
                        'data' => array(
                            'nickname'=>$username,
                            'avatar'=>'http://log.otherpool.com/assets/images/head.jpg'

                        )
                    );
                    break;
            }
            break;
        case 'login':
            if (isset($request->post['username']) && isset($request->post['password'])) {

                if (isset($userList[$request->post['username']]) && $userList[$request->post['username']] == $request->post['password']) {

                    $sessionId = bin2hex(pack('N', mt_rand(268435456, 4294967295)));

                    $server->session->set($sessionId, array(
                        'user' => $request->post['username'],
                        'logintime' => $now,
                        'lasttime' => $now
                    ));

                    $response->cookie('session_id', $sessionId, $now + 86400, '/');
                    $response->cookie('username', $request->post['username'], $now + 86400, '/');

                    $return = array(
                        'code' => 0,
                        'msg' => 'Login is successful.',
                        'result' => true
                    );
                } else {

                    $return = array(
                        'code' => 400,
                        'msg' => 'Username or password is incorrect.',
                        'result' => false
                    );
                }
            } else {
                $return = array(
                    'code' => 400,
                    'msg' => 'Username and password is necessary.',
                    'result' => false
                );
            }
            break;
        case 'data':

            $return = array(
                'code' => 0,
                'msg' => 'success',
                'data' => false
            );

            switch ($action) {
                case 'sharedata':
                    $coin = isset($request->get['coin']) ? $request->get['coin'] : 'eth';
                    $time = isset($request->get['startTime']) ? $request->get['startTime'] : 0;

                    $result = $server->dataController->getShareData($coin, $time);
                    break;

                case 'logindata':
                    $coin = isset($request->get['coin']) ? $request->get['coin'] : 'eth';
                    $wallet = isset($request->get['wallet']) ? $request->get['wallet'] : '';
                    $time = isset($request->get['startTime']) ?$request->get['startTime']  : 0;

                    $result = $server->dataController->getloginData($coin, $wallet, $time);
                    break;

                case 'walletip':
                    $wallet = isset($request->get['wallet']) ? $request->get['wallet'] : '';
                    $time = isset($request->get['startTime']) ? $request->get['startTime'] : 0;

                    $result = $server->dataController->getwalletIP($wallet, $time);
                    break;
                case 'ipwallet':
                    $ip = isset($request->get['ip']) ? $request->get['ip'] : '';
                    $time = isset($request->get['startTime']) ? $request->get['startTime'] : 0;

                    $result = $server->dataController->getIpWallet($ip, $time);
                    break;
                case 'shareprocessor':
                    $time = isset($request->get['startTime']) ? $request->get['startTime'] : 0;

                    $result = $server->dataController->getShareProcessor($time);
                    break;
                case 'boardcastdata':
                    $coin = isset($request->get['coin']) ? $request->get['coin'] : 'eth';
                    $time = isset($request->get['startTime']) ? $request->get['startTime'] : 0;

                    $result = $server->dataController->getBoardcastData($coin, $time);
                    break;
                case 'blockdata':
                    $coin = isset($request->get['coin']) ? $request->get['coin'] : 'eth';
                    $time = isset($request->get['startTime']) ? $request->get['startTime'] : 0;

                    $result = $server->dataController->getBlockList($coin, $time);
                    break;
                case 'blockcounter':
                    $coin = isset($request->get['coin']) ? $request->get['coin'] : 'eth';

                    $result = $server->dataController->getBlockCounter($coin);
                    break;
                case 'toplogin':
                    $coin = isset($request->get['coin']) ? $request->get['coin'] : 'eth';

                    $time = isset($request->get['startTime']) ? $request->get['startTime'] : 0;

                    $result = $server->dataController->getTopLogin($coin, $time);
                    break;
                case 'toplargelogin':
                    $coin = isset($request->get['coin']) ? $request->get['coin'] : 'eth';

                    $time = isset($request->get['startTime']) ? $request->get['startTime'] : 0;

                    $result = $server->dataController->getTopLargeLogin($coin, $time);
                    break;
                case 'getblockbywallet':
                    $coin = isset($request->get['coin']) ? $request->get['coin'] : 'eth';
                    $wallet = trim(isset($request->get['wallet']) ? $request->get['wallet'] : '');
                    $time = isset($request->get['startTime']) ? $request->get['startTime'] : 0;

                    $result = $server->dataController->getBlockListByWallet($coin, $wallet, $time);
                    break;
                default:
                    $result = false;
                    break;
            }

            $return['result'] = $result;

            break;
        case 'beepool':
            $return = [
                'code' => 0,
                'msg' => 'success',
                'data' => false
            ];

            switch ($action) {
                case 'check-user':
                    $search = $request->get['search'] ?? null;
                    if ($search == null) $result = null;
                    $result = $server->beepoolController->CheckUser($search);
                    break;
                case 'order-category':
                    $result = $server->beepoolController->OrderCategory();
                    break;
                case 'order-list':
                    $cid = $request->get['cid'] ?? null;
                    $status = $request->get['status'] ?? null;
                    $search = $request->get['search'] ?? null;
                    $page = $request->get['page'] ?? 1;
                    $size = $request->get['size'] ?? 10;
                    $result = $server->beepoolController->OrderList($cid, $status, (string)$search, $page, $size);
                    break;
                case 'order-reply':
                    $order_id = $request->post['order_id'] ?? '';
                    $reply = $request->post['reply'] ?? '';
                    $result = $server->beepoolController->OrderReply($order_id, $reply);
                    break;
                case 'new-user':
                    $coin = $request->post['coin'] ?? 'eth';
                    $result = $server->beepoolController->DayNewUsers($coin);
                    break;
                default:
                    $result = false;
                    break;
            }

            $return['result'] = $result;
            break;
        case 'wallet':
            $return = [
                'code' => 0,
                'msg' => 'success',
                'data' => false
            ];
            switch ($action) {
                case 'rank':
                    $type_all = ['diff', 'share', 'delay', 'reject'];
                    $order_all = ['desc', 'asc'];
                    $limit_all = [1, 100, 500, 1000, 3000, 5000];
                    $coin = $request->get['coin'] ?? 'eth';
                    $type = $request->get['type'] ?? 'diff';
                    $order = $request->get['order'] ?? 'desc';
                    $limit = $request->get['limit'] ?? 100;
                    if (!in_array($type, $type_all) || !in_array($limit, $limit_all) || !in_array($order, $order_all)) {
                        $result['code'] = 400;
                        $result['msg'] = '参数错误,请检查';
                        return $return;
                    }
                    $result = $server->walletlogController->getWalletRank($coin, $type, $order, (int)$limit);
                    break;
                case 'range':
                    $coin = $request->get['coin'] ?? 'eth';
                    $result = $server->walletlogController->getWalletRange($coin);
                    break;
                case 'wave':
                    $coin = $request->get['coin'] ?? 'eth';
                    $order_field = $request->get['order_field'] ?? 'diff';
                    $order = $request->get['order'] ?? 'desc';
                    $page = $request->get['page'] ?? 1;
                    $size = $request->get['size'] ?? 100;
                    //允许排序的字段
                    $field_all = ['diff', 'share', 'reject', 'delay', 'diff24', 'share24', 'up_diff_wave', 'up_share_wave'];
                    $order_all = ['asc', 'desc'];
                    if (!in_array($order_field, $field_all) || !in_array($order, $order_all)) {
                        $result['code'] = 400;
                        $result['msg'] = '参数错误,请检查';
                        return $return;
                    }
                    $result = $server->walletlogController->getWalletWave($coin, $order_field, $order, (int)$page, (int)$size);
                    break;
                case 'history':
                    $coin = $request->get['coin'] ?? 'eth';
                    $wallet = $request->get['wallet'] ?? '';
                    if (!$wallet) {
                        $result['code'] = 400;
                        $result['msg'] = '参数错误,请检查';
                        return $return;
                    }
                    $result = $server->walletlogController->getWalletAll($coin,$wallet);
                    break;
                case  'diff_today_income':
                    $coin = $request->get['coin'] ?? 'eth';
                    $result = $server->walletlogController->getCoinDiff24($coin, [time() - 7200]);
                    break;
                case  'diff_all_income':
                    $coin = $request->get['coin'] ?? 'eth';
                    $result = $server->walletlogController->getCoinDiffAll($coin);
                    break;
                case  'diff_network':
                    $coin = $request->get['coin'] ?? 'eth';
                    $result = $server->walletlogController->coinNetworkDiff($coin);
                    break;
                case  'active_user':
                    $coin = $request->get['coin'] ?? 'eth';
                    $result = $server->walletlogController->activeUser($coin);
                    break;
                case  'active_diff':
                    $coin = $request->get['coin'] ?? 'eth';
                    $result = $server->walletlogController->activeDiff($coin);
                    break;
                case  'active_up_diff':
                    $coin = $request->get['coin'] ?? 'eth';
                    $result = $server->walletlogController->activeUpDiff($coin);
                    break;
                default:
                    $result = false;
                    break;
            }
            $return['result'] = $result;
            break;
        case 'count':
            $return = [
                'code' => 0,
                'msg' => 'success',
                'data' => false
            ];
            switch ($action) {
                case 'logincount':
                    $order_all = ['desc', 'asc'];
                    $order = $request->get['order'] ?? 'desc';
                    $coin = $request->get['coin'] ?? 'eth';
                    $page = $request->get['page'] ?? 1;
                    $size = $request->get['size'] ?? 100;
                    if (!in_array($order, $order_all)) {
                        $result['code'] = 400;
                        $result['msg'] = '参数错误,请检查';
                        return $return;
                    }
                    $result = $server->loginCountController->LoginCount($coin, $order, (int)$page, (int)$size);
                    break;
                case 'largelogincount':
                    $order_all = ['desc', 'asc'];
                    $order = $request->get['order'] ?? 'desc';
                    $coin = $request->get['coin'] ?? 'eth';
                    if (!in_array($order, $order_all)) {
                        $result['code'] = 400;
                        $result['msg'] = '参数错误,请检查';
                        return $return;
                    }
                    $result = $server->loginCountController->LargeLoginCount($coin, $order);
                    break;
                case 'last24hdata':
                    $tables = ['logincount', 'largelogincount'];
                    $coin = $request->get['coin'] ?? 'eth';
                    $table = $request->get['name'] ?? 'name';
                    if (!in_array($table, $tables)) {
                        $result['code'] = 400;
                        $result['msg'] = '参数错误,请检查';
                        return $return;
                    }
                    $result = $server->loginCountController->Last24hData($coin, $table);
                    break;
                case 'walletdata':
                    $tables = ['logincount', 'largelogincount'];
                    $coin = $request->get['coin'] ?? 'eth';
                    $table = $request->get['name'] ?? 'name';
                    $wallet = $request->get['wallet'] ?? '';
                    if (!in_array($table, $tables)) {
                        $result['code'] = 400;
                        $result['msg'] = '参数错误,请检查';
                        return $return;
                    }
                    $result = $server->loginCountController->WalletData($coin, $wallet, $table);
                    break;
            }
            $return['result'] = $result;
            break;
        default:
            break;
    }

    $response->end(json_encode($return));
});

$server->start();
