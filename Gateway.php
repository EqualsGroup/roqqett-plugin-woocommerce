<?php

namespace Roqqett;

use WC_Payment_Gateway as WCGateway;
use WC_Order as Order;
use WC_Order_Item as Item;
use WC_Order_Item_Product as ItemProduct;
use Exception;

/**
 * Roqqett Payment Gateway
 *
 * TODO: Dynamic Shipping
 */

define("WP_PATH", realpath(__DIR__ . "/../../")); // TODO does this already exist?

class Gateway extends WCGateway
{
    const WEBHOOK_EVENT_TYPE = "eventType";
    const WEBHOOK_MERCHANT_CART_ID = "merchantCartId";
    const WEBHOOK_ORDER_ID = "orderId";

    const EVENT_CART_COMPLETED = "cart_completed";
    const EVENT_CART_CANCELLED = "cart_cancelled";
    const EVENT_CART_ABANDONED = "cart_abandoned";

    const EVENT_TYPES = [
        // A little duplication here to ensure behaviours are easy to change
        self::EVENT_CART_COMPLETED => "webhook_cart_completed",
        self::EVENT_CART_CANCELLED => "webhook_cart_cancelled",
        self::EVENT_CART_ABANDONED => "webhook_cart_abandoned",
    ];

    const LOG_PATH = WP_PATH . "/uploads/wc-logs/roqqett.log";

    public function __construct()
    {
        $this->id = "roqqett";
        $this->icon = plugins_url('/roqqett/img/card-options.png', dirname(__FILE__));
        $this->has_fields = true;
        // WC Admin text
        $this->method_title = "Roqqett";
        $this->method_description = "Roqqett Pay and Roqqett Checkout banking app payments";
        // Frontend text
        $this->title = "Pay via banking app"; // For some reason method_title takes this.
        $this->description = "Fast banking app payments that are Roqqett-powered."; // $this->get_option("description");
        $this->testmode = "yes" === $this->get_option("testmode");
        $this->api_url = "https://api.roqqett.com";
        $this->fulfilment_url = "https://pay.roqqett.com/api/channel/fulfilment/js";

        $this->api_secret =  $this->get_option("api_secret");
        
        $this->pay_checkout_id = $this->get_option("pay_checkout_id");
        
        $this->pay_expiry = $this->get_option("pay_expiry");

        $this->checkout_checkout_id = $this->get_option("checkout_checkout_id");
        
        $this->checkout_button_colour = $this->get_option("checkout_button_colour");
        $this->checkout_expiry = $this->get_option("checkout_expiry");
        $this->enable_logging = "yes" === $this->get_option("enable_logging");
        $this->enabled = $this->get_option("enabled") && $this->is_valid();
        if (is_admin()) {
            add_action('admin_notices', [$this, 'check_admin']);
            add_action("woocommerce_update_options_payment_gateways_" . $this->id, [$this, "process_admin_options"]);
        }
        $this->supports(["products", "refunds"]);
        $this->init_form_fields();
        if ($this->is_valid()) {
            $this->init_settings();
        }
        add_action("wp_enqueue_scripts", [$this, "payment_scripts"]);
        add_action("woocommerce_after_checkout_validation", [$this, "prevent_submission"]);
        add_action('woocommerce_api_roqqett-webhook', [$this, "webhook"]);
        add_action('woocommerce_api_roqqett-checkout', [$this, "roqqett_checkout"]);
        add_filter("woocommerce_thankyou_order_received_text", [$this, "order_received_text"]);
        // is there a better place for these?
        wp_register_style("roqqett.frontend", plugins_url("css/frontend.css", __FILE__));
        wp_enqueue_style("roqqett.frontend");
        if ($this->checkout_checkout_id && $this->checkout_expiry && $this->checkout_button_colour) {
            add_action('woocommerce_proceed_to_checkout', [$this, "roqqett_order_button_html"], 20);
            // wp_enqueue_scripts doesn't necessarily happen in this case, let's just put ours in here
            // if (0 === did_action('wp_enqueue_scripts')) {
                $this->payment_scripts();
            // }
        }

        $this->should_prevent_submit =
            isset($_POST['roq_prevent_submit']) &&
            "true" === $_POST['roq_prevent_submit'];

        $this->transfer_id =
            (
                isset($_POST['roq_transfer_id']) &&
                !empty($_POST['roq_transfer_id'])
            ) ?
            $_POST['roq_transfer_id'] :
            null;

        $this->is_roqqett_payment_method =
            isset($_POST['payment_method']) &&
            "roqqett" === $_POST['payment_method'];
    }

