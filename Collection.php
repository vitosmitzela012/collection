<?php
/**
 * Date: 2022/6/26
 * Time: 11:51
 * 波场、资金归集、tron、tronapi
 * 文档： https://tronapi.gitbook.io/collection
 * 源码获取=>纸飞机(Telegram):@laowu2021
 */

namespace app\api\controller;

use GuzzleHttp\Client;
use think\Controller;
use think\Request;
use Tron\Address;

class Collection extends Controller
{
    protected $config;
    protected $uri;
    protected $receivingAddress;
    protected $trxprivateKey;
    protected $minU;
    protected $feewall_open;
    protected $trx_open;
    protected $collection_trx_address;

    public function __construct(Request $request = null)
    {
        parent::__construct($request);
        $this->uri = 'https://api.trongrid.io'; /*API地址*/
        /*基础配置*/
        $this->config = [
            'contract_address' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',// USDT TRC20 合约地址，固定的不要轻易动
            'decimals' => 6, /*精度*/
        ];
        $this->receivingAddress = config("site.collection_usdt_address");  /*接收U的钱包*/
        $this->trxprivateKey = config("site.feewalletkey"); /*手续费钱包私钥*/
        $this->minU = config("site.min_amount"); /*提现最小U*/
        $this->feewall_open = config("site.feewall_open"); /*是否自动补充手续费*/


        $this->trx_open = config("site.trx_open"); /*是否归集TRX*/
        $this->collection_trx_address = config("site.collection_trx_address"); /*接收TRX的钱包*/
    }

    /**
     * @return \think\response\Json
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     * http://collection.com/api/custommade/collec
     * http://collection.com/api/trc20/generateAddress
     * 批量归集
     * application/api/controller/Custommade.php    这是第二个文件替换的位置
     */
    public function collec()
    {
        $num = 0;
        $nums = $this->request->param('num', 50);
        $address = \app\admin\model\Address::where([])->order('updatetime asc')->limit($nums)->select();
        if ($address) {
            foreach ($address as $k => $v) {
                self::collection($v['private_key'], $v['id']);
                $num += 1;
            }
        }
        return json(['code' => 'success', 'msg' => '执行完成' . $num . '个钱包的归集工作', 'time' => date('Y-m-d H:i:s')]);
    }

    /**
     * @return \think\response\Json
     * 资金归集（离线签名）
     * $address 转出金额，最小单位为1 精度为6
     * $toaddress  接收归集U的钱包
     * $key  转U的钱包私钥
     * http://test.qun.gay/api/custommade/collection
     */
    public function collection($key, $id)
    {
        if (!$key) {
            return ['code' => 0, 'data' => null, 'msg' => '私钥不能为空'];
        }
        $from = self::getAddressv2($key);

        $address = $from->address;
        /*查询USDT余额，大于等于最低金额就归集*/
        $amount = self::getAddressBalance($address);

        if ($amount < $this->minU) {
            \app\admin\model\Address::where(['id' => $id])->update(['updatetime' => time()]);
            return ['code' => 0, 'data' => null, 'msg' => 'U小于预设值,无需归集'];
        }

        if ($amount <= 0) {
            \app\admin\model\Address::where(['id' => $id])->update(['updatetime' => time()]);
            return ['code' => 0, 'data' => null, 'msg' => 'U为0,无需归集'];
        }

        /*查询TRX余额，不够就要转进来10*/
        $trxbalance = self::getBalance($address);
        if ($trxbalance < 10) {
            if ($this->feewall_open) {
                /*trx转账*/
                $trxret = self::sendTrx($address, 10, $this->trxprivateKey);
                \app\admin\model\Address::where(['id' => $id])->update(['updatetime' => time()]);
                return ['code' => 0, 'data' => ['usdt' => $amount, 'trx' => $trxbalance], 'trxtraderet' => $trxret, 'msg' => '当前钱包TRX不足10个，已调用手续费充值接口。由于TRX到账响应时间有延迟，故此接口不能立马进行归集，否则会失败，请稍手动刷新查看TRX到账后再进行手动资金归集'];
            } else {
                /*继续往下走执行归集操作*/
            }

        }
        $api = new \Tron\Api(new Client(['base_uri' => $this->uri]));
        $TRC20 = new \Tron\TRC20($api, $this->config);

        $hexAddress = $TRC20->tron->address2HexString($this->receivingAddress);
        $to = new \Tron\Address($this->receivingAddress, '', $hexAddress);
        $ret = $TRC20->transfer($from, $to, $amount);


        /*归集剩余的TRX*/
        if ($this->trx_open) {
            /*trx转账*/
            $trxbalance = self::getBalance($address);
            if ($trxbalance > 0) {
                self::sendTrx($this->collection_trx_address, $trxbalance, $key);
            }
        }

        /*更新当前钱包的余额*/
        $amount = self::getAddressBalance($address);
        $trxbalance = self::getBalance($address);
        \app\admin\model\Address::where(['id' => $id])->update(['usdt' => $amount, 'trx' => $trxbalance, 'updatetime' => time()]);

        return ['code' => 1, 'msg' => '操作成功', 'data' => $ret];
    }

