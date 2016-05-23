<?php
namespace yii\helpers;
use Thread;
use Redis;
use Yii;
/**
 * 爬虫
 * 单行抓取类 
 * @author alibeibei
 *        
 */
class Crawler extends Thread
{

    private $_curl;

    private $_timeout = 30;
    
    public $id;
    
    public $runing = false;
    
    public $rules;
    
    public $task_url;
    
    public $task_id=null;
    
    
    //REDIS服务主机IP
    private $_HOST = null;
    
    //redis服务端口
    private $_PORT = null;
    
    //连接时长 默认为0 不限制时长
    private $_TIMEOUT = 0;
    
    //数据库名
    private $_DBNAME = null;
    
    //连接类型 1普通连接 2长连接
    private $_CTYPE = 1;
    
    //实例名
    public $_REDIS = null;
    
    //事物对象
    private $_TRANSCATION = null;
    
    /**
     * 初始化
     */
    public function __construct($id)
    {
        $this->id = $id;
        $this->runing = true;
    }
    
    public function getRedis(){
        $this->_HOST ='localhost';
        $this->_PORT = '6379';
        $this->_TIMEOUT = 0;
        $this->_DBNAME = null;
        $this->_CTYPE = 1;
        if (!isset($this->_REDIS)) {
            $this->_REDIS = new Redis();
        
            $this->connect($this->_HOST, $this->_PORT, $this->_TIMEOUT, $this->_DBNAME, $this->_CTYPE);
        }
    }
     
    /**
     * 连接redis服务器
     */
    private function connect($host,$port,$timeout,$dbname,$type)
    {
        switch ($type) {
            case 1:
                $this->_REDIS->connect($host, $port, $timeout);
                break;
            case 2:
                $this->_REDIS->pconnect($host, $port, $timeout);
                break;
            default:
                break;
        }
    }
    
    /**
     * curl
     * @param string $url
     * @param string $refer_str
     * @param string $user_agent_str
     * @param string $post_data_str
     * @param string $cookie_str
     * @param number $is_need_head
     */
    public function curl($url,$refer_str = '', $user_agent_str = '', $post_data_str = '', $cookie_str = '', $is_need_head = 0){
        
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        if ($refer_str != '') {
            curl_setopt($curl, CURLOPT_REFERER, $refer_str);
        }
        if ($user_agent_str != '') {
            curl_setopt($curl, CURLOPT_USERAGENT, $user_agent_str);
        }
        if ($post_data_str != '') {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $post_data_str);
        }
        if ($cookie_str != '') {
            curl_setopt($curl, CURLOPT_COOKIEFILE, str_replace('\\', '/', dirname(__FILE__)) . '/' . $cookie_str);
            curl_setopt($curl, CURLOPT_COOKIEJAR, str_replace('\\', '/', dirname(__FILE__)) . '/' . $cookie_str);
        }
        
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            'Accept-Language:zh-CN,zh;q=0.8'
        ));
         
        curl_setopt($curl, CURLOPT_HEADER, $is_need_head);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_TIMEOUT, $this->_timeout);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($curl, CURLOPT_MAXREDIRS, 5);
        $res=curl_exec($curl);
        curl_close($curl);
        return $res;
    }
    
    /**
     * 运行
     */
    public function run() {
        while ($this->runing) {
            if (is_null($this->task_id)) {
                echo "线程({$this->id})等待任务...\n";
            } else {
                echo "线程({$this->id}) 收到任务参数::{$this->task_id}.\n";
                $this->startWork($this->task_id,$this->rules,$this->task_url);
                $this->task_id=null;
            }
            sleep(1);
        }
    }
    
    /**
     * 开始工作
     */
    public function startWork($task_id,$rules,$task_url)
    {       
          if($rules){
              $rules=json_decode($rules,true);
              //获取centent
              $content=$this->filter($rules,$this->curl($task_url));          
              //加入redis
              $res=array();
              $res['task_id']=$task_id;
              $res['content']=$content;
              $this->saveRedis('message',json_encode($res));
           }    
        
    }
    
    
    //+++-------------------------队列操作-------------------------+++//
    
    /**
     * 入队列
     * @param $list string 队列名
     * @param $value mixed 入队元素值
     * @param $deriction int 0:数据入队列头(左) 1:数据入队列尾(右) 默认为0
     * @param $repeat int 判断value是否存在  0:不判断存在 1:判断存在 如果value存在则不入队列
     */
    public function listPush($list,$value,$direction=0,$repeat=0)
    {
        $return = null;
    echo 4;
        switch ($direction) {
            case 0:
                if ($repeat)
                    $return = $this->_REDIS->lPushx($list,$value);
                    else
                        echo 5;
                        $return = $this->_REDIS->lPush($list,$value);
                        echo 6;
                        break;
            case 1:
                if ($repeat)
                    $return = $this->_REDIS->rPushx($list,$value);
                    else
                        $return = $this->_REDIS->rPush($list,$value);
           echo 3;
                        break;
            default:
                $return = false;
                break;
        }
    
        return $return;
    }
    
    /**
     * 出队列
     * @param $list1 string 队列名
     * @param $deriction int 0:数据入队列头(左) 1:数据入队列尾(右) 默认为0
     * @param $list2 string 第二个队列名 默认null
     * @param $timeout int timeout为0:只获取list1队列的数据
     *        timeout>0:如果队列list1为空 则等待timeout秒 如果还是未获取到数据 则对list2队列执行pop操作
     */
    public function listPop($list1,$deriction=0,$list2=null,$timeout=0)
    {
        $return = null;
    
        switch ($deriction) {
            case 0:
                if ($timeout && $list2)
                    $return = $this->_REDIS->blPop($list1,$list2,$timeout);
                    else
                        $return = $this->_REDIS->lPop($list1);
                        break;
            case 1:
                if ($timeout && $list2)
                    $return = $this->_REDIS->brPop($list1,$list2,$timeout);
                    else
                        $return = $this->_REDIS->rPop($list1);
                        break;
            default:
    
                $return = false;
                break;
        }
    
        return $return;
    }
    
    
    
    
    
    
    
    /**
     * 保存在redis里面
     * @param unknown $key
     * @param unknown $value
     */
    public function saveRedis($key,$value){
        echo $key;
        $this->getRedis();
        echo 1;
        $resStatus=$this->listPush($key, $value);
        echo 2;
        if($resStatus){
            echo 'success';
        }else{
            echo 'fail';
        }
    }
    
    
    /**
     * 根据规则过滤信息
     */
    public function filter($rules,$content)
    {
        $res=array();
        foreach ($rules as $k=>$v){
            $data='';
            $resStatus=preg_match($v,$content,$data);
            if($resStatus){
               $res[$k]=$data[0];
            }
        }
        return $res;
    }
    
}