    public function prevent_submission()
    {
        if (
            $this->should_prevent_submit &&
            wc_notice_count('error') === 0
        ) {
            wc_add_notice("Roqqett: Validating...", 'notice');
        }
    }

    private function roq_add_admin_notice(string $type, string $notice)
    {
        ?>
        <div class="notice notice-<?php echo $type; ?>">
            <p><?php echo $notice; ?></p>
        </div>
        <?php
    }

    private function roq_add_admin_error(string $error)
    {
        return $this->roq_add_admin_notice("error", $error);
    }

    private function roq_add_admin_warning(string $warning)
    {
        return $this->roq_add_admin_notice("warning", $warning);
    }

    public function is_valid(): bool
    {
        return (
            !empty($this->api_secret) &&
            !empty($this->pay_checkout_id) &&
            !empty($this->pay_expiry) // TODO checkout later
        );
    }

    public function check_admin()
    {
        if ($this->enabled == 'no') {
            return;
        }

        if (empty($this->api_secret)) {
            $this->roq_add_admin_error("Roqqett is not ready! You need to enter your API secret in the Roqqett WooCommerce <a href=\"/wp-admin/admin.php?page=wc-settings&tab=checkout&section=roqqett\">payment settings screen</a>.");
        }

        if (empty($this->pay_checkout_id) && empty($this->checkout_checkout_id)) {
            $this->roq_add_admin_error("Roqqett is not ready! You need to enter a checkout ID in the Roqqett WooCommerce <a href=\"/wp-admin/admin.php?page=wc-settings&tab=checkout&section=roqqett\">payment settings screen</a>.");
        } else {
            if (empty($this->pay_checkout_id)) {
                $this->roq_add_admin_warning("Roqqett Pay is not active. Activate by entering a Checkout ID in the Roqqett Pay section in the <a href=\"/wp-admin/admin.php?page=wc-settings&tab=checkout&section=roqqett\">payment settings screen</a>.");
            } elseif (empty($this->pay_expiry)) {
                $this->roq_add_admin_error("Roqqett Pay is not ready! You need to enter your expiry time into the Roqqett WooCommerce <a href=\"/wp-admin/admin.php?page=wc-settings&tab=checkout&section=roqqett\">payment settings screen</a>.");
            }

            if (empty($this->checkout_checkout_id)) {
                $this->roq_add_admin_warning("Roqqett Checkout is not active. Activate by entering a Checkout ID in the Roqqett Checkout section in the <a href=\"/wp-admin/admin.php?page=wc-settings&tab=checkout&section=roqqett\">payment settings screen</a>.");
            } elseif (empty($this->checkout_expiry)) {
                $this->roq_add_admin_error("Roqqett Checkout is not ready! You need to enter your expiry time into the Roqqett WooCommerce <a href=\"/wp-admin/admin.php?page=wc-settings&tab=checkout&section=roqqett\">payment settings screen</a>.");
            }
        }
    }

