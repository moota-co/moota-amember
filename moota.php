<?php
/**
 * @table paysystems
 * @id offline
 * @title Moota
 * @author Onnay Okheng <onnay.okheng@gmail.com> - Moota.co
 * @recurring none
 * @country ID
 */

class Am_Paysystem_Moota extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const PLUGIN_REVISION = '1.5.0';

    public function __construct(Am_Di $di, array $config)
    {
        $this->defaultTitle = ___("Transfer Bank (by Moota.co)");
        $this->defaultDescription = ___("Transfer manual ke Bank BCA atau Mandiri");
        parent::__construct($di, $config);
        $this->addUniqueNumber();
    }

    public function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }

    public function addUniqueNumber()
    {
        $uniqueNumber = $this->getConfig('unique_number');
        $uniqueNumberType = $this->getConfig('unique_number_type');


        if ($uniqueNumber == 'yes') {
            Am_Di::getInstance()->hook->add(Am_Event::INVOICE_GET_CALCULATORS, function (Am_Event $e) use ($uniqueNumberType) {
                if (@$GLOBALS['add_fraction']++) {
                    return;
                }
                $invoice = $e->getInvoice();
                $item = $invoice->getItem(0);
                if ($item->data()->get('orig_first_price') <= 0) {
                    return;
                }

                $id = $invoice->pk() ?: $e->getDi()->db->selectCell("SELECT MAX(invoice_id)+1 FROM ?_invoice;");

                if ($item && !$item->data()->get('add_fraction')) {
                    $item->data()->set('add_fraction', 1);

                    // append custom calculator 
                    $calculators = $e->getReturn();
                    $calculators[] = new MootaCalculator($e->getInvoice(), $uniqueNumberType, $id);
                                        
                    $e->setReturn($calculators);
                    
                    
                }
                
                unset($GLOBALS['add_fraction']);
            });
        }
    }

    public function getSupportedCurrencies()
    {
        return array('IDR');
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addHtml()
            ->setHtml('Moota Plugin v.' . self::PLUGIN_REVISION);

        $moota_mode = $form->addSelect('moota_mode')->setLabel(___('Mode'));
        $moota_mode->addRule('required');
        $moota_mode->addOption('*** ' . ___('Pilih mode') . ' ***', '');
        $moota_mode->addOption('Testing', 'testing');
        $moota_mode->addOption('Production', 'production');

        $form->addText('api_key', array('class' => 'el-wide'))
            ->setLabel('Kode API Moota')
            ->addRule('required');
        $label = Am_Html::escape(___('di sini'));
        $url = 'http://app.moota.co/integrations/token';
        $text = ___('Kode API Moota bisa didapat ');
        $form->addHtml()
            ->setHtml(<<<CUT
$text <a href="$url" class="link">$label</a>.
CUT
            );

        $unique_number = $form->addSelect('unique_number')->setLabel(___('Tambah kode unik?'));
        $unique_number->addRule('required');
        $unique_number->addOption('*** ' . ___('Pilih') . ' ***', '');
        $unique_number->addOption('Ya', 'yes');
        $unique_number->addOption('Tidak', 'no');

        $unique_number_type = $form->addSelect('unique_number_type')->setLabel(___('Tipe kode unik'));
        $unique_number_type->addRule('required');
        $unique_number_type->addOption('*** ' . ___('Pilih') . ' ***', '');
        $unique_number_type->addOption('Tambahkan', 'plus');
        $unique_number_type->addOption('Kurangi', 'minus');

        $form->addTextarea("html", array('class' => 'el-wide', "rows"=>20))->setLabel(
                ___("Instruksi pembayaran untuk konsumen\n".
                "Anda bisa menggunakan kode HTML di form ini,\n".
                "dan akan tampil di konsumen Anda ketika memilih metode pembayaran ini\n".
                "Berikut tag yang bisa Anda gunakan untuk kontennya:\n".
                "%s - Struk tagihan HTML\n".
                "%s - Judul Produk\n".
                "%s - Nomor Invoice\n".
                "%s - Total pembayaran", '%receipt_html%', '%invoice_title%', '%invoice.public_id%', '%invoice.first_total%'))
            ->setValue(<<<CUT
%receipt_html%

Pembayaran bisa melalui 2 rekening di bawah ini : 


BANK BCA 

1234567890
A.n. Nama Anda


BANK MANDIRI 

1234567890
A.n. Nama Anda

---------------------------------------------------------------------
Jangan lupa masukan Invoice ID >> %invoice.public_id% << pada kolom keterangan saat transfer 

Konfirmasi pembayaran silahkan klik link dibawah:
(silahkan cantumkan link konfirmasi bila ada).
CUT
            );

        $label = Am_Html::escape(___('Moota.co'));
        $url = 'https://moota.co';
        $text = ___('Dibuat dan diintegrasikan oleh ');
        $form->addHtml()
            ->setHtml(<<<CUT
$text <a href="$url" class="link">$label</a>.
CUT
                );
    }

    public function _process($invoice, $request, $result)
    {
        unset($this->getDi()->session->cart);
        if ((float)$invoice->first_total == 0) {
            $invoice->addAccessPeriod(new Am_Paysystem_Transaction_Free($this));
        }
        $result->setAction(
            new Am_Paysystem_Action_Redirect(
                $this->getDi()->url("payment/".$this->getId()."/instructions",
                    array('id'=>$invoice->getSecureId($this->getId())), false)
            )
        );
    }
    public function directAction($request, $response, $invokeArgs)
    {
        $actionName = $request->getActionName();
        $invoiceLog = $this->_logDirectAction($request, $response, $invokeArgs);
        switch ($actionName) {
            case 'instructions':
                $invoice = $this->getDi()->invoiceTable->findBySecureId($request->getFiltered('id'), $this->getId());
                if (!$invoice) {
                    throw new Am_Exception_InputError(___("Sorry, seems you have used wrong link"));
                }
                $view = new Am_View;
                $html = $this->getConfig('html', 'Instruksi untuk pembayaran ini belum ada.');

                $tpl = new Am_SimpleTemplate;
                $tpl->receipt_html = $view->partial('_receipt.phtml', array('invoice' => $invoice, 'di' => $this->getDi()));
                $tpl->invoice = $invoice;
                $tpl->user = $this->getDi()->userTable->load($invoice->user_id);
                $tpl->invoice_id = $invoice->invoice_id;
                $tpl->cancel_url = $this->getDi()->url('cancel', array('id'=>$invoice->getSecureId('CANCEL')), false);
                $tpl->invoice_title = $invoice->getLineDescription();

                $view->invoice = $invoice;
                $view->content = $tpl->render($html) . $view->blocks('payment/offline/bottom');
                $view->title = $this->getTitle();
                $response->setBody($view->render("layout.phtml"));
                break;
            case "verify":
                $ipnUrl = $this->getPluginUrl('ipn');
                $token = $this->getConfig('api_key');
                $resultsTransactions = array();

                /** Check if push notification has authorize header */
                if (!$this->moota_check_authorize()) {
                    die("Need Authorize");
                    return;
                }

                $notifications = json_decode(file_get_contents("php://input"));
                $notifications = json_decode($notifications);

                // Logging notification dari moota.co

                $path = dirname(__FILE__)."/Moota-Data";

                if(!file_exists($path)){
                    mkdir($path);
                } else {
                    if(!is_dir($path)){
                        unlink($path);
                        mkdir($path);
                    }

                    $logfile = $path."/log.json";

                    if(!file_exists($logfile)){
                        $log = [
                            "created_at"    =>  date('Y-m-d'),
                            "delete_on"     =>  date("Y-m-d", strtotime(" + 4 Days")),
                            "data"          =>  []
                        ];

                        $log["data"][] = $notifications;

                        file_put_contents($logfile, json_encode($log));
                    } else {
                        $prev_log = json_decode(file_get_contents($logfile), true);
                        if(is_array($prev_log)){
                            if($prev_log["delete_on"] == date("Y-m-d")){
                                $log = [
                                    "created_at"    =>  date('Y-m-d'),
                                    "delete_on"     =>  date("Y-m-d", strtotime(" + 4 Days")),
                                    "data"          =>  []
                                ];
                                
                                $log["data"][]  =   $notifications;
                                file_put_contents($logfile, json_encode($log));
                            } else {
                                $prev_log["data"][] = $notifications;
                                file_put_contents($logfile, json_encode($prev_log));
                            }
                        }
                    }
                }

                // End Logging


                if (count($notifications) > 0) {
                    foreach ($notifications as $notification) {
                        $request = new Am_HttpRequest($ipnUrl . "?apikey={$token}", Am_HttpRequest::METHOD_POST);
                        $request->addPostParameter(array(
                            'id' => $notification->id,
                            'bank_id' => $notification->bank_id,
                            'account_number' => $notification->account_number,
                            'bank_type' => $notification->bank_type,
                            'date' => $notification->date,
                            'amount' => $notification->amount,
                            'description' => $notification->description,
                            'type' => $notification->type,
                            'balance' => $notification->balance
                        ));

                        $log = $this->logRequest($request);
                        $responce = $request->send();
                        $log->add($responce);

                        if ($responce->getStatus() == 200) {
                            $r = json_decode($responce->getBody(), true);
                            if (!empty($r)) {
                                $resultsTransactions[] = $r;
                            }
                        }
                    }
                }
                header('Content-Type: application/json');
                echo json_encode($resultsTransactions);
                exit;
                break;
            case 'ipn':

                /** Check if push notification has authorize header */
                if (!$this->moota_check_authorize()) {
                    die("Need Authorize");
                    return;
                }
                
                $data = array();
                try {
                    $transaction = $this->createTransaction($request, $response, $invokeArgs);
                    if (!$transaction) {
                        throw new Am_Exception_InputError("Request not handled - createTransaction() returned null");
                    }
                    $invoice_id = $transaction->findInvoiceId();
                    if ($invoice_id == false) {
                        $data = array(
                            'status' => 'duplicate-order',
                            'amount' => $request->get('amount'),
                            'transaction_id' => $request->get('id'),
                            'bank_type' => $request->get('bank_type'),
                        );
                        header('Content-Type: application/json');
                        echo json_encode($data);
                        exit;
                        break;
                    } else if ($invoice_id == null) {
                        $data = array(
                            'status' => 'not-found',
                            'amount' => $request->get('amount'),
                            'transaction_id' => $request->get('id'),
                            'bank_type' => $request->get('bank_type'),
                        );
                        header('Content-Type: application/json');
                        echo json_encode($data);
                        exit;
                        break;
                    }
                    $transaction->setInvoiceLog($invoiceLog);
                    try {
                        $transaction->process();
                    } catch (Exception $e) {
                        if ($invoiceLog) {
                            $invoiceLog->add($e);
                        }
                        throw $e;
                    }
                    if ($invoiceLog) {
                        $invoiceLog->setProcessed();
                    }
                    
                    $data = array(
                        'invoice_id' => $invoice_id,
                        'status' => 'completed',
                        'amount' => $request->get('amount'),
                        'transaction_id' => $request->get('id'),
                        'bank_type' => $request->get('bank_type'),
                    );
                } catch (Exception $e) {
                    $data = array();
                }

                header('Content-Type: application/json');
                echo json_encode($data);
                exit;
                break;
            default:
                return parent::directAction($request, $response, $invokeArgs);
        }
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Moota($this, $request, $response, $invokeArgs);
    }

    public function cancelAction(Invoice $invoice, $actionName, Am_Paysystem_Result $result)
    {
        $result->setSuccess();
        $invoice->setCancelled(true);
    }

    public function getReadme()
    {
        $verify = $this->getPluginUrl('verify');
        return <<<CUT
<strong>Tutorial cara integrasi dengan Moota:</strong>
- Salin link berikut ini - <strong>$verify</strong>.
- Lalu login ke website Moota (Pastikan Anda sudah mempunyai akun bank yang sudah didaftarkan).
- Edit akun bank, dan masuk ke tab "Notifikasi".
- Edit Push Notif: dan masukkan link tadi <strong>$verify</strong>.
- Atur min menjadi 0, dan max menjadi 999.
- Simpan, dan beres.
CUT;
    }
    /**
     * Check Moota Authorize
     * @return bool
     */
    public function moota_check_authorize()
    {
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            if (strpos(strtolower($_SERVER['HTTP_AUTHORIZATION']), 'basic')===0) {
                list($token, $other) = explode(':', substr($_SERVER['HTTP_AUTHORIZATION'], 6));
                if ($this->getConfig('moota_mode') == 'production' && $this->getConfig('api_key') == $token) {
                    return true;
                } elseif ($this->getConfig('moota_mode') == 'testing' && $this->getConfig('api_key') == $token) {
                    return true;
                }
            }
        } elseif (isset($_GET['apikey'])) {
            $token = $_GET['apikey'];
            if ($this->getConfig('moota_mode') == 'production' && $this->getConfig('api_key') == $token) {
                return true;
            } elseif ($this->getConfig('moota_mode') == 'testing' && $this->getConfig('api_key') == $token) {
                return true;
            }
        }
        return false;
    }
}



