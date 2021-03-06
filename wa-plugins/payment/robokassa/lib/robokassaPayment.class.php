<?php

/**
 * @property-read string $merchant_login
 * @property-read string $merchant_pass1
 * @property-read string $merchant_pass2
 * @property-read string $merchant_test_pass1
 * @property-read string $merchant_test_pass2
 * @property-read string $hash
 * @property-read string $locale
 * @property-read string $testmode
 * @property-read string $gateway_currency
 * @property-read string $merchant_currency
 * @property-read int $lifetime
 * @property-read boolean $commission
 * @link http://docs.robokassa.ru/ru/
 *
 */
class robokassaPayment extends waPayment implements waIPayment
{
    private static $url = 'https://auth.robokassa.ru/Merchant/';

    private $order_id;
    private $request_testmode = 1;

    public function allowedCurrency()
    {
        return $this->merchant_currency ? $this->merchant_currency : 'RUB';
    }

    public function payment($payment_form_data, $order_data, $auto_submit = false)
    {
        $order = waOrder::factory($order_data);
        $description = preg_replace('/[^\.\?,\[]\(\):;"@\\%\s\w\d]+/', ' ', $order->description);
        $description = preg_replace('/[\s]{2,}/', ' ', $description);

        $sum = $order->total;
        if ($this->commission) {
            $sum = $this->getCommission($sum);
        }

        $form_fields = array(
            'MrchLogin' => $this->merchant_login,
            'OutSum'    => number_format($sum, 2, '.', ''),
            'InvId'     => $order->id,
        );

        $form_fields['SignatureValue'] = $this->getPaymentHash($form_fields);
        $form_fields['IsTest'] = sprintf('%d', !!$this->testmode);
        $form_fields['Desc'] = mb_substr($description, 0, 100, "UTF-8");
        $form_fields['IncCurrLabel'] = $this->gateway_currency;
        $form_fields['Culture'] = $this->locale;
        $form_fields['Encoding'] = 'utf-8';
        if (!empty($this->lifetime)) {
            $form_fields['ExpirationDate'] = date('c', strtotime(sprintf('+%d hour', max(1, $this->lifetime))));
        }


        $form_fields['shp_wa_app_id'] = $this->app_id;
        $form_fields['shp_wa_merchant_id'] = $this->merchant_id;
        $form_fields['shp_wa_testmode'] = intval($this->testmode);

        $view = wa()->getView();
        $form_url = self::$url.'Index.aspx';

        $view->assign(compact('form_fields', 'form_url', 'auto_submit'));

        return $view->fetch($this->path.'/templates/payment.html');
    }

    private function getPaymentHash($form_fields)
    {
        $hash_string = implode(':', $form_fields).':'.($this->testmode ? $this->merchant_test_pass1 : $this->merchant_pass1);
        $hash_string .= ':shp_wa_app_id='.$this->app_id;
        $hash_string .= ':shp_wa_merchant_id='.$this->merchant_id;

        $hash_string .= sprintf(':shp_wa_testmode=%d', $this->testmode);
        switch ($this->hash) {
            case 'sha256':
                if (function_exists('hash') && function_exists('hash_algos') && in_array('sha256', hash_algos())) {
                    $hash = hash($this->hash, $hash_string);
                } else {
                    throw new waException('sha256 not supported');
                }
                break;
            case 'sha1':
                $hash = sha1($hash_string);
                break;
            case 'md5':
            default:
                $hash = md5($hash_string);
                break;
        }
        return $hash;
    }


    protected function callbackInit($request)
    {
        if (!empty($request['InvId']) && intval($request['InvId'])) {
            $this->app_id = ifempty($request['shp_wa_app_id']);
            $this->merchant_id = ifempty($request['shp_wa_merchant_id']);
            $this->request_testmode = ifempty($request['shp_wa_testmode']);
            $this->order_id = intval($request['InvId']);
        } elseif (!empty($request['app_id'])) {
            $this->app_id = $request['app_id'];
        }
        return parent::callbackInit($request);
    }