    public function init_form_fields()
    {
        $this->form_fields = [
            "enabled" => [
                "title"       => "Enable/Disable",
                "label"       => "Enable Roqqett",
                "type"        => "checkbox",
                "description" => "",
                "default"     => false
            ],
            "testmode" => [
                "title"       => "Test mode",
                "label"       => "Enable Test Mode",
                "type"        => "checkbox",
                "default"     => false
            ],
            "api_secret" => [
                "title"       => "API secret",
                "type"        => "text",
                "description" => "You should have been provided this when your checkout was configured in the Roqqett portal (<a href=\"https://portal.roqqett.com/\" target=\"_blank\" rel=\"noopener\">open</a>). If not create a new one under API keys -> Create API Key."
            ],
            "webhook_url" => [
                "title"       => "Webhook URL",
                "type"        => "text",
                "disabled"    => true,
                "default"     => home_url("/wc-api/roqqett-webhook"),
                "description" => "This callback URL should be added to all of your checkout instance(s) used by this site in the Roqqett portal (<a href=\"https://portal.roqqett.com/\" target=\"_blank\" rel=\"noopener\">open</a>) under Checkouts -> Edit Checkout -> Webhook URL.",
                "custom_attributes" => [
                    "value" => home_url("/wc-api/roqqett-webhook"),
                    "placeholder" => home_url("/wc-api/roqqett-webhook")
                ]
            ],
            "roqqett_pay" => [
                "type" => "title",
                "title" => "Roqqett Pay"
            ],
            "pay_checkout_id" => [
                "title"       => "Checkout ID",
                "type"        => "text",
                "description" => "You should use the ID of the Roqqett <strong>Pay</strong> instance that is configured in the Roqqett portal (<a href=\"https://portal.roqqett.com/\" target=\"_blank\" rel=\"noopener\">open</a>)."
            ],
            "pay_expiry" => [
                "title"       => "Expiry period",
                "type"        => "text",
                "description" => "The period (in minutes) a checkout is active for before it automatically expires and the consumer can no longer pay. Default is 20 minutes.",
                "default"     => "20"
            ],
            "roqqett_checkout" => [
                "type" => "title",
                "title" => "Roqqett Checkout"
            ],
            "checkout_checkout_id" => [
                "title"       => "Checkout ID",
                "type"        => "text",
                "description" => "You should use the ID of the Roqqett <strong>Checkout</strong> instance that is configured in the Roqqett portal (<a href=\"https://portal.roqqett.com/\" target=\"_blank\" rel=\"noopener\">open</a>)."
            ],
            "checkout_button_colour" => [
                "title"       => "Button colour",
                "type"        => "select",
                "default"     => "white-on-blue",
                "description" => "Set the button colour that best suits your <strong>cart summary</strong> page's colour scheme.",
                "options"     => [
                    "white-on-blue" => "Roqqett blue + white text",
                    "blue-on-white" => "White + Roqqett blue text",
                    "blue-on-green" => "Green + Roqqett blue text",
                ]
            ],
            "checkout_expiry" => [
                "title"       => "Expiry period",
                "type"        => "text",
                "description" => "The period (in minutes) a checkout is active for before it automatically expires and the consumer can no longer pay. Default is 20 minutes.",
                "default"     => "20"
            ],
            "debug_settings" => [
                "title" => "Debug Settings",
                "type" => "title"
            ],
            "enable_logging" => [
                "title"       => "Enable logging",
                "label"       => "Log all activity",
                "type"        => "checkbox",
                "default"     => true,
                "description" => "Log Roqqett events to " . self::LOG_PATH, // todo wp/wc fn
            ]
        ];
    }

    protected function validate_webhook_url_field($key, $value)
    {
        return home_url("/wc-api/roqqett-callback");
    }

    protected function validate_api_secret_field($key, $value)
    {
        if (empty($value)) {
            throw new Exception("Roqqett is not ready! You need to add your API Key & Secret in the settings.");
        }

        return $this->validate_text_field($key, $value);
    }

    protected function validate_pay_checkout_id_field($key, $value)
    {
        return $this->validate_text_field($key, $value);
    }

    protected function validate_pay_expiry_field($key, $value)
    {
        return $this->validate_text_field($key, $value);
    }

    protected function validate_checkout_checkout_id_field($key, $value)
    {
        return $this->validate_text_field($key, $value);
    }

    protected function validate_checkout_expiry_field($key, $value)
    {
        return $this->validate_text_field($key, $value);
    }

