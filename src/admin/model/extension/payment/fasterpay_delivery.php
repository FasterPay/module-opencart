<?php

class ModelExtensionPaymentFasterPayDelivery extends Model
{
    const STATUS_ORDER_PLACED = 'order_placed';
    const STATUS_ORDER_SHIPPED = 'order_shipped';
    const STATUS_DELIVERED = 'delivered';
    const SUCCESS_CODE = '00';
    const SEVENTEEN_TRACKING_URL = 'https://t.17track.net/en#nums=';

    public function sendDeliveryData($order, $status)
    {
        try {
            $logger = new Log('fasterpay.log');
            $gateway = $this->getGatewayModel()->initGateway();
            $this->validateOrderForDelivery($order);
            $payload = $this->prepareDeliveryData($order, $status);

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
            $json = $response->getDecodeResponse();

            if (!is_array($json) || !isset($json['code']) || $json['code'] != self::SUCCESS_CODE) {
                throw new \Exception('Unsuccessful or Invalid response: ' . $response->getRawResponse());
            }
        } catch (\Exception $e) {
            $logger->write($e->getMessage());
        }
    }

    public static function getDefaultDeliveryTrackingUrl() {
        return self::SEVENTEEN_TRACKING_URL;
    }

    private function prepareDeliveryData($order, $status)
    {
        $trackingData = $this->getUtilModel()->getActiveOrderShipment($order['order_id']);
        return [
            "payment_order_id" => $this->getUtilModel()->getTransactionId($order['order_id']),
            "merchant_reference_id" => (string)$order['order_id'],
            "status" => $status,
            "refundable" => true,
            "details" => 'opencart delivery action',
            "reason" => 'None',
            "estimated_delivery_datetime" => date('Y-m-d H:i:s O'),
            "carrier_tracking_id" => !empty($trackingData['tracking_number']) ? $trackingData['tracking_number'] : "N/A",
            "carrier_type" => (!empty($trackingData['shipping_courier_id']) && $courier = $this->getUtilModel()->getShippingCourierCode($trackingData['shipping_courier_id'])) ? $courier : "N/A",
            "shipping_address" => [
                "country_code" => $this->getUtilModel()->getCountryCode($order['shipping_country_id'] ? $order['shipping_country_id'] : $order['payment_country_id']),
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
            "type" => !$order['is_downloadable'] ? "physical" : "digital",
            "public_key" => $this->getGatewayModel()->initGateway()->getConfig()->getPublicKey(),
        ];
    }

    private function validateOrderForDelivery($order)
    {
        $requiredFields = self::getRequiredFieldsForDelivery();
        $missingFields = [];

        foreach ($requiredFields as $field) {
            if (!isset($order[$field])) {
                $missingFields[] = $field;
            }
        }

        if (count($missingFields)) {
            throw new \Exception('Missing order required field(s): ' . join(', ', $missingFields));
        }
    }

    private function getUtilModel()
    {
        if (!$this->model_extension_payment_fasterpay_util) {
            require_once(__DIR__ . '/fasterpay_util.php');
            $this->model_extension_payment_fasterpay_util = new ModelExtensionPaymentFasterPayUtil($this->registry);
        }
        return $this->model_extension_payment_fasterpay_util;
    }

    private function getGatewayModel()
    {
        if (!$this->model_extension_payment_fasterpay) {
            require_once(__DIR__ . '/fasterpay.php');
            $this->model_extension_payment_fasterpay = new ModelExtensionPaymentFasterPay($this->registry);
        }
        return $this->model_extension_payment_fasterpay;
    }

    private static function getRequiredFieldsForDelivery()
    {
        return [
            'order_id',
            'shipping_country_id',
            'payment_country_id',
            'shipping_city',
            'payment_city',
            'shipping_postcode',
            'payment_postcode',
            'shipping_zone',
            'payment_zone',
            'shipping_address_1',
            'payment_address_1',
            'telephone',
            'shipping_firstname',
            'payment_firstname',
            'firstname',
            'shipping_lastname',
            'payment_lastname',
            'lastname',
            'email',
            'is_downloadable'
        ];
    }
}