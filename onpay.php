<?php
$nzshpcrt_gateways[$num]['name'] = 'Onpay';
$nzshpcrt_gateways[$num]['internalname'] = 'onpay';
$nzshpcrt_gateways[$num]['function'] = 'gateway_onpay';
$nzshpcrt_gateways[$num]['form'] = "form_onpay";
$nzshpcrt_gateways[$num]['submit_function'] = "submit_onpay";
$nzshpcrt_gateways[$num]['display_name'] = 'Оплата через платежный интегратор Onpay.ru';
$nzshpcrt_gateways[$num]['image'] = WPSC_URL . '/images/onpay.gif';

function to_float($sum) {
    if (strpos($sum, ".")) {
        $sum = round($sum, 2);
    } else {
        $sum = $sum . ".0";
    }
    return $sum;
}

function gateway_onpay($separator, $sessionid) {
    global $wpdb;
    $purchase_log_sql = "SELECT * FROM `" . WPSC_TABLE_PURCHASE_LOGS . "` WHERE `sessionid`= " . $sessionid . " LIMIT 1";
    $purchase_log = $wpdb->get_results($purchase_log_sql, ARRAY_A);
    $cart_sql = "SELECT * FROM `" . WPSC_TABLE_CART_CONTENTS . "` WHERE `purchaseid`='" . $purchase_log[0]['id'] . "'";
    $cart = $wpdb->get_results($cart_sql, ARRAY_A);
    $onpay_url = get_option('onpay_url') . get_option('onpay_login');
    $data['pay_for'] = $purchase_log[0]['id'];
    $data['currency'] = get_option('onpay_curcode');
    $data['url_success'] = get_option('siteurl') . "/?onpay_callback=true";
    $data['pay_mode'] = 'fix';
    $email_data = $wpdb->get_results("SELECT `id`,`type` FROM `" . WPSC_TABLE_CHECKOUT_FORMS . "` WHERE `type` IN ('email') AND `active` = '1'", ARRAY_A);
    foreach ((array) $email_data as $email) {
        $data['user_email'] = $_POST['collected_data'][$email['id']];
    }
    if (($_POST['collected_data'][get_option('email_form_field')] != null) && ($data['email'] == null)) {
        $data['user_email'] = $_POST['collected_data'][get_option('email_form_field')];
    }
    $currency_code = $wpdb->get_results("SELECT `code` FROM `" . WPSC_TABLE_CURRENCY_LIST . "` WHERE `id`='" . get_option('currency_type') . "' LIMIT 1", ARRAY_A);
    $local_currency_code = $currency_code[0]['code'];
    $onpay_currency_code = get_option('onpay_curcode');

    $curr = new CURRENCYCONVERTER();
    $decimal_places = 2;
    $total_price = 0;
    $i = 1;
    $all_donations = true;
    $all_no_shipping = true;
    foreach ($cart as $item) {
        $product_data = $wpdb->get_results("SELECT * FROM `" . $wpdb->posts . "` WHERE `id`='" . $item['prodid'] . "' LIMIT 1", ARRAY_A);
        $product_data = $product_data[0];
        $variation_count = count($product_variations);
        $variation_sql = "SELECT * FROM `" . WPSC_TABLE_CART_ITEM_VARIATIONS . "` WHERE `cart_id`='" . $item['id'] . "'";
        $variation_data = $wpdb->get_results($variation_sql, ARRAY_A);
        $variation_count = count($variation_data);
        if ($variation_count >= 1) {
            $variation_list = " (";
            $j = 0;
            foreach ($variation_data as $variation) {
                if ($j > 0) {
                    $variation_list .= ", ";
                }
                $value_id = $variation['venue_id'];
                $value_data = $wpdb->get_results("SELECT * FROM `" . WPSC_TABLE_VARIATION_VALUES . "` WHERE `id`='" . $value_id . "' LIMIT 1", ARRAY_A);
                $variation_list .= $value_data[0]['name'];
                $j++;
            }
            $variation_list .= ")";
        } else {
            $variation_list = '';
        }
        $local_currency_productprice = $item['price'];
        $local_currency_shipping = $item['pnp'];
        $onpay_currency_productprice = $local_currency_productprice;
        $onpay_currency_shipping = $local_currency_shipping;
        $data['amount_' . $i] = number_format(sprintf("%01.2f", $onpay_currency_productprice), $decimal_places, '.', '');
        $data['quantity_' . $i] = $item['quantity'];
        $total_price = $total_price + ($data['amount_' . $i] * $data['quantity_' . $i]);
        if ($all_no_shipping != false)
            $total_price = $total_price + $data['shipping_' . $i] + $data['shipping2_' . $i];
        $i++;
    }
    $base_shipping = $purchase_log[0]['base_shipping'];
    if (($base_shipping > 0) && ($all_donations == false) && ($all_no_shipping == false)) {
        $data['handling_cart'] = number_format($base_shipping, $decimal_places, '.', '');
        $total_price += number_format($base_shipping, $decimal_places, '.', '');
    }
    $data['price'] = $total_price;
    $onpay_key = get_option('onpay_key');
    $sum_for_md5 = to_float($data['price']);
    $data['md5'] = md5("fix;$sum_for_md5;" . $data['currency'] . ";" . $data['pay_for'] . ";yes;$onpay_key");
    if (WPSC_GATEWAY_DEBUG == true) {
        exit("<pre>" . print_r($data, true) . "</pre>");
    }
    $output = "
  	<form id=\"onpay_form\" name=\"onpay_form\" method=\"post\" action=\"$onpay_url\">\n";

    foreach ($data as $n => $v) {
        $output .= "			<input type=\"hidden\" name=\"$n\" value=\"$v\" />\n";
    }

    $output .= "			<input type=\"submit\" value=\"Continue to onpay\" />
		</form>
	";

    if (get_option('onpay_debug') == 1) {
        echo ("DEBUG MODE ON!!<br/>");
        echo("The following form is created and would be posted to onpay for processing.  Press submit to continue:<br/>");
        echo("<pre>" . htmlspecialchars($output) . "</pre>");
    }

    echo($output);

    if (get_option('onpay_debug') == 0) {
        echo "<script language=\"javascript\" type=\"text/javascript\">document.getElementById('onpay_form').submit();</script>";
    }

    exit();
}