    public function admin_options()
    {
        ?>
        <h3>Roqqett</h3>
        <p>The one-click Roqqett Pay and Checkout buttons let your customers pay using their banking app in a slick and secure manner. Customers using Roqqett Checkout for the first time will need to fill in a short form but thereafter can complete orders with one click regardless of device or browser without a password.</p>
        <p><a href="https://portal.roqqett.com/" target="_blank" rel="noreferrer">Roqqett Portal</a> | <a
            href="https://docs.roqqett.com/docs" target="_blank" rel="noreferrer">API Docs</a> | <a
            href="https://www.roqqett.com/support" target="_blank" rel="noreferrer">Contact Us</a>
        </p>
        <?php
            $this->display_errors();
        ?>
        <table class="form-table">
            <?php $this->generate_settings_html(); ?>
        </table>
        <?php
    }

    public function payment_scripts()
    {
        // we need JavaScript to process a token only on cart/checkout pages, right?
        if (! is_cart() && ! is_checkout() && ! isset($_GET['pay_for_order'])) {
            return;
        }

        // if our payment gateway is disabled, we do not have to enqueue JS too
        if ('no' === $this->enabled) {
            return;
        }

        // no reason to enqueue JavaScript if API key and checkout ID are not set
        if (empty($this->api_secret) || empty($this->pay_checkout_id)) {
            $this->log("No API secret or pay checkout ID present.");
            return;
        }

        wp_enqueue_script(
            'roqqett_js',
            $this->fulfilment_url,
            [],
            null // no ?ver=
        );

        wp_register_script(
            'woocommerce_roqqett',
            plugins_url(
                'js/roqqett.js',
                __FILE__
            ),
            [
                'jquery',
                'roqqett_js'
            ]
        );
        wp_enqueue_script('woocommerce_roqqett');
    }

    public function payment_fields()
    {
        ?>
        <p>Fast banking app payments that are Roqqett-powered</p>
        <?php
        $this->hidden_fields();
    }

    public function validate_fields()
    {
        if (
            $this->is_roqqett_payment_method &&
            !$this->transfer_id &&
            !$this->should_prevent_submit
        ) {
            $this->log("Transfer ID has not been retrieved.");
            wc_add_notice("Transfer ID has not been retrieved.", "error");
            return false;
        }
        return true;
    }

    private function price_to_int(string $price): int
    {
        // Do not use intval(): https://www.php.net/manual/en/function.intval.php#112039
        return round(100 * $price); // * operator casts to a number type
    }

    private function to_percentage(float $proportion): int
    {
        return round(100 * $proportion);
    }

    private function get_shipping_tax_rate(Order $order): int
    {
        return $this->to_percentage(
            $order->get_shipping_total() === "0" ? 0 :
            $this->price_to_int($order->get_shipping_tax()) /
            (
                $this->price_to_int($order->get_shipping_total()) -
                (
                    $order->get_prices_include_tax() ?
                    0 :
                    $this->price_to_int($order->get_shipping_tax())
                )
            )
        );
    }

    private function get_roqqett_order(string $roqqett_order_id)
    {
        return wp_remote_get(
            $this->api_url . "/orders/${roqqett_order_id}",
            [
                "method" => "GET",
                "headers" => [
                    "Accept" => "application/json",
                    "Content-Type" => "application/json",
                    "X-API-KEY" => $this->api_secret
                ],
                "data_format" => "body"
            ]
        );
    }

    // Use a filter so that Checkout can clear the cart on completion if not already done.
    public function order_received_text(string $text) {
        echo $text;
        global $woocommerce;
        $woocommerce->cart->empty_cart();
    }

