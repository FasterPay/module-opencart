<?php

class ControllerCheckoutFasterPayPingback extends Controller
{
    const DEFAULT_PINGBACK_RESPONSE_SUCCESS = "OK";
    const PAYMENT_METHOD = 'FasterPay';
    const HISTORY_SUCCESS_MESSAGE = 'Order approved!, Transaction Id: #';
    const FASTERPAY_EVENT_PAYMENT = 'payment';

    public function index()
    {
        $this->load->model('setting/setting');

        $defaultConfigs = $this->model_setting_setting->getSetting('payment_fasterpay');
        $server = $this->request->server;
        $pingbackData = file_get_contents('php://input');

        $validationParams = [
            'pingbackData' => $pingbackData,
            'signVersion' => !empty($server['HTTP_X_FASTERPAY_SIGNATURE_VERSION']) ? $server['HTTP_X_FASTERPAY_SIGNATURE_VERSION'] : '',
            'signature' => !empty($server['HTTP_X_FASTERPAY_SIGNATURE']) ? $server['HTTP_X_FASTERPAY_SIGNATURE'] : ''
        ];

        $pingbackData = json_decode($pingbackData, 1);

        if (empty($pingbackData['event']) || $pingbackData['event'] != self::FASTERPAY_EVENT_PAYMENT) {
            exit('Invalid event!');
        }

        if (!$this->getFasterPayModel()->initGateway()->pingback()->validate($validationParams)) {
            exit('Invalid pingback data!');
        }

        $orderId = $pingbackData['payment_order']['merchant_order_id'];
        $order = $this->getCheckoutOrderModel()->getOrder($orderId);

        if (!$order) {
            exit('Invalid order!');
        }

        if ($order['payment_method'] != self::PAYMENT_METHOD || $order['payment_code'] != strtolower(self::PAYMENT_METHOD)) {
            exit('Invalid payment method!');
        }               

        if (!$order['order_status']) {
            $this->getCheckoutOrderModel()->addOrderHistory($order['order_id'], $defaultConfigs['payment_fasterpay_complete_status'], '', true);
        }

        if ($order['order_status_id'] != $this->config->get('payment_fasterpay_complete_status')) {
            $this->getCheckoutOrderModel()->addOrderHistory($order['order_id'], $this->config->get('payment_fasterpay_complete_status'), self::HISTORY_SUCCESS_MESSAGE . $pingbackData['payment_order']['id'], true);
        }

        require_once(DIR_APPLICATION . '/../admin/model/extension/payment/fasterpay_delivery.php');
        $deliveryModel = new ModelExtensionPaymentFasterPayDelivery($this->registry);
        $deliveryStatus = ModelExtensionPaymentFasterPayDelivery::STATUS_ORDER_PLACED;
        if ($deliveryModel->orderIsDownloadable($order)) {
            $deliveryStatus = ModelExtensionPaymentFasterPayDelivery::STATUS_DELIVERED;
        }
        // if ($deliveryModel->orderHasShipping($order)) {
        //     $deliveryModel->sendDeliveryData($order, $deliveryStatus);
        // }
        $deliveryModel->sendDeliveryData($order, $deliveryStatus);

        echo self::DEFAULT_PINGBACK_RESPONSE_SUCCESS;
    }

    /**
     * @return ModelPaymentFasterPay
     */
    protected function getFasterPayModel()
    {
        if (!$this->model_extension_payment_fasterpay) {
            $this->load->model('extension/payment/fasterpay');
        }
        return $this->model_extension_payment_fasterpay;
    }

    /**
     * @return ModelCheckoutOrder
     */
    protected function getCheckoutOrderModel()
    {
        if (!$this->model_checkout_order) {
            $this->load->model('checkout/order');
        }
        return $this->model_checkout_order;
    }
}
