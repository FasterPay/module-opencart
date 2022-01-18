<?php

class ControllerExtensionPaymentFasterPay extends Controller
{
    public function index()
    {
        $this->language->load('extension/payment/fasterpay');

        $data['pay_via_fasterpay'] = $this->language->get('pay_via_fasterpay');
        $data['widget_link'] = $this->url->link('extension/payment/fasterpay/processPayment', '', 'SSL');

        if (file_exists(DIR_TEMPLATE . $this->config->get('config_template') . '/template/extension/payment/fasterpay')) {
            return $this->load->view($this->config->get('config_template') . '/template/extension/payment/fasterpay', $data);
        }

        return $this->load->view('extension/payment/fasterpay', $data);
    }

    public function processPayment()
    {   
        $this->load->model('checkout/order');
        $this->load->model('extension/payment/fasterpay');
        $this->language->load('extension/payment/fasterpay');
        
        if (empty($this->session->data['order_id'])) {
            // Redirect to shopping cart
            $this->response->redirect($this->url->link('checkout/cart'));
        }

        $orderInfo = $this->model_checkout_order->getOrder($this->session->data['order_id']);
        
        $orderInfo['total'] = $this->currency->format($orderInfo['total'], $orderInfo['currency_code']);

        $this->cart->clear();
        unset($this->session->data['order_id']);

        // Add to activity log
        $this->model_extension_payment_fasterpay->addAccountActivity($orderInfo['order_id']);
        $data = $this->prepareViewData($orderInfo);
        
        if (file_exists(DIR_TEMPLATE . $this->config->get('config_template') . '/template/extension/payment/fasterpay_widget')) {
            $template = $this->config->get('config_template') . '/template/extension/payment/fasterpay_widget';
        } else { 
            $template = 'extension/payment/fasterpay_widget';
        }
        
        $viewData = $this->load->view($template, $data);
        
        $this->response->setOutput($viewData);
    }

    protected function prepareViewData($orderInfo)
    {
        $this->document->setTitle($this->language->get('widget_title'));

        $data['form'] = $this->model_extension_payment_fasterpay->generateForm($orderInfo);

        return $data;
    }
}