    private function create_roqqett_cart($checkout_id, $expiry, $transfer_id, $order)
    {
        return wp_remote_post(
            $this->api_url . "/carts/",
            [
                "method" => "POST",
                "headers" => [
                    "Accept" => "application/json",
                    "Content-Type" => "application/json",
                    "X-API-KEY" => $this->api_secret
                ],
                "data_format" => "body",
                "body" => wp_json_encode([
                    "checkoutId" => $checkout_id,
                    "expiryTimeMinutes" => $expiry,
                    "transferId" => $transfer_id,
                    "merchantCartId" => $order->get_id(),
                    "transaction" => [
                        "total" => [
                            "amount" => $this->price_to_int($order->get_total()),
                            "currency" => $order->get_currency(),
                            "description" => "Total cost"
                        ]
                    ],
                    "basket" => [
                        "subTotal" => array_reduce(
                            $order->get_items(),
                            function ($carry, $item) {
                                return $carry +
                                    $this->price_to_int($item->get_total()) +
                                    $this->price_to_int($item->get_total_tax());
                            },
                            0
                        ),
                        "items" => array_map(function ($item) {
                            return [
                                "lineId" => "",
                                "quantity" => $item->get_quantity(),
                                "productId" => $item->get_product_id(),
                                "productName" => $item->get_name(),
                                "total" =>
                                    $this->price_to_int($item->get_total()) +
                                    $this->price_to_int($item->get_total_tax()),
                                "unitPrice" => round(
                                    $this->price_to_int($item->get_total() / $item->get_quantity())
                                ),
                                // "taxRate" => 0,
                                "taxTotal" => $this->price_to_int($item->get_total_tax()) // Check if before or after tax
                            ];
                        }, array_values($order->get_items()))
                    ],
                    "shippingDetails" => [
                        "currency" => $order->get_currency(),
                        "totalPrice" =>
                            $this->price_to_int($order->get_shipping_total()) +
                            $this->price_to_int($order->get_shipping_tax()),
                        // Hack until we get price and taxTotal
                        "taxRate" => $this->get_shipping_tax_rate($order),
                        "description" => "",
                        "serviceCode" => (count($order->get_shipping_methods())) > 0 ? array_values($order->get_shipping_methods())[0]->get_method_id() : "",
                        "serviceName" => $order->get_shipping_method()
                        // "maxDeliveryDate" => "",
                        // "minDeliveryDate" => ""
                    ],
                    "testMode" => $this->testmode
                ])
            ]
        );
    }

    public function process_payment($order_id)
    {
        if ($this->should_prevent_submit) {
            // If we are validating, don't process the payment.
            return;
        }

        global $woocommerce;

        // Has Woo already made the order?

        // $order = new WC_Order($order_id);
        $order = wc_get_order($order_id);

        if ($order->get_status() !== "pending") {
            $order->update_status("failed", "Order status was not pending, cannot process a non-pending order.", "woocommerce");
            wc_add_notice("Order status was not pending, cannot process a non-pending order.", "error");
            $this->log("Order status was not pending, cannot process a non-pending order (order ID = " . $order->get_id() . ").");
            return;
        }

        if ($order->get_payment_method() !== "roqqett") {
            $order->update_status("failed", "Payment method is not Roqqett, cannot process a non-Roqqett order.", "woocommerce");
            wc_add_notice("Payment method is not Roqqett, cannot process a non-Roqqett order.", "error");
            $this->log("Payment method is not Roqqett, cannot process a non-Roqqett order (order ID = " . $order->get_id() . ").");
            return;
        }

        $order->add_order_note("Awaiting Roqqett payment", false);

        // Hit Roqqett create cart endpoint, get cart ID

        if (!$this->transfer_id) {
            $order->update_status("failed", "Client error", "woocommerce");

            wc_add_notice("Transfer ID has not been retrieved.", "error");
            $this->log("Transfer ID has not been retrieved.");
            return;
        }

        $response = $this->create_roqqett_cart(
            $this->pay_checkout_id,
            $this->pay_expiry,
            $this->transfer_id,
            $order
        );

        if (is_wp_error($response)) {
            wc_add_notice("Error, couldn't create a cart, error: " . $response->get_error_code(), "error");

            $order->update_status("failed", "Couldn't create a Roqqett cart", "woocommerce");

            $this->log("Error, couldn't create a cart, order ID = " . $order->get_id() . ". errors: " . wp_json_encode($response->errors) . ".");

            return;
        }

        if ($response['response']['code'] !== 200) {
            $body = json_decode($response['body'], true);

            wc_add_notice("Error, couldn't create a cart, error: " . $response['response']['code'], "error");

            $order->update_status("failed", "Couldn't create a Roqqett cart", "woocommerce");

            $this->log("Error, couldn't create a cart, order ID = " . $order->get_id() . ". error " . $response['response']['code'] . ": " . $response['body']);

            return;
        }

        $order->add_order_note("Processing Roqqett payment", false);

        return [
            'result' => 'success',
            'refresh' => false,
            'reload' => false,
            'messages' => []
        ];
    }

