<?php
class beepoolController
{
    private $db = null;
    private $coredb = null;

    public function __construct($db, $coredb)
    {
        $this->db = $db;
        $this->coredb = $coredb;
    }

    /**
     * 反馈问题分类
     * @return array
     */
    public function OrderCategory()
    {
        $result = $this->db->select('`cat_id`, `cat_name`')
                ->table('`order_category`')
                ->where(['status' => 1])
                ->order('`cat_id` asc')
                ->queryAll();
        return $result ?: [];
    }

    /**
     * 反馈列表
     * @param null $cid
     * @param null $status
     * @param string|null $search
     * @param int $page
     * @param int $size
     * @return array
     */
    public function OrderList($cid = null, $status = null, string $search = null ,int $page = 1, int $size = 10) : array
    {
        $where = [];

        if (!empty($cid)) {
            $where['cat_id'] = (int)$cid;
        }

        if ($status != null) {
            $where['status'] = (int)$status;
        }

        if (!empty($search)) {
            $search = $this->db->real_escape_string($search);
            $user = $this->db->select('`user_id`')
                ->table('`user`')
                ->where( ' `email` = ' . $search . ' or `phone` = ' . $search)
                ->queryAll();
            if (isset($user[0]['user_id']) && is_array($user)) {
                $where['user_id'] = (int)$user[0]['user_id'];
            } else {
                //没有检索到的用户直接返回空
                return [];
            }
        }

        //计算分页起始
        $offset = ($page-1) * $size;
        if ($offset < 0) $offset = 0;
        if ($size < 0 || $size > 100) $size = 10;

        //计算分页总数
        $this->db->select('count(*) as count')->table('`order`');
        if ($where) $this->db->where($where);
        $count = $this->db->queryAll();

        if (isset($count[0]['count']) && $count[0]['count'] > 0) {
            $result = [
                'page' => $page,
                'page_size' => $size,
                'total' => (int)$count[0]['count'],
                'total_size' => ceil($count[0]['count'] / $size)
            ];
        } else {
            //如果未匹配则直接返回
            return [];
        }

        //获取列表详细
        $this->db->select('*')->table('`order`');
        if ($where) $this->db->where($where);
        $this->db->order('`order_id` desc');
        $this->db->limit($size, $offset);
        $result['_list'] = $this->db->queryAll();


        if (is_array($result['_list']) && count($result['_list']) > 0) {
            //加入用户信息
            $uid = array_unique(array_column($result['_list'], 'user_id'));
            $user_info = $this->db->select('`user_id`, `email`, `phone`')
                ->table('`user`')
                ->where_in('user_id', $uid)
                ->queryAll();

            foreach ($user_info as $info) {
                array_walk($result['_list'], function(&$v) use ($info) {
                    if ($v['user_id'] === $info['user_id']) $v += $info;
                });
            }

        }

        return $result ?: [];
    }

    /**
     * 回复反馈消息
     * @param string $order_id
     * @param string $reply
     * @return bool
     */
    public function OrderReply(string $order_id, string $reply) : bool
    {
        if ($order_id == '' || $reply == '') {
            return 0;
        }
        $reply = htmlspecialchars($reply);
        $result = $this->db->update(['reply' => $reply, 'status' => 1])
            ->table('`order`')
            ->where(['order_id' => $order_id])
            ->queryAll();
        return $result;
    }

    /**
     * 检测用户是否存在
     * @param string $union_search
     * @return bool
     */
    public function CheckUser(string $union_search) : bool
    {
        $real_union_search = $this->db->real_escape_string($union_search);
        $result = $this->db->select('`user_id`')
            ->table('`user`')
            ->where('`name` = ' . $real_union_search . ' or `email` = '. $real_union_search . ' or `phone` = ' . $real_union_search)
            ->queryAll();

        if ($result) return 1;

       $result = $this->db->select('`user_id`')
           ->table('`account`')
           ->where(['name' => $union_search])
           ->queryAll();

        return $result ? 1 : 0;
    }

    /**
     * 获取过去三十天的用户新增情况
     * @param $coin
     * @return array
     */
    public function DayNewUsers($coin)
    {
        $time = time() - 86400 * 30;
        $sql = "SELECT COUNT(`wallet`) AS `count`,FROM_UNIXTIME(created_at, '%Y-%m-%d') AS `days` 
        FROM `accounts` WHERE `created_at` > {$time} and `coin` = '{$coin}' GROUP BY `days`";
        $result = $this->coredb->query($sql);
        if (is_array($result) && count($result)) {
            $counts = array_column($result, 'count');
            $pie = [
                'total' => array_sum($counts),
                'x' => array_column($result, 'days'),
                'y' => $counts
            ];
            return $pie;
        }
        return [];
    }

}