class Am_Paysystem_Transaction_Moota extends Am_Paysystem_Transaction_Incoming
{
    public function getUniqId()
    {
        $bank_type = $this->request->get('bank_type');
        $transaction_id = $this->findInvoiceId();
        return "{$bank_type}.{$transaction_id}";
    }

    public function validateSource()
    {
        return true; //@see findInvoiceId
    }

    public function validateStatus()
    {
        return true;
    }

    public function validateTerms()
    {
        return true;
    }

    public function findInvoiceId()
    {
        $amount = $this->request->get('amount');
        $invoice = Am_Di::getInstance()->db->select("SELECT * FROM ?_invoice WHERE status = 0 AND first_total = ?d AND tm_added > NOW() - INTERVAL 1 WEEK", $amount);
        if (count($invoice) > 1) {
            return false;
        }
        return !empty($invoice) ? $invoice[0]['public_id'] : null;
    }
}



/**
 * @author Muhammad Azamuddin <mas.azamuddin@gmail.com> 
 * @class
 */

class MootaCalculator extends Am_Invoice_Calc{
    /** @var Coupon */
    protected $coupon;
    protected $user;

    public $uniqueNumberType;
    public $id;

    public function __construct($invoice, $uniqueNumberType, $id){
        $this->uniqueNumberType = $uniqueNumberType;
        $this->id = $id;
    }
    