    public function roqqett_checkout()
    {
        global $woocommerce;
        // $cart = $woocommerce->cart;

        // As opposed to POST
        $transfer_id = $_GET['roq_transfer_id'];

        $order_id = $woocommerce->checkout->create_order([
            "payment_method" => "roqqett"
        ]);
        $order = wc_get_order($order_id);

        $response = $this->create_roqqett_cart(
            $this->checkout_checkout_id,
            $this->checkout_expiry,
            $transfer_id,
            $order
        );

        if (is_wp_error($response)) {
            $order->update_status("failed", "Couldn't create a Roqqett cart", "woocommerce");
            $this->log("Error, couldn't create a cart, order ID = " . $order->get_id() . ". errors: " . wp_json_encode($response->errors) . ".");
            return;
        }

        if ($response['response']['code'] !== 200) {
            $body = json_decode($response['body'], true);
            $order->update_status("failed", "Couldn't create a Roqqett cart", "woocommerce");
            $this->log("Error, couldn't create a cart, order ID = " . $order->get_id() . ". error " . $response['response']['code'] . ": " . $response['body']);
            return;
        }

        $order->add_order_note("Processing Roqqett payment", false);

        return [
            'result' => 'success',
            'refresh' => false,
            'reload' => false,
            'messages' => []
        ];
    }

    public function webhook_cart_completed(Order $order, string $roqqett_order_id): string
    {
        $roqqett_order = $this->get_roqqett_order($roqqett_order_id);
        $body = json_decode($roqqett_order['body'], true);

        // @TODO here: check for failure
        $roqqett_order_data = $body['data'];

        // If this is a Roqqett Checkout order, we need to insert the customer's details.
        if ($roqqett_order_data['checkoutId'] === $this->checkout_checkout_id) {
            // We know we're in a Checkout order. Add the address and shipping option.
            $roqqett_shipping_address = $roqqett_order_data['shippingAddress'];
            $roqqett_address = $roqqett_shipping_address['address'];

            $order->set_billing_email($roqqett_shipping_address['email']);

            $order->set_billing_first_name($roqqett_shipping_address['firstName']);
            $order->set_billing_last_name($roqqett_shipping_address['lastName']);
            $order->set_billing_phone($roqqett_shipping_address['phoneNumber']);

            $order->set_billing_address_1($roqqett_address['addressLine1']);
            $order->set_billing_address_2($roqqett_address['addressLine2']);
            $order->set_billing_city($roqqett_address['city']);
            $order->set_billing_postcode($roqqett_address['postcode']);

            // @TODO: see if shipping country is the country code
            $order->set_billing_country($roqqett_address['countryCode']);

            $order->set_shipping_first_name($roqqett_shipping_address['firstName']);
            $order->set_shipping_last_name($roqqett_shipping_address['lastName']);
            $order->set_shipping_phone($roqqett_shipping_address['phoneNumber']);

            $order->set_shipping_address_1($roqqett_address['addressLine1']);
            $order->set_shipping_address_2($roqqett_address['addressLine2']);
            $order->set_shipping_city($roqqett_address['city']);
            $order->set_shipping_postcode($roqqett_address['postcode']);

            // @TODO: see if shipping country is the country code
            $order->set_shipping_country($roqqett_address['countryCode']);

            $order->apply_changes();
        }

        $order->payment_complete();
        $order->reduce_order_stock();
        $order->add_order_note('Hey, your order is paid! Thank you!', true);
        $order->update_status("processing", "Order paid in Roqqett", "woocommerce");

        return "Successfully marked order as payment complete.";
    }

