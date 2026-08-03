/* Jacob Frees Evolves Polls — Admin JavaScript */

jQuery(document).ready(function($) {
    // Auto-close warning
    var $expiry = $('#jfp_poll_expiry');
    if ($expiry.length) {
        $expiry.on('change', function() {
            var val = $(this).val();
            if (val) {
                var expiryDate = new Date(val);
                var now = new Date();
                if (expiryDate < now) {
                    alert('This date is in the past. The poll will be closed immediately.');
                }
            }
        });
    }

    // Update vote count via AJAX when editing
    if (typeof pagenow !== 'undefined' && pagenow === 'jf_poll') {
        var postId = $('#post_ID').val();
        if (postId) {
            $.ajax({
                url: ajaxurl,
                method: 'POST',
                data: {
                    action: 'jfp_admin_vote_count',
                    post_id: postId,
                    nonce: jfpAdmin.nonce || ''
                },
                success: function(response) {
                    if (response.success) {
                        $('.jfp-vote-count').text(response.data.count + ' votes cast');
                    }
                }
            });
        }
    }
});