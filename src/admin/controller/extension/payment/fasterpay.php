<?php
/*
 * Module Name: FasterPay for OpenCart
 * Description: Official FasterPay module for OpenCart
 * Version: 1.2.0
 * Author: The FasterPay Team
 * Author URI: https://www.fasterpay.com/
 * License: The MIT License (MIT)
 *
 */
class ControllerExtensionPaymentFasterPay extends Controller
{
    private $error = [];
    const ORDER_REFUNDED_STATUS_ID = 11;
    const PAYMENT_METHOD = 'FasterPay';

    // Generate default configs
    public function install()
    {
        $this->load->model('setting/setting');
        $this->load->model('setting/event');
        $defaultConfigs = $this->model_setting_setting->getSetting('config');
        $url = new Url(HTTP_CATALOG, $this->config->get('config_secure') ? HTTP_CATALOG : HTTPS_CATALOG);
        $this->model_setting_setting->editSetting('payment_fasterpay', [
            'payment_fasterpay_complete_status' => @$defaultConfigs['config_complete_status_id'],
            'payment_fasterpay_test_mode' => 0,
            'payment_fasterpay_status' => 1, // Pending
            'payment_fasterpay_sort_order' => 1,
            'payment_fasterpay_success_url' => $url->link('checkout/success'),
            'payment_fasterpay_pingback_url' => ''
        ]);
        $this->model_setting_event->addEvent('fasterpay_add_refund_btn', 'admin/controller/sale/order/info/after', 'extension/payment/fasterpay/addRefundButtonScript');
        $this->model_setting_event->addEvent('fasterpay_add_delivery_tracking', 'admin/controller/sale/order/info/after', 'extension/payment/fasterpay/addDeliveryTrackingSection');
        $this->getUtilModel()->createOtherShippingCourierIfNotExist();
    }

    public function uninstall()
    {
        $this->load->model('setting/setting');
        $this->load->model('setting/event');
        $this->model_setting_setting->deleteSetting('payment_fasterpay');
        $this->model_setting_event->deleteEventByCode('fasterpay_add_refund_btn');
        $this->model_setting_event->deleteEventByCode('fasterpay_add_delivery_tracking');
    }

    /**
     * Index action
     */
    public function index()
    {
        $this->load->model('setting/setting');
        $this->load->model('extension/payment/fasterpay');
        $this->load->language('extension/payment/fasterpay');

        if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
            $this->model_setting_setting->editSetting('payment_fasterpay', array_map('trim', $this->request->post));
            $this->session->data['success'] = $this->language->get('text_success');
            $this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', 'SSL'));
        }

        $translationData = $this->prepareTranslationData();
        $viewData = $this->prepareViewData();
        $data = array_merge($translationData, $viewData);