    public function webhook_cart_abandoned(Order $order): string
    {
        if ("processing" === $order->get_status()) {
            $this->log("Did not mark order as failed because it was processing: " . $order->id);
            return "Did not mark order as failed because it was processing.";
        }
        $order->add_order_note('Your cart was abandoned.', true);
        $order->update_status("failed", "Cart abandoned", "woocommerce");

        $this->log("Cart abandoned (order ID = " . $order->id . ").");

        return "Successfully marked order as failed because it was abandoned.";
    }

    public function webhook_cart_cancelled(Order $order): string
    {
        // $order->add_order_note('Your cart was cancelled.', true);
        // $order->update_status("failed", "Cart cancelled", "woocommerce");

        $this->log("Received cart cancelled webhook (order ID = " . $order->id . "), ignoring.");

        return "";
    }

    public function webhook()
    {
        // update_option('webhook_debug_get', $_GET);
        // update_option('webhook_debug_post', $_POST);
        try {
            header("Content-Type: application/json");

            // Isn't there a wp or wc function for this?
            $postBody = json_decode(file_get_contents("php://input"), true);

            $this->log("Webhook received");

            if (!isset($postBody[self::WEBHOOK_EVENT_TYPE])) {
                // Unmanaged event type. Die for future proofing reasons just in case there's more
                throw new Exception("No event type");
            }

            $eventType = $postBody[self::WEBHOOK_EVENT_TYPE];

            if (!isset(self::EVENT_TYPES[$eventType])) {
                throw new Exception(
                    sprintf("Invalid event type (%s)", $eventType)
                );
            }

            if (!isset($postBody[self::WEBHOOK_MERCHANT_CART_ID])) {
                throw new Exception(
                    sprintf("No %s field present.", self::WEBHOOK_MERCHANT_CART_ID)
                );
            }

            $merchantCartId = $postBody[self::WEBHOOK_MERCHANT_CART_ID];

            if (empty($merchantCartId)) {
                throw new Exception(
                    sprintf("%s cannot be empty.", self::WEBHOOK_MERCHANT_CART_ID)
                );
            }

            $order = wc_get_order($merchantCartId);
            // @TODO do we need to find the cart for any purpose? If so, how does one do it?

            $callback = [$this, self::EVENT_TYPES[$eventType]];

            $response = $callback($order, $postBody[self::WEBHOOK_ORDER_ID]);

            echo json_encode([
                "success" => true,
                "message" => $response
            ]);
        } catch (Exception $e) {
            header("HTTP/1.1 400 Bad Request");

            $this->log("[Webhook] Caught error: " . $e->getMessage());

            echo json_encode([
                "success" => false,
                "message" => $e->getMessage(),
            ]);
        }

        die(); // escape from WP
    }

    private function hidden_fields()
    {
        ?>
        <input type="hidden" name="roq_transfer_id" id="roq_transfer_id" />
        <input type="hidden" name="roq_prevent_submit" id="roq_prevent_submit" value="false" />
        <?php
    }

    public function roqqett_order_button_html()
    {
        ?>
        <a href="javascript:void(0)" id="roqqett-checkout" class="roqqett-checkout">
            <?php foreach(["smallest", "small", "medium", "large", "largest"] as $size) : ?>
            <img class="roqqett-checkout-<?php echo $size; ?>" style="display: none;" src="/wp-content/plugins/roqqett/img/checkout-<?php echo $size; ?>-<?php echo $this->checkout_button_colour; ?>.png"/>
            <?php endforeach; ?>
        </a>
        <?php
        $this->hidden_fields();
    }


    private function log(string $str): void
    {
        if ($this->enable_logging) {
            $logger = wc_get_logger(); // wc_print_t
            $logger->info($str, ["source" => "roqqett"]);
        }
    }
}