    public function callbackHandler($request)
    {
        $transaction_data = $this->formalizeData($request);
        $transaction_result = ifempty($request['transaction_result'], 'success');

        $url = null;
        $app_payment_method = null;

        switch ($transaction_result) {
            case 'result':
                $this->verifySign($request);

                if ($this->commission) {
                    $amount = $this->getRates($transaction_data['amount']);
                    if ($amount && ($amount != $transaction_data['amount'])) {
                        $template = ' С магазина была удержана комиссия, фактическая полученная сумма %0.2f';
                        $transaction_data['view_data'] .= sprintf($template, $transaction_data['amount']);
                        $transaction_data['amount'] = $amount;
                    }
                }

                $app_payment_method = self::CALLBACK_PAYMENT;
                $transaction_data['state'] = self::STATE_CAPTURED;
                $transaction_data['type'] = self::OPERATION_AUTH_CAPTURE;
                break;
            case 'success':
                $url = $this->getAdapter()->getBackUrl(waAppPayment::URL_SUCCESS, $transaction_data);
                break;
            case 'failure':
                if ($this->order_id && $this->app_id) {
                    $app_payment_method = self::CALLBACK_CANCEL;
                    $transaction_data['state'] = self::STATE_CANCELED;
                }
                $url = $this->getAdapter()->getBackUrl(waAppPayment::URL_FAIL, $transaction_data);
                break;
            default:
                $url = $this->getAdapter()->getBackUrl(waAppPayment::URL_FAIL, $transaction_data);
                break;
        }

        if ($app_payment_method) {
            $transaction_data = $this->saveTransaction($transaction_data, $request);
            $this->execAppCallback($app_payment_method, $transaction_data);
        }
        if ($transaction_result == 'result') {
            echo 'OK'.$this->order_id;
            return array(
                'template' => false,
            );
        } else {
            if ($url) {
                return array(
                    'redirect' => $url,
                );
            } else {
                return array(
                    'template' => $this->path.'/templates/callback.html',
                    'back_url' => $url,
                );
            }
        }
    }

    protected function formalizeData($transaction_raw_data)
    {
        $transaction_data = parent::formalizeData($transaction_raw_data);
        $transaction_data['view_data'] = '';
        if ($this->testmode || $this->request_testmode) {
            $transaction_data['view_data'] = 'Тестовый режим';
        }
        $transaction_data['native_id'] = $this->order_id;
        $transaction_data['order_id'] = $this->order_id;
        $transaction_data['amount'] = ifempty($transaction_raw_data['OutSum'], '');

        $transaction_data['currency_id'] = $this->merchant_currency;
        return $transaction_data;
    }


    public static function settingGatewayCurrency($name, $params = array())
    {
        $default = array(
            'title_wrapper'       => false,
            'description_wrapper' => false,
            'control_wrapper'     => '%s%s%s',
        );
        $params = array_merge($params, $default);

        $data = array();


        if (!empty($params['instance']) && ($params['instance'] instanceof self)) {
            $data['MerchantLogin'] = $params['instance']->merchant_login;
        }

        $params['options'] = self::getCurrencyOptions($data);

        if (empty($params['options'])) {
            $message = <<<HTML
<span class="errormsg">
    Произошла ошибка при получении списка доступных способов оплаты шлюза (Детали в логе платежного плагина).
</span>
HTML;
            if (!empty($params['value'])) {
                $template = <<<HTML
<span class="hint">
    Текущее значение настройки: <b>%s</b>.
</span>
HTML;
                $message .= sprintf($template, htmlentities($params['value'], ENT_QUOTES, waHtmlControl::$default_charset));
            } else {
                $message .= <<<HTML
<span class="hint">
    Покупателю будет предложено самостоятельно выбрать способ оплаты на сайте платежного шлюза.
</span>
HTML;
            }
            return waHtmlControl::getControl(waHtmlControl::HIDDEN, $name, $params).$message;
        } else {
            return waHtmlControl::getControl(waHtmlControl::SELECT, $name, $params);
        }
    }

    protected function initControls()
    {
        $this->registerControl('GatewayCurrency');
    }

    private function getCommission($amount)
    {
        if (!$this->gateway_currency) {
            throw new waPaymentException('Для рассчета коммиссии необходимо выбрать способ оплаты');
        }
        $params = array(
            'MerchantLogin' => $this->merchant_login,
            'IncCurrLabel'  => $this->gateway_currency,
            'IncSum'        => number_format(doubleval($amount), 2, '.', ''),
        );
        $xml = self::queryXmlService('CalcOutSumm', $params);
        if ($rate = (double)$xml->OutSum) {
            return $rate;
        }
        throw new waPaymentException(sprintf('Не удалось рассчитать комиссию для %s', $this->gateway_currency));
    }