function answer($type, $code, $pay_for, $order_amount, $order_currency, $text, $key) {

    $md5 = strtoupper(md5("$type;$pay_for;$order_amount;$order_currency;$code;$key"));
    return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<result>\n<code>$code</code>\n<pay_for>$pay_for</pay_for>\n<comment>$text</comment>\n<md5>$md5</md5>\n</result>";
}

function answerpay($type, $code, $pay_for, $order_amount, $order_currency, $text, $onpay_id, $key) {

    $md5 = strtoupper(md5("$type;$pay_for;$onpay_id;$pay_for;$order_amount;$order_currency;$code;$key"));
    return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<result>\n<code>$code</code>\n <comment>$text</comment>\n<onpay_id>$onpay_id</onpay_id>\n <pay_for>$pay_for</pay_for>\n<order_id>$pay_for</order_id>\n<md5>$md5</md5>\n</result>";
}

function nzshpcrt_onpay_callback() {
    global $wpdb;
    if ($_REQUEST['type'] == 'check' || $_REQUEST['type'] == 'pay') {
        $onpay_key = get_option('onpay_key');
        $order_amount = $_REQUEST['order_amount'];
        $onpay_id = $_REQUEST['onpay_id'];
        $order_currency = $_REQUEST['order_currency'];
        $pay_for = $pay_for = $_REQUEST['pay_for'];
        $md5 = $_REQUEST['md5'];

        switch ($_REQUEST['type']) {
            case 'check':
                $purchase_log_sql = "SELECT * FROM `" . WPSC_TABLE_PURCHASE_LOGS . "` WHERE `id`= " . $pay_for . " LIMIT 1";
                $purchase_log = $wpdb->get_results($purchase_log_sql, ARRAY_A);
                if ($purchase_log[0]['totalprice'] == $order_amount) {
                    echo(answer($_REQUEST['type'], 0, $pay_for, $order_amount, $order_currency, 'OK', $onpay_key)); //Отвечаем серверу OnPay, что все хорошо, можно принимать деньги
                    exit;
                } else {
                    $rezult = 'Bad payment!';
                };
                break;

            case 'pay':
                $md5fb = strtoupper(md5($_REQUEST['type'] . ";" . $pay_for . ";" . $onpay_id . ";" . $order_amount . ";" . $order_currency . ";" . $onpay_key . ""));
                if ($md5fb != $md5) {
                    echo(answerpay($_REQUEST['type'], 7, $pay_for, $order_amount, $order_currency, 'Md5 signature is wrong', $onpay_id, $onpay_key));
                } else {
                    echo(answerpay($_REQUEST['type'], 0, $pay_for, $order_amount, $order_currency, 'OK', $onpay_id, $onpay_key));
                    $wpdb->update(WPSC_TABLE_PURCHASE_LOGS, array('processed' => 3, 'date' => time()), array('id' => $pay_for), array('%d', '%s'), array('%d'));
                    exit;
                }
                break;

            default:
                echo 'Not check or pay';
                break;
        }
    }
}

