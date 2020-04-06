<?php
require_once DIR_SYSTEM . '/thirdparty/fasterpay-php/lib/autoload.php';

class ModelExtensionPaymentFasterPayUtil extends Model
{
    const HISTORY_SUCCESS_MESSAGE = 'Order approved!, Transaction Id: #';

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

    public function getOrder($orderId) {
        $order = $this->db->query("SELECT * FROM `" . DB_PREFIX . "order` WHERE order_id = '{$orderId}'")->row;
        if ($order) {
            $order['is_downloadable'] = $this->orderIsDownloadable($order);
        }
        return $order;
    }

    public function orderIsDownloadable($order)
    {
        if(isset($order['is_downloadable'])) {
            return $order['is_downloadable'];
        }
        $orderId = $order['order_id'];
        return !!$this->db->query("SELECT count(*) as count FROM `" . DB_PREFIX . "order` o INNER JOIN `" . DB_PREFIX . "order_product` od ON od.order_id = o.order_id INNER JOIN `" . DB_PREFIX . "product_to_download` ptd ON ptd.product_id = od.product_id WHERE o.order_id = '{$orderId}'")->row['count'];
    }

    public function getCountryCode($countryId)
    {
        $country = $this->getCountry($countryId);
        return $country ? $country['iso_code_2'] : false;
    }

    public function getCountry($countryId)
    {
        return $this->db->query("SELECT * FROM `" . DB_PREFIX . "country` WHERE country_id = '{$countryId}'")->row;
    }

    public function getShipmentCouriers()
    {
        return $this->db->query("SELECT * FROM `" . DB_PREFIX . "shipping_courier`")->rows;
    }

    public function getShippingCourierCode($courierId)
    {
        $courier = $this->getShippingCourier($courierId);
        return $courier ? $courier['shipping_courier_code'] : false;
    }

    public function getShippingCourier($courierId)
    {
        return $this->db->query("SELECT * FROM `" . DB_PREFIX . "shipping_courier` WHERE shipping_courier_id = '{$courierId}'")->row;
    }

    public function createOrderShipment($orderId, $courier_id, $trackingNumber)
    {
        $this->db->query("INSERT INTO `" . DB_PREFIX . "order_shipment` (order_id, shipping_courier_id, tracking_number, date_added) VALUES (" . (int)$orderId . "," . (int)$courier_id . ",'" . $this->db->escape($trackingNumber) ."', '" . date('Y-m-d H:i:s') . "')");

        return $this->db->getLastId();
    }

    public function getOrderShipment($orderId)
    {
        return $this->db->query("SELECT * FROM `" . DB_PREFIX . "order_shipment` WHERE order_id = '{$orderId}' ORDER BY date_added DESC LIMIT 1")->row;
    }

    public function getOrderShipments($orderId)
    {
        return $this->db->query("SELECT os.*, sc.shipping_courier_name FROM `" . DB_PREFIX . "order_shipment` os LEFT JOIN `" . DB_PREFIX . "shipping_courier` sc ON sc.shipping_courier_id = os.shipping_courier_id  WHERE order_id = '{$orderId}'")->rows;
    }
}