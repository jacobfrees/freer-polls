/* Freer Polls — Frontend JavaScript
   Handles: vote submission (with write-in suggestions), results rendering, comment posting
   Uses AJAX + REST API. No dependencies beyond jQuery (WordPress ships it).
   Version 1.1.0 — Added voter tracking fix, write-in suggestions
*/

(function($) {
    'use strict';

    // --- Vote option selection ---
    $(document).on('click', '.freer-poll-option', function(e) {
        var $poll = $(this).closest('.freer-poll');
        var voteType = $poll.data('vote-type');
        var $option = $(this);

        // Deselect write-in if clicking a regular option
        $poll.find('.freer-poll-write-in-input').val('');
        $poll.find('.freer-poll-write-in').removeClass('freer-poll-selected');
        $poll.find('.freer-poll-write-in .freer-poll-option-check').text('○');

        if (voteType === 'multi') {
            $option.toggleClass('freer-poll-selected');
            var $check = $option.find('.freer-poll-option-check');
            $check.text($option.hasClass('freer-poll-selected') ? '☑' : '☐');
        } else {
            // Single choice — deselect all, select this
            $poll.find('.freer-poll-option').removeClass('freer-poll-selected');
            $poll.find('.freer-poll-option .freer-poll-option-check').text('○');
            $option.addClass('freer-poll-selected');
            $option.find('.freer-poll-option-check').text('●');
        }
    });

    // --- Write-in field selection ---
    $(document).on('click', '.freer-poll-write-in', function(e) {
        // Don't toggle if clicking the input field itself
        if ($(e.target).is('input')) return;

        var $poll = $(this).closest('.freer-poll');
        var $writeIn = $(this);

        // Deselect all regular options
        $poll.find('.freer-poll-option').removeClass('freer-poll-selected');
        $poll.find('.freer-poll-option .freer-poll-option-check').text('○');

        // Toggle write-in selection
        $writeIn.toggleClass('freer-poll-selected');
        var $check = $writeIn.find('.freer-poll-option-check');
        $check.text($writeIn.hasClass('freer-poll-selected') ? '●' : '○');

        // Focus the input when selected
        if ($writeIn.hasClass('freer-poll-selected')) {
            $writeIn.find('.freer-poll-write-in-input').focus();
        }
    });

    // Also handle clicking on the write-in input
    $(document).on('focus', '.freer-poll-write-in-input', function() {
        var $writeIn = $(this).closest('.freer-poll-write-in');
        var $poll = $(this).closest('.freer-poll');

        // Deselect all regular options
        $poll.find('.freer-poll-option').removeClass('freer-poll-selected');
        $poll.find('.freer-poll-option .freer-poll-option-check').text('○');

        // Select write-in
        $writeIn.addClass('freer-poll-selected');
        $writeIn.find('.freer-poll-option-check').text('●');
    });

    // --- Vote submission ---
    $(document).on('click', '.freer-poll-submit', function(e) {
        e.preventDefault();
        var pollId = $(this).data('poll');
        var $poll = $('.freer-poll[data-poll-id="' + pollId + '"]');
        var $btn = $(this);
        var selected = [];
        var suggestion = '';

        // Check if write-in is selected
        var $writeIn = $poll.find('.freer-poll-write-in.freer-poll-selected');
        if ($writeIn.length > 0) {
            suggestion = $writeIn.find('.freer-poll-write-in-input').val().trim();
            if (!suggestion) {
                showPollMessage($poll, 'Type a suggestion or select an option.', 'error');
                return;
            }
        } else {
            $poll.find('.freer-poll-option.freer-poll-selected').each(function() {
                selected.push(parseInt($(this).data('choice')));
            });

            if (selected.length === 0) {
                showPollMessage($poll, 'Select an option or type a suggestion.', 'error');
                return;
            }
        }

        $btn.prop('disabled', true).text('Voting...');

        // Get nonce from the options container
        var nonce = $poll.find('.freer-poll-options').data('nonce') || '';

        $.ajax({
            url: freer_polls_ajax.rest_url + '/polls/' + pollId + '/vote',
            method: 'POST',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', freer_polls_ajax.nonce);
                xhr.setRequestHeader('X-Freer-Poll-Nonce', nonce);
            },
            contentType: 'application/json',
            data: JSON.stringify({
                choices: selected,
                suggestion: suggestion
            }),
            success: function(response) {
                if (response.success) {
                    // Replace options with results
                    $poll.find('.freer-poll-options').slideUp(300);
                    $poll.find('.freer-poll-submit').fadeOut(300);

                    setTimeout(function() {
                        var resultsHtml = renderResults(response.results);
                        var votedMsg = '<p class="freer-poll-voted-msg">✓ Vote recorded. Here are the current results.</p>';
                        $poll.find('.freer-poll-options').replaceWith(votedMsg + resultsHtml);
                        $poll.find('.freer-poll-submit').remove();
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
        var $existing = $poll.find('.freer-poll-msg');
        if ($existing.length === 0) {
            $poll.append('<p class="freer-poll-msg freer-poll-' + type + '" style="margin-top:12px;font-size:0.8125rem;">' + msg + '</p>');
        } else {
            $existing.text(msg).removeClass('freer-poll-success freer-poll-error').addClass('freer-poll-' + type);
        }
        if (type === 'success') {
            setTimeout(function() {
                $existing.fadeOut(300, function() { $(this).remove(); });
            }, 3000);
        }
    }

    function renderResults(results) {
        if (!results || results.total === 0) {
            return '<div class="freer-poll-results"><p class="freer-poll-no-votes">No votes yet. Be the first.</p></div>';
        }

        var html = '<div class="freer-poll-results">';
        html += '<div class="freer-poll-total">' + results.total + ' vote' + (results.total !== 1 ? 's' : '') + '</div>';

        var maxVotes = 0;
        results.options.forEach(function(opt) {
            if (opt.votes > maxVotes) maxVotes = opt.votes;
        });

        results.options.forEach(function(opt) {
            var barWidth = maxVotes > 0 ? Math.round((opt.votes / maxVotes) * 100 * 10) / 10 : 0;
            var isLeader = opt.votes === maxVotes && opt.votes > 0;

            html += '<div class="freer-poll-result-row' + (isLeader ? ' freer-poll-result-leader' : '') + '">';
            html += '<div class="freer-poll-result-label">' + escapeHtml(opt.label) + '</div>';
            html += '<div class="freer-poll-result-bar-wrap">';
            html += '<div class="freer-poll-result-bar" style="width:' + barWidth + '%"></div>';
            html += '<span class="freer-poll-result-pct">' + opt.percentage + '%</span>';
            html += '<span class="freer-poll-result-count">(' + opt.votes + ')</span>';
            html += '</div>';
            html += '</div>';
        });

        // Render write-in suggestions
        if (results.write_ins && results.write_ins.length > 0) {
            html += '<div class="freer-poll-write-in-results">';
            html += '<h4 class="freer-poll-write-in-heading">Suggestions</h4>';
            results.write_ins.forEach(function(suggestion) {
                html += '<div class="freer-poll-write-in-item">' + escapeHtml(suggestion) + '</div>';
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
    $(document).on('click', '.freer-poll-comment-submit', function(e) {
        e.preventDefault();
        var pollId = $(this).data('poll');
        var $poll = $('.freer-poll[data-poll-id="' + pollId + '"]');
        var $input = $poll.find('.freer-poll-comment-input');
        var $name = $poll.find('.freer-poll-comment-name');
        var $status = $poll.find('.freer-poll-comment-status');
        var $btn = $(this);

        var content = $input.val().trim();
        if (!content) {
            $status.text('Comment cannot be empty.').removeClass('freer-poll-success').addClass('freer-poll-error');
            return;
        }

        $btn.prop('disabled', true).text('Posting...');
        $status.removeClass('freer-poll-success freer-poll-error').text('');

        $.ajax({
            url: freer_polls_ajax.rest_url + '/polls/' + pollId + '/comments',
            method: 'POST',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', freer_polls_ajax.nonce);
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
                    $status.text('✓ Comment posted.').addClass('freer-poll-success');

                    // Append comment to list
                    var author = response.data.author || 'Anonymous';
                    var commentHtml = '<div class="freer-poll-comment" style="padding:12px 0;border-bottom:1px solid #1a1538;">';
                    commentHtml += '<div class="freer-poll-comment-author">' + escapeHtml(author) + '</div>';
                    commentHtml += '<div class="freer-poll-comment-content">' + escapeHtml(response.data.content) + '</div>';
                    commentHtml += '</div>';

                    var $list = $poll.find('.freer-poll-comments-list');
                    if ($list.find('.freer-poll-no-comments').length) {
                        $list.html(commentHtml);
                    } else {
                        $list.append(commentHtml);
                    }

                    // Update count
                    var $count = $poll.find('.freer-poll-comments-count');
                    var currentCount = parseInt($count.text()) || 0;
                    $count.text(currentCount + 1);

                    setTimeout(function() {
                        $status.text('');
                    }, 3000);
                } else {
                    $status.text(response.data.message || 'Failed to post comment.').addClass('freer-poll-error');
                }
            },
            error: function() {
                $status.text('Failed to post comment. Try again.').addClass('freer-poll-error');
            },
            complete: function() {
                $btn.prop('disabled', false).text('Post Comment');
            }
        });
    });

})(jQuery);
