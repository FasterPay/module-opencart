<?php
require_once DIR_SYSTEM . '/thirdparty/fasterpay-php/lib/autoload.php';

class ModelExtensionPaymentFasterPayDelivery extends Model
{
    const STATUS_ORDER_PLACED = 'order_placed';
    const STATUS_ORDER_SHIPPED = 'order_shipped';
    const STATUS_DELIVERED = 'delivered';
    const HISTORY_SUCCESS_MESSAGE = 'Order approved!, Transaction Id: #';
    const SEVENTEEN_TRACKING_URL = 'https://t.17track.net/en#nums=';

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

    public function getOrder($orderId)
    {
        return $this->db->query("SELECT * FROM `" . DB_PREFIX . "order` WHERE order_id = '{$orderId}'")->row;
    }

    public function orderHasShipping($order)
    {
        return !!$order['shipping_code'];
    }

    public function orderIsDownloadable($order)
    {
        $orderId = $order['order_id'];
        if (!isset($order['is_downloadable'])) {
            $order['is_downloadable'] = !!$this->db->query("SELECT count(*) as count FROM `" . DB_PREFIX . "order` o INNER JOIN `" . DB_PREFIX . "order_product` od ON od.order_id = o.order_id INNER JOIN `" . DB_PREFIX . "product_to_download` ptd ON ptd.product_id = od.product_id WHERE o.order_id = '{$orderId}'")->row['count'];
        }
        return $order['is_downloadable'];
    }

    public function getShippingCourier($courierId)
    {
        return $this->db->query("SELECT * FROM `" . DB_PREFIX . "shipping_courier` WHERE shipping_courier_id = '{$courierId}'")->row;
    }

    public function getShippingCourierCode($courierId)
    {
        $courier = $this->getShippingCourier($courierId);
        return $courier ? $courier['shipping_courier_code'] : false;
    }

    public function getShipmentCouriers()
    {
        return $this->db->query("SELECT * FROM `" . DB_PREFIX . "shipping_courier`")->rows;
    }

    public function getOrderShipment($orderId)
    {
        return $this->db->query("SELECT * FROM `" . DB_PREFIX . "order_shipment` WHERE order_id = '{$orderId}' ORDER BY date_added DESC LIMIT 1")->row;
    }

    public function getCountry($countryId)
    {
        return $this->db->query("SELECT * FROM `" . DB_PREFIX . "country` WHERE country_id = '{$countryId}'")->row;
    }

    public function getCountryCode($countryId)
    {
        $country = $this->getCountry($countryId);
        return $country ? $country['iso_code_2'] : false;
    }

    public function getOrderShipments($orderId)
    {
        return $this->db->query("SELECT os.*, sc.shipping_courier_name FROM `" . DB_PREFIX . "order_shipment` os LEFT JOIN `" . DB_PREFIX . "shipping_courier` sc ON sc.shipping_courier_id = os.shipping_courier_id  WHERE order_id = '{$orderId}'")->rows;
    }

    public function sendDeliveryData($orderId, $status)
    {
        try {
            $logger = new Log('fasterpay.log');
            $gateway = $this->initGateway();
            $payload = $this->prepareDeliveryData($orderId, $status);

            $logger->write(json_encode($payload));

            $response = $gateway->callApi(
                'api/v1/deliveries',
                $payload,
                'POST',
                [
                    'content-type: application/json',
                    'x-apikey: ' . $gateway->getConfig()->getPrivateKey(),
                ]
            );

            $logger->write($response->getRawResponse());
        } catch (\Exception $e) {
            $logger->write($e->getMessage());
        }
    }

    public function addOrderTrackingInfo($orderId, $courier_id, $trackingNumber)
    {
        $this->db->query("INSERT INTO `" . DB_PREFIX . "order_shipment` (order_id, shipping_courier_id, tracking_number) VALUES (" . (int)$orderId . "," . (int)$courier_id . ",'" . $this->db->escape($trackingNumber) ."')");

        return $this->db->getLastId();
    }

    private function prepareDeliveryData($orderId, $status)
    {
        $order = is_array($orderId) ? $orderId : $this->getOrder($orderId);
        $trackingData = $this->getOrderShipment($order['order_id']);
        return [
            "payment_order_id" => $this->getTransactionId($order['order_id']),
            "merchant_reference_id" => (string)$order['order_id'],
            "status" => $status,
            "refundable" => true,
            "details" => 'opencart delivery action',
            "reason" => 'None',
            "estimated_delivery_datetime" => date('Y-m-d H:i:s O'),
            "carrier_tracking_id" => !empty($trackingData['tracking_number']) ? $trackingData['tracking_number'] : "N/A",
            "carrier_type" => (!empty($trackingData['shipping_courier_id']) && $courier = $this->getShippingCourierCode($trackingData['shipping_courier_id'])) ? $courier : "N/A",
            "shipping_address" => [
                "country_code" => $this->getCountryCode($order['shipping_country_id'] ? $order['shipping_country_id'] : $order['payment_country_id']),
                "city" => $order['shipping_city'] ? $order['shipping_city'] : $order['payment_city'],
                // note: zip is optional in OC
                "zip" => $order['shipping_postcode'] ? $order['shipping_postcode'] : ($order['payment_postcode'] ? $order['payment_postcode'] : 'N/A'),
                "state" => $order['shipping_zone'] ? $order['shipping_zone'] : $order['payment_zone'],
                "street" => $order['shipping_address_1'] ? $order['shipping_address_1'] : $order['payment_address_1'],
                "phone" => $order['telephone'],
                "first_name" => $order['shipping_firstname'] ? $order['shipping_firstname'] : ($order['payment_firstname'] ? $order['payment_firstname'] : $order['firstname']),
                "last_name" => $order['shipping_lastname'] ? $order['shipping_lastname'] : ($order['payment_lastname'] ? $order['payment_lastname'] : $order['lastname']),
                "email" => $order['email']
            ],
            'attachments' => ['N/A'],
            "type" => !$this->orderIsDownloadable($order) ? "physical" : "digital",
            "public_key" => $this->initGateway()->getConfig()->getPublicKey(),
        ];
    }
}