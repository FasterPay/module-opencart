<?php
require_once DIR_SYSTEM . '/thirdparty/fasterpay-php/lib/autoload.php';

class ModelExtensionPaymentFasterPayUtil extends Model
{
    const HISTORY_SUCCESS_MESSAGE = 'Order approved!, Transaction Id: #';
    const OTHER_COURIER_CODE = 'other';

    public function getTransactionId($orderId)
    {
        $query = $this->db->query('SELECT * FROM ' . DB_PREFIX . "order_history WHERE `comment` LIKE '".self::HISTORY_SUCCESS_MESSAGE."%'  AND `order_id` = ".$orderId.' ORDER BY `date_added` DESC LIMIT 1');

        if (empty($query->rows)) {
            return false;
        }

        $comment = $query->rows[0]['comment'];
        $transactionId = $this->getTransactionIdFromSuccessMessage($comment);

        return $transactionId;
    }

    public function getOrderDetail($orderId) {
        $order = $this->db->query("SELECT * FROM `" . DB_PREFIX . "order` WHERE order_id = '{$orderId}'")->row;
        if (empty($order)) {
            return null;
        }
        $order['is_downloadable'] = $this->isOrderDownloadable($order['order_id']);
        return $order;
    }

    public function getCountryCode($countryId)
    {
        $country = $this->getCountry($countryId);
        return !empty($country['iso_code_2']) ? $country['iso_code_2'] : false;
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
        return !empty($courier['shipping_courier_code']) ? $courier['shipping_courier_code'] : false;
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

    public function getActiveOrderShipment($orderId)
    {
        return $this->db->query("SELECT * FROM `" . DB_PREFIX . "order_shipment` WHERE order_id = '{$orderId}' ORDER BY date_added DESC LIMIT 1")->row;
    }

    public function getOrderShipments($orderId)
    {
        return $this->db->query("SELECT os.*, sc.shipping_courier_name FROM `" . DB_PREFIX . "order_shipment` os LEFT JOIN `" . DB_PREFIX . "shipping_courier` sc ON sc.shipping_courier_id = os.shipping_courier_id  WHERE order_id = '{$orderId}'")->rows;
    }

    public function createOtherShippingCourierIfNotExist()
    {
        $otherCourier = $this->db->query("SELECT * FROM `" . DB_PREFIX . "shipping_courier` where shipping_courier_code = '" . self::OTHER_COURIER_CODE .  "'")->row;

        if (!$otherCourier) {
            $this->db->query("INSERT INTO `" . DB_PREFIX . "shipping_courier` (shipping_courier_id, shipping_courier_code, shipping_courier_name) VALUES ((SELECT (MAX(sc.shipping_courier_id) + 1) from `" . DB_PREFIX . "shipping_courier` sc), '" . self::OTHER_COURIER_CODE ."', '" . ucfirst(self::OTHER_COURIER_CODE) ."')");
        }
    }

    public function generateSuccessMessage($transactionId)
    {
        return self::HISTORY_SUCCESS_MESSAGE . $transactionId;
    }

    public function getTransactionIdFromSuccessMessage($message)
    {
        $transactionId = trim(str_replace(self::HISTORY_SUCCESS_MESSAGE, '', $message));
        if (!is_numeric($transactionId)) {
            return false;
        }
        return $transactionId;
    }

    private function isOrderDownloadable($orderId)
    {
        $countDownloadableProduct = $this->db->query("SELECT count(*) as count FROM `" . DB_PREFIX . "order` o INNER JOIN `" . DB_PREFIX . "order_product` od ON od.order_id = o.order_id INNER JOIN `" . DB_PREFIX . "product_to_download` ptd ON ptd.product_id = od.product_id WHERE o.order_id = '{$orderId}'")->row['count'];
        return !!$countDownloadableProduct;
    }
}