        $this->response->setOutput($this->load->view('extension/payment/fasterpay', $data));
    }

    // admin/controller/sale/order/info/after
    public function addDeliveryTrackingSection()
    {
        if ($this->request->server['REQUEST_METHOD'] == 'GET') {
            $this->load->model('extension/payment/fasterpay_delivery');
            $this->load->model('setting/setting');

            $orderId = $this->getGetData('order_id');
            $order = $this->getUtilModel()->getOrderDetail($orderId);
            $body = $this->response->getOutput();

            if (
                $order
                && !$order['is_downloadable']
                && $order['payment_method'] == self::PAYMENT_METHOD
                && $order['payment_code'] == strtolower(self::PAYMENT_METHOD)
            ) {

                $this->load->language('extension/payment/fasterpay');

                $data = [
                    'order_id' => $orderId,
                    'update_url' => str_replace('&amp;', '&', $this->url->link('extension/payment/fasterpay/delivery', 'user_token=' . $this->session->data['user_token'])),
                    'text_courier_name' => $this->language->get('text_courier_name'),
                    'text_tracking_number' => $this->language->get('text_tracking_number'),
                    'text_order_shipment' => $this->language->get('text_order_shipment'),
                    'text_date_added' => $this->language->get('text_date_added'),
                    'text_action' => $this->language->get('text_action'),
                    'text_no_order_shipments' => $this->language->get('text_no_order_shipments'),
                    'order_shipments' => $this->getUtilModel()->getOrderShipments($orderId),
                    'couriers' => $this->getUtilModel()->getShipmentCouriers(),
                    'track_url' => ModelExtensionPaymentFasterPayDelivery::getDefaultDeliveryTrackingUrl(),
                ];
                $body .= $this->load->view('extension/payment/fasterpay_delivery_tracking', $data);
                $this->response->setOutput($body);
            }
        }
    }

    // admin/controller/sale/order/info/after
    public function addRefundButtonScript()
    {
        if ($this->request->server['REQUEST_METHOD'] == 'GET') {
            $this->load->model('sale/order');
            $this->load->model('setting/setting');

            $orderId = $this->getGetData('order_id');
            $order = $this->model_sale_order->getOrder($orderId);
            $body = $this->response->getOutput();
            $defaultConfigs = $this->model_setting_setting->getSetting('payment_fasterpay');

            if ($order
                && $order['order_status_id']
                && $order['order_status_id'] != self::ORDER_REFUNDED_STATUS_ID
                && $order['payment_method'] == self::PAYMENT_METHOD
                && $order['payment_code'] == strtolower(self::PAYMENT_METHOD)) {

                $this->load->language('extension/payment/fasterpay');

                $data = [
                    'order_id' => $orderId,
                    'refund_url' =>str_replace('&amp;', '&', $this->url->link('extension/payment/fasterpay/refund', 'user_token=' . $this->session->data['user_token'])),
                    'text_confirm_refund_order' => sprintf($this->language->get('text_confirm_refund_order'), $orderId)
                ];
                $body .= $this->load->view('extension/payment/fasterpay_refund_btn', $data);
                $this->response->setOutput($body);
            }
        }
    }

    public function delivery()
    {
        if ($this->request->server['REQUEST_METHOD'] == 'POST') {
            $orderId = $this->getPostData('order_id', null);
            $courierId = $this->getPostData('shipping_courier_id', null);
            $trackingNumber = trim($this->getPostData('tracking_number', null));

            if (!empty($orderId) && !empty($courierId) && !empty($trackingNumber)) {
                $this->getUtilModel()->createOrderShipment(
                    $orderId,
                    $courierId,
                    $trackingNumber
                );
                $order = $this->getUtilModel()->getOrderDetail($orderId);
                $this->getDeliveryModel()->sendDeliveryData($order, ModelExtensionPaymentFasterPayDelivery::STATUS_ORDER_SHIPPED);
            }

            $this->response->addHeader('Content-Type: application/json');
            $this->response->setOutput(json_encode(['data' => $this->getUtilModel()->getOrderShipments($orderId)]));
        }
    }

    public function refund()
    {
        if ($this->request->server['REQUEST_METHOD'] == 'POST') {
            try {
                $this->load->model('setting/setting');
                $this->load->model('sale/order');
                $this->load->model('extension/payment/fasterpay');
                $this->load->language('extension/payment/fasterpay');

                $data = [
                    'success' => false,
                    'message' => $this->language->get('text_refund_order_fail')
                ];

                $gateway = $this->model_extension_payment_fasterpay->initGateway();
                $orderId = $this->getPostData('order_id', null);
                $order = $this->model_sale_order->getOrder($orderId);
                $defaultConfigs = $this->model_setting_setting->getSetting('payment_fasterpay');

                $this->validateRefundOrder($order, $data, $defaultConfigs);

                $transactionId = $this->model_extension_payment_fasterpay->getTransactionId($orderId);
                if (!$transactionId) {
                    throw new FasterPay\Exception('Invalid Order');
                }

                $amount = $order['total'];

                $refundResponse = $gateway->paymentService()->refund($transactionId, $amount);

                if ($refundResponse->isSuccessful()) {
                    $response = $this->addOrderHistory($orderId, self::ORDER_REFUNDED_STATUS_ID, 'Refunded ' . $amount . ' ' . $order['currency_code'], $order['store_id']);
                    $response = json_decode($response, 1);

                    if (!$response || !empty($response['error'])) {
                        throw new FasterPay\Exception($response['error']);
                    }

                    $data = [
                        'success' => true,
                        'message' => $this->language->get('text_refund_order_success')
                    ];
                } else {
                    throw new FasterPay\Exception($refundResponse->getErrors()->getMessage() . ' ' . $amount . ' ' .$transactionId);
                }
            } catch (FasterPay\Exception $e) {
                $data = [
                    'success' => false,
                    'message' => $e->getMessage()
                ];
            }

            $this->response->addHeader('Content-Type: application/json');
            $this->response->setOutput(json_encode($data));
        }
    }

    /**
     * @param $key
     * @param $default
     * @return mixed
     */
    private function getPostData($key, $default)
    {
        return isset($this->request->post[$key]) ? $this->request->post[$key] : $default;
    }

    /**
     * @param $key
     * @param $default
     * @return mixed
     */
    private function getGetData($key, $default = null)
    {
        return isset($this->request->get[$key]) ? $this->request->get[$key] : $default;
    }

    protected function prepareTranslationData()
    {
        $this->document->setTitle($this->language->get('heading_title'));
        return [
            'heading_title' => $this->language->get('heading_title'),

            'text_edit' => $this->language->get('text_edit'),
            'text_enabled' => $this->language->get('text_enabled'),
            'text_disabled' => $this->language->get('text_disabled'),
            'text_yes' => $this->language->get('text_yes'),
            'text_no' => $this->language->get('text_no'),
            'text_pingback_url_hint' => $this->language->get('text_pingback_url_hint'),

            'entry_public_key' => $this->language->get('entry_public_key'),
            'entry_private_key' => $this->language->get('entry_private_key'),
            'entry_test' => $this->language->get('entry_test'),
            'entry_success_url' => $this->language->get('entry_success_url'),
            'entry_pingback_url' => $this->language->get('entry_pingback_url'),

            'entry_transaction' => $this->language->get('entry_transaction'),
            'entry_total' => $this->language->get('entry_total'),
            'entry_order_status' => $this->language->get('entry_order_status'),
            'entry_geo_zone' => $this->language->get('entry_geo_zone'),
            'entry_status' => $this->language->get('entry_status'),
            'entry_sort_order' => $this->language->get('entry_sort_order'),
            'entry_complete_status' => $this->language->get('entry_complete_status'),
            'entry_active' => $this->language->get('entry_active'),

            'button_save' => $this->language->get('button_save'),
            'button_cancel' => $this->language->get('button_cancel')
        ];
    }

    protected function prepareViewData()
    {
        $data['statuses'] = $this->model_extension_payment_fasterpay->getAllStatuses();
        $data['error_warning'] = isset($this->error['warning']) ? $this->error['warning'] : '';

        $data['breadcrumbs'] = [
            [
                'text' => $this->language->get('text_home'),
                'href' => $this->url->link('common/home', 'user_token=' . $this->session->data['user_token'], 'SSL'),
                'separator' => false
            ],
            [
                'text' => $this->language->get('text_payment'),
                'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', 'SSL'),
                'separator' => ' :: '
            ],
            [
                'text' => $this->language->get('heading_title'),
                'href' => $this->url->link('extension/payment/fasterpay', 'user_token=' . $this->session->data['user_token'], 'SSL'),
                'separator' => ' :: '
            ]
        ];

        $data['action'] = $this->url->link('extension/payment/fasterpay', 'user_token=' . $this->session->data['user_token'], 'SSL');
        $data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', 'SSL');

        $data['payment_fasterpay_public_key'] = $this->getPostData(
            'payment_fasterpay_public_key',
            $this->config->get('payment_fasterpay_public_key'));

        $data['payment_fasterpay_private_key'] = $this->getPostData(
            'payment_fasterpay_private_key',
            $this->config->get('payment_fasterpay_private_key'));

        $data['payment_fasterpay_test_mode'] = $this->getPostData(
            'payment_fasterpay_test_mode',
            $this->config->get('payment_fasterpay_test_mode'));

        $data['payment_fasterpay_status'] = $this->getPostData(
            'payment_fasterpay_status',
            $this->config->get('payment_fasterpay_status'));

        $data['payment_fasterpay_sort_order'] = $this->getPostData(
            'payment_fasterpay_sort_order',
            $this->config->get('payment_fasterpay_sort_order'));

        $data['payment_fasterpay_success_url'] = $this->getPostData(
            'payment_fasterpay_success_url',
            $this->config->get('payment_fasterpay_success_url'));

        $data['payment_fasterpay_pingback_url'] = $this->getPostData(
            'payment_fasterpay_pingback_url',
            $this->config->get('payment_fasterpay_pingback_url'));

        $data['payment_fasterpay_complete_status'] = $this->getPostData(
            'payment_fasterpay_complete_status',
            $this->config->get('payment_fasterpay_complete_status'));

        $data['default_pingback_url'] =  $this->model_extension_payment_fasterpay->getDefaultPingbackUrl();

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        return $data;
    }

    /**
     * Validator
     * @return bool
     */
    private function validate()
    {
        if (!$this->user->hasPermission('modify', 'extension/payment/fasterpay')) {
            $this->error['warning'] = $this->language->get('error_permission');
        }

        return empty($this->error);
    }

    private function getAPIToken()
    {
        $this->load->model('user/api');

        $apiInfo = $this->model_user_api->getApi($this->config->get('config_api_id'));
        $apiToken = '';
        if ($apiInfo) {
            $session = new Session($this->config->get('session_engine'), $this->registry);
            
            $session->start();
                    
            $this->model_user_api->deleteApiSessionBySessonId($session->getId());
            
            $this->model_user_api->addApiSession($apiInfo['api_id'], $session->getId(), $this->request->server['SERVER_ADDR']);
            
            $session->data['api_id'] = $apiInfo['api_id'];

            $apiToken = $session->getId();
            // trigger session write to session storage immediately to use data in same thread
            $session->close();
        }

        return $apiToken;
    }

    private function validateRefundOrder($order, $data, $defaultConfigs)
    {
        if (!$order || !$order['order_status_id']) {
            throw new FasterPay\Exception('Invalid Order');
        }

        if ($order['order_status_id'] == self::ORDER_REFUNDED_STATUS_ID) {
            throw new FasterPay\Exception('Order already refunded');
        }

        if ($order['order_status_id'] != $defaultConfigs['payment_fasterpay_complete_status']) {
            throw new FasterPay\Exception('Invalid order status');
        }
    }

    private function addOrderHistory($orderId, $orderStatusId, $comment, $storeId)
    {
        $apiToken = $this->getAPIToken();
        $url = new Url(HTTP_CATALOG, $this->config->get('config_secure') ? HTTP_CATALOG : HTTPS_CATALOG);
        $endPoint = $url->link('api/order/history', 'order_id=' . $orderId . '&api_token=' . $apiToken . '&store_id=' . $storeId);
        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_URL => str_replace('&amp;', '&', $endPoint),
            CURLOPT_POST => 1,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_POSTFIELDS => http_build_query([
                'order_status_id' => $orderStatusId,
                'override' => 0,
                'append' => 0,
                'comment' => $comment
            ])
        ]);
        $res = curl_exec($curl);

        curl_close($curl);

        return $res;
    }

    private function getUtilModel()
    {
        if (!$this->model_extension_payment_fasterpay_util) {
            $this->load->model('extension/payment/fasterpay_util');
        }
        return $this->model_extension_payment_fasterpay_util;
    }

    private function getDeliveryModel()
    {
        if (!$this->model_extension_payment_fasterpay_delivery) {
            $this->load->model('extension/payment/fasterpay_delivery');
        }
        return $this->model_extension_payment_fasterpay_delivery;
    }
}
