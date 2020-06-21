<?php

function getAccessToken():string{
    if(
      isset($_SESSION['payPalAccessToken'])&&
      isset($_SESSION['payPalAccessTokenExpires']) &&
      $_SESSION['payPalAccessTokenExpires'] > time()
    ){
      return $_SESSION['payPalAccessToken'];
    }

    require_once CONFIG_DIR.'/paypal.php';

    $curl = curl_init();
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_URL =>PAYPAL_BASE_URL.'/v1/oauth2/token',
        CURLOPT_HTTPHEADER => [
          'Accept: application/json',
          'Accept-Language: en_US'
        ],
        CURLOPT_USERPWD=>PAYPAL_CLIENT_ID.':'.PAYPAL_SECRET,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS=>'grant_type=client_credentials'
    ];
    curl_setopt_array($curl,$options);
    $result = curl_exec($curl);
    if(curl_errno($curl)){
      curl_close($curl);
      echo curl_error($curl);
      return'';
    }
    curl_close($curl);
    $data = json_decode($result,true);
    $accessToken = $data['access_token'];

    $_SESSION['payPalAccessToken'] = $accessToken;
    $_SESSION['payPalAccessTokenExpires'] = time()+$data['expires_in'];
    return $accessToken;

}
function productToPayPalItem(array $product):stdClass{
  $item = new stdClass();
  $item->name = $product['title'];
  $price = $product['price'];
  $tax = $price * 0.19;
  $netPrice = $price - $tax;

  $item->unit_amount = new stdClass();
  $item->unit_amount->currency_code ="EUR";
  $item->unit_amount->value = number_format($netPrice/100,2);
  $item->tax = new stdClass();
  $item->tax->currency_code ="EUR";
  $item->tax->value =number_format($tax/100,2);
  $item->quantity = $product['quantity'];
  $item->category = 'PHYSICAL_GOODS';
  $item->description = $product['description'];
  return $item;
}
function createOrder(string $accessToken,array $deliveryAddressData,array $products){
  require_once CONFIG_DIR.'/paypal.php';


$payer = new stdClass();
$payer->name = new stdClass();
$payer->name->given_name = $deliveryAddressData['recipient'];
$payer->address = new stdClass();
$payer->address->address_line_1 =$deliveryAddressData['streetNumber'].' '.$deliveryAddressData['street'];
$payer->address->admin_area_2 =$deliveryAddressData['city'];
$payer->address->postal_code = $deliveryAddressData['zipCode'];
$payer->address->admin_area_1 = "Deutschland";
$payer->address->country_code ="DE";

$object = new stdClass();

$object->items = [];
$totalValue = 0;
$itemsTotal = 0;
$taxTotal = 0;
foreach($products as $product){
  $item = productToPayPalItem($product);
  $object->items[]= $item;
  $itemsTotal += $item->unit_amount->value;
  $taxTotal +=$item->tax->value;
  $totalValue+=(int)$product['price'];
}
$amountObject = new stdClass();
$amountObject->currency_code ="EUR";
$amountObject->breakdown = new stdClass();
$amountObject->breakdown->item_total = new stdClass();
$amountObject->breakdown->item_total->value = $itemsTotal;
$amountObject->breakdown->item_total->currency_code = "EUR";
$amountObject->breakdown->tax_total = new stdClass();
$amountObject->breakdown->tax_total->value = $taxTotal;
$amountObject->breakdown->tax_total->currency_code = "EUR";


$amountObject->value =number_format($totalValue/100,2);
$object->amount=$amountObject;

$object->shipping = new stdClass();
$object->shipping->address = $payer->address;

$applicationContext = new stdClass();
$applicationContext->shipping_preference="SET_PROVIDED_ADDRESS";
$applicationContext->return_url ="http://localhost/shop/index.php/paymentComplete";
$applicationContext->cancel_url = "http://localhost/shop/index.php/cart";

$data = [
  "payer"=>$payer,
  "application_context"=>$applicationContext,
  "intent"=>"CAPTURE",
  "purchase_units"=>[

    $object
  ]
];

$dataString = json_encode($data);

  $curl = curl_init();
  $options = [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_URL =>PAYPAL_BASE_URL.'/v2/checkout/orders',
      CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer '.$accessToken
      ],

      CURLOPT_POST => true,
      CURLOPT_POSTFIELDS=>$dataString
  ];
  curl_setopt_array($curl,$options);
  $result = curl_exec($curl);
  if(curl_errno($curl)){
    curl_close($curl);
    echo curl_error($curl);
    return'';
  }
  curl_close($curl);
  $data = json_decode($result,true);

  if(!isset($data) && $data['status'] !== "CREATED"){

    return '';
  }
  var_dump($data);
  setPayPalOrderId($data['id']);
  $url = '';
  foreach($data['links'] as $link){
    if($link['rel'] !== "approve"){
      continue;
    }
    $url = $link['href'];
  }

 header("Location: ".$url);
}

function setPayPalOrderId(string $orderId):void{
  $_SESSION['paypalOrderId'] = $orderId;
}
function getPayPalOrderId():?string{
  return isset($_SESSION['paypalOrderId'])?$_SESSION['paypalOrderId']:null;
}
function setPayPalRequestId(string $paypalRequestId):void{
  $_SESSION['paypalRequestId'] = $payPalRequestId;
}
function getPayPalRequestId():?string{
  return isset($_SESSION['paypalRequestId'])?$_SESSION['paypalRequestId']:null;
}
function capturePayment(string $accessToken,string $orderId,string $token){
  require_once CONFIG_DIR.'/paypal.php';
$data = new stdClass();

$data->payment_source = new stdClass();
$data->payment_source->token = new stdClass();
$data->payment_source->token->id =$token;
 $data->payment_source->token->type ="BILLING_AGREEMENT";
    $dataString = json_encode($data);

    $curl = curl_init();
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_URL =>PAYPAL_BASE_URL.'/v2/checkout/orders/'.$orderId.'/capture',
        CURLOPT_HTTPHEADER => [
          'Content-Type: application/json',
          'Authorization: Bearer '.$accessToken,
          //'PayPal-Request-Id: '.$payPalRequestId
        ],

        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS=>$dataString
    ];
    curl_setopt_array($curl,$options);
    $result = curl_exec($curl);
    if(curl_errno($curl)){
      curl_close($curl);
      echo curl_error($curl);
      return'';
    }
    curl_close($curl);
    $data = json_decode($result,true);
    var_dump($data);
}

function paypalCreateOrder(array $deliveryAddressData,array $cartProducts){
  $accessToken = getAccessToken();
  createOrder($accessToken,$deliveryAddressData,$cartProducts);
}
function paypalPaymentComplete(){
  $accessToken = getAccessToken();
  $orderId = getPayPalOrderId();
  $payPalRequestId = getPayPalRequestId();
  $token =filter_input(INPUT_GET,'token',FILTER_SANITIZE_STRING);

  if($accessToken && $orderId && $token){
      capturePayment($accessToken,$orderId,$token);
  }
}
function vorkassePaymentComplete(){
  //TODO
}
function vorkasseCreateOrder(array $deliveryAddressData,array $cartProducts){
  //TODO;
  header("Location ".BASE_URL."index.php/paymentComplete");
}
