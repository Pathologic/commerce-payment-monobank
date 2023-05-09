<?php

namespace Commerce\Payments;

class MonobankPayment extends Payment
{
    public function __construct(\DocumentParser $modx, array $params = [])
    {
        parent::__construct($modx, $params);
        $this->lang = $modx->commerce->getUserLanguage('monobank');
    }

    public function getMarkup()
    {
        if (empty($this->getSetting('token'))) {
            return '<span class="error" style="color: red;">' . $this->lang['monobank.error.empty_client_credentials'] . '</span>';
        }

        return '';
    }

    public function getPaymentLink()
    {
        $processor = $this->modx->commerce->loadProcessor();
        $order = $processor->getOrder();
        $payment = $this->createPayment($order['id'], $order['amount']);
        switch($order['currency']) {
            case 'USD':
                $ccy = 840;
                break;
            case 'EUR':
                $ccy = 978;
                break;
            default:
                $ccy = 980;
        }
        $data = [
            'amount'           => (int) ($payment['amount'] * 100),
            'ccy'              => $ccy,
            'merchantPaymInfo' => [
                'reference'   => $order['id'] . '-' . $order['hash'],
                'destination' => ci()->tpl->parseChunk($this->lang['payments.payment_description'], [
                    'order_id'  => $order['id'],
                    'site_name' => $this->modx->getConfig('site_name'),
                ]),
            ],
            'redirectUrl'      => MODX_SITE_URL . 'commerce/monobank/payment-process?' . http_build_query(['paymentHash' => $payment['hash']]),
            'webHookUrl'       => MODX_SITE_URL . 'commerce/monobank/payment-process?' . http_build_query(['paymentHash' => $payment['hash']]),
        ];

        $items = $this->prepareItems($processor->getCart());

        $isPartialPayment = $payment['amount'] < $order['amount'];

        if ($isPartialPayment) {
            $items = $this->decreaseItemsAmount($items, $order['amount'], $payment['amount']);
        }

        $products = [];

        foreach ($items as $item) {
            $products[] = [
                'name'   => mb_substr($item['name'], 0, 127),
                'qty'    => (int) $item['count'],
                'sum'    => (int) ($item['price'] * 100),
                'code'   => $item['id']
            ];
        }

        $data['merchantPaymInfo']['basketOrder'] = $products;
        $response = $this->request('invoice/create', $data);
        if (isset($response['pageUrl'])) {
            return $response['pageUrl'];
        }

        return false;
    }

    public function handleCallback()
    {
        if (!isset($_GET['paymentHash']) || !is_string($_GET['paymentHash']) || !preg_match('/^[a-z0-9]+$/',
                $_GET['paymentHash'])) {
            return false;
        }
        $data = file_get_contents('php://input');
        if ($this->getSetting('debug')) {
            $this->modx->logEvent(0, 1, htmlentities(print_r($data, true)),
                'Commerce Monobank Payment Callback Start');
        }
        if (empty($data)) {
            $processor = $this->modx->commerce->loadProcessor();

            try {
                $payment = $processor->loadPaymentByHash($_GET['paymentHash']);

                if (!$payment) {
                    throw new Exception('Payment "' . htmlentities(print_r($_GET['paymentHash'],
                            true)) . '" . not found!');
                }

                if ($payment['paid'] == '1') {
                    $this->modx->sendRedirect(MODX_BASE_URL . 'commerce/monobank/payment-success?paymentHash=' . $_GET['paymentHash']);
                } else {
                    $this->modx->sendRedirect(MODX_BASE_URL . 'commerce/monobank/payment-failed?paymentHash=' . $_GET['paymentHash']);
                }
            } catch (Exception $e) {
                $this->modx->logEvent(0, 3, 'Payment process failed: ' . $e->getMessage(),
                    'Commerce Monobank Payment');

                return false;
            }
        } else {
            $data = json_decode($data, true) ?? [];
            if(!isset($data['invoiceId'])) return false;
        }
        $response = $this->request('invoice/status', ['invoiceId' => $data['invoiceId']]);
        if (isset($response['status']) && $response['status'] === 'success') {
            $amount = number_format($response['amount'] / 100, 2);
            $processor = $this->modx->commerce->loadProcessor();

            try {
                $payment = $processor->loadPaymentByHash($_GET['paymentHash']);

                if (!$payment) {
                    throw new Exception('Payment "' . htmlentities(print_r($_GET['paymentHash'],
                            true)) . '" . not found!');
                }

                $processor->processPayment($payment, $amount);
            } catch (Exception $e) {
                $this->modx->logEvent(0, 3, 'Payment process failed: ' . $e->getMessage(),
                    'Commerce Monobank Payment');

                return false;
            }

            return true;
        } else {
            return false;
        }
    }

    /**
     * @param  string  $method
     * @param  array  $data
     * @return array
     */
    protected function request(string $method, array $data = []): array
    {
        $url = 'https://api.monobank.ua/api/merchant/';
        $headers = [
            'Content-Type: application/json',
            'X-Token: ' . $this->getSetting('token')
        ];
        $options = [
            CURLOPT_URL            => $url . $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
        ];
        if($method == 'invoice/create') {
            if (is_array($data) && !empty($data)) {
                $data = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            }
            $options[CURLOPT_POSTFIELDS] = $data;
        } else {
            $options[CURLOPT_URL] .= '?' . http_build_query($data);
        }


        $ch = curl_init();
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);

        if ($this->getSetting('debug')) {
            $this->modx->logEvent(0, 1, "URL: <pre>$url</pre>\n\nHeaders: <pre>" . htmlentities(print_r($headers,
                    true)) . "</pre>\n\nRequest data: <pre>" . htmlentities(print_r($data,
                    true)) . "</pre>\n\nResponse data: <pre>" . htmlentities(print_r($response,
                    true)) . "</pre>" . (curl_errno($ch) ? "\n\nError: <pre>" . htmlentities(curl_error($ch)) . "</pre>" : ''),
                'Commerce Monobank Payment Debug: request');
        }

        curl_close($ch);

        return json_decode($response, true) ?? [];
    }
}
