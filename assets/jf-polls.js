/* Jacob Frees Evolves Polls — Frontend JavaScript
   Handles: vote submission (with write-in suggestions), results rendering, comment posting
   Uses AJAX + REST API. No dependencies beyond jQuery (WordPress ships it).
   Version 1.1.0 — Added voter tracking fix, write-in suggestions
*/

(function($) {
    'use strict';

    // --- Vote option selection ---
    $(document).on('click', '.jfp-poll-option', function(e) {
        var $poll = $(this).closest('.jfp-poll');
        var voteType = $poll.data('vote-type');
        var $option = $(this);

        // Deselect write-in if clicking a regular option
        $poll.find('.jfp-write-in-input').val('');
        $poll.find('.jfp-write-in').removeClass('jfp-selected');
        $poll.find('.jfp-write-in .jfp-poll-option-check').text('○');

        if (voteType === 'multi') {
            $option.toggleClass('jfp-selected');
            var $check = $option.find('.jfp-poll-option-check');
            $check.text($option.hasClass('jfp-selected') ? '☑' : '☐');
        } else {
            // Single choice — deselect all, select this
            $poll.find('.jfp-poll-option').removeClass('jfp-selected');
            $poll.find('.jfp-poll-option .jfp-poll-option-check').text('○');
            $option.addClass('jfp-selected');
            $option.find('.jfp-poll-option-check').text('●');
        }
    });

    // --- Write-in field selection ---
    $(document).on('click', '.jfp-write-in', function(e) {
        // Don't toggle if clicking the input field itself
        if ($(e.target).is('input')) return;

        var $poll = $(this).closest('.jfp-poll');
        var $writeIn = $(this);

        // Deselect all regular options
        $poll.find('.jfp-poll-option').removeClass('jfp-selected');
        $poll.find('.jfp-poll-option .jfp-poll-option-check').text('○');

        // Toggle write-in selection
        $writeIn.toggleClass('jfp-selected');
        var $check = $writeIn.find('.jfp-poll-option-check');
        $check.text($writeIn.hasClass('jfp-selected') ? '●' : '○');

        // Focus the input when selected
        if ($writeIn.hasClass('jfp-selected')) {
            $writeIn.find('.jfp-write-in-input').focus();
        }
    });

    // Also handle clicking on the write-in input
    $(document).on('focus', '.jfp-write-in-input', function() {
        var $writeIn = $(this).closest('.jfp-write-in');
        var $poll = $(this).closest('.jfp-poll');

        // Deselect all regular options
        $poll.find('.jfp-poll-option').removeClass('jfp-selected');
        $poll.find('.jfp-poll-option .jfp-poll-option-check').text('○');

        // Select write-in
        $writeIn.addClass('jfp-selected');
        $writeIn.find('.jfp-poll-option-check').text('●');
    });

    // --- Vote submission ---
    $(document).on('click', '.jfp-poll-submit', function(e) {
        e.preventDefault();
        var pollId = $(this).data('poll');
        var $poll = $('.jfp-poll[data-poll-id="' + pollId + '"]');
        var $btn = $(this);
        var selected = [];
        var suggestion = '';

        // Check if write-in is selected
        var $writeIn = $poll.find('.jfp-write-in.jfp-selected');
        if ($writeIn.length > 0) {
            suggestion = $writeIn.find('.jfp-write-in-input').val().trim();
            if (!suggestion) {
                showPollMessage($poll, 'Type a suggestion or select an option.', 'error');
                return;
            }
        } else {
            $poll.find('.jfp-poll-option.jfp-selected').each(function() {
                selected.push(parseInt($(this).data('choice')));
            });

            if (selected.length === 0) {
                showPollMessage($poll, 'Select an option or type a suggestion.', 'error');
                return;
            }
        }

        $btn.prop('disabled', true).text('Voting...');

        // Get nonce from the options container
        var nonce = $poll.find('.jfp-poll-options').data('nonce') || '';

        $.ajax({
            url: jfp_ajax.rest_url + '/polls/' + pollId + '/vote',
            method: 'POST',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', jfp_ajax.nonce);
                xhr.setRequestHeader('X-JFP-Nonce', nonce);
            },
            contentType: 'application/json',
            data: JSON.stringify({
                choices: selected,
                suggestion: suggestion
            }),
            success: function(response) {
                if (response.success) {
                    // Replace options with results
                    $poll.find('.jfp-poll-options').slideUp(300);
                    $poll.find('.jfp-poll-submit').fadeOut(300);

                    setTimeout(function() {
                        var resultsHtml = renderResults(response.results);
                        var votedMsg = '<p class="jfp-poll-voted-msg">✓ Vote recorded. Here are the current results.</p>';
                        $poll.find('.jfp-poll-options').replaceWith(votedMsg + resultsHtml);
                        $poll.find('.jfp-poll-submit').remove();
                    }, 300);
                } else {
                    showPollMessage($poll, response.message || 'Vote failed.', 'error');
                    $btn.prop('disabled', false).text('Vote');
                }
            },
            error: function(xhr) {
                var msg = 'Vote failed. Try again.';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    msg = xhr.responseJSON.message;
                }
                showPollMessage($poll, msg, 'error');
                $btn.prop('disabled', false).text('Vote');
            }
        });
    });

    function showPollMessage($poll, msg, type) {
        var $existing = $poll.find('.jfp-poll-msg');
        if ($existing.length === 0) {
            $poll.append('<p class="jfp-poll-msg jfp-' + type + '" style="margin-top:12px;font-size:0.8125rem;">' + msg + '</p>');
        } else {
            $existing.text(msg).removeClass('jfp-success jfp-error').addClass('jfp-' + type);
        }
        if (type === 'success') {
            setTimeout(function() {
                $existing.fadeOut(300, function() { $(this).remove(); });
            }, 3000);
        }
    }

    function renderResults(results) {
        if (!results || results.total === 0) {
            return '<div class="jfp-poll-results"><p class="jfp-no-votes">No votes yet. Be the first.</p></div>';
        }

        var html = '<div class="jfp-poll-results">';
        html += '<div class="jfp-poll-total">' + results.total + ' vote' + (results.total !== 1 ? 's' : '') + '</div>';

        var maxVotes = 0;
        results.options.forEach(function(opt) {
            if (opt.votes > maxVotes) maxVotes = opt.votes;
        });

        results.options.forEach(function(opt) {
            var barWidth = maxVotes > 0 ? Math.round((opt.votes / maxVotes) * 100 * 10) / 10 : 0;
            var isLeader = opt.votes === maxVotes && opt.votes > 0;

            html += '<div class="jfp-result-row' + (isLeader ? ' jfp-result-leader' : '') + '">';
            html += '<div class="jfp-result-label">' + escapeHtml(opt.label) + '</div>';
            html += '<div class="jfp-result-bar-wrap">';
            html += '<div class="jfp-result-bar" style="width:' + barWidth + '%"></div>';
            html += '<span class="jfp-result-pct">' + opt.percentage + '%</span>';
            html += '<span class="jfp-result-count">(' + opt.votes + ')</span>';
            html += '</div>';
            html += '</div>';
        });

        // Render write-in suggestions
        if (results.write_ins && results.write_ins.length > 0) {
            html += '<div class="jfp-write-in-results">';
            html += '<h4 class="jfp-write-in-heading">Suggestions</h4>';
            results.write_ins.forEach(function(suggestion) {
                html += '<div class="jfp-write-in-item">' + escapeHtml(suggestion) + '</div>';
            });
            html += '</div>';
        }

        html += '</div>';
        return html;
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    // --- Comments ---
    $(document).on('click', '.jfp-comment-submit', function(e) {
        e.preventDefault();
        var pollId = $(this).data('poll');
        var $poll = $('.jfp-poll[data-poll-id="' + pollId + '"]');
        var $input = $poll.find('.jfp-comment-input');
        var $name = $poll.find('.jfp-comment-name');
        var $status = $poll.find('.jfp-comment-status');
        var $btn = $(this);

        var content = $input.val().trim();
        if (!content) {
            $status.text('Comment cannot be empty.').removeClass('jfp-success').addClass('jfp-error');
            return;
        }

        $btn.prop('disabled', true).text('Posting...');
        $status.removeClass('jfp-success jfp-error').text('');

        $.ajax({
            url: jfp_ajax.rest_url + '/polls/' + pollId + '/comments',
            method: 'POST',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', jfp_ajax.nonce);
            },
            contentType: 'application/json',
            data: JSON.stringify({
                content: content,
                author: $name.val().trim(),
                website: '' // honeypot
            }),
            success: function(response) {
                if (response.success) {
                    $input.val('');
                    $status.text('✓ Comment posted.').addClass('jfp-success');

                    // Append comment to list
                    var author = response.data.author || 'Anonymous';
                    var commentHtml = '<div class="jfp-comment" style="padding:12px 0;border-bottom:1px solid #1a1538;">';
                    commentHtml += '<div class="jfp-comment-author">' + escapeHtml(author) + '</div>';
                    commentHtml += '<div class="jfp-comment-content">' + escapeHtml(response.data.content) + '</div>';
                    commentHtml += '</div>';

                    var $list = $poll.find('.jfp-comments-list');
                    if ($list.find('.jfp-no-comments').length) {
                        $list.html(commentHtml);
                    } else {
                        $list.append(commentHtml);
                    }

                    // Update count
                    var $count = $poll.find('.jfp-comments-count');
                    var currentCount = parseInt($count.text()) || 0;
                    $count.text(currentCount + 1);

                    setTimeout(function() {
                        $status.text('');
                    }, 3000);
                } else {
                    $status.text(response.data.message || 'Failed to post comment.').addClass('jfp-error');
                }
            },
            error: function() {
                $status.text('Failed to post comment. Try again.').addClass('jfp-error');
            },
            complete: function() {
                $btn.prop('disabled', false).text('Post Comment');
            }
        });
    });

})(jQuery);
