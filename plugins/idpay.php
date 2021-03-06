<?php

/*
 * payro24 Virtual Freer Payment gateway
 * http://freer.ir/virtual
 *
 * Copyright (c) 2018 payro24 Co, payro24.ir
 * Reference Document on https://payro24.ir/web-service
 * 
 */

/*
 *  Gateway information
 */
$pluginData['payro24']['type'] = 'payment';
$pluginData['payro24']['name'] = 'درگاه پرداخت پیرو';
$pluginData['payro24']['uniq'] = 'payro24';
$pluginData['payro24']['description'] = 'درگاه پرداخت الکترونیک <a href="https://payro24.ir">پیرو</a>';
$pluginData['payro24']['author']['name'] = 'payro24';
$pluginData['payro24']['author']['url'] = 'https://payro24.ir';
$pluginData['payro24']['author']['email'] = 'support@payro24.ir';

/*
 *  Gateway configuration
 */
$pluginData['payro24']['field']['config'][1]['title'] = 'API-Key';
$pluginData['payro24']['field']['config'][1]['name'] = 'api_key';
$pluginData['payro24']['field']['config'][2]['title'] = 'عنوان خرید';
$pluginData['payro24']['field']['config'][2]['name'] = 'title';
$pluginData['payro24']['field']['config'][3]['title'] = 'حالت آزمایشی درگاه (0 یا 1)';
$pluginData['payro24']['field']['config'][3]['name'] = 'sandbox';

/**
 * Create new payment on payro24
 * Get payment path and payment id.
 *
 * @param array $data
 *
 * @return void
 */
function gateway__payro24($data)
{
    global $db, $get, $smarty;

    $payment = $db->fetch('SELECT * FROM `payment` WHERE `payment_rand` = "'. $data[invoice_id] .'" LIMIT 1;');
    if ($payment && !empty($data['api_key']))
    {
        $api_key = $data['api_key'];
        $url = 'https://api.payro24.ir/v1.1/payment';
        $sandbox_mode = (!empty($data['sandbox']) && $data['sandbox'] != 0) ? 'true' : 'false';

        $params = array(
            'order_id'  => $data['invoice_id'],
            'callback'  => $data['callback'],
            'amount'    => $data['amount'],
            'phone'     => $payment['payment_mobile'],
            'desc'      => $data['title'] .'-'. $data['invoice_id'] .'-'. $payment['payment_email'],
        );
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Content-Type: application/json",
            "P-TOKEN: $api_key",
            "P-SANDBOX: $sandbox_mode"
        ));
        
        $result = curl_exec($ch);
        
        $result = json_decode($result);
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // Display warning message
        if ($http_status != 201 || empty($result) || empty($result->id) || empty($result->link)) {
            $data['title'] = 'خطای سیستم';
            $data['message'] = '<font color="red">در اتصال به درگاه پیرو مشکلی به وجود آمد٬ لطفا از درگاه سایر بانک‌ها استفاده نمایید.</font>'. $http_status .'<br /><a href="index.php" class="button">بازگشت</a>';
            $conf = $db->fetch('SELECT * FROM `config` WHERE `config_id` = "1" LIMIT 1');
            $smarty->assign('config', $conf);
            $smarty->assign('data', $data);
            $smarty->display('message.tpl');
            return;
        }
        
        // Save payment id
        $query = $db->queryUpdate('payment', array('payment_res_num' => $result->id), 'WHERE `payment_rand` = "'. $data['invoice_id'] .'" LIMIT 1;');
        $db->execute($query);
        
        // Redirect user to gateway
        header('Location:' . $result->link);
    }
}

/**
 * Payment callback
 * Inquiry payment result by trackId and orderId.
 *
 * @param array $data
 *
 * @return array
 */
function callback__payro24($data)
{
    global $db, $get;

    $status    = !empty($_POST['status'])  ? $_POST['status']   : (!empty($_GET['status'])  ? $_GET['status']   : NULL);
    $track_id  = !empty($_POST['track_id'])? $_POST['track_id'] : (!empty($_GET['track_id'])? $_GET['track_id'] : NULL);
    $id        = !empty($_POST['id'])      ? $_POST['id']       : (!empty($_GET['id'])      ? $_GET['id']       : NULL);
    $order_id  = !empty($_POST['order_id'])? $_POST['order_id'] : (!empty($_GET['order_id'])? $_GET['order_id'] : NULL);

    $output['status'] = 0;
    $output['message']= 'پرداخت انجام نشده است.';
       
    if (!empty($status) && !empty($order_id) && !empty($id) && !empty($data['api_key']))
    {
        if ($status == 100)
        {
            $payment = $db->fetch('SELECT * FROM `payment` WHERE `payment_rand` = "'. $order_id .'" LIMIT 1;');

            if ($payment['payment_status'] == 1 && $payment['payment_res_num'] == $id)
            {
                $api_key = $data['api_key'];
                $url = 'https://api.payro24.ir/v1.1/payment/verify';
                $sandbox_mode = (!empty($data['sandbox']) && $data['sandbox'] != 0) ? 'true' : 'false';
                
                $params = array(
                    'order_id'  => $payment['payment_rand'],
                    'id'        => $payment['payment_res_num'],
                );
                
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
                curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                    "Content-Type: application/json",
                    "P-TOKEN: $api_key",
                    "P-SANDBOX: $sandbox_mode"
                ));
                
                $result = curl_exec($ch);
                $result = json_decode($result);

                $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($http_status == 200 && !empty($result))
                {
                    // Successful payment
                    if ($result->status == 100 && $result->amount == $payment['payment_amount'])
                    {
                        $output['status']     = 1;
                        $output['res_num']    = $result->id;
                        $output['ref_num']    = $result->track_id;
                        $output['payment_id'] = $payment['payment_id'];
                    }
                    else
                    {
                        // Failed payment
                        $output['status'] = 0;
                        $output['message']= 'پرداخت توسط پیرو تایید نشد‌ : '. $result->status;
                    }
                }
            }
            else
            {
                // Double spending (paid invoice)
                $output['status'] = 0;
                $output['message']= 'سفارش قبلا پرداخت شده است.';
            }
        }
        else
        {
            // Canceled payment  
            $output['status'] = 0;
            $output['message']= 'بازگشت ناموفق تراکنش از درگاه پرداخت';
        }
    }
    return $output;
}