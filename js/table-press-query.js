jQuery(document).ready(function($) {

    // --- Configuration and Helper Functions ---
    // The 'kcm_ajax_object' is now available thanks to wp_localize_script
    var ajaxUrl = kcm_ajax_object.ajax_url;
    // var getFormNonce = kcm_ajax_object.nonce; // If you use a nonce for fetching the form

    // Helper function to escape HTML (good to have)
    function escapeHtml(unsafe) {
        if (typeof unsafe !== 'string') return '';
        return unsafe
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    // --- Event Listener for clicking the contact trigger ---
    $('.kcm-cartouche-button.kcm-contact-trigger').on('click', function(e) {
        e.preventDefault();
        var $trigger = $(this); // The clicked <a> tag

        // Determine the target <span> ID based on data-recipient-email
        var recipientEmail = $trigger.data('recipient-email');
        if (!recipientEmail) {
            console.error('Data attribute "data-recipient-email" is missing on the trigger.');
            alert('Cannot identify contact person.'); // Consider a more user-friendly, non-alert message
            return;
        }

        // Construct the ID of the target span. Replace invalid ID characters.
        // Email addresses can contain '.', '@', which are not ideal in IDs without escaping.
        // We replace '.' and '@' with '-' for the ID.
        // This ID should match the ID of the <span> element in your HTML that will contain the form.
        var targetSpanId = recipientEmail.replace(/[@.]/g, '-'); // Ensure this matches your HTML span ID structure
        var $formContainer = $('#' + targetSpanId);

        // If the form container for this trigger doesn't exist, log an error and stop.
        if (!$formContainer.length) {
            console.error('Form container span with ID "' + targetSpanId + '" not found.');
            alert('Form display area is missing.'); // User feedback
            return;
        }

        // If the form container already has the form and is visible, hide it (toggle idea)
        if ($formContainer.hasClass('kcm-form-loaded') && $formContainer.is(':visible')) {
            $formContainer.slideUp(function() {
                $(this).empty().removeClass('kcm-form-loaded'); // Empty and reset status
            });
            return; // Stop here, no new form needed
        }

        // If another form is open, close it first (optional, for 1 open form at a time)
        // This targets any container with 'kcm-dynamic-form-container' and 'kcm-form-loaded'
        // and ensures it's not the current $formContainer that we are about to open.
        $('.kcm-dynamic-form-container.kcm-form-loaded').not($formContainer).slideUp(function() {
            $(this).empty().removeClass('kcm-form-loaded');
        });

        // Empty the container and show a loader, then slide it down to show.
        // Add 'kcm-dynamic-form-container' class for consistent styling and targeting.
        $formContainer.html('<p>Loading form...</p>').addClass('kcm-dynamic-form-container').slideDown();

        // Get other data from the trigger if needed for the PHP callback
        var recipientName = $trigger.data('recipient-name') || 'Contact Person'; // Default if not provided
        var taskGroup = $trigger.data('task-group') || ''; // Default if not provided

        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: {
                action: 'kcm_get_contact_form', // PHP action hook for getting the form
                recipient_name: recipientName,
                recipient_email: recipientEmail, // Important for PHP to know which form to generate for
                task_group: taskGroup
                // security: getFormNonce, // If you use a nonce for fetching the form
            },
            dataType: 'json', // We expect a JSON response from the server
            success: function(response) {
                if (response.success && response.data && response.data.html) {
                    $formContainer.html(response.data.html).addClass('kcm-form-loaded'); // Mark as loaded
                    // Focus on the first input field for better UX
                    $formContainer.find('input[type="text"]:first, textarea:first').first().focus();
                } else {
                    var errorMessage = response.data && response.data.message ? response.data.message : 'Could not load the form.';
                    $formContainer.html('<p class="error">' + escapeHtml(errorMessage) + '</p>');
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error('AJAX Error (get_form):', textStatus, errorThrown, jqXHR.responseText);
                $formContainer.html('<p class="error">Network error or server error: ' + escapeHtml(textStatus) + '</p>');
            }
        });
    });

    // --- Event Listener for submitting the dynamically loaded form ---
    // Use event delegation, bound to a static parent element that always exists.
    // Since form containers ($formContainer) are dynamic, it's better to bind to a higher,
    // stable element like 'body' or a general wrapper around all your contact buttons and their form areas.
    // If your shortcode output has a general wrapper, use that. Otherwise, 'body' is safe.
    // The selector targets a form with id 'kcm-dynamic-contact-form' *inside* an element with class 'kcm-dynamic-form-container'.
    $('body').on('submit', '.kcm-dynamic-form-container #kcm-dynamic-contact-form', function(e) {
        e.preventDefault(); // Prevent default form submission
        var $form = $(this); // The submitted form
        var $formWrapper = $form.closest('.kcm-dynamic-form-container'); // The span containing the form
        var $submitButton = $form.find('input[type="submit"]');
        var $feedbackDiv = $formWrapper.find('.kcm-form-feedback'); // Look for feedback div within the form wrapper

        // Create or select a feedback div if it doesn't exist within the wrapper.
        // This ensures feedback is displayed close to the form it belongs to.
        if (!$feedbackDiv.length) {
            // If the PHP callback for get_form doesn't include a .kcm-form-feedback div,
            // this line will create one inside the $formWrapper.
            $feedbackDiv = $('<div class="kcm-form-feedback" style="display:none;"></div>').appendTo($formWrapper);
        }
        $feedbackDiv.html('').hide(); // Clear previous feedback and hide

        $.ajax({
            url: ajaxUrl, // Use the localized ajax_url from kcm_ajax_object
            type: 'POST',
            data: $form.serialize(), // serialize() includes action, nonce, and all form fields from the <form>
            dataType: 'json', // Expect a JSON response from the PHP handler kcm_send_contact_email_callback
            beforeSend: function() {
                $submitButton.prop('disabled', true).val('Sending...'); // Disable button and show sending state
            },
            success: function(response) {
                if (response.success) {
                    $feedbackDiv.html('<p class="success">' + escapeHtml(response.data.message) + '</p>').slideDown();
                    $form[0].reset(); // Reset the form fields after successful submission
                    // Optionally, hide the form after success after a few seconds
                    setTimeout(function() {
                        $formWrapper.slideUp(function() {
                            $(this).empty().removeClass('kcm-form-loaded'); // Clean up the container
                        });
                    }, 3000); // 3 seconds delay
                } else {
                    var errorMessage = response.data && response.data.message ? response.data.message : 'Error sending message.';
                    $feedbackDiv.html('<p class="error">' + escapeHtml(errorMessage) + '</p>').slideDown();
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error('AJAX Error (send_email):', textStatus, errorThrown, jqXHR.responseText);
                // Ensure $feedbackDiv is still correctly referenced
                // (it should be if it was found or created before the AJAX call)
                if (!$feedbackDiv.length) { // Fallback check, though unlikely to be needed if logic above is sound
                    $feedbackDiv = $form.closest('.kcm-dynamic-form-container').find('.kcm-form-feedback');
                    if (!$feedbackDiv.length) {
                         $feedbackDiv = $('<div class="kcm-form-feedback" style="display:none;"></div>').appendTo($form.closest('.kcm-dynamic-form-container'));
                    }
                }
                $feedbackDiv.html('<p class="error">' + escapeHtml('Network error or server error sending message: ') + escapeHtml(textStatus) + '</p>').slideDown();
            },
            complete: function() {
                // This function is always executed after success or error
                $submitButton.prop('disabled', false).val('Send Message'); // Or whatever the original button text was, make it translatable if possible
            }
        });
    });

    // Optional: If you want a way to explicitly close the form
    // (e.g., a close button inside the dynamically loaded form)
    // This also needs event delegation.
    // Your PHP (kcm_get_contact_form_callback) would need to include a button like:
    // <button type="button" class="kcm-close-form-button">Close</button>
    $('body').on('click', '.kcm-dynamic-form-container .kcm-close-form-button', function(e) {
        e.preventDefault();
        $(this).closest('.kcm-dynamic-form-container').slideUp(function() {
            $(this).empty().removeClass('kcm-form-loaded'); // Clean up the container
        });
    });

}); // End of jQuery(document).ready