    private function getRates($amount)
    {
        if (!$this->gateway_currency) {
            throw new waPaymentException('Для рассчета коммиссии необходимо выбрать способ оплаты');
        }
        $params = array(
            'MerchantLogin' => $this->merchant_login,
            'IncCurrLabel'  => $this->gateway_currency,
            'OutSum'        => number_format(doubleval($amount), 2, '.', ''),
        );
        $xml = self::queryXmlService('GetRates', $params);

        $xpath = '/RatesList/Groups/Group/Items/Currency[@Label="%s"]/Rate';
        $xpath = sprintf($xpath, $this->gateway_currency);
        if ($namespaces = $xml->getNamespaces(true)) {
            $name = array();
            foreach ($namespaces as $id => $namespace) {
                $xml->registerXPathNamespace($name[] = 'wa'.$id, $namespace);
            }
            $xpath = preg_replace('@(^[/]*|[/]+)@', '$1'.implode(':', $name).':', $xpath);
        }

        if ($rates = $xml->xpath($xpath)) {
            $rate = reset($rates);
            return (double)$rate['IncSum'];
        }
        throw new waPaymentException(sprintf('Не удалось рассчитать комиссию для %s', $this->gateway_currency));
    }

    private static function getCurrencyOptions($data = array())
    {
        $options = array();
        try {
            $params = array(
                'MerchantLogin' => $data['MerchantLogin'],
            );

            $xml = self::queryXmlService('GetCurrencies', $params);

            $options[] = array(
                'title' => 'На выбор покупателя на сайте шлюза',
                'value' => '',
                'group' => 'Пользовательский',
            );
            foreach ($xml->Groups as $xml_group) {
                foreach ($xml_group->Group as $xml_group_item) {
                    foreach ($xml_group_item->Items as $xml_items) {
                        foreach ($xml_items as $xml_item) {
                            $options[] = array(
                                'title' => (string)$xml_item['Name'].' — '.(string)$xml_item['Alias'],
                                'value' => (string)$xml_item['Label'],
                                'group' => (string)$xml_group_item['Description'],
                            );
                        }
                    }
                }
            }

        } catch (waException $ex) {
            self::log(preg_replace('/payment$/', '', strtolower(__CLASS__)), $ex->getMessage());
        }
        return $options;
    }

    /**
     * @param string $service
     * @param string[] $params
     * @return SimpleXMLElement
     * @throws waException
     */
    private static function queryXmlService($service, $params = array())
    {
        $options = array(
            'format' => waNet::FORMAT_XML,
            'verify' => false,
        );

        if (!class_exists('waNet')) {
            throw new waPaymentException('Требуется актуальная версия фреймворка Webasyst');
        }

        if (empty($params['MerchantLogin'])) {
            $params['MerchantLogin'] = 'demo';
        }
        if (empty($params['Language'])) {
            $params['Language'] = 'ru';
        }

        $url = '%sWebService/Service.asmx/%s?%s';
        $net = new waNet($options);
        try {

            $xml = $net->query(sprintf($url, self::$url, $service, http_build_query($params)));
        } catch (waException $ex) {
            if ($message = $net->getResponse(true)) {
                self::log(preg_replace('/payment$/', '', strtolower(__CLASS__)), $ex->getMessage());
                self::log(preg_replace('/payment$/', '', strtolower(__CLASS__)), $message);
                throw new waPaymentException($message);
            } else {
                throw $ex;
            }
        }
        if ($code = (int)$xml->Result->Code) {
            $message = (string)$xml->Result->Description;
            self::log(preg_replace('/payment$/', '', strtolower(__CLASS__)), $code.': '.$message);
            throw new waPaymentException($message);
        }

        return $xml;
    }

    private function verifySign($request)
    {
        $password = $this->testmode ? $this->merchant_test_pass2 : $this->merchant_pass2;
        if ($password) {
            $hash_string = ifempty($request['OutSum'], '').':'.ifempty($request['InvId'], '').':'.$password;
            $hash_string .= ':shp_wa_app_id='.$this->app_id;
            $hash_string .= ':shp_wa_merchant_id='.$this->merchant_id;
            if (isset($request['shp_wa_testmode']) || $this->testmode) {
                if ((int)$this->testmode != ifset($request['shp_wa_testmode'], '0')) {
                    throw new waPaymentException('Invalid test mode request');
                }
            }
            $hash_string .= sprintf(':shp_wa_testmode=%d', $this->testmode);

            $sign = strtolower(md5($hash_string));
            $server_sign = strtolower(ifempty($request['SignatureValue'], ''));
            if (empty($server_sign) || ($server_sign != $sign)) {
                throw new waPaymentException('Invalid sign');
            }
        } else {
            throw new waPaymentException('Empty payment password');
        }
    }
}
