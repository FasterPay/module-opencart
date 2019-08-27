<?php
require_once DIR_SYSTEM . '/thirdparty/fasterpay-php/lib/autoload.php';

class ModelExtensionPaymentFasterPay extends Model
{
    private $gateway;
    const FASTERPAY_MODULE_SOURCE = 'opencart';

    public function getMethod($address, $total)
    {
        $this->load->language('extension/payment/fasterpay');
        $method_data = [];
        if ($this->config->get('payment_fasterpay_status') && $total > 0) {
            $method_data = [
                'code' => 'fasterpay',
                'title' => $this->language->get('text_title'),
                'sort_order' => $this->config->get('payment_fasterpay_sort_order'),
                'terms' => ''
            ];
        }

        return $method_data;
    }

    /**
     * @param $orderInfo
     * @return string
     */
    public function generateForm($orderInfo)
    {
        $successUrl = $this->config->get('payment_fasterpay_success_url');
        $successUrl = $successUrl ?: $this->url->link('checkout/success');
        $customPingbackUrl = $this->config->get('payment_fasterpay_pingback_url');
        $customPingbackUrl = $customPingbackUrl ?: $this->getDefaultPingbackUrl();

        $params = [
            'description' => 'Order #' . (!empty($orderInfo['order_id']) ? $orderInfo['order_id'] : ''),
            'amount' => !empty($orderInfo['total']) ? $orderInfo['total'] : '',
            'currency' => !empty($orderInfo['currency_code']) ? $orderInfo['currency_code'] : '',
            'merchant_order_id' => !empty($orderInfo['order_id']) ? $orderInfo['order_id'] : '',
            'success_url' => $successUrl,
            'sign_version' => \FasterPay\Services\Signature::SIGN_VERSION_2, // to use version 1 please skip this param or set it 'v1'
            'module_source' => self::FASTERPAY_MODULE_SOURCE,
            'pingback_url' => $customPingbackUrl,
            'email' => !empty($orderInfo['email']) ? $orderInfo['email'] : '',
            'first_name' => !empty($orderInfo['payment_firstname']) ? $orderInfo['payment_firstname'] : '',
            'last_name' => !empty($orderInfo['payment_lastname']) ? $orderInfo['payment_lastname'] : '',
            'city' => !empty($orderInfo['payment_city']) ? $orderInfo['payment_city'] : '',
            'zip' => !empty($orderInfo['payment_postcode']) ? $orderInfo['payment_postcode'] : ''
        ];

        $gateway = $this->initGateway();
        $form = $gateway->paymentForm()->buildForm($params, [
            'autoSubmit' => true,
            'hidePayButton' => true
        ]);
        return $form;
    }

    public function initGateway()
    {
        if (empty($this->gateway)) {
            $this->gateway = new FasterPay\Gateway([
                'publicKey' => $this->config->get('payment_fasterpay_public_key'),
                'privateKey' => $this->config->get('payment_fasterpay_private_key'),
                'isTest' => $this->config->get('payment_fasterpay_test_mode')
            ]);
        }

        return $this->gateway;
    }

    public function addAccountActivity($orderId)
    {
        $this->load->model('account/activity');

        if ($this->customer->isLogged()) {
            $activity_data = [
                'customer_id' => $this->customer->getId(),
                'name'        => $this->customer->getFirstName() . ' ' . $this->customer->getLastName(),
                'order_id'    => $orderId
            ];
            
            $this->model_account_activity->addActivity('order_account', $activity_data);
        } else {
            $activity_data = [
                'name'     => $this->session->data['guest']['firstname'] . ' ' . $this->session->data['guest']['lastname'],
                'order_id' => $orderId
            ];
            
            $this->model_account_activity->addActivity('order_guest', $activity_data);
        }
    }

    public function getDefaultPingbackUrl()
    {
        return $this->url->link('checkout/fasterpay_pingback');
    }
}