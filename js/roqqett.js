jQuery(function ($) {
    const roqqett = new Roqqett();
    const checkout_form = $("form.woocommerce-checkout");
    const checkout_button = $("#roqqett-checkout");
    const isSafari = /^((?!chrome|android).)*safari/i.test(navigator.userAgent);

    let popupCheckInterval;
    let hasValidationFailure = false;
    let readyToPopup = false;
    let _window;

    const getTransferId = async () => {
        try {
            const {transferId, isFresh} = await roqqett.checkout();
            checkout_form.find("#roq_transfer_id").val(transferId);
            checkout_form.off("checkout_place_order", placeOrder);
            checkout_form.off("submit", roqqettSubmit);
            if (isFresh) {
                // do the ajax
                checkout_form.submit();
            }
            // Return to original method
            checkout_form.on("checkout_place_order", placeOrder);
            checkout_form.on("submit", roqqettSubmit);
        } catch (err) {
            console.error({err});
        }
    };

    const placeOrder = ev => {
        ev.preventDefault();
        // First time do the validation only
        readyToPopup = false;
        hasValidationFailure = false;
        $("#roq_prevent_submit").val("true");
        return true;
    };

    const popupCheck = async () => {
        if (hasValidationFailure) {
            readyToPopup = false;
            if (isSafari) {
                _window.close();
            }
            clearInterval(popupCheckInterval);
        }

        if (readyToPopup) {
            readyToPopup = false;
            clearInterval(popupCheckInterval);
            await getTransferId();
        }
    }

    const roqqettSubmit = () => {
        if (isSafari) {
            const w = 375;
            const h = 730;
            const y = window.top.outerHeight / 2 + window.top.screenY - h / 2;
            const x = window.top.outerWidth / 2 + window.top.screenX - w / 2;
            _window = window.open(
                "about:blank",
                "RoqqettTransferWindow",
                `width=${w}, height=${h}, top=${y}, left=${x}, toolbar=no, location=no, directories=no, status=no, menubar=no, scrollbars=no, resizable=no, copyhistory=no, rel=opener`
            );
        }
        popupCheckInterval = setInterval(popupCheck, 50);
    };

    const checkoutError = async () => {
        // If the submission didn't go well and produced more errors... 
        if ($(".woocommerce-error, .is-error").find("li").length !== 0 ||
            !$(".woocommerce-info, .is-info").text().includes("Roqqett: Validating...")) {
            // Keep preventing submit.
            hasValidationFailure = true;
            return;
        }

        hasValidationFailure = false;

        // If the submission went well, got validated
        // and produced the special Roqqett validation error...

        // Allow submission this time.
        $("#roq_prevent_submit").val("false");

        // Okay to go!
        readyToPopup = true;
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
    checkout_form.on("submit", roqqettSubmit);
    checkout_button.on("click", startRoqqettCheckout);
    $(document.body).on("checkout_error", checkoutError);

    $(document).on("load", showCorrectCheckoutButton);    
    $(window).on("resize", showCorrectCheckoutButton);
    showCorrectCheckoutButton();
});
