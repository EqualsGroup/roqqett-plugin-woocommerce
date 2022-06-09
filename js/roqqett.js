jQuery(function ($) {
    const roqqett = new Roqqett();
    const checkout_form = $("form.woocommerce-checkout");
    const checkout_button = $("#roqqett-checkout");

    const getTransferId = async () => {
        try {
            const {transferId, isFresh} = await roqqett.checkout();
            checkout_form.find("#roq_transfer_id").val(transferId);
            checkout_form.off("checkout_place_order", placeOrder);
            if (isFresh) {
                checkout_form.submit();
            }
            // Return to original method
            checkout_form.on("checkout_place_order", placeOrder);
        } catch (err) {
            console.error({err});
        }
    };

    const placeOrder = () => {
        $("#roq_prevent_submit").val("true");
        return true;
    };

    const checkoutError = async() => {
        if ($(".woocommerce-error").find("li").length !== 0 ||
            !$(".woocommerce-info").text().includes("Roqqett: Validating...")) {
            return;
        }

        $("#roq_prevent_submit").val("false");

        await getTransferId();
    };

    const startRoqqettCheckout = async event => {
        // We will have to validate or send to the original place.
        event.preventDefault(); // In case it gets hooked to a button.
        const {transferId, isFresh} = await roqqett.checkout();
        if (isFresh) {
            $.getJSON(
                `/wc-api/roqqett-checkout?roq_transfer_id=${transferId}`
            ); // No need to wait for this, Roqqett will pick it up.
        }
    };

    // As a placeholder until container queries are rolled out
    const showCorrectCheckoutButton = () => {
        const checkout_container = $(".roqqett-checkout");
        const container_width = parseInt(checkout_container.css("width"));
        const all_checkout_contents = $(".roqqett-checkout img");
        all_checkout_contents.hide();
        /* Ifs, until pattern guards are rolled out */
        const appropriate_checkout_contents =
            (470 < container_width) ?
                $(".roqqett-checkout-largest") :
            (290 < container_width) ?
                $(".roqqett-checkout-large") :
            (240 < container_width) ?
                $(".roqqett-checkout-medium") :
            (150 < container_width) ?
                $(".roqqett-checkout-small"):
            $(".roqqett-checkout-smallest");
        appropriate_checkout_contents.show();
    }

    checkout_form.on("checkout_place_order", placeOrder);
    checkout_button.on("click", startRoqqettCheckout);
    $(document.body).on("checkout_error", checkoutError);

    $(document).on("load", showCorrectCheckoutButton);    
    $(window).on("resize", showCorrectCheckoutButton);
    showCorrectCheckoutButton();
});