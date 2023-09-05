<?php

namespace App\Http\Controllers;

use Adyen\Model\Checkout\CreateCheckoutSessionRequest;
use Adyen\Model\Checkout\Amount;
use Adyen\Model\Checkout\LineItem;
use Illuminate\Http\Request;
use App\Http\AdyenClient;
use Illuminate\Support\Facades\Config;
use Symfony\Component\Console\Output\ConsoleOutput;

class CheckoutController extends Controller
{
    protected $checkout;

    public function __construct(AdyenClient $checkout)
    {
        $this->checkout = $checkout->service;
    }

    public function index()
    {
        return view('pages.index');
    }

    public function preview(Request $request)
    {
        $type = $request->type;
        return view('pages.preview', ['type' => $type]);
    }

    public function checkout(Request $request)
    {
        $data = [
            'type' => $request->type,
            'clientKey' => Config::get('adyen.client_key'),
        ];

        return view('pages.payment', $data);
    }

    public function redirect(Request $request)
    {
        $data = ['clientKey' => Config::get('adyen.client_key')];
        return view('pages.redirect', $data);
    }

    public function result(Request $request)
    {
        $type = $request->type;
        return view('pages.result', ['type' => $type]);
    }

    /* ################# API ENDPOINTS ###################### */
    // The API routes are exempted from app/Http/Middleware/VerifyCsrfToken.php

    public function sessions(Request $request)
    {
        $orderRef = uniqid();

        // Setting the base URL
        $baseURL = url()->previous();
        $baseURL = substr($baseURL, 0, -15);

        $amount = new Amount();
        $amount->setCurrency("EUR")->setValue(10000);
        $lineItem1 = new LineItem();
        $lineItem1->setQuantity(1)->setAmountIncludingTax(5000)->setDescription("Sunglasses");
        $lineItem2 = new LineItem();
        $lineItem2->setQuantity(1)->setAmountIncludingTax(5000)->setDescription("Headphones");

        // Creating the actual session request
        $sessionRequest = new CreateCheckoutSessionRequest();
        $sessionRequest
            ->setChannel("Web")
            ->setAmount($amount)
            ->setCountryCode("NL")
            ->setMerchantAccount(Config::get('adyen.merchant_account'))
            ->setReference($orderRef)
            ->setReturnUrl("${baseURL}/redirect?orderRef=${orderRef}")
            ->setLineItems([$lineItem1, $lineItem2]);

        return $this->checkout->sessions($sessionRequest);
    }

    // Webhook integration
    public function webhooks(Request $request)
    {
        $hmacKey = env('ADYEN_HMAC_KEY');
        $validator = new \Adyen\Util\HmacSignature();
        $out = new ConsoleOutput();

        $notifications = $request->getContent();
        // Add null handling
        $notifications = json_decode($notifications, true);

        if (isset($notifications['notificationItems'])) {
            $notificationItems = $notifications['notificationItems'];

            // Fetch the first (and only) NotificationRequestItem
            $item = array_shift($notificationItems);

            if (isset($item['NotificationRequestItem'])) {
                $requestItem = $item['NotificationRequestItem'];

                if ($validator->isValidNotificationHMAC($hmacKey, $requestItem)) {
                    // Consume the event asynchronously (e.g., INSERT into DB or queue)
                    $out->writeln("Eventcode " . json_encode($requestItem['eventCode'], true));
                } else {
                    return response('[refused]', 401);
                }
            }
        }

        return response('[accepted]', 200);
    }
}