function nzshpcrt_onpay_results() {
    if (isset($_POST['cs1']) && ($_POST['cs1'] != '') && ($_GET['sessionid'] == '')) {
        $_GET['sessionid'] = $_POST['cs1'];
    }
}

function submit_onpay() {
    if (isset($_POST['onpay_login'])) {
        update_option('onpay_login', $_POST['onpay_login']);
    }

    if (isset($_POST['onpay_key'])) {
        update_option('onpay_key', $_POST['onpay_key']);
    }


    if (isset($_POST['onpay_curcode'])) {
        update_option('onpay_curcode', $_POST['onpay_curcode']);
    }

    if (isset($_POST['onpay_url'])) {
        update_option('onpay_url', $_POST['onpay_url']);
    }

    if (isset($_POST['onpay_debug'])) {
        update_option('onpay_debug', $_POST['onpay_debug']);
    }

    if (!isset($_POST['onpay_form']))
        $_POST['onpay_form'] = array();
    foreach ((array) $_POST['onpay_form'] as $form => $value) {
        update_option(('onpay_form_' . $form), $value);
    }
    return true;
}

function form_onpay() {
    $select_currency[get_option('onpay_curcode')] = "selected='selected'";
    $onpay_url = ( get_option('onpay_url') == '' ? 'http://secure.onpay.ru/pay/' . get_option('onpay_login') : get_option('onpay_url') );
    $onpay_salt = ( get_option('onpay_key') == '' ? 'changeme' : get_option('onpay_key') );

    $onpay_debug = get_option('onpay_debug');
    $onpay_debug1 = "";
    $onpay_debug2 = "";
    switch ($onpay_debug) {
        case 0:
            $onpay_debug2 = "checked ='checked'";
            break;
        case 1:
            $onpay_debug1 = "checked ='checked'";
            break;
    }

    if (!isset($select_currency['USD']))
        $select_currency['USD'] = '';
    if (!isset($select_currency['RUR']))
        $select_currency['RUR'] = '';
    if (!isset($select_currency['EUR']))
        $select_currency['EUR'] = '';

    $output = "
		<tr>
			<td>Логин в Onpay</td>
			<td><input type='text' size='40' value='" . get_option('onpay_login') . "' name='onpay_login' /></td>
		</tr>
		<tr>
			<td>&nbsp;</td> <td><small>Ваш логин в платежном агрегаторе Onpay (обычно http://secure.onpay.ru/pay/ваш_логин)</small></td>
		</tr>

		<tr>
			<td>Ключ API IN</td>
			<td><input type='text' size='40' value='" . get_option('onpay_key') . "' name='onpay_key' /></td>
		</tr>
		<tr>
			<td>&nbsp;</td> <td><small>Такой же как и в настройках магазина на сайте Onpay.ru. Должен содержать не менее 10	символов.</small></td>
		</tr>

		<tr>
			<td>Валюта</td>
			<td><select name='onpay_curcode'>
					<option " . $select_currency['USD'] . " value='USD'>USD - Доллар</option>
					<option " . $select_currency['EUR'] . " value='EUR'>EUR - Евро</option>
					<option " . $select_currency['RUR'] . " value='RUR'>RUR - Рубли</option>
				</select>
			</td>
		</tr>
		<tr>
			<td>&nbsp;</td> <td><small>Выберите валюту, в которую
			будут конвертироваться все платежи в
			магазине.</small></td>
		</tr>

		<tr>
			<td>Адрес URL API</td>
			<td><input type='text' size='40' value='http://" . $_SERVER['SERVER_NAME'] . '/' . "' name='onpay_return_url' /></td>
		</tr>
		<tr>
			<td>&nbsp;</td> <td><small>Скопируйте и вставьте этот
			адрес в настройках магазина на сайте onpay.ru в поле URL
			API.</small></td>
		</tr>

		<tr>
			<td>Отладка</td>
			<td>
				<input type='radio' value='1' name='onpay_debug' id='onpay_debug1' " . $onpay_debug1 . " /> <label for='onpay_debug1'>" . __('Yes', 'wpsc') . "</label> &nbsp;
				<input type='radio' value='0' name='onpay_debug' id='onpay_debug2' " . $onpay_debug2 . " /> <label for='onpay_debug2'>" . __('No', 'wpsc') . "</label>
			</td>
		</tr>
		<tr>
			<td>&nbsp;</td>
			<td><small>Режим отладки.</small></td>
		</tr>

</tr>";

    return $output;
}

add_action('init', 'nzshpcrt_onpay_callback');
add_action('init', 'nzshpcrt_onpay_results');
?>
