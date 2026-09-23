(function ($) {
    "use strict";

    // Install + Activate button
    $("#install-activate-button").on("click", function (e) {
        if ( $(this).data("activated") ) {
            return;
        }

        e.preventDefault();

        var button = $(this);
        button.prop("disabled", true)
              .text("Installing & Activating recommended plugins…")
              .addClass("processing-spinner");

        $.post(digital_marketing_freelancer_localize.ajax_url, {
            action: "digital_marketing_freelancer_install_and_activate_plugins",
            nonce: digital_marketing_freelancer_localize.nonce
        }, function (response) {
            if (response.success) {
                window.location.href = digital_marketing_freelancer_localize.redirect_url;
            } else {
                button.text(response.data?.message || "Installation failed");
            }
        });
    });

})(jQuery);
