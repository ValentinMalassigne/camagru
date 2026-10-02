/* Camagru image detail script (bonus: AJAXify likes and comments).
 *
 * Progressive enhancement only: the like button and the comment form work as
 * plain POST + redirect without this script. When it runs, both are submitted
 * through XMLHttpRequest instead (never fetch, see COMPATIBILITY.md entry 5)
 * and the page is updated in place:
 * - like: the button label, its look and the count come from the JSON answer;
 * - comment: the server answers with the comment already rendered by the same
 *   partial as the page (escaping happens server-side with e()), so the
 *   inserted entry is identical to the server-rendered ones.
 *
 * Handled errors show a short status line, never the console.
 * Written in ES5 style: var and function only, no arrow functions, no
 * template literals, no async/await.
 */
(function () {
    'use strict';

    var likeForm = document.getElementById('like-form');
    var commentForm = document.getElementById('comment-form');
    if (!likeForm && !commentForm) {
        // Not on the image detail page.
        return;
    }

    /** Read the session's CSRF token from a form's hidden input. */
    function csrfOf(form) {
        var input = form.querySelector('input[name="_csrf_token"]');
        return input ? input.value : '';
    }

    /** Replace the text content of an element (no innerHTML). */
    function setText(element, text) {
        while (element.firstChild) {
            element.removeChild(element.firstChild);
        }
        if (text !== '') {
            element.appendChild(document.createTextNode(text));
        }
    }

    /**
     * POST a url-encoded body with XHR and answer with parsed JSON.
     *
     * @param {string} url     Target of the form.
     * @param {string} data    Already url-encoded body.
     * @param {string} token   CSRF token (also sent as X-CSRF-Token header).
     * @param {Function} onDone Called with (parsedJsonOrErrorMessage, status).
     */
    function postJson(url, data, token, onDone) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', url, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
        xhr.setRequestHeader('X-CSRF-Token', token);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) {
                return;
            }
            var parsed = null;
            try {
                parsed = JSON.parse(xhr.responseText);
            } catch (parseError) {
                parsed = null;
            }
            onDone(parsed, xhr.status);
        };
        xhr.send(data);
    }

    /* --- Like toggle ---------------------------------------------------- */

    if (likeForm) {
        var likeButton = document.getElementById('like-button');
        var likeCount = document.getElementById('like-count');
        var likeStatus = document.getElementById('like-status');
        var likePending = false;

        likeForm.addEventListener('submit', function (event) {
            event.preventDefault();
            if (likePending) {
                return;
            }
            likePending = true;
            setText(likeStatus, '');

            var token = csrfOf(likeForm);
            postJson(likeForm.getAttribute('action'), '_csrf_token=' + token, token,
                function (answer, status) {
                    likePending = false;
                    if (status === 200 && answer && typeof answer.count === 'number') {
                        // Update the button, its look and the count in place.
                        var liked = answer.liked === true;
                        setText(likeButton, liked ? 'Unlike' : 'Like');
                        if (liked) {
                            likeButton.className = 'image-view__like-button image-view__like-button--on';
                        } else {
                            likeButton.className = 'image-view__like-button';
                        }
                        setText(likeCount, answer.count + (answer.count === 1 ? ' like' : ' likes'));
                    } else {
                        // Expired session or logged out elsewhere: show the
                        // server's message; the plain flow still works.
                        setText(likeStatus, answer && answer.error
                            ? answer.error
                            : 'The like could not be saved. Please reload the page.');
                    }
                });
        });
    }

    /* --- Comment form --------------------------------------------------- */

    if (commentForm) {
        var bodyField = document.getElementById('comment-body');
        var commentStatus = document.getElementById('comment-status');
        var commentCount = document.getElementById('comment-count');
        var submitButton = commentForm.querySelector('button[type="submit"]');
        var commentPending = false;

        commentForm.addEventListener('submit', function (event) {
            event.preventDefault();
            if (commentPending) {
                return;
            }
            commentPending = true;
            setText(commentStatus, '');
            if (submitButton) {
                submitButton.disabled = true;
            }

            var token = csrfOf(commentForm);
            var data = '_csrf_token=' + token + '&body=' + encodeURIComponent(bodyField.value);
            postJson(commentForm.getAttribute('action'), data, token,
                function (answer, status) {
                    commentPending = false;
                    if (submitButton) {
                        submitButton.disabled = false;
                    }
                    if (status === 200 && answer && answer.html) {
                        // The list may not exist yet (the "No comments yet"
                        // state); create it and drop the placeholder text.
                        var list = document.getElementById('comments-list');
                        if (!list) {
                            list = document.createElement('ul');
                            list.id = 'comments-list';
                            list.className = 'comments__list';
                            var empty = document.getElementById('comments-empty');
                            if (empty && empty.parentNode) {
                                empty.parentNode.replaceChild(list, empty);
                            }
                        }
                        list.insertAdjacentHTML('beforeend', answer.html);
                        setText(commentCount, String(answer.count));
                        bodyField.value = '';
                    } else {
                        setText(commentStatus, answer && answer.error
                            ? answer.error
                            : 'The comment could not be posted. Please reload the page.');
                    }
                });
        });
    }
}());
