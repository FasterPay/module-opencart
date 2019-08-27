<?php

require_once DIR_SYSTEM.'/thirdparty/fasterpay-php/lib/autoload.php';

class ModelExtensionPaymentFasterPay extends Model
{
    const HISTORY_SUCCESS_MESSAGE = 'Order approved!, Transaction Id: #';
    private $gateway;

    public function getAllStatuses()
    {
        $query = $this->db->query('SELECT * FROM '.DB_PREFIX.'order_status');
        foreach ($query->rows as $value) {
            $result[$value['order_status_id']] = $value['name'];
        }

        return $result;
    }

    public function initGateway()
    {
        if (empty($this->gateway)) {
            $this->gateway = new FasterPay\Gateway([
                'publicKey' => $this->config->get('payment_fasterpay_public_key'),
                'privateKey' => $this->config->get('payment_fasterpay_private_key'),
                'isTest' => $this->config->get('payment_fasterpay_test_mode'),
            ]);
        }

        return $this->gateway;
    }

    public function getTransactionId($orderId)
    {
        $query = $this->db->query('SELECT * FROM '.DB_PREFIX."order_history WHERE `comment` LIKE '".self::HISTORY_SUCCESS_MESSAGE."%'  AND `order_id` = ".$orderId.' ORDER BY `date_added` DESC LIMIT 1');

        if (empty($query->rows)) {
            return false;
        }

        $comment = $query->rows[0]['comment'];
        $transactionId = trim(str_replace(self::HISTORY_SUCCESS_MESSAGE, '', $comment));

        if (!is_numeric($transactionId)) {
            return false;
        }

        return $transactionId;
    }

    public function getDefaultPingbackUrl()
    {
        $url = new Url(HTTP_CATALOG, $this->config->get('config_secure') ? HTTP_CATALOG : HTTPS_CATALOG);
        return $url->link('checkout/fasterpay_pingback');
    }
}