    /**
     * 根据私钥获取地址
     * $privateKey 私 钥
     */
    private function getAddressv2($key)
    {
        $api = new \Tron\Api(new Client(['base_uri' => $this->uri]));
        $TRC20 = new \Tron\TRC20($api, $this->config);
        /*私钥地址*/
        $privateKeyToAddress = $TRC20->privateKeyToAddress($key);
        return $privateKeyToAddress;
    }

    /**
     * 获取USDT余额
     * $address 地址对象
     */
    public function getAddressBalance($address)
    {
        $api = new \Tron\Api(new Client(['base_uri' => $this->uri]));
        $TRC20 = new \Tron\TRC20($api, $this->config);
        $hexAddress = $TRC20->tron->address2HexString($address);
        $getBalance = new Address($address, '', $hexAddress);
        $balance = $TRC20->balance($getBalance);
        return $balance;
    }

    /**
     * @return \think\response\Json
     * @throws \IEXBase\TronAPI\Exception\TronException
     * 查询TRX余额
     */
    public function getBalance($address, $str = '')
    {
        $fullNode = new \IEXBase\TronAPI\Provider\HttpProvider($this->uri);
        $solidityNode = new \IEXBase\TronAPI\Provider\HttpProvider($this->uri);
        $eventServer = new \IEXBase\TronAPI\Provider\HttpProvider($this->uri);
        $signServer = new \IEXBase\TronAPI\Provider\HttpProvider($this->uri);
        $explorer = new \IEXBase\TronAPI\Provider\HttpProvider($this->uri);
        $tron = new \IEXBase\TronAPI\Tron($fullNode, $solidityNode, $eventServer, $signServer, $explorer);
        //Balance  查询TRX余额
        $Balance = $tron->getBalance($address, true);
        return $Balance;
    }

    /**
     * @param $to 接收trx的账户地址
     * @param $key 接收trx的账户私钥 可以修改去掉这个参数，因为你可以不查接收账号的TRX余额，查一下是为了防止多转手续费，看个人自己
     * @return false
     * @throws \IEXBase\TronAPI\Exception\TronException
     * 注意：到账是有延迟的，所以不能立即执行转账操作，可以写个脚本三分钟后再去处理转账操作，对应的订单加一个trx转账发起时间，这样就能监控哪些订单时间足够可以进行下一步U的转账
     */
    public function sendTrx($to, $account, $key)
    {
        $fullNode = new \IEXBase\TronAPI\Provider\HttpProvider($this->uri);
        $solidityNode = new \IEXBase\TronAPI\Provider\HttpProvider($this->uri);
        $eventServer = new \IEXBase\TronAPI\Provider\HttpProvider($this->uri);
        $signServer = new \IEXBase\TronAPI\Provider\HttpProvider($this->uri);
        $explorer = new \IEXBase\TronAPI\Provider\HttpProvider($this->uri);
        $tron = new \IEXBase\TronAPI\Tron($fullNode, $solidityNode, $eventServer, $signServer, $explorer, $key);
        //Balance  查询TRX余额
        $fromaddress = self::getAddressv2($key);
        $ret = $tron->sendTrx($to, $account, null, $fromaddress->address);
        return $ret;
    }


}