    public function calculate(Invoice $invoiceBill)
    {
        $this->coupon = $invoiceBill->getCoupon();
        $this->user = $invoiceBill->getUser();
        $isFirstPayment = $invoiceBill->isFirstPayment();

        $uniqueNumberType = $this->uniqueNumberType;
        $id = $this->id;

        $unique_code = $id % 999;

        foreach ($invoiceBill->getItems() as $item) {
            $item->first_discount = $item->second_discount = 0;
            if(!$this->coupon){
                /** PENTING INI */
                if($uniqueNumberType == 'plus'){
                    $item->first_price += $unique_code;
                } else {
                    $item->first_price -= $unique_code;
                }

                if($uniqueNumberType == 'plus'){
                    $item->second_price += $unique_code;
                } else {
                    $item->second_price -= $unique_code;
                }
            }
            $item->_calculateTotal();
        }

        if (!$this->coupon) return;

        if ($this->coupon->getBatch()->discount_type == Coupon::DISCOUNT_PERCENT){
            foreach ($invoiceBill->getItems() as $item) {
            	if (intval($this->coupon->getBatch()->discount) < 100) {
	                if ($this->coupon->isApplicable($item, $isFirstPayment)) {
	                    $item->first_discount = $item->qty * moneyRound($item->first_price * $this->coupon->getBatch()->discount / 100);

	                    /** PENTING INI */
	                    if($uniqueNumberType == 'plus'){
	                        $item->first_discount -= $unique_code;
	                    } else {
	                        $item->first_discount += $unique_code;
	                    }
	                } 
	                if ($this->coupon->isApplicable($item, false)) {
	                    $item->second_discount = $item->qty * moneyRound($item->second_price * $this->coupon->getBatch()->discount / 100);


	                    /** PENTING INI */
	                    if($uniqueNumberType == 'plus'){
	                        $item->second_discount -= $unique_code;
	                    } else {
	                        $item->second_discount += $unique_code;
	                    }
	                }
            	} else {
            		if ($this->coupon->isApplicable($item, $isFirstPayment))
            			$item->first_discount = $item->qty * moneyRound($item->first_price * $this->coupon->getBatch()->discount / 100);

            		if ($this->coupon->isApplicable($item, false))
            			$item->second_discount = $item->qty * moneyRound($item->second_price * $this->coupon->getBatch()->discount / 100);
            	}
            }
        } else { // absolute discount
            $discountFirst = $this->coupon->getBatch()->discount;
            $discountSecond = $this->coupon->getBatch()->discount;

            $first_discountable = $second_discountable = array();
            $first_total = $second_total = 0;
            $second_total = array_reduce($second_discountable, function($s, $item) {return $s+=$item->second_total;}, 0);
            foreach ($invoiceBill->getItems() as $item) {
                if ($this->coupon->isApplicable($item, $isFirstPayment)) {
                    $first_total += $item->first_total;
                    $first_discountable[] = $item;
                }
                if ($this->coupon->isApplicable($item, false)) {
                    $second_total += $item->second_total;
                    $second_discountable[] = $item;
                }
            }
            if ($first_total) {
                $k = max(0,min($discountFirst / $first_total, 1)); // between 0 and 1!
                foreach ($first_discountable as $item) {
                    $item->first_discount = moneyRound($item->first_total * $k);
                    /** PENTING INI */
                    if($uniqueNumberType == 'plus'){
                        $item->first_discount -= $unique_code;
                    } else {
                        $item->first_discount += $unique_code;
                    }
                }
            }
            if ($second_total) {
                $k = max(0,min($discountSecond / $second_total, 1)); // between 0 and 1!
                foreach ($second_discountable as $item) {
                    $item->second_discount = moneyRound($item->second_total * $k);
                    /** PENTING INI */
                    if($uniqueNumberType == 'plus'){
                        $item->second_discount -= $unique_code;
                    } else {
                        $item->second_discount += $unique_code;
                    }
                }
            }
        }

        foreach ($invoiceBill->getItems() as $item) {
            $item->_calculateTotal();
        }
